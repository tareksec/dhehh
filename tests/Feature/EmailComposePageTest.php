<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailInbox;
use App\Models\Company;
use App\Models\EmailAttachment;
use App\Models\EmailLog;
use App\Models\EmailMailbox;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\QuickReply;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\Email\Inbox\EmailThreadStats;
use App\Services\Email\Inbox\OutboundEmailService;
use App\Support\EmailAddressList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Concerns\BuildsEmailInbox;
use Tests\TestCase;

/**
 * CRM → Email, writing (owner request 2026-11-02): the compose window for a new message, a reply, a reply to all and a
 * forward; the draft that saves itself; what is thrown away on Close; files; Quick Replies; Send and its undo window;
 * a failed message going again; internal notes; who may do any of it.
 */
class EmailComposePageTest extends TestCase
{
    use BuildsEmailInbox;
    use RefreshDatabase;

    private Company $company;

    private EmailMailbox $mailbox;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->company = $this->makeCompany('Mail Co', 'mail-co');
        app(CompanyContext::class)->set($this->company);
        $this->makeSite($this->company);
        $this->mailbox = $this->makeMailbox($this->company, 'info@shop.test', "Sadia\nZamZam Gadget", 'ZamZam Gadget');
        $this->user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        Auth::login($this->user);
    }

    // --- The window -----------------------------------------------------------------------------------

    public function test_compose_opens_a_new_message_with_the_signature_and_the_mailbox_as_sender(): void
    {
        Livewire::test(EmailInbox::class)
            ->assertSee('Compose')
            ->call('composeNew')
            ->assertSee('New message')
            ->assertSee('ZamZam Gadget &lt;info@shop.test&gt;', false)
            ->assertSet('composeTo', '')
            ->assertSet('composeSubject', '');

        $draft = EmailMessage::query()->where('kind', EmailMessage::KIND_DRAFT)->firstOrFail();
        $this->assertSame($this->user->getKey(), $draft->user_id);
        $this->assertStringContainsString('ZamZam Gadget', (string) $draft->body_html);
    }

    public function test_the_draft_saves_what_is_typed_cleans_the_text_and_reports_bad_addresses(): void
    {
        $page = Livewire::test(EmailInbox::class)->call('composeNew')
            ->set('composeTo', 'Rahim <rahim@x.test>, oops')
            ->set('composeCc', 'sadia@z.test')
            ->set('composeSubject', "Offer\r\nBcc: spy@evil.test")
            ->set('composeData.body', '<p>Hello <b>Rahim</b></p><script>alert(1)</script>')
            ->call('saveCompose');

        $draft = EmailMessage::query()->where('kind', EmailMessage::KIND_DRAFT)->firstOrFail();
        $this->assertSame(['rahim@x.test'], EmailAddressList::addresses($draft->to_addresses));
        $this->assertSame(['sadia@z.test'], EmailAddressList::addresses($draft->cc_addresses));
        $this->assertStringNotContainsString("\n", $draft->subject);
        $this->assertStringNotContainsString('<script', (string) $draft->body_html);
        $this->assertStringContainsString('Hello Rahim', (string) $draft->body_text);
        $this->assertSame(['oops'], $page->get('composeInvalid'));
        $this->assertNotNull($page->get('composeSavedAt'));
        $page->assertSee('Check these addresses: oops');

        $this->assertSame(['Offer Bcc: spy@evil.test'], [$draft->subject], 'A line break in the subject cannot start a new header.');
    }

    public function test_closing_throws_away_a_draft_nobody_typed_in_and_keeps_one_with_content(): void
    {
        $page = Livewire::test(EmailInbox::class)->call('composeNew')->call('closeCompose')->assertSet('composeId', null);
        $this->assertSame(0, EmailMessage::query()->count(), 'An untouched new message leaves nothing behind.');
        $this->assertSame(0, EmailThread::query()->count());

        $page->call('composeNew')->set('composeSubject', 'Keep me')->call('closeCompose')->assertSet('composeId', null);
        $this->assertSame(1, EmailMessage::query()->where('kind', EmailMessage::KIND_DRAFT)->count());

        $page->call('showFolder', 'drafts');
        $this->assertSame(['Keep me'], $page->instance()->threads->pluck('subject')->all());
        $this->assertSame(1, $page->instance()->counts['drafts']);
    }

    public function test_a_reply_that_was_not_changed_is_not_kept_but_one_that_was_is(): void
    {
        $thread = $this->received('Bulk price', text: 'How much?', messageId: 'a@x.test');
        $message = $thread->messages()->firstOrFail();
        $page = Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey());

        $page->call('composeReply', $message->getKey())->call('closeCompose');
        $this->assertSame(0, EmailMessage::query()->where('kind', EmailMessage::KIND_DRAFT)->count());

        $page->call('composeReply', $message->getKey())->set('composeData.body', '<p>It is 450 each.</p>')->call('closeCompose');
        $this->assertSame(1, EmailMessage::query()->where('kind', EmailMessage::KIND_DRAFT)->count());

        $page->call('composeReply', $message->getKey())->assertSet('composeId', EmailMessage::query()->where('kind', EmailMessage::KIND_DRAFT)->value('id'));
        $this->assertSame(1, EmailMessage::query()->where('kind', EmailMessage::KIND_DRAFT)->count(), 'Reply again opens the same draft and a kept one is never thrown away just by opening it.');
        $page->call('closeCompose');
        $this->assertSame(1, EmailMessage::query()->where('kind', EmailMessage::KIND_DRAFT)->count());
    }

    public function test_discarding_removes_the_draft_and_its_files(): void
    {
        $page = Livewire::test(EmailInbox::class)->call('composeNew')->set('composeSubject', 'Gone');
        $page->set('composeUploads', [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]);
        $this->assertSame(1, EmailAttachment::query()->count());

        $page->call('discardCompose')->assertSet('composeId', null);

        $this->assertSame(0, EmailMessage::query()->count());
        $this->assertSame(0, EmailAttachment::query()->count());
    }

    // --- Reply, reply all, forward, edit --------------------------------------------------------------

    public function test_reply_reply_all_and_forward_open_with_the_right_people_subject_and_text(): void
    {
        $thread = $this->received('Quote', from: 'rahim@x.test', name: 'Rahim', text: 'Please see the list', messageId: 'orig@x.test');
        $message = $thread->messages()->firstOrFail();
        $message->forceFill(['to_addresses' => [['address' => 'info@shop.test', 'name' => null], ['address' => 'boss@shop.test', 'name' => null]], 'cc_addresses' => [['address' => 'sadia@z.test', 'name' => 'Sadia']]])->save();
        $page = Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey());

        $page->assertSee('Reply all')->assertSee('Forward')
            ->call('composeReply', $message->getKey())
            ->assertSet('composeTo', 'Rahim <rahim@x.test>')
            ->assertSet('composeSubject', 'Re: Quote');
        $page->call('discardCompose');

        $page->call('composeReply', $message->getKey(), true)
            ->assertSet('composeTo', 'Rahim <rahim@x.test>, boss@shop.test')
            ->assertSet('composeCc', 'Sadia <sadia@z.test>')
            ->assertSet('composeShowCc', true);
        $page->call('discardCompose');

        $page->call('composeForward', $message->getKey())
            ->assertSet('composeTo', '')
            ->assertSet('composeSubject', 'Fwd: Quote');
        $this->assertStringContainsString('Please see the list', (string) EmailMessage::query()->findOrFail($page->get('composeId'))->body_text);
    }

    public function test_reply_all_is_offered_only_when_someone_else_is_on_the_message(): void
    {
        $alone = $this->received('Just you and me', text: 'Hi');
        Livewire::test(EmailInbox::class)->call('openThread', $alone->getKey())->assertSee('Reply')->assertDontSee('Reply all');
    }

    public function test_reply_works_on_a_conversation_that_already_holds_a_draft_and_notes(): void
    {
        $thread = $this->received('Open question', text: 'Hi', messageId: 'q@x.test', when: now()->subHour());
        $message = $thread->messages()->firstOrFail();
        $this->message($thread, EmailMessage::OUT, 'Internal', from: 'a@shop.test', kind: EmailMessage::KIND_NOTE, status: EmailMessage::STATUS_NOTE, read: true, when: now()->subMinutes(30));
        $draft = $this->message($thread, EmailMessage::OUT, 'Half written', from: 'info@shop.test', kind: EmailMessage::KIND_DRAFT, status: EmailMessage::STATUS_DRAFT, read: true, userId: $this->user->getKey(), when: now());
        $draft->forceFill(['in_reply_to' => 'q@x.test'])->save();

        $page = Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey());

        $page->assertSeeHtml('wire:click="composeReply('.$message->getKey().')"', 'The reply buttons act on the newest real message, not on the draft or a note.');
        $page->call('composeReply', $message->getKey())->assertSet('composeId', $draft->getKey());
    }

    public function test_a_draft_of_my_own_can_be_edited_again_and_someone_elses_cannot(): void
    {
        $thread = $this->received('Open question', text: 'Hi');
        $mine = $this->message($thread, EmailMessage::OUT, 'My half-written reply', from: 'info@shop.test', kind: EmailMessage::KIND_DRAFT, status: EmailMessage::STATUS_DRAFT, read: true, userId: $this->user->getKey());
        $theirs = $this->message($thread, EmailMessage::OUT, 'Their draft', from: 'info@shop.test', kind: EmailMessage::KIND_DRAFT, status: EmailMessage::STATUS_DRAFT, read: true, userId: User::factory()->create(['role' => 'sales_staff', 'is_active' => true])->getKey());
        $page = Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey());

        $page->assertSee('Edit draft')->call('editDraft', $theirs->getKey())->assertSet('composeId', null);
        $page->call('editDraft', $mine->getKey())->assertSet('composeId', $mine->getKey());
    }

    // --- Sending --------------------------------------------------------------------------------------

    public function test_sending_is_refused_with_the_reason_and_the_window_stays_open(): void
    {
        $page = Livewire::test(EmailInbox::class)->call('composeNew')->set('composeData.body', '<p>Hello</p>');

        $page->call('sendCompose')->assertNotified('Add at least one person to send to.')->assertSet('composeId', fn ($id) => $id !== null);

        $page->set('composeTo', 'good@x.test, bad')->call('sendCompose')->assertNotified('Check these addresses: bad');
        $this->assertSame(EmailMessage::STATUS_DRAFT, EmailMessage::query()->firstOrFail()->status);
    }

    public function test_a_sent_message_waits_out_the_undo_window_and_goes_when_the_page_asks_after_it(): void
    {
        Mail::fake();
        $thread = $this->received('Bulk price', text: 'How much?', messageId: 'a@x.test');
        $message = $thread->messages()->firstOrFail();

        $page = Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey())
            ->call('composeReply', $message->getKey())
            ->set('composeData.body', '<p>It is 450 each.</p>')
            ->call('sendCompose')
            ->assertSet('composeId', null)
            ->assertSee('Message sent')
            ->assertSee('Undo');

        $sent = EmailMessage::query()->where('direction', EmailMessage::OUT)->where('kind', EmailMessage::KIND_MESSAGE)->firstOrFail();
        $this->assertSame(EmailMessage::STATUS_PENDING, $sent->status);
        $this->assertSame(['id' => $sent->getKey(), 'seconds' => 10], $page->get('sentUndo'));
        Mail::assertNothingSent();

        $this->travel(11)->seconds();
        $page->call('deliverDue')->assertSet('sentUndo', null)->assertNotified('Message sent');

        Mail::assertSentCount(1);
        $this->assertSame(EmailMessage::STATUS_SENT, $sent->fresh()->status);
        $this->assertSame(1, EmailLog::query()->where('kind', EmailLog::KIND_STAFF_REPLY)->count());
        $this->assertSame(2, $thread->fresh()->message_count);
    }

    public function test_a_sent_message_can_be_taken_back_into_the_window_until_its_time_is_up(): void
    {
        Mail::fake();
        $page = Livewire::test(EmailInbox::class)->call('composeNew')->set('composeTo', 'rahim@x.test')->set('composeSubject', 'Offer')->set('composeData.body', '<p>Take it</p>')->call('sendCompose');
        $sent = EmailMessage::query()->where('direction', EmailMessage::OUT)->where('kind', EmailMessage::KIND_MESSAGE)->firstOrFail();

        $page->call('undoSend', $sent->getKey())->assertSet('composeId', $sent->getKey())->assertSet('composeTo', 'rahim@x.test')->assertSet('composeSubject', 'Offer')->assertSet('sentUndo', null);
        $this->assertSame(EmailMessage::KIND_DRAFT, $sent->fresh()->kind);

        $page->call('sendCompose');
        $this->travel(11)->seconds();
        $page->call('undoSend', $sent->getKey())->assertNotified('Too late to take it back. The message has been sent.');
        $this->assertSame(EmailMessage::KIND_MESSAGE, $sent->fresh()->kind);
    }

    public function test_with_no_undo_window_the_message_goes_at_once(): void
    {
        Mail::fake();
        $this->mailbox->forceFill(['settings' => ['undo_send_seconds' => 0]])->save();

        $page = Livewire::test(EmailInbox::class)->call('composeNew')->set('composeTo', 'rahim@x.test')->set('composeSubject', 'Now')->set('composeData.body', '<p>Hi</p>')->call('sendCompose');

        Mail::assertSentCount(1);
        $page->assertSet('sentUndo', null)->assertNotified('Message sent');
    }

    public function test_a_message_that_could_not_go_says_why_and_can_be_sent_again(): void
    {
        $noMail = $this->makeCompany('No Mail Co', 'no-mail-co');
        app(CompanyContext::class)->set($noMail);
        $site = StorefrontSetting::withoutGlobalScopes()->create(['company_id' => $noMail->getKey(), 'site_type' => StorefrontSetting::SITE_BLADE]);
        $mailbox = $this->makeMailbox($noMail, 'hello@nomail.test');
        $mailbox->forceFill(['settings' => ['undo_send_seconds' => 0]])->save();
        $this->company = $noMail;
        $this->mailbox = $mailbox;

        $page = Livewire::test(EmailInbox::class)->call('composeNew')->set('composeTo', 'rahim@x.test')->set('composeSubject', 'Hello')->set('composeData.body', '<p>Hi</p>')->call('sendCompose');
        $page->assertNotified('The message was not sent: No email account is set up for this site (Settings → Integrations → Email Setup).');

        $failed = EmailMessage::query()->where('status', EmailMessage::STATUS_FAILED)->firstOrFail();
        $thread = $failed->thread;
        $page->call('openThread', $thread->getKey())->assertSee('Not sent')->assertSee('Send again');

        $site->forceFill(['notification_credentials' => $this->smtp()])->save();
        Mail::fake();
        $page->call('retryMessage', $failed->getKey())->assertNotified('Message sent');
        $this->assertSame(EmailMessage::STATUS_SENT, $failed->fresh()->status);
    }

    // --- Files ----------------------------------------------------------------------------------------

    public function test_files_are_added_listed_counted_and_removed(): void
    {
        $page = Livewire::test(EmailInbox::class)->call('composeNew');

        $page->set('composeUploads', [UploadedFile::fake()->create('offer.pdf', 200, 'application/pdf'), UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg')])
            ->assertSee('offer.pdf')->assertSee('photo.jpg')->assertSee('of 12 MB used');

        $this->assertSame(2, EmailAttachment::query()->count());
        $file = EmailAttachment::query()->where('filename', 'offer.pdf')->firstOrFail();
        $this->assertSame('PDF-less fake content is stored as is', 'PDF-less fake content is stored as is');
        $this->assertNotNull(app(CompanyStorageService::class)->readPrivate($file->path, $this->company));

        $page->call('removeComposeFile', $file->getKey())->assertDontSee('offer.pdf')->assertSee('photo.jpg');
        $this->assertSame(1, EmailAttachment::query()->count());
    }

    public function test_files_over_the_limit_are_refused_with_a_message(): void
    {
        $page = Livewire::test(EmailInbox::class)->call('composeNew');

        $page->set('composeUploads', [UploadedFile::fake()->createWithContent('big.bin', str_repeat('a', 7_000_000)), UploadedFile::fake()->createWithContent('bigger.bin', str_repeat('b', 7_000_000))]);

        $this->assertSame(1, EmailAttachment::query()->count(), 'The second file would pass 12 MB in all.');
        $page->assertNotified('The files are too big: one message can carry 12 MB in all.');
    }

    // --- Quick replies --------------------------------------------------------------------------------

    public function test_quick_replies_are_listed_searched_and_inserted_into_the_message(): void
    {
        QuickReply::query()->create(['shortcut' => 'Price list', 'body' => "Our tier prices are attached.\nPlease confirm the quantity.", 'is_active' => true]);
        QuickReply::query()->create(['shortcut' => 'Return policy', 'body' => 'Returns within 7 days.', 'is_active' => true]);
        QuickReply::query()->create(['shortcut' => 'Old one', 'body' => 'Retired text', 'is_active' => false]);
        $other = $this->makeCompany('Other Co', 'other-co');
        QuickReply::withoutGlobalScopes()->create(['company_id' => $other->getKey(), 'shortcut' => 'Secret', 'body' => 'Not yours', 'is_active' => true]);

        $page = Livewire::test(EmailInbox::class)->call('composeNew')->assertDontSee('Return policy');

        $page->set('showQuickReplies', true)->assertSee('Price list')->assertSee('Return policy')->assertDontSee('Old one')->assertDontSee('Secret');
        $page->set('quickReplySearch', 'return')->assertSee('Return policy')->assertDontSee('Price list');
        $page->set('quickReplySearch', '')->call('insertQuickReply', QuickReply::query()->where('shortcut', 'Price list')->value('id'))
            ->assertSet('showQuickReplies', false)
            ->assertDispatched('run-rich-editor-commands', fn (string $event, array $params): bool => ($params['commands'][0]['name'] ?? null) === 'insertContent'
                && str_contains((string) ($params['commands'][0]['arguments'][0] ?? ''), 'Our tier prices are attached.<br>')
                && str_contains((string) ($params['commands'][0]['arguments'][0] ?? ''), 'Please confirm the quantity.'));

        $page->call('insertQuickReply', QuickReply::query()->where('is_active', false)->value('id'))->assertNotDispatched('run-rich-editor-commands');
    }

    // --- Notes ----------------------------------------------------------------------------------------

    public function test_a_note_is_added_to_the_conversation_and_never_sent(): void
    {
        Mail::fake();
        $thread = $this->received('Complaint', text: 'Late order');
        $page = Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey());

        $page->set('noteOpen', true)->assertSee('Write a note for your team')
            ->set('noteText', '  ')->call('addNote')->assertNotified('Write the note first.')
            ->set('noteText', 'Called him, ships tomorrow.')->call('addNote')
            ->assertSet('noteText', '')->assertSet('noteOpen', false)
            ->assertSee('Called him, ships tomorrow.')->assertSee('Internal note, only staff see it');

        $this->assertSame(1, EmailMessage::query()->where('kind', EmailMessage::KIND_NOTE)->count());
        $this->assertSame(0, app(OutboundEmailService::class)->deliverDue());
        Mail::assertNothingSent();
        $this->assertSame(1, app(EmailThreadStats::class)->refresh($thread)->message_count, 'A note is not a message.');
    }

    // --- Who may ---------------------------------------------------------------------------------------

    public function test_staff_who_may_only_view_cannot_write_or_send(): void
    {
        UserRole::query()->create(['name' => 'Mail viewer', 'slug' => 'mail_viewer', 'permissions' => ['crm.view'], 'is_active' => true]);
        Auth::login(User::factory()->create(['role' => 'mail_viewer', 'is_active' => true]));
        $thread = $this->received('Read only', text: 'Hi');
        $message = $thread->messages()->firstOrFail();

        Livewire::test(EmailInbox::class)->assertDontSee('Compose')->call('openThread', $thread->getKey())->assertDontSee('Reply all')->assertDontSee('Add note');

        foreach ([['composeNew', []], ['composeReply', [$message->getKey()]], ['composeForward', [$message->getKey()]], ['sendCompose', []], ['saveCompose', []], ['addNote', []], ['deliverDue', []], ['retryMessage', [$message->getKey()]]] as [$method, $arguments]) {
            Livewire::test(EmailInbox::class)->call($method, ...$arguments)->assertForbidden();
        }

        $this->assertSame(0, EmailMessage::query()->where('kind', EmailMessage::KIND_DRAFT)->count());
    }

    public function test_messages_and_drafts_of_another_company_or_mailbox_cannot_be_used(): void
    {
        $other = $this->makeCompany('Foreign Co', 'foreign-co');
        $foreignBox = $this->makeMailbox($other, 'a@foreign.test');
        $theirs = $this->received('Not ours', company: $other, mailbox: $foreignBox);
        $foreignMessage = $theirs->messages()->withoutGlobalScopes()->firstOrFail();
        $elsewhere = $this->received('Other mailbox', mailbox: $this->makeMailbox($this->company, 'sales@shop.test'));

        $page = Livewire::test(EmailInbox::class);
        $page->call('composeReply', $foreignMessage->getKey())->assertSet('composeId', null);
        $page->call('openThread', $theirs->getKey());
        $page->call('composeReply', $foreignMessage->getKey())->call('composeForward', $foreignMessage->getKey())->assertSet('composeId', null);
        $page->call('editDraft', $foreignMessage->getKey())->call('retryMessage', $foreignMessage->getKey())->assertSet('composeId', null);

        $page->call('composeReply', $elsewhere->messages()->firstOrFail()->getKey())->assertSet('composeId', null);
        $this->assertSame(0, EmailMessage::withoutGlobalScopes()->where('kind', EmailMessage::KIND_DRAFT)->count());
    }

    // --- Styles ---------------------------------------------------------------------------------------

    public function test_the_compose_window_styles_live_in_the_admin_theme_with_a_full_screen_phone_layout(): void
    {
        $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));

        foreach (['.zz-mail-composer {', '.zz-mail-composer-foot', '.zz-mail-qr-pop', '.zz-mail-noteform', '.zz-mail-compose {'] as $selector) {
            $this->assertStringContainsString($selector, $css);
        }

        $this->assertMatchesRegularExpression('/@media \(max-width: 760px\)\s*\{\s*\/\* On a phone the compose window is the whole screen\. \*\/\s*\.zz-mail-composer \{\s*position: fixed;/', $css);
    }
}
