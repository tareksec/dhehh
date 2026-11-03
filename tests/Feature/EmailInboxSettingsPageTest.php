<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailInboxSettings;
use App\Models\Company;
use App\Models\EmailLabel;
use App\Models\EmailMailbox;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Email\Inbox\InboundEmailService;
use App\Services\Email\Inbox\OutboundEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Feature\Concerns\BuildsEmailInbox;
use Tests\TestCase;

/**
 * Settings → Email inbox (owner request 2026-11-02): who may open it, making a mailbox and seeing its forwarding address
 * once, a new address, the test mail, saving the connection / signature / behaviour, labels, and the settings taking effect
 * (the signature on replies, alerts for new mail).
 */
class EmailInboxSettingsPageTest extends TestCase
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
        URL::forceScheme('https');
        $this->company = $this->makeCompany('Mail Co', 'mail-co');
        app(CompanyContext::class)->set($this->company);
        $this->makeSite($this->company);
        $this->mailbox = $this->makeMailbox($this->company, 'info@shop.test', "Sadia\nZamZam Gadget", 'ZamZam Gadget');
        $this->user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Auth::login($this->user);
    }

    // --- Who may open it ------------------------------------------------------------------------------

    public function test_settings_managers_open_the_page_and_other_staff_do_not(): void
    {
        $this->assertTrue(EmailInboxSettings::canAccess());
        $this->actingAs($this->user)
            ->withSession(['current_company_id' => $this->company->getKey(), 'current_company_selection_explicit' => true])
            ->get(EmailInboxSettings::getUrl())
            ->assertOk()
            ->assertSee('Email inbox settings')
            ->assertSee('info@shop.test');

        $staff = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        Auth::login($staff);
        $this->assertFalse(EmailInboxSettings::canAccess());
        Livewire::test(EmailInboxSettings::class)->assertForbidden();
    }

    public function test_a_company_without_a_mailbox_is_told_so_and_can_add_one(): void
    {
        app(CompanyContext::class)->set($this->makeCompany('Empty Co', 'empty-co'));

        Livewire::test(EmailInboxSettings::class)->assertSee('No mailbox yet')->assertSee('Add mailbox');
    }

    // --- Adding a mailbox and its forwarding address --------------------------------------------------

    public function test_a_mailbox_is_added_with_a_forwarding_address_that_is_shown_once_and_works(): void
    {
        Mail::fake();
        $page = Livewire::test(EmailInboxSettings::class)
            ->callAction('addMailbox', ['name' => 'Sales', 'address' => 'Sales@Shop.Test', 'storefront_setting_id' => null])
            ->assertHasNoActionErrors();

        $mailbox = EmailMailbox::query()->where('address', 'sales@shop.test')->firstOrFail();
        $this->assertSame('Sales', $mailbox->name);
        $this->assertSame($this->company->getKey(), $mailbox->company_id);
        $this->assertTrue($mailbox->is_active);
        $this->assertSame(EmailMailbox::RECEIVE_WEBHOOK, $mailbox->receive_mode);
        $this->assertNotNull($mailbox->token_hash);
        $page->assertSet('mailboxId', $mailbox->getKey());

        $url = (string) $page->get('forwardingUrl');
        $this->assertStringContainsString('/webhooks/email/'.$mailbox->getKey().'/', $url);
        $page->assertSee('Forwarding address for the Cloudflare Worker')->assertSeeHtml($url);

        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'message/rfc822'], (string) file_get_contents(base_path('tests/Fixtures/email/plain.eml')))->assertOk();
        $this->assertSame(1, EmailMessage::withoutGlobalScopes()->where('mailbox_id', $mailbox->getKey())->count());

        app(CompanyContext::class)->set($this->company);
        Auth::login($this->user);
        $fresh = Livewire::test(EmailInboxSettings::class)->set('mailboxId', $mailbox->getKey());
        $this->assertNull($fresh->get('forwardingUrl'), 'The address is never shown again: only its hash is kept.');
        $fresh->assertDontSeeHtml($url)->assertSee('was shown once');
    }

    public function test_a_second_mailbox_with_the_same_address_a_bad_address_or_another_companys_site_is_refused(): void
    {
        Livewire::test(EmailInboxSettings::class)
            ->callAction('addMailbox', ['name' => 'Again', 'address' => 'INFO@shop.test'])
            ->assertHasActionErrors(['address']);
        Livewire::test(EmailInboxSettings::class)
            ->callAction('addMailbox', ['name' => 'Bad', 'address' => 'not-an-address'])
            ->assertHasActionErrors(['address']);
        $this->assertSame(1, EmailMailbox::query()->count());

        $other = $this->makeCompany('Foreign Co', 'foreign-co');
        $theirSite = $this->makeSite($other, 'smtp.foreign.test');
        Livewire::test(EmailInboxSettings::class)->callAction('addMailbox', ['name' => 'Odd', 'address' => 'odd@shop.test', 'storefront_setting_id' => $theirSite->getKey()]);
        $this->assertNull(EmailMailbox::query()->where('address', 'odd@shop.test')->value('storefront_setting_id'), 'A site of another company is ignored.');
    }

    public function test_the_same_address_may_exist_in_another_company(): void
    {
        $other = $this->makeCompany('Foreign Co', 'foreign-co');
        $this->makeMailbox($other, 'hello@shared.test');

        Livewire::test(EmailInboxSettings::class)->callAction('addMailbox', ['name' => 'Hello', 'address' => 'hello@shared.test'])->assertHasNoActionErrors();
        $this->assertSame(2, EmailMailbox::withoutGlobalScopes()->where('address', 'hello@shared.test')->count());
    }

    public function test_a_new_forwarding_address_stops_the_old_one_at_once(): void
    {
        $old = $this->mailbox->issueToken();
        $oldUrl = $this->mailbox->webhookUrl($old);
        $raw = (string) file_get_contents(base_path('tests/Fixtures/email/plain.eml'));
        $this->call('POST', $oldUrl, [], [], [], ['CONTENT_TYPE' => 'message/rfc822'], $raw)->assertOk();

        // A web request leaves no company selected; the person is working in this company again.
        app(CompanyContext::class)->set($this->company);
        Auth::login($this->user);
        $page = Livewire::test(EmailInboxSettings::class)->call('newToken');
        $newUrl = (string) $page->get('forwardingUrl');

        $this->assertNotSame($oldUrl, $newUrl);
        $this->call('POST', $oldUrl, [], [], [], ['CONTENT_TYPE' => 'message/rfc822'], $raw)->assertNotFound();
        $this->call('POST', $newUrl, [], [], [], ['CONTENT_TYPE' => 'message/rfc822'], $raw)->assertOk();
    }

    // --- Saving ---------------------------------------------------------------------------------------

    public function test_the_connection_signature_and_behaviour_are_saved(): void
    {
        Livewire::test(EmailInboxSettings::class)
            ->assertSet('data.undo_send_seconds', 10)
            ->assertSet('data.match_customers', true)
            ->assertSet('data.load_remote_images', false)
            ->set('data.name', 'Support desk')
            ->set('data.address', 'Support@Shop.Test')
            ->set('data.signature', "  Sadia\n01XXXXXXXXX  ")
            ->set('data.signature_on_replies', false)
            ->set('data.undo_send_seconds', 30)
            ->set('data.match_customers', false)
            ->set('data.load_remote_images', true)
            ->set('data.notify', false)
            ->set('data.receive_mode', EmailMailbox::RECEIVE_NONE)
            ->set('data.is_active', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified('Changes saved');

        $mailbox = $this->mailbox->fresh();
        $this->assertSame('Support desk', $mailbox->name);
        $this->assertSame('support@shop.test', $mailbox->address);
        $this->assertSame("Sadia\n01XXXXXXXXX", $mailbox->signature);
        $this->assertFalse($mailbox->is_active);
        $this->assertSame(EmailMailbox::RECEIVE_NONE, $mailbox->receive_mode);
        $this->assertSame(30, $mailbox->setting('undo_send_seconds'));
        $this->assertFalse($mailbox->setting('match_customers'));
        $this->assertTrue($mailbox->setting('load_remote_images'));
        $this->assertFalse($mailbox->setting('notify'));
        $this->assertFalse($mailbox->setting('signature_on_replies'));
        $this->assertFalse($mailbox->receivesMail());
    }

    public function test_saving_refuses_an_address_another_mailbox_of_the_company_has_but_keeps_its_own(): void
    {
        $this->makeMailbox($this->company, 'sales@shop.test');

        $page = Livewire::test(EmailInboxSettings::class)->set('data.address', 'sales@shop.test')->call('save')->assertHasErrors(['data.address']);
        $this->assertSame('info@shop.test', $this->mailbox->fresh()->address);

        $page->set('data.address', 'info@shop.test')->call('save')->assertHasNoErrors();
    }

    public function test_a_site_of_another_company_cannot_be_saved_as_the_sending_account(): void
    {
        $other = $this->makeCompany('Foreign Co', 'foreign-co');
        $theirSite = $this->makeSite($other, 'smtp.foreign.test');

        Livewire::test(EmailInboxSettings::class)->set('data.storefront_setting_id', $theirSite->getKey())->call('save');

        $this->assertNull($this->mailbox->fresh()->storefront_setting_id);
    }

    public function test_the_mailbox_shown_can_be_switched_and_only_to_one_of_this_company(): void
    {
        $sales = $this->makeMailbox($this->company, 'sales@shop.test', null, 'Sales');
        $foreign = $this->makeCompany('Foreign Co', 'foreign-co');
        $theirs = $this->makeMailbox($foreign, 'a@foreign.test');

        $page = Livewire::test(EmailInboxSettings::class)->assertSet('data.address', 'info@shop.test')->set('mailboxId', $sales->getKey())->assertSet('data.address', 'sales@shop.test');
        $page->set('mailboxId', $theirs->getKey());
        $this->assertSame('info@shop.test', $page->instance()->mailbox()->address, 'Another company\'s mailbox is never shown.');
    }

    // --- Sending and the test mail --------------------------------------------------------------------

    public function test_the_sending_card_describes_the_email_account_and_the_test_mail_goes_to_the_mailbox_address(): void
    {
        Mail::fake();

        Livewire::test(EmailInboxSettings::class)
            ->assertSee('smtp.shop.test:587')
            ->assertSee('info@shop.test')
            ->assertSee('Working')
            ->call('sendTestMail')
            ->assertNotified('Test mail sent to info@shop.test. It should appear in CRM → Email in a few seconds.');
    }

    public function test_the_test_mail_says_why_it_failed_and_a_company_without_a_site_is_told_so(): void
    {
        StorefrontSetting::withoutGlobalScopes()->where('company_id', $this->company->getKey())->firstOrFail()->forceFill(['notification_credentials' => ['mail_port' => 1] + $this->smtp('127.0.0.1')])->save();
        Livewire::test(EmailInboxSettings::class)->call('sendTestMail')->assertNotified();

        StorefrontSetting::withoutGlobalScopes()->where('company_id', $this->company->getKey())->delete();
        Livewire::test(EmailInboxSettings::class)->assertSee('no site yet')->call('sendTestMail')->assertNotified('This company has no site yet, so there is no email account to send through.');
    }

    // --- Labels ---------------------------------------------------------------------------------------

    public function test_labels_are_created_renamed_recoloured_and_deleted_without_deleting_the_mail(): void
    {
        $thread = $this->received('Tagged mail');
        $page = Livewire::test(EmailInboxSettings::class)->assertSee('No labels yet.');

        $page->set('labelName', '')->call('saveLabel')->assertHasErrors(['labelName']);
        $page->set('labelName', 'Wholesale')->set('labelColor', 'chartreuse')->call('saveLabel')->assertHasErrors(['labelColor']);
        $page->set('labelColor', 'green')->call('saveLabel')->assertHasNoErrors()->assertSee('Wholesale');

        $label = EmailLabel::query()->where('name', 'Wholesale')->firstOrFail();
        $this->assertSame('green', $label->color);
        $this->assertSame($this->company->getKey(), $label->company_id);
        $thread->labels()->attach($label->getKey());

        $page->set('labelName', 'wholesale')->call('saveLabel')->assertHasErrors(['labelName']);
        $this->assertSame(1, EmailLabel::query()->count(), 'Names are the same whatever their case.');

        $page->call('editLabel', $label->getKey())->assertSet('labelName', 'Wholesale')->assertSet('labelColor', 'green')
            ->set('labelName', 'WHOLESALE')->set('labelColor', 'red')->call('saveLabel')->assertHasNoErrors()->assertSet('editingLabelId', null);
        $label->refresh();
        $this->assertSame('WHOLESALE', $label->name, 'A label may be renamed to a different spelling of its own name.');
        $this->assertSame('red', $label->color);

        $page->call('deleteLabel', $label->getKey());
        $this->assertNull(EmailLabel::query()->find($label->getKey()));
        $this->assertSame(0, $thread->labels()->count());
        $this->assertNotNull(EmailThread::query()->find($thread->getKey()), 'The conversation stays.');
    }

    public function test_the_labels_of_another_company_cannot_be_edited_or_deleted(): void
    {
        $other = $this->makeCompany('Foreign Co', 'foreign-co');
        $theirs = EmailLabel::withoutGlobalScopes()->create(['company_id' => $other->getKey(), 'name' => 'Zebra-Hidden', 'color' => 'red']);

        $page = Livewire::test(EmailInboxSettings::class)->call('editLabel', $theirs->getKey())->assertSet('editingLabelId', null)->call('deleteLabel', $theirs->getKey());

        $this->assertNotNull(EmailLabel::withoutGlobalScopes()->find($theirs->getKey()));
        $page->assertDontSee('Zebra-Hidden');
    }

    // --- The settings take effect ---------------------------------------------------------------------

    public function test_a_reply_carries_the_signature_only_when_the_mailbox_says_so(): void
    {
        $thread = $this->received('Question', text: 'How much?', messageId: 'q@x.test');
        $message = $thread->messages()->firstOrFail();
        $outbound = app(OutboundEmailService::class);
        $staff = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);

        $with = $outbound->startReply($message, $staff);
        $this->assertStringContainsString('ZamZam Gadget', (string) $with->body_html);

        $outbound->discardDraft($with);
        $this->mailbox->forceFill(['settings' => ['signature_on_replies' => false]])->save();
        $without = $outbound->startReply($message->fresh(), $staff);
        $this->assertStringNotContainsString('Sadia', (string) $without->body_html);

        $new = $outbound->startNew($this->mailbox->fresh(), $staff);
        $this->assertStringContainsString('Sadia', (string) $new->body_html, 'A new message always has the signature.');
    }

    public function test_new_mail_alerts_the_staff_who_may_answer_it_or_only_the_person_it_is_given_to(): void
    {
        $agent = $this->member('agent@shop.test', 'sales_staff');
        $viewerOnly = $this->member('look@shop.test', 'accountant');
        $raw = (string) file_get_contents(base_path('tests/Fixtures/email/plain.eml'));

        app(InboundEmailService::class)->receive($this->mailbox, $raw);

        $this->assertSame(1, $agent->notifications()->count());
        $this->assertSame(0, $viewerOnly->notifications()->count(), 'Staff without CRM access are not alerted.');
        $this->assertStringContainsString('Rahim Traders', json_encode($agent->notifications()->first()->data));
        $this->assertStringContainsString('Bulk price for 56-inch ceiling fans', json_encode($agent->notifications()->first()->data));

        app(InboundEmailService::class)->receive($this->mailbox, $raw);
        $this->assertSame(1, $agent->notifications()->count(), 'A message delivered twice alerts once.');

        $thread = EmailThread::query()->firstOrFail();
        $thread->forceFill(['assigned_to' => $this->user->getKey()])->save();
        $reply = str_replace('Message-ID: <abc123.def@rahimtraders.example>', 'Message-ID: <second@rahimtraders.example>', (string) file_get_contents(base_path('tests/Fixtures/email/reply.eml')));
        app(InboundEmailService::class)->receive($this->mailbox, $reply);

        $this->assertSame(1, $agent->notifications()->count(), 'A conversation given to someone alerts only them.');
        $this->assertSame(1, $this->user->notifications()->count());
    }

    public function test_no_alert_when_the_mailbox_has_alerts_switched_off(): void
    {
        $agent = $this->member('agent@shop.test', 'sales_staff');
        $this->mailbox->forceFill(['settings' => ['notify' => false]])->save();

        app(InboundEmailService::class)->receive($this->mailbox->fresh(), (string) file_get_contents(base_path('tests/Fixtures/email/plain.eml')));

        $this->assertSame(1, EmailMessage::query()->count());
        $this->assertSame(0, $agent->notifications()->count());
    }

    // --- Helpers --------------------------------------------------------------------------------------

    private function member(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => $role, 'is_active' => true]);
        $user->companies()->syncWithoutDetaching([$this->company->getKey() => ['role' => $role, 'is_default' => true]]);

        return $user;
    }
}
