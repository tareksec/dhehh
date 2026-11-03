<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailInbox;
use App\Filament\Pages\EmailInboxSettings;
use App\Models\Company;
use App\Models\Customer;
use App\Models\EmailLabel;
use App\Models\EmailMailbox;
use App\Models\EmailRule;
use App\Models\EmailThread;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Email\Inbox\EmailAddressSuggester;
use App\Services\Email\Inbox\InboundEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Feature\Concerns\BuildsEmailInbox;
use Tests\TestCase;

/**
 * Email inbox, Phase 2 step 10 (docs/email-inbox-plan.md): rules that label, assign or archive mail as it arrives (and
 * the Rules card in Settings → Email inbox that manages them), and address suggestions in the To / Cc / Bcc boxes.
 */
class EmailRulesAndSuggestionsTest extends TestCase
{
    use BuildsEmailInbox;
    use RefreshDatabase;

    private Company $company;

    private EmailMailbox $mailbox;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        URL::forceScheme('https');
        $this->company = $this->makeCompany('Rules Co', 'rules-co');
        app(CompanyContext::class)->set($this->company);
        $this->makeSite($this->company);
        $this->mailbox = $this->makeMailbox($this->company, 'info@shop.test');
        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->staff = User::factory()->create(['role' => 'sales_staff', 'is_active' => true, 'name' => 'Sadia Staff']);
        $this->staff->companies()->attach($this->company->getKey(), ['role' => 'sales_staff', 'is_default' => true]);
        Auth::login($this->admin);
    }

    private function rule(array $values = []): EmailRule
    {
        return EmailRule::query()->create($values + ['company_id' => $this->company->getKey(), 'mailbox_id' => $this->mailbox->getKey(), 'name' => 'Rule', 'position' => 1, 'is_active' => true]);
    }

    private function arrive(string $from, string $subject, string $name = 'Rahim', ?EmailMailbox $mailbox = null): EmailThread
    {
        static $n = 0;
        $n++;
        $raw = "Message-ID: <rule{$n}@x.test>\r\nFrom: {$name} <{$from}>\r\nTo: info@shop.test\r\nSubject: {$subject}\r\nDate: ".now()->toRfc2822String()."\r\nContent-Type: text/plain\r\n\r\nHello";
        $result = app(InboundEmailService::class)->receive($mailbox ?? $this->mailbox, $raw);

        return EmailThread::query()->findOrFail($result->message->thread_id);
    }

    // --- Rules at work --------------------------------------------------------------------------------

    public function test_a_rule_puts_a_label_gives_the_conversation_away_and_archives_it(): void
    {
        $label = EmailLabel::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Wholesale', 'color' => 'green']);
        $this->rule(['from_contains' => '@bigbuyer.test', 'label_id' => $label->getKey(), 'assign_to' => $this->staff->getKey(), 'archive' => true]);

        $thread = $this->arrive('buyer@bigbuyer.test', 'Price list please');

        $this->assertSame(EmailThread::FOLDER_ARCHIVED, $thread->folder);
        $this->assertSame($this->staff->getKey(), (int) $thread->assigned_to);
        $this->assertSame(['Wholesale'], $thread->labels()->pluck('name')->all());
        $this->assertSame(1, $thread->unread_count, 'Archived mail is still unread.');
    }

    public function test_a_rule_that_does_not_fit_changes_nothing(): void
    {
        $this->rule(['from_contains' => '@bigbuyer.test', 'archive' => true]);

        $thread = $this->arrive('someone@else.test', 'Hello');

        $this->assertSame(EmailThread::FOLDER_INBOX, $thread->folder);
    }

    public function test_the_sender_and_the_subject_must_both_fit_and_case_does_not_matter(): void
    {
        $this->rule(['from_contains' => 'SHOP.example', 'subject_contains' => 'INVOICE', 'archive' => true]);

        $this->assertSame(EmailThread::FOLDER_INBOX, $this->arrive('a@shop.example', 'Hello')->folder);
        $this->assertSame(EmailThread::FOLDER_INBOX, $this->arrive('a@other.test', 'Your invoice')->folder);
        $this->assertSame(EmailThread::FOLDER_ARCHIVED, $this->arrive('a@shop.example', 'Your Invoice #4')->folder);
    }

    public function test_the_sender_name_counts_too(): void
    {
        $this->rule(['from_contains' => 'bkash', 'archive' => true]);

        $this->assertSame(EmailThread::FOLDER_ARCHIVED, $this->arrive('noreply@pay.test', 'Receipt', 'bKash Alerts')->folder);
    }

    public function test_a_rule_with_no_condition_fits_nothing_and_a_switched_off_rule_does_nothing(): void
    {
        $this->rule(['archive' => true]);
        $this->rule(['from_contains' => 'x.test', 'archive' => true, 'is_active' => false, 'position' => 2]);

        $this->assertSame(EmailThread::FOLDER_INBOX, $this->arrive('a@x.test', 'Hello')->folder);
    }

    public function test_a_conversation_that_is_already_assigned_keeps_its_owner_and_spam_is_never_touched(): void
    {
        $other = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $other->companies()->attach($this->company->getKey(), ['role' => 'sales_staff', 'is_default' => true]);
        $first = $this->received('Open case', from: 'rahim@x.test', read: true, assignedTo: $other->getKey(), messageId: 'open@x.test');
        $this->rule(['subject_contains' => 'open case', 'assign_to' => $this->staff->getKey()]);

        $raw = "Message-ID: <again@x.test>\r\nIn-Reply-To: <open@x.test>\r\nFrom: Rahim <rahim@x.test>\r\nTo: info@shop.test\r\nSubject: Re: Open case\r\nDate: ".now()->toRfc2822String()."\r\nContent-Type: text/plain\r\n\r\nMore";
        app(InboundEmailService::class)->receive($this->mailbox, $raw);

        $this->assertSame($other->getKey(), (int) $first->fresh()->assigned_to);

        $spam = $this->received('Spam thing', from: 'spam@x.test', folder: EmailThread::FOLDER_SPAM, messageId: 'spam@x.test');
        $this->rule(['from_contains' => 'spam@x.test', 'archive' => true, 'position' => 3]);
        $raw = "Message-ID: <spam2@x.test>\r\nIn-Reply-To: <spam@x.test>\r\nFrom: spam@x.test\r\nTo: info@shop.test\r\nSubject: Re: Spam thing\r\nDate: ".now()->toRfc2822String()."\r\nContent-Type: text/plain\r\n\r\nBuy";
        app(InboundEmailService::class)->receive($this->mailbox, $raw);
        $this->assertSame(EmailThread::FOLDER_SPAM, $spam->fresh()->folder);
    }

    public function test_a_person_who_may_not_use_crm_is_not_given_a_conversation(): void
    {
        $outsider = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $this->rule(['from_contains' => 'x.test', 'assign_to' => $outsider->getKey()]);

        $this->assertNull($this->arrive('a@x.test', 'Hello')->assigned_to);
    }

    public function test_rules_belong_to_their_mailbox(): void
    {
        $second = $this->makeMailbox($this->company, 'sales@shop.test');
        $this->rule(['from_contains' => 'x.test', 'archive' => true]);

        $this->assertSame(EmailThread::FOLDER_INBOX, $this->arrive('a@x.test', 'Hello', mailbox: $second)->folder);
    }

    public function test_mail_is_kept_even_if_a_rule_cannot_run(): void
    {
        $other = $this->makeCompany('Other Co', 'other-co');
        $foreign = EmailLabel::withoutGlobalScopes()->create(['company_id' => $other->getKey(), 'name' => 'Theirs', 'color' => 'red']);
        $this->rule(['from_contains' => 'x.test', 'label_id' => $foreign->getKey(), 'archive' => true]);

        $thread = $this->arrive('a@x.test', 'Hello');

        $this->assertSame(1, $thread->message_count);
        $this->assertSame(0, $thread->labels()->count(), 'Another company\'s label is never used.');
        $this->assertSame(EmailThread::FOLDER_ARCHIVED, $thread->folder, 'The rest of the rule still runs.');
    }

    // --- The Rules card -------------------------------------------------------------------------------

    public function test_rules_are_added_edited_ordered_switched_and_deleted_in_settings(): void
    {
        $label = EmailLabel::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Returns', 'color' => 'red']);
        $page = Livewire::test(EmailInboxSettings::class)->assertSee('No rules yet.');

        $page->set('ruleName', 'Returns mail')->set('ruleSubject', 'return')->set('ruleLabelId', $label->getKey())->call('saveRule')->assertHasNoErrors()->assertSee('Returns mail');
        $rule = EmailRule::query()->firstOrFail();
        $this->assertSame('return', $rule->subject_contains);
        $this->assertSame($label->getKey(), (int) $rule->label_id);
        $this->assertSame($this->mailbox->getKey(), (int) $rule->mailbox_id);

        $page->set('ruleName', 'Staff mail')->set('ruleFrom', '@shop.test')->set('ruleAssignTo', $this->staff->getKey())->call('saveRule')->assertHasNoErrors();
        $second = EmailRule::query()->where('name', 'Staff mail')->firstOrFail();
        $this->assertGreaterThan($rule->position, $second->position);

        $page->call('moveRule', $second->getKey(), -1);
        $this->assertLessThan($rule->fresh()->position, $second->fresh()->position);

        $page->call('editRule', $rule->getKey())->assertSet('ruleName', 'Returns mail')->set('ruleName', 'Returns')->set('ruleArchive', true)->call('saveRule');
        $this->assertSame('Returns', $rule->fresh()->name);
        $this->assertTrue($rule->fresh()->archive);

        $page->call('toggleRule', $rule->getKey());
        $this->assertFalse($rule->fresh()->is_active);

        $page->call('deleteRule', $rule->getKey());
        $this->assertNull(EmailRule::query()->find($rule->getKey()));
    }

    public function test_a_rule_needs_a_name_a_condition_and_an_action(): void
    {
        $page = Livewire::test(EmailInboxSettings::class);

        $page->set('ruleName', '')->set('ruleFrom', 'x')->set('ruleArchive', true)->call('saveRule')->assertHasErrors('ruleName');
        $page->set('ruleName', 'No condition')->set('ruleFrom', '')->set('ruleSubject', '')->call('saveRule')->assertHasErrors(['ruleFrom', 'ruleSubject']);
        $page->set('ruleFrom', 'x')->set('ruleArchive', false)->call('saveRule')->assertHasErrors('ruleLabelId');
        $this->assertSame(0, EmailRule::query()->count());
    }

    public function test_a_rule_cannot_use_another_companys_label_or_a_person_outside_the_company(): void
    {
        $other = $this->makeCompany('Other Co', 'other-co');
        $foreign = EmailLabel::withoutGlobalScopes()->create(['company_id' => $other->getKey(), 'name' => 'Theirs', 'color' => 'red']);
        $outsider = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);

        Livewire::test(EmailInboxSettings::class)->set('ruleName', 'Sneaky')->set('ruleFrom', 'x')->set('ruleLabelId', $foreign->getKey())->set('ruleAssignTo', $outsider->getKey())->call('saveRule')->assertHasErrors('ruleLabelId');

        $this->assertSame(0, EmailRule::query()->count());
    }

    public function test_another_companys_rules_are_not_shown_or_changed(): void
    {
        $other = $this->makeCompany('Other Co', 'other-co');
        $theirs = EmailRule::withoutGlobalScopes()->create(['company_id' => $other->getKey(), 'mailbox_id' => $this->makeMailbox($other, 'x@other.test')->getKey(), 'name' => 'Their rule', 'from_contains' => 'y', 'archive' => true]);

        Livewire::test(EmailInboxSettings::class)->assertDontSee('Their rule')->call('deleteRule', $theirs->getKey())->call('toggleRule', $theirs->getKey());

        $this->assertTrue(EmailRule::withoutGlobalScopes()->find($theirs->getKey())->is_active);
    }

    // --- Address suggestions --------------------------------------------------------------------------

    public function test_suggestions_come_from_past_contacts_and_customers_without_repeats_or_ourselves(): void
    {
        $this->received('Old', from: 'rahim@x.test', name: 'Rahim Uddin');
        $this->received('Own', from: 'info@shop.test', name: 'Rahim Self');
        Customer::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Rahima Begum', 'phone' => '01711111111', 'email' => 'rahima@y.test']);
        Customer::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Rahim Uddin', 'phone' => '01722222222', 'email' => 'RAHIM@x.test']);

        $found = app(EmailAddressSuggester::class)->suggest($this->mailbox, 'rahim');

        $this->assertSame(['rahim@x.test', 'rahima@y.test'], array_column($found, 'address'));
        $this->assertSame([false, true], array_column($found, 'customer'));
        $this->assertSame([], app(EmailAddressSuggester::class)->suggest($this->mailbox, 'r'));
        $this->assertSame([], app(EmailAddressSuggester::class)->suggest($this->mailbox, '%%'));
    }

    public function test_suggestions_never_cross_companies(): void
    {
        $other = $this->makeCompany('Other Co', 'other-co');
        Customer::withoutGlobalScopes()->create(['company_id' => $other->getKey(), 'name' => 'Karim Secret', 'phone' => '01733333333', 'email' => 'karim@secret.test']);
        $theirBox = $this->makeMailbox($other, 'z@other.test');
        $this->received('Theirs', from: 'karim@other.test', company: $other, mailbox: $theirBox);

        $this->assertSame([], app(EmailAddressSuggester::class)->suggest($this->mailbox, 'karim'));
    }

    public function test_typing_in_the_to_box_lists_suggestions_and_a_click_fills_the_address(): void
    {
        $this->received('Old', from: 'rahim@x.test', name: 'Rahim Uddin');
        $this->received('Older', from: 'rahman@x.test', name: 'Rahman');

        $page = Livewire::test(EmailInbox::class)->call('composeNew')->set('composeTo', 'karim@z.test, rah')
            ->assertSee('rahim@x.test')->assertSee('rahman@x.test');

        $page->call('pickSuggestion', 1);
        $this->assertSame('karim@z.test, Rahim Uddin <rahim@x.test>, ', $page->get('composeTo'));
        $page->assertSet('suggestField', null);

        $page->set('composeTo', 'rahim@x.test, rah')->assertSee('pickSuggestion(0)', false)->assertDontSee('pickSuggestion(1)', false);
    }

    public function test_the_cc_box_suggests_too_and_nothing_shows_for_a_short_word(): void
    {
        $this->received('Old', from: 'rahim@x.test', name: 'Rahim Uddin');

        Livewire::test(EmailInbox::class)->call('composeNew')->set('composeShowCc', true)->set('composeCc', 'r')->assertDontSee('rahim@x.test')
            ->set('composeCc', 'rah')->assertSee('rahim@x.test');
    }
}
