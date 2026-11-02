<?php

namespace Tests\Feature;

use App\Mail\ComposedEmail;
use App\Models\Company;
use App\Models\EmailAttachment;
use App\Models\EmailLog;
use App\Models\EmailMailbox;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\Email\Inbox\EmailSendException;
use App\Services\Email\Inbox\EmailThreadStats;
use App\Services\Email\Inbox\InboundEmailService;
use App\Services\Email\Inbox\OutboundEmailService;
use App\Support\EmailAddressList;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Email inbox's sending side (owner request 2026-11-02): reading a typed address list, starting a new message,
 * a reply, a reply to all and a forward, keeping the draft, the undo-send window, handing the message to the mail
 * server exactly once, the Email Log rows, a failed send and its retry, internal notes, and company isolation.
 */
class OutboundEmailServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private EmailMailbox $mailbox;

    private User $user;

    private OutboundEmailService $outbound;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->company = $this->makeCompany('Outbox Co', 'outbox-co');
        app(CompanyContext::class)->set($this->company);
        $this->makeSite($this->company);
        $this->mailbox = $this->makeMailbox($this->company, 'info@shop.test', signature: "Sadia\nZamZam Gadget");
        $this->user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $this->outbound = app(OutboundEmailService::class);
    }

    // --- Typed address lists --------------------------------------------------------------------------

    public function test_a_typed_list_gives_clean_addresses_and_names_and_names_the_ones_that_are_wrong(): void
    {
        $parsed = EmailAddressList::parse('Rahim Traders <Rahim@X.test>, "Karim, Sons" <karim@y.test>; sales@z.test, not-an-address, rahim@x.test');

        $this->assertSame(['rahim@x.test', 'karim@y.test', 'sales@z.test'], EmailAddressList::addresses($parsed['valid']), 'Lower case, once each, in order.');
        $this->assertSame('Rahim Traders', $parsed['valid'][0]['name']);
        $this->assertSame('Karim, Sons', $parsed['valid'][1]['name'], 'A comma inside a quoted name does not split the address.');
        $this->assertNull($parsed['valid'][2]['name']);
        $this->assertSame(['not-an-address'], $parsed['invalid']);

        $fromList = EmailAddressList::parse([['address' => 'A@b.test', 'name' => "Line\nBreak"], ['address' => 'bad']]);
        $this->assertSame('Line Break', $fromList['valid'][0]['name'], 'A name cannot carry a line break into a header.');
        $this->assertSame(['bad'], $fromList['invalid']);
        $this->assertSame(['valid' => [], 'invalid' => []], EmailAddressList::parse(null));
        $this->assertSame('Rahim <r@x.test>', EmailAddressList::label(['address' => 'r@x.test', 'name' => 'Rahim']));
    }

    // --- Starting a message ---------------------------------------------------------------------------

    public function test_a_new_message_is_a_draft_in_a_conversation_of_its_own_with_the_signature(): void
    {
        $draft = $this->outbound->startNew($this->mailbox, $this->user);

        $this->assertSame(EmailMessage::KIND_DRAFT, $draft->kind);
        $this->assertSame(EmailMessage::STATUS_DRAFT, $draft->status);
        $this->assertSame('info@shop.test', $draft->from_address);
        $this->assertStringContainsString('ZamZam Gadget', (string) $draft->body_html);
        $this->assertStringContainsString('ZamZam Gadget', (string) $draft->body_text);

        $thread = $draft->thread;
        $this->assertSame(EmailThread::FOLDER_ARCHIVED, $thread->folder, 'A draft is not in the Inbox.');
        $this->assertSame(0, $thread->message_count);
    }

    public function test_a_reply_goes_to_the_sender_with_re_the_ids_that_join_it_and_the_original_quoted(): void
    {
        $original = $this->received('Bulk price for fans', 'rahim@x.test', 'Rahim', 'How much for 200 pieces?', messageId: 'orig-1@x.test', references: 'older-0@x.test');

        $draft = $this->outbound->startReply($original, $this->user);

        $this->assertSame(['rahim@x.test'], EmailAddressList::addresses($draft->to_addresses));
        $this->assertSame('Re: Bulk price for fans', $draft->subject);
        $this->assertSame('orig-1@x.test', $draft->in_reply_to);
        $this->assertSame('older-0@x.test orig-1@x.test', $draft->references);
        $this->assertSame($original->thread_id, $draft->thread_id, 'A reply stays in the conversation.');
        $this->assertStringContainsString('How much for 200 pieces?', (string) $draft->body_html);
        $this->assertStringContainsString('wrote:', (string) $draft->body_html);

        $this->assertTrue($this->outbound->startReply($original, $this->user)->is($draft), 'Coming back to a reply opens the same draft.');
        $this->assertSame(1, EmailMessage::query()->where('kind', EmailMessage::KIND_DRAFT)->count());

        $again = $this->received('RE: Bulk price for fans', 'karim@y.test', 'Karim', 'ok', messageId: 'orig-2@y.test');
        $second = $this->outbound->startReply($again, $this->user);
        $this->assertSame('RE: Bulk price for fans', $second->subject, 'Re: is not added twice.');
    }

    public function test_a_reply_goes_to_the_reply_to_address_when_the_mail_has_one(): void
    {
        $result = app(InboundEmailService::class)->receive($this->mailbox, (string) file_get_contents(base_path('tests/Fixtures/email/contact-form.eml')));
        $message = $result->message;

        $this->assertSame('wordpress@zamzamint.example', $message->from_address);
        $this->assertSame('farhana@example.test', $message->reply_to_addresses[0]['address']);

        $draft = $this->outbound->startReply($message, $this->user);

        $this->assertSame(['farhana@example.test'], EmailAddressList::addresses($draft->to_addresses), 'The answer goes to the person, not to the website that forwarded the form.');
    }

    public function test_reply_all_adds_the_other_people_but_never_ourselves_or_anyone_twice(): void
    {
        $original = $this->received('Quote', 'rahim@x.test', 'Rahim', 'Please see', to: [['address' => 'info@shop.test', 'name' => 'Shop'], ['address' => 'boss@shop.test', 'name' => null]], cc: [['address' => 'sadia@zamzam.test', 'name' => 'Sadia'], ['address' => 'rahim@x.test', 'name' => null], ['address' => 'info@shop.test', 'name' => null]]);

        $draft = $this->outbound->startReply($original, $this->user, all: true);

        $this->assertSame(['rahim@x.test', 'boss@shop.test'], EmailAddressList::addresses($draft->to_addresses));
        $this->assertSame(['sadia@zamzam.test'], EmailAddressList::addresses($draft->cc_addresses), 'Not our own address, and not someone who is already in To.');
    }

    public function test_answering_one_of_our_own_messages_goes_to_the_people_it_was_sent_to(): void
    {
        $thread = $this->thread('Offer');
        $own = $this->message($thread, EmailMessage::OUT, 'Our offer', status: EmailMessage::STATUS_SENT, to: [['address' => 'karim@y.test', 'name' => null]], from: 'info@shop.test');

        $draft = $this->outbound->startReply($own, $this->user);

        $this->assertSame(['karim@y.test'], EmailAddressList::addresses($draft->to_addresses));
    }

    public function test_a_forward_is_a_new_conversation_with_the_text_the_header_lines_and_the_files(): void
    {
        $original = $this->received('Invoice 88', 'rahim@x.test', 'Rahim', 'Invoice attached', messageId: 'inv-88@x.test');
        $file = $this->attach($original, 'invoice.pdf', 'application/pdf', 'PDFDATA');

        $draft = $this->outbound->startForward($original, $this->user);

        $this->assertSame('Fwd: Invoice 88', $draft->subject);
        $this->assertNotSame($original->thread_id, $draft->thread_id);
        $this->assertNull($draft->in_reply_to, 'A forward does not join the original conversation.');
        $this->assertStringContainsString('Forwarded message', (string) $draft->body_html);
        $this->assertStringContainsString('Invoice attached', (string) $draft->body_html);
        $this->assertStringContainsString('rahim@x.test', (string) $draft->body_text, 'Who wrote it, in the header lines.');

        $copy = $draft->attachments()->firstOrFail();
        $this->assertSame('invoice.pdf', $copy->filename);
        $this->assertNotSame($file->path, $copy->path, 'The copy is its own file; deleting one does not break the other.');
        $this->assertSame('PDFDATA', app(CompanyStorageService::class)->readPrivate($copy->path, $this->company));
        $this->assertTrue($draft->thread->fresh()->has_attachments === false, 'A draft does not make the conversation show a paper-clip.');
    }

    // --- The draft ------------------------------------------------------------------------------------

    public function test_saving_a_draft_cleans_the_text_keeps_the_good_addresses_and_returns_the_bad_ones(): void
    {
        $draft = $this->outbound->startNew($this->mailbox, $this->user);

        $result = $this->outbound->saveDraft($draft, [
            'to' => 'Rahim <rahim@x.test>, oops',
            'cc' => 'sadia@zamzam.test',
            'bcc' => '',
            'subject' => "Offer\r\nBcc: spy@evil.test",
            'body_html' => '<p>Hello <b>Rahim</b></p><script>alert(1)</script><img src="x" onerror="alert(2)">',
        ]);

        $draft = $result['draft']->fresh();
        $this->assertSame(['oops'], $result['invalid']);
        $this->assertSame(['rahim@x.test'], EmailAddressList::addresses($draft->to_addresses));
        $this->assertSame(['sadia@zamzam.test'], EmailAddressList::addresses($draft->cc_addresses));
        $this->assertNull($draft->bcc_addresses);
        $this->assertStringNotContainsString("\n", $draft->subject, 'A line break in the subject cannot start a new header.');
        $this->assertStringNotContainsString('<script', (string) $draft->body_html);
        $this->assertStringNotContainsString('onerror', (string) $draft->body_html);
        $this->assertStringContainsString('Hello Rahim', (string) $draft->body_text);

        $thread = $draft->thread->fresh();
        $this->assertSame($draft->subject, $thread->subject, 'The Drafts list shows what is typed.');
        $this->assertSame('rahim@x.test', $thread->contact_email);
        $this->assertStringContainsString('Hello Rahim', (string) $thread->snippet);
    }

    public function test_files_are_stored_privately_counted_against_the_limit_and_removed_with_their_file(): void
    {
        $draft = $this->outbound->startNew($this->mailbox, $this->user);

        $file = $this->outbound->addAttachment($draft, '../../etc/passwd.txt', 'TEXT/Plain', 'hello');
        $this->assertSame('passwd.txt', $file->filename, 'Only the file name is kept.');
        $this->assertSame('text/plain', $file->mime);
        $this->assertSame(5, $file->size);
        $this->assertSame('hello', app(CompanyStorageService::class)->readPrivate($file->path, $this->company));

        try {
            $this->outbound->addAttachment($draft, 'big.bin', 'application/octet-stream', str_repeat('a', EmailAttachment::MAX_TOTAL_BYTES));
            $this->fail('A message cannot carry more than the limit.');
        } catch (EmailSendException $exception) {
            $this->assertStringContainsString('12 MB', $exception->getMessage());
        }

        $this->assertSame(1, $draft->attachments()->count());

        $this->outbound->removeAttachment($file);
        $this->assertSame(0, $draft->attachments()->count());
        $this->assertNull(app(CompanyStorageService::class)->readPrivate($file->path, $this->company));
    }

    public function test_throwing_a_draft_away_removes_a_conversation_that_was_only_the_draft_and_keeps_a_real_one(): void
    {
        $alone = $this->outbound->startNew($this->mailbox, $this->user);
        $this->outbound->addAttachment($alone, 'a.txt', 'text/plain', 'x');
        $threadId = $alone->thread_id;

        $this->outbound->discardDraft($alone);
        $this->assertNull(EmailThread::query()->find($threadId));
        $this->assertSame(0, EmailAttachment::query()->count());

        $original = $this->received('Hello', 'rahim@x.test', 'Rahim', 'Hi');
        $reply = $this->outbound->startReply($original, $this->user);
        $this->outbound->discardDraft($reply);

        $this->assertNotNull(EmailThread::query()->find($original->thread_id), 'The customer\'s conversation stays.');
        $this->assertSame(1, $original->thread->fresh()->message_count);
    }

    // --- Sending --------------------------------------------------------------------------------------

    public function test_sending_is_refused_with_the_reason_when_there_is_nobody_to_send_to_too_many_or_nothing_to_send(): void
    {
        $draft = $this->outbound->startNew($this->mailbox, $this->user);
        $this->assertRefused($draft, 'Add at least one person to send to.');

        $this->outbound->saveDraft($draft, ['to' => 'rahim@x.test', 'body_html' => '<p></p>']);
        $this->assertRefused($draft->fresh(), 'Write something or attach a file before sending.');

        $this->outbound->addAttachment($draft, 'a.txt', 'text/plain', 'x');
        $this->outbound->queue($draft->fresh(), $this->user);
        $this->assertSame(EmailMessage::STATUS_PENDING, $draft->fresh()->status, 'A message with only a file is allowed.');

        $many = $this->outbound->startNew($this->mailbox, $this->user);
        $this->outbound->saveDraft($many, ['to' => implode(',', array_map(fn (int $i): string => "p{$i}@x.test", range(1, 51))), 'body_html' => '<p>Hi</p>']);
        $this->assertRefused($many->fresh(), 'One message can go to 50 people at most.');

        $this->mailbox->forceFill(['is_active' => false])->save();
        $off = $this->outbound->startNew($this->mailbox->fresh(), $this->user);
        $this->assertRefused($off, 'This mailbox is switched off.');
    }

    public function test_a_sent_message_waits_out_the_undo_window_and_can_be_taken_back_until_then(): void
    {
        Mail::fake();
        $draft = $this->readyDraft('rahim@x.test');

        $queued = $this->outbound->queue($draft, $this->user);

        $this->assertSame(EmailMessage::KIND_MESSAGE, $queued->kind);
        $this->assertSame(EmailMessage::STATUS_PENDING, $queued->status);
        $this->assertSame(EmailMessage::OUT, $queued->direction);
        $this->assertTrue($queued->send_after->isFuture());
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}@shop\.test$/', (string) $queued->message_id);
        $this->assertSame($this->user->getKey(), $queued->user_id);
        Mail::assertNothingSent();
        $this->assertSame(0, $this->outbound->deliverDue(), 'Nothing is due inside the window.');

        $this->assertTrue($this->outbound->undo($queued));
        $draft = $queued->fresh();
        $this->assertSame(EmailMessage::KIND_DRAFT, $draft->kind);
        $this->assertNull($draft->send_after);
        $this->assertNull($draft->message_id, 'A taken-back message gets a new id when it is sent again.');
        $this->assertSame(0, $draft->thread->fresh()->message_count);

        $queued = $this->outbound->queue($draft, $this->user);
        $this->travel(11)->seconds();
        $this->assertFalse($this->outbound->undo($queued), 'Too late: the window is over.');
    }

    public function test_a_due_message_goes_out_once_to_everyone_with_one_log_row_each_and_the_conversation_is_updated(): void
    {
        Mail::fake();
        $original = $this->received('Bulk price', 'rahim@x.test', 'Rahim', 'How much?', messageId: 'orig-1@x.test');
        $draft = $this->outbound->startReply($original, $this->user);
        $this->outbound->saveDraft($draft, ['cc' => 'sadia@zamzam.test', 'bcc' => 'boss@shop.test', 'body_html' => '<p>It is 450 each.</p>']);
        $this->outbound->addAttachment($draft, 'offer.pdf', 'application/pdf', 'PDF');
        $queued = $this->outbound->queue($draft->fresh(), $this->user);

        $this->travel(11)->seconds();
        $this->assertSame(1, $this->outbound->deliverDue());
        $this->assertSame(0, $this->outbound->deliverDue(), 'A second round finds nothing to send.');
        $this->assertFalse($this->outbound->deliver($queued->fresh()), 'A message that was sent is not sent again.');

        Mail::assertSentCount(1);
        Mail::assertSent(ComposedEmail::class, fn (ComposedEmail $mail): bool => $mail->hasTo('rahim@x.test')
            && $mail->hasCc('sadia@zamzam.test')
            && $mail->hasBcc('boss@shop.test')
            && $mail->mailSubject === 'Re: Bulk price'
            && $mail->inReplyTo === 'orig-1@x.test'
            && $mail->references === ['orig-1@x.test']
            && $mail->messageId === $queued->message_id
            && count($mail->files) === 1
            && $mail->files[0]['name'] === 'offer.pdf'
            && $mail->files[0]['content'] === 'PDF');

        $sent = $queued->fresh();
        $this->assertSame(EmailMessage::STATUS_SENT, $sent->status);
        $this->assertNull($sent->send_after);
        $this->assertNull($sent->error);

        $this->assertEqualsCanonicalizing(['rahim@x.test', 'sadia@zamzam.test', 'boss@shop.test'], EmailLog::query()->where('kind', EmailLog::KIND_STAFF_REPLY)->where('status', EmailLog::STATUS_SENT)->pluck('recipient')->all());

        $thread = $original->thread->fresh();
        $this->assertSame(2, $thread->message_count);
        $this->assertNotNull($thread->last_outbound_at);
        $this->assertSame(1, $thread->unread_count, 'Our own reply does not make it unread; the customer\'s message still is.');
        $this->assertTrue($thread->has_attachments);
        $this->assertSame('It is 450 each.', $thread->snippet);
    }

    public function test_a_mailbox_with_no_undo_window_sends_at_once(): void
    {
        Mail::fake();
        $this->mailbox->forceFill(['settings' => ['undo_send_seconds' => 0]])->save();
        $draft = $this->readyDraft('rahim@x.test');

        $message = $this->outbound->queue($draft, $this->user);

        $this->assertSame(EmailMessage::STATUS_SENT, $message->status);
        Mail::assertSentCount(1);
    }

    public function test_a_message_that_could_not_be_sent_says_why_and_goes_again_when_retried(): void
    {
        $other = $this->makeCompany('No Mail Co', 'no-mail-co');
        app(CompanyContext::class)->set($other);
        $site = StorefrontSetting::withoutGlobalScopes()->create(['company_id' => $other->getKey(), 'site_type' => StorefrontSetting::SITE_BLADE]);
        $mailbox = $this->makeMailbox($other, 'hello@nomail.test');
        $draft = $this->readyDraft('rahim@x.test', $mailbox);
        $mailbox->forceFill(['settings' => ['undo_send_seconds' => 0]])->save();

        $failed = $this->outbound->queue($draft->fresh(), $this->user)->fresh();

        $this->assertSame(EmailMessage::STATUS_FAILED, $failed->status);
        $this->assertStringContainsString('No email account is set up', (string) $failed->error);
        $this->assertSame(1, EmailLog::query()->where('status', EmailLog::STATUS_SKIPPED)->count(), 'The Email Log says it was not sent.');
        $this->assertSame(1, $failed->thread->fresh()->message_count, 'A failed message still shows in its conversation, so it can be retried.');

        $site->forceFill(['notification_credentials' => $this->smtp()])->save();
        Mail::fake();

        $this->assertTrue($this->outbound->retry($failed));
        $this->assertSame(EmailMessage::STATUS_SENT, $failed->fresh()->status);
        $this->assertNull($failed->fresh()->error);
        $this->assertFalse($this->outbound->retry($failed->fresh()), 'Only a failed message can be retried.');
    }

    public function test_a_message_stuck_in_sending_is_failed_after_ten_minutes_and_never_sent_twice(): void
    {
        Mail::fake();
        $message = $this->readyDraft('rahim@x.test');
        $message = $this->outbound->queue($message, $this->user);
        EmailMessage::query()->whereKey($message->getKey())->update(['status' => EmailMessage::STATUS_SENDING, 'updated_at' => now()]);

        $this->assertSame(0, $this->outbound->deliverDue());
        $this->assertSame(EmailMessage::STATUS_SENDING, $message->fresh()->status, 'Another process may still be sending it.');

        $this->travel(11)->minutes();
        $this->assertSame(0, $this->outbound->deliverDue());

        $this->assertSame(EmailMessage::STATUS_FAILED, $message->fresh()->status);
        $this->assertStringContainsString('interrupted', (string) $message->fresh()->error);
        Mail::assertNothingSent();
    }

    public function test_the_scheduled_command_sends_every_companys_due_messages_through_that_companys_own_account(): void
    {
        Mail::fake();
        $this->mailbox->forceFill(['settings' => ['undo_send_seconds' => 10]])->save();
        $mine = $this->outbound->queue($this->readyDraft('rahim@x.test'), $this->user);
        $notYet = $this->outbound->queue($this->readyDraft('later@x.test'), $this->user);
        EmailMessage::query()->whereKey($notYet->getKey())->update(['send_after' => now()->addMinutes(5)]);

        $other = $this->makeCompany('Second Co', 'second-co');
        $this->makeSite($other, 'smtp.second.test');
        $otherMailbox = $this->makeMailbox($other, 'hi@second.test');
        app(CompanyContext::class)->set($other);
        $theirs = $this->outbound->queue($this->readyDraft('karim@y.test', $otherMailbox), $this->user);
        app(CompanyContext::class)->clear();

        $this->travel(11)->seconds();
        $this->artisan('email:send-due')->expectsOutputToContain('Sent 2 email(s).')->assertSuccessful();

        $this->assertSame(EmailMessage::STATUS_SENT, EmailMessage::withoutGlobalScopes()->find($mine->getKey())->status);
        $this->assertSame(EmailMessage::STATUS_SENT, EmailMessage::withoutGlobalScopes()->find($theirs->getKey())->status);
        $this->assertSame(EmailMessage::STATUS_PENDING, EmailMessage::withoutGlobalScopes()->find($notYet->getKey())->status, 'A message not due yet waits.');
        $this->assertSame('smtp:smtp.second.test', EmailLog::withoutGlobalScopes()->where('recipient', 'karim@y.test')->value('via'), 'Sent through the second company\'s own account.');
        $this->assertSame('smtp:smtp.shop.test', EmailLog::withoutGlobalScopes()->where('recipient', 'rahim@x.test')->value('via'));
    }

    public function test_one_company_cannot_touch_or_send_another_companys_messages(): void
    {
        Mail::fake();
        $other = $this->makeCompany('Third Co', 'third-co');
        $this->makeSite($other, 'smtp.third.test');
        $otherMailbox = $this->makeMailbox($other, 'x@third.test');
        app(CompanyContext::class)->set($other);
        $theirs = $this->outbound->queue($this->readyDraft('karim@y.test', $otherMailbox), $this->user);

        app(CompanyContext::class)->set($this->company);
        $this->travel(11)->seconds();

        $this->assertSame(0, $this->outbound->deliverDue(), 'The other company\'s due message is not in this company\'s round.');

        foreach ([
            fn () => $this->outbound->deliver($theirs),
            fn () => $this->outbound->undo($theirs),
            fn () => $this->outbound->startNew($otherMailbox, $this->user),
            fn () => $this->outbound->addNote(EmailThread::withoutGlobalScopes()->findOrFail($theirs->thread_id), $this->user, 'hello'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Another company\'s record was accepted.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(EmailMessage::STATUS_PENDING, EmailMessage::withoutGlobalScopes()->find($theirs->getKey())->status);
    }

    // --- Notes ----------------------------------------------------------------------------------------

    public function test_an_internal_note_is_kept_in_the_conversation_but_is_never_sent_or_counted(): void
    {
        Mail::fake();
        $original = $this->received('Complaint', 'rahim@x.test', 'Rahim', 'Late order');

        $note = $this->outbound->addNote($original->thread, $this->user, '  Called him, will ship tomorrow.  ');

        $this->assertSame(EmailMessage::KIND_NOTE, $note->kind);
        $this->assertSame('Called him, will ship tomorrow.', $note->body_text);
        $this->assertSame($this->user->email, $note->from_address);
        $this->assertSame(0, $this->outbound->deliverDue());
        Mail::assertNothingSent();
        $this->assertSame(1, $this->app->make(EmailThreadStats::class)->refresh($original->thread)->message_count, 'A note is not a message.');

        $this->expectException(EmailSendException::class);
        $this->outbound->addNote($original->thread, $this->user, '   ');
    }

    // --- What goes over the wire ----------------------------------------------------------------------

    public function test_the_mail_carries_the_ids_the_text_twin_the_html_and_the_files(): void
    {
        $mail = new ComposedEmail(
            fromAddress: 'info@shop.test',
            fromName: 'ZamZam Gadget',
            mailSubject: 'Re: Bulk price',
            htmlBody: '<p>It is <b>450</b> each.</p>',
            textBody: 'It is 450 each.',
            messageId: 'new-1@shop.test',
            inReplyTo: 'orig-1@x.test',
            references: ['older-0@x.test', 'orig-1@x.test'],
            files: [['name' => 'offer.pdf', 'mime' => 'application/pdf', 'content' => 'PDFDATA']],
        );

        $sent = Mail::mailer('array')->to('rahim@x.test')->cc('sadia@zamzam.test')->bcc('boss@shop.test')->send($mail);
        $raw = $sent->getOriginalMessage()->toString();

        $this->assertStringContainsString('Message-Id: <new-1@shop.test>', $raw);
        $this->assertStringContainsString('In-Reply-To: <orig-1@x.test>', $raw);
        $this->assertStringContainsString('References: <older-0@x.test> <orig-1@x.test>', $raw);
        $this->assertStringContainsString('From: ZamZam Gadget <info@shop.test>', $raw);
        $this->assertStringContainsString('Subject: Re: Bulk price', $raw);
        $this->assertStringContainsString('It is 450 each.', $raw, 'The plain-text part is there.');
        $this->assertStringContainsString('<b>450</b>', $raw, 'And the HTML part.');
        $this->assertStringContainsString('offer.pdf', $raw);
        $this->assertStringNotContainsString('boss@shop.test', $raw, 'A Bcc address is not written into the headers.');
        $this->assertContains('boss@shop.test', array_map(fn ($address) => $address->getAddress(), $sent->getEnvelope()->getRecipients()), 'But the mail server is told to deliver to it.');
    }

    // --- Helpers --------------------------------------------------------------------------------------

    private function assertRefused(EmailMessage $draft, string $reason): void
    {
        try {
            $this->outbound->queue($draft, $this->user);
            $this->fail("Sending should be refused: {$reason}");
        } catch (EmailSendException $exception) {
            $this->assertSame($reason, $exception->getMessage());
        }

        $this->assertSame(EmailMessage::STATUS_DRAFT, $draft->fresh()->status, 'A refused message stays a draft.');
    }

    private function readyDraft(string $to, ?EmailMailbox $mailbox = null): EmailMessage
    {
        $draft = $this->outbound->startNew($mailbox ?? $this->mailbox, $this->user);

        return $this->outbound->saveDraft($draft, ['to' => $to, 'subject' => 'Hello', 'body_html' => '<p>Hello there</p>'])['draft']->fresh();
    }

    private function thread(string $subject, string $contact = 'rahim@x.test'): EmailThread
    {
        return EmailThread::query()->create([
            'company_id' => $this->company->getKey(),
            'mailbox_id' => $this->mailbox->getKey(),
            'subject' => $subject,
            'contact_email' => $contact,
        ]);
    }

    /**
     * @param  array<int, array{address: string, name: ?string}>  $to
     * @param  array<int, array{address: string, name: ?string}>  $cc
     */
    private function message(EmailThread $thread, string $direction, string $text, string $status = EmailMessage::STATUS_RECEIVED, ?string $from = 'rahim@x.test', ?string $name = null, array $to = [], array $cc = [], ?string $messageId = null, ?string $references = null): EmailMessage
    {
        $message = EmailMessage::query()->create([
            'company_id' => $this->company->getKey(),
            'thread_id' => $thread->getKey(),
            'mailbox_id' => $this->mailbox->getKey(),
            'direction' => $direction,
            'kind' => EmailMessage::KIND_MESSAGE,
            'status' => $status,
            'message_id' => $messageId,
            'references' => $references,
            'from_address' => $from,
            'from_name' => $name,
            'to_addresses' => $to ?: [['address' => 'info@shop.test', 'name' => null]],
            'cc_addresses' => $cc ?: null,
            'subject' => $thread->subject,
            'body_text' => $text,
            'is_read' => $direction === EmailMessage::OUT,
            'sent_at' => now()->subHour(),
        ]);

        app(EmailThreadStats::class)->refresh($thread);

        return $message;
    }

    /**
     * @param  array<int, array{address: string, name: ?string}>  $to
     * @param  array<int, array{address: string, name: ?string}>  $cc
     */
    private function received(string $subject, string $from, ?string $name, string $text, ?string $messageId = null, ?string $references = null, array $to = [], array $cc = [], ?EmailThread $thread = null): EmailMessage
    {
        return $this->message($thread ?? $this->thread($subject, $from), EmailMessage::IN, $text, from: $from, name: $name, to: $to, cc: $cc, messageId: $messageId ?? uniqid('m').'@x.test', references: $references);
    }

    private function attach(EmailMessage $message, string $name, string $mime, string $content): EmailAttachment
    {
        $path = app(CompanyStorageService::class)->putPrivate($this->company, 'email-attachments', $name, $content);

        return EmailAttachment::query()->create([
            'company_id' => $this->company->getKey(),
            'message_id' => $message->getKey(),
            'filename' => $name,
            'mime' => $mime,
            'size' => strlen($content),
            'path' => $path,
        ]);
    }

    /** @return array<string, mixed> */
    private function smtp(string $host = 'smtp.shop.test'): array
    {
        return ['mail_host' => $host, 'mail_port' => 587, 'mail_username' => 'user', 'mail_password' => 'secret', 'mail_encryption' => 'tls', 'mail_from_address' => 'info@shop.test'];
    }

    private function makeSite(Company $company, string $host = 'smtp.shop.test'): StorefrontSetting
    {
        return StorefrontSetting::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'site_type' => StorefrontSetting::SITE_BLADE,
            'notification_credentials' => $this->smtp($host),
        ]);
    }

    private function makeCompany(string $name, string $slug): Company
    {
        return Company::query()->create(['name' => $name, 'slug' => $slug, 'invoice_prefix' => strtoupper(substr($slug, 0, 3)), 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true]);
    }

    private function makeMailbox(Company $company, string $address, ?string $signature = null): EmailMailbox
    {
        return EmailMailbox::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'name' => 'ZamZam Gadget',
            'address' => $address,
            'receive_mode' => EmailMailbox::RECEIVE_WEBHOOK,
            'signature' => $signature,
            'is_active' => true,
        ]);
    }
}
