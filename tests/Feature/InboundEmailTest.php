<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\EmailAttachment;
use App\Models\EmailMailbox;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Lead;
use App\Services\CompanyContext;
use App\Services\Email\Inbox\EmailSanitizer;
use App\Services\Email\Inbox\EmailThreadResolver;
use App\Services\Email\Inbox\InboundEmailResult;
use App\Services\Email\Inbox\InboundEmailService;
use App\Services\Email\Inbox\MimeEmailParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Email inbox's receiving side (owner request 2026-11-02): reading a raw message, cleaning its HTML, joining
 * it to a conversation, matching the sender to a customer, keeping its files on the company's private storage,
 * and the webhook the Cloudflare Worker posts to.
 */
class InboundEmailTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private EmailMailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->company = $this->makeCompany('Inbox Co', 'inbox-co');
        app(CompanyContext::class)->set($this->company);
        $this->mailbox = $this->makeMailbox($this->company, 'info@zamzamgadgetbd.com');
    }

    // --- Reading a message ----------------------------------------------------------------------------

    public function test_a_plain_message_is_read_with_its_names_subject_and_bangla_text(): void
    {
        $email = (new MimeEmailParser)->parse($this->fixture('plain'));

        $this->assertSame('abc123.def@rahimtraders.example', $email->messageId);
        $this->assertSame('purchase@rahimtraders.example', $email->fromAddress, 'The address is lower case.');
        $this->assertSame('Rahim Traders', $email->fromName);
        $this->assertSame('Bulk price for 56-inch ceiling fans', $email->subject);
        $this->assertSame(['info@zamzamgadgetbd.com', 'other@shop.test'], array_column($email->to, 'address'));
        $this->assertSame('ZamZam Gadget', $email->to[0]['name']);
        $this->assertSame(['sadia@zamzam.test'], array_column($email->cc, 'address'));
        $this->assertStringContainsString('ধন্যবাদ', $email->text);
        $this->assertNull($email->html);
        $this->assertSame('2026-11-02 04:42:00', $email->date->utc()->format('Y-m-d H:i:s'));
    }

    public function test_an_encoded_bangla_subject_name_and_body_are_decoded(): void
    {
        $email = (new MimeEmailParser)->parse($this->fixture('bangla'));

        $this->assertSame('অর্ডার এখনো পাইনি', $email->subject);
        $this->assertSame('রহিম ট্রেডার্স', $email->fromName);
        $this->assertStringContainsString('আসসালামু আলাইকুম', $email->text);
    }

    public function test_a_message_without_a_message_id_gets_a_stable_one_and_the_reply_ids_are_read(): void
    {
        $parser = new MimeEmailParser;
        $first = $parser->parse($this->fixture('no-id'));
        $second = $parser->parse($this->fixture('no-id'));

        $this->assertStringStartsWith('generated-', $first->messageId);
        $this->assertSame($first->messageId, $second->messageId, 'The same message gives the same id, so a second delivery is recognised.');

        $reply = $parser->parse($this->fixture('reply'));
        $this->assertSame('abc123.def@rahimtraders.example', $reply->inReplyTo);
        $this->assertSame(['abc123.def@rahimtraders.example'], $reply->references);
    }

    public function test_html_only_mail_gets_a_text_version_and_files_are_found_with_safe_names(): void
    {
        $email = (new MimeEmailParser)->parse($this->fixture('attachment'));

        $this->assertStringContainsString('Photos attached.', $email->text, 'The text version is made from the HTML when the message has none.');
        $this->assertCount(2, $email->attachments);

        $logo = collect($email->attachments)->firstWhere('filename', 'logo.png');
        $this->assertTrue($logo->inline);
        $this->assertSame('logo@example.test', $logo->contentId);
        $this->assertSame('image/png', $logo->mime);

        $pdf = collect($email->attachments)->firstWhere('mime', 'application/pdf');
        $this->assertSame('invoice.pdf', $pdf->filename, 'A path in the file name is cut off.');
        $this->assertFalse($pdf->inline);
    }

    public function test_an_empty_or_senderless_message_is_refused(): void
    {
        $parser = new MimeEmailParser;

        $this->expectException(\InvalidArgumentException::class);
        $parser->parse("   \n");
    }

    public function test_a_message_with_no_sender_at_all_is_refused_unless_the_envelope_names_one(): void
    {
        $parser = new MimeEmailParser;
        $raw = "To: info@zamzamgadgetbd.com\nSubject: hi\nMessage-ID: <x@y.test>\n\nbody\n";

        $this->assertSame('envelope@sender.test', $parser->parse($raw, 'Envelope@Sender.test')->fromAddress);

        $this->expectException(\InvalidArgumentException::class);
        $parser->parse($raw);
    }

    // --- Cleaning the HTML ----------------------------------------------------------------------------

    public function test_the_sanitizer_removes_what_can_run_code_and_keeps_the_layout(): void
    {
        $html = (new MimeEmailParser)->parse($this->fixture('alternative'))->html;
        $clean = (new EmailSanitizer)->clean((string) $html);

        foreach (['<script', 'onload', 'onerror', 'javascript:', '<form', '<iframe', '<input', 'alert(', 'steal('] as $bad) {
            $this->assertStringNotContainsString($bad, $clean, "{$bad} must be gone.");
        }

        $this->assertStringContainsString('<b>my order</b>', $clean);
        $this->assertStringContainsString('color:#c00', $clean, 'Inline styles stay.');
        $this->assertStringContainsString('<table>', $clean);
        $this->assertStringContainsString('href="https://shop.test/track"', $clean);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $clean);
        $this->assertStringContainsString('target="_blank"', $clean);
        $this->assertSame('', (new EmailSanitizer)->clean('   '));
    }

    // --- Taking a message in --------------------------------------------------------------------------

    public function test_a_received_message_starts_a_conversation_in_the_inbox(): void
    {
        $result = $this->receive('plain');

        $this->assertFalse($result->duplicate);
        $this->assertTrue($result->newThread);

        $thread = $result->message->thread;
        $this->assertSame(EmailThread::FOLDER_INBOX, $thread->folder);
        $this->assertSame(EmailThread::STATUS_OPEN, $thread->status);
        $this->assertSame('Bulk price for 56-inch ceiling fans', $thread->subject);
        $this->assertSame('purchase@rahimtraders.example', $thread->contact_email);
        $this->assertSame(1, $thread->message_count);
        $this->assertSame(1, $thread->unread_count);
        $this->assertTrue($thread->needsReply());
        $this->assertStringStartsWith('Hello, We would like to order 200 pieces.', (string) $thread->snippet);

        $message = $result->message;
        $this->assertSame(EmailMessage::IN, $message->direction);
        $this->assertSame(EmailMessage::STATUS_RECEIVED, $message->status);
        $this->assertFalse($message->is_read);
        $this->assertSame($this->company->getKey(), (int) $message->company_id);
        $this->assertNotNull($this->mailbox->fresh()->last_received_at);
    }

    public function test_the_same_message_delivered_twice_is_kept_once(): void
    {
        $first = $this->receive('plain');
        $second = $this->receive('plain');

        $this->assertTrue($second->duplicate);
        $this->assertSame($first->message->getKey(), $second->message->getKey());
        $this->assertSame(1, EmailMessage::query()->count());
        $this->assertSame(1, EmailThread::query()->firstOrFail()->message_count);
    }

    public function test_a_reply_joins_its_conversation_by_the_reply_headers(): void
    {
        $first = $this->receive('plain');
        $reply = $this->receive('reply');

        $this->assertFalse($reply->newThread);
        $this->assertSame($first->message->thread_id, $reply->message->thread_id);

        $thread = $first->message->thread->fresh();
        $this->assertSame(2, $thread->message_count);
        $this->assertSame(2, $thread->unread_count);
        $this->assertStringStartsWith('Thank you, please confirm delivery', (string) $thread->snippet);
        $this->assertSame(1, EmailThread::query()->count());
    }

    public function test_a_reply_without_headers_joins_by_subject_and_sender_but_a_stranger_does_not(): void
    {
        $first = $this->receive('plain');
        $byName = $this->receive('subject-only');

        $this->assertSame($first->message->thread_id, $byName->message->thread_id, 'RE: Re: Subject is the same subject from the same person.');
        $this->assertSame('bulk price for 56-inch ceiling fans', EmailThreadResolver::normalizeSubject('RE: Re: Fwd: Bulk  price for 56-inch ceiling fans'));
    }

    public function test_a_stranger_with_the_same_subject_starts_its_own_conversation(): void
    {
        $this->receive('plain');
        $raw = str_replace(['purchase@rahimtraders.example', 'subject-only-1@rahimtraders.example'], ['someone.else@example.test', 'other-1@example.test'], $this->fixture('subject-only'));

        $other = app(InboundEmailService::class)->receive($this->mailbox, $raw);

        $this->assertTrue($other->newThread);
        $this->assertSame(2, EmailThread::query()->count());
    }

    public function test_a_message_after_the_window_does_not_join_by_subject(): void
    {
        $first = $this->receive('plain');
        $first->message->thread->forceFill(['last_message_at' => now()->subDays(EmailThreadResolver::SUBJECT_WINDOW_DAYS + 1)])->save();

        $late = $this->receive('subject-only');

        $this->assertTrue($late->newThread);
    }

    public function test_a_message_brings_an_archived_or_deleted_conversation_back_but_not_a_spam_one(): void
    {
        $first = $this->receive('plain');
        $thread = $first->message->thread;

        foreach ([EmailThread::FOLDER_ARCHIVED, EmailThread::FOLDER_TRASH] as $away) {
            $thread->forceFill(['folder' => $away, 'status' => EmailThread::STATUS_CLOSED])->save();
            $this->receiveAgain('reply', $away);
            $this->assertSame(EmailThread::FOLDER_INBOX, $thread->fresh()->folder, "From {$away} it comes back.");
            $this->assertSame(EmailThread::STATUS_OPEN, $thread->fresh()->status, 'A closed conversation is reopened.');
        }

        $thread->forceFill(['folder' => EmailThread::FOLDER_SPAM])->save();
        $this->receiveAgain('reply', 'spam');
        $this->assertSame(EmailThread::FOLDER_SPAM, $thread->fresh()->folder, 'Spam stays spam.');
    }

    public function test_the_sender_is_matched_to_a_customer_and_a_lead_of_the_same_company_only(): void
    {
        $customer = Customer::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Rahim Traders', 'phone' => '01700000001', 'email' => 'Purchase@RahimTraders.example']);
        $lead = Lead::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Rahim', 'email' => 'purchase@rahimtraders.example', 'phone' => '01700000002']);

        $other = $this->makeCompany('Other Co', 'other-co');
        Customer::withoutGlobalScopes()->create(['company_id' => $other->getKey(), 'name' => 'Elsewhere', 'phone' => '01700000003', 'email' => 'purchase@rahimtraders.example']);

        $thread = $this->receive('plain')->message->thread;

        $this->assertSame($customer->getKey(), (int) $thread->customer_id);
        $this->assertSame($lead->getKey(), (int) $thread->lead_id);
    }

    public function test_matching_can_be_switched_off_for_a_mailbox(): void
    {
        Customer::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Rahim Traders', 'phone' => '01700000001', 'email' => 'purchase@rahimtraders.example']);
        $this->mailbox->forceFill(['settings' => ['match_customers' => false]])->save();

        $this->assertNull($this->receive('plain')->message->thread->customer_id);
    }

    public function test_files_go_to_the_companys_private_storage_with_no_trace_of_the_sent_name(): void
    {
        $message = $this->receive('attachment')->message;
        $thread = $message->thread->fresh();

        $this->assertTrue($thread->has_attachments);
        $this->assertCount(2, $message->attachments);

        foreach ($message->attachments as $file) {
            $this->assertStringStartsWith($this->company->storageRoot().'/private/email-attachments/', $file->path);
            $this->assertStringNotContainsString('invoice', $file->path, 'The stored name is made up, never the sender\'s.');
            Storage::disk('local')->assertExists($file->path);
        }

        $pdf = $message->attachments->firstWhere('mime', 'application/pdf');
        $this->assertSame("%PDF-1.4\n% test document\n", Storage::disk('local')->get($pdf->path));
        $this->assertSame('invoice.pdf', $pdf->filename);
        $this->assertSame(25, $pdf->size);
    }

    public function test_the_message_is_kept_clean_and_without_the_raw_text(): void
    {
        $message = $this->receive('alternative')->message;

        $this->assertStringNotContainsString('<script', (string) $message->body_html);
        $this->assertStringContainsString('<b>my order</b>', (string) $message->body_html);
        $this->assertStringContainsString('Where is my order?', (string) $message->body_text);
        $this->assertSame(strlen($this->fixture('alternative')), $message->raw_size);
        $this->assertArrayNotHasKey('raw', $message->getAttributes());
    }

    public function test_a_message_for_one_company_is_never_seen_or_joined_by_another(): void
    {
        $first = $this->receive('plain');

        $other = $this->makeCompany('Other Co', 'other-co');
        $otherBox = $this->makeMailbox($other, 'info@other.test');
        $theirs = app(InboundEmailService::class)->receive($otherBox, $this->fixture('plain'));

        $this->assertFalse($theirs->duplicate, 'The same Message-ID in another company\'s mailbox is another message.');
        $this->assertNotSame($first->message->thread_id, $theirs->message->thread_id);
        $this->assertSame($other->getKey(), (int) $theirs->message->company_id);

        app(CompanyContext::class)->set($this->company);
        $this->assertSame(1, EmailMessage::query()->count());
        $this->assertSame(1, EmailThread::query()->count());

        app(CompanyContext::class)->set($other);
        $this->assertSame(1, EmailMessage::query()->count());
        $this->assertSame($theirs->message->getKey(), EmailMessage::query()->firstOrFail()->getKey());
        $this->assertSame(0, EmailAttachment::query()->count());
    }

    // --- The webhook ----------------------------------------------------------------------------------

    public function test_the_webhook_takes_a_message_with_the_right_token_and_needs_no_csrf_token(): void
    {
        $token = $this->mailbox->issueToken();

        $this->call('POST', $this->url($this->mailbox, $token), [], [], [], ['CONTENT_TYPE' => 'message/rfc822', 'HTTP_X_ENVELOPE_FROM' => 'purchase@rahimtraders.example'], $this->fixture('plain'))
            ->assertOk()
            ->assertExactJson(['ok' => true, 'duplicate' => false]);

        $this->assertSame(1, EmailMessage::withoutGlobalScopes()->count());

        $this->call('POST', $this->url($this->mailbox, $token), [], [], [], ['CONTENT_TYPE' => 'message/rfc822'], $this->fixture('plain'))
            ->assertOk()
            ->assertExactJson(['ok' => true, 'duplicate' => true]);

        $this->assertSame(1, EmailMessage::withoutGlobalScopes()->count());
    }

    public function test_a_wrong_token_or_an_unknown_mailbox_is_the_same_404(): void
    {
        $token = $this->mailbox->issueToken();

        $this->call('POST', $this->url($this->mailbox, 'wrong-token'), [], [], [], [], $this->fixture('plain'))->assertNotFound();
        $this->call('POST', route('webhooks.email', ['mailbox' => 999999, 'token' => $token]), [], [], [], [], $this->fixture('plain'))->assertNotFound();
        $this->call('POST', $this->url($this->mailbox, ''), [], [], [], [], '')->assertNotFound();

        $this->assertSame(0, EmailMessage::withoutGlobalScopes()->count());
    }

    public function test_another_mailbox_s_token_does_not_work_and_a_new_token_replaces_the_old_one(): void
    {
        $other = $this->makeMailbox($this->company, 'sales@zamzamgadgetbd.com');
        $otherToken = $other->issueToken();
        $old = $this->mailbox->issueToken();

        $this->call('POST', $this->url($this->mailbox, $otherToken), [], [], [], [], $this->fixture('plain'))->assertNotFound();

        $new = $this->mailbox->issueToken();
        $this->assertNotSame($old, $new);
        $this->call('POST', $this->url($this->mailbox, $old), [], [], [], [], $this->fixture('plain'))->assertNotFound();
        $this->call('POST', $this->url($this->mailbox, $new), [], [], [], [], $this->fixture('plain'))->assertOk();

        $this->assertNotSame($new, $this->mailbox->fresh()->token_hash, 'Only a hash is kept.');
        $this->assertSame(64, strlen((string) $this->mailbox->fresh()->token_hash));
        $this->assertArrayNotHasKey('token_hash', $this->mailbox->fresh()->toArray());
    }

    public function test_a_mailbox_that_does_not_receive_says_so_only_to_the_holder_of_its_token(): void
    {
        $token = $this->mailbox->issueToken();
        $this->mailbox->forceFill(['is_active' => false])->save();

        $this->call('POST', $this->url($this->mailbox, $token), [], [], [], [], $this->fixture('plain'))
            ->assertStatus(409)
            ->assertJson(['error' => 'mailbox_not_receiving']);
        $this->call('POST', $this->url($this->mailbox, 'wrong'), [], [], [], [], $this->fixture('plain'))->assertNotFound();

        $this->mailbox->forceFill(['is_active' => true, 'receive_mode' => EmailMailbox::RECEIVE_NONE])->save();
        $this->call('POST', $this->url($this->mailbox, $token), [], [], [], [], $this->fixture('plain'))->assertStatus(409);

        $this->assertSame(0, EmailMessage::withoutGlobalScopes()->count());
    }

    public function test_a_message_that_is_too_large_or_unreadable_is_refused(): void
    {
        $token = $this->mailbox->issueToken();

        // The test PHP's post_max_size is 8 MB; in production the web server's 16 MB limit comes first for anything bigger than this.
        $this->withoutMiddleware(ValidatePostSize::class);

        $this->call('POST', $this->url($this->mailbox, $token), [], [], [], ['CONTENT_LENGTH' => InboundEmailService::MAX_BYTES + 1], 'x')
            ->assertStatus(413)
            ->assertJson(['error' => 'message_too_large']);

        $this->call('POST', $this->url($this->mailbox, $token), [], [], [], [], "   \n")
            ->assertStatus(422)
            ->assertJson(['error' => 'unreadable_message']);

        $this->assertSame(0, EmailMessage::withoutGlobalScopes()->count());
    }

    public function test_the_webhook_route_is_throttled_and_exempt_from_csrf_in_the_app_bootstrap(): void
    {
        $route = app('router')->getRoutes()->getByName('webhooks.email');
        $this->assertContains('throttle:600,1', $route->gatherMiddleware());

        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringContainsString("'webhooks/email/*'", $bootstrap);
    }

    // --- Helpers --------------------------------------------------------------------------------------

    private function receive(string $fixture): InboundEmailResult
    {
        return app(InboundEmailService::class)->receive($this->mailbox, $this->fixture($fixture));
    }

    /** The reply fixture again, as a message of its own (a new Message-ID each time), to test what a later message does. */
    private function receiveAgain(string $fixture, string $tag): InboundEmailResult
    {
        $raw = str_replace('reply-1@rahimtraders.example', 'reply-'.$tag.'@rahimtraders.example', $this->fixture($fixture));

        return app(InboundEmailService::class)->receive($this->mailbox, $raw);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/email/'.$name.'.eml'));
    }

    private function url(EmailMailbox $mailbox, string $token): string
    {
        return '/webhooks/email/'.$mailbox->getKey().'/'.$token;
    }

    private function makeCompany(string $name, string $slug): Company
    {
        return Company::query()->create(['name' => $name, 'slug' => $slug, 'invoice_prefix' => strtoupper(substr($slug, 0, 3)), 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true]);
    }

    private function makeMailbox(Company $company, string $address): EmailMailbox
    {
        return EmailMailbox::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'name' => 'Test mailbox',
            'address' => $address,
            'receive_mode' => EmailMailbox::RECEIVE_WEBHOOK,
            'is_active' => true,
        ]);
    }
}
