<?php

namespace Tests\Feature;

use App\Filament\Investor\Pages\Agreements;
use App\Filament\Investor\Pages\BankAccounts;
use App\Filament\Investor\Pages\Profile;
use App\Filament\Investor\Pages\Requests;
use App\Filament\Resources\ChannelPartners\Pages\ViewChannelPartner;
use App\Filament\Resources\Investors\Pages\ViewInvestor;
use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Models\AuditLog;
use App\Models\ChannelPartner;
use App\Models\Company;
use App\Models\Investor;
use App\Models\InvestorBankAccount;
use App\Models\InvestorPortalAccount;
use App\Models\InvestorRequest;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\AgreementService;
use App\Services\CompanyContext;
use App\Services\InvestorPortalAccountService;
use App\Services\InvestorPortalStaffEntryService;
use App\Services\InvestorRequestService;
use App\Support\InvestorPortalStaffPreview;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Owner request 2026-10-08: staff with investments.portal_manage open the
 * investor portal as an investor or channel partner with one click, in a new
 * tab, from that person's page. A look-only staff preview, entered through a
 * one-time token because the dashboard and the portal are on different hosts.
 */
class InvestorPortalStaffEntryTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->staff = User::factory()->create(['role' => 'super_admin']);
    }

    // ---- The button -----------------------------------------------------

    public function test_the_button_is_in_the_header_of_the_investor_and_partner_pages_and_opens_a_new_tab(): void
    {
        $company = $this->company('button.example.test');
        $investor = $this->investor($company, 'Rahim', '01711000101');
        $partner = ChannelPartner::query()->create(['company_id' => $company->id, 'name' => 'Partner', 'phone' => '01711000102']);
        $this->onAdmin($company);

        Livewire::test(ViewInvestor::class, ['record' => $investor->getKey()])
            ->assertActionVisible('portalOpen')
            ->assertActionHasUrl('portalOpen', route('investor-portal.open-investor', $investor))
            ->assertActionShouldOpenUrlInNewTab('portalOpen');

        Livewire::test(ViewChannelPartner::class, ['record' => $partner->getKey()])
            ->assertActionVisible('portalOpen')
            ->assertActionHasUrl('portalOpen', route('investor-portal.open-partner', $partner))
            ->assertActionShouldOpenUrlInNewTab('portalOpen');
    }

    public function test_the_button_is_hidden_without_the_portal_permission_or_while_the_portal_is_off(): void
    {
        $company = $this->company('hidden.example.test');
        $investor = $this->investor($company, 'Rahim', '01711000103');
        $this->onAdmin($company);

        // An accountant has no investments.portal_manage (a manager now does, IMS-V2/ROLES.md §3.3).
        $this->actingAs(User::factory()->create(['role' => 'accountant']));
        Livewire::test(ViewInvestor::class, ['record' => $investor->getKey()])->assertActionHidden('portalOpen');

        $this->actingAs($this->staff);
        $company->update(['investor_portal_enabled' => false]);
        Livewire::test(ViewInvestor::class, ['record' => $investor->getKey()])->assertActionHidden('portalOpen');
    }

    // ---- The dashboard route --------------------------------------------

    public function test_the_route_sends_the_new_tab_to_the_portal_with_a_one_time_token_and_sends_no_sms(): void
    {
        $company = $this->company('route.example.test', withSms: true);
        $investor = $this->investor($company, 'Rahim', '+880 1711-000104');

        $response = $this->asStaff($company)->get(route('investor-portal.open-investor', $investor));

        $response->assertRedirectContains('http://investor.route.example.test/investor/staff-entry/');
        $response->assertHeader('Cache-Control', 'no-store, private');
        Http::assertNothingSent();

        // A portal account now exists so the preview has someone to be, but nobody was invited.
        $account = InvestorPortalAccount::query()->withoutGlobalScopes()->where('investor_id', $investor->id)->firstOrFail();
        $this->assertSame('01711000104', $account->phone);
        $this->assertSame(InvestorPortalAccount::STATUS_INVITED, $account->status);
        $this->assertNull($account->invited_at);
        $this->assertNull($account->invite_token_hash);
        $this->assertNull($account->password);

        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'investor_portal.staff_entry_issued')->firstOrFail();
        $this->assertSame($this->staff->id, (int) $log->user_id);
    }

    public function test_a_channel_partner_can_be_opened_too(): void
    {
        $company = $this->company('partner.example.test');
        $partner = ChannelPartner::query()->create(['company_id' => $company->id, 'name' => 'Partner', 'phone' => '01711000105']);

        $this->asStaff($company)
            ->get(route('investor-portal.open-partner', $partner))
            ->assertRedirectContains('http://investor.partner.example.test/investor/staff-entry/');

        $this->assertSame(1, InvestorPortalAccount::query()->withoutGlobalScopes()->where('channel_partner_id', $partner->id)->count());
    }

    public function test_the_route_needs_the_portal_permission(): void
    {
        $company = $this->company('guard.example.test');
        $investor = $this->investor($company, 'Rahim', '01711000106');
        $url = route('investor-portal.open-investor', $investor);

        // An accountant has no investments.portal_manage (a manager now does, IMS-V2/ROLES.md §3.3).
        $manager = User::factory()->create(['role' => 'accountant']);
        $manager->companies()->attach($company->getKey(), ['role' => 'accountant', 'is_default' => true]);

        $this->asStaff($company, $manager)->get($url)->assertForbidden();
        $this->assertSame(0, InvestorPortalAccount::query()->withoutGlobalScopes()->count());
    }

    public function test_the_route_does_not_reach_another_companys_investor(): void
    {
        $companyA = $this->company('a-side.example.test');
        $companyB = $this->company('b-side.example.test');
        $stranger = $this->investor($companyB, 'Other', '01711000107');

        $this->asStaff($companyA)->get(route('investor-portal.open-investor', $stranger))->assertNotFound();
        $this->assertSame(0, InvestorPortalAccount::query()->withoutGlobalScopes()->count());
    }

    public function test_the_route_refuses_a_disabled_account_a_missing_phone_and_a_portal_that_is_off(): void
    {
        $company = $this->company('refuse.example.test');
        $disabled = $this->investor($company, 'Disabled', '01711000108');
        $account = app(InvestorPortalAccountService::class)->ensureAccount($disabled);
        app(InvestorPortalAccountService::class)->disable($account);
        $noPhone = $this->investor($company, 'No phone', '');

        $this->asStaff($company)->get(route('investor-portal.open-investor', $disabled))->assertRedirect()->assertRedirectContains('/admin/');
        $this->get(route('investor-portal.open-investor', $noPhone))->assertRedirectContains('/admin/');

        $company->update(['investor_portal_enabled' => false]);
        $this->get(route('investor-portal.open-investor', $this->investor($company, 'Off', '01711000109')))->assertRedirectContains('/admin/');

        $this->assertSame(0, InvestorPortalAccount::query()->withoutGlobalScopes()->where('phone', '01711000109')->count());
    }

    // ---- The portal end -------------------------------------------------

    public function test_the_token_signs_the_staff_member_in_as_the_person_without_counting_as_their_sign_in(): void
    {
        $company = $this->company('enter.example.test');
        $investor = $this->investor($company, 'Rahim Uddin', '01711000110');
        $token = $this->token($company, $investor);

        $this->onPortal($company);
        $this->get('http://investor.enter.example.test/investor/staff-entry/'.$token)
            ->assertRedirect('http://investor.enter.example.test/investor');

        $account = InvestorPortalAccount::query()->withoutGlobalScopes()->where('investor_id', $investor->id)->firstOrFail();
        $this->assertTrue(auth('investor')->check());
        $this->assertSame($account->id, auth('investor')->id());
        $this->assertTrue(InvestorPortalStaffPreview::isFor($account));

        // Not the person's own sign-in.
        $this->assertNull($account->fresh()->last_login_at);
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->where('action', 'investor_portal.login')->count());

        // What the preview does is the staff member's, not the investor's.
        $entered = AuditLog::query()->withoutGlobalScopes()->where('action', 'investor_portal.staff_entered')->firstOrFail();
        $this->assertSame($this->staff->id, (int) $entered->user_id);
        $this->assertNull($entered->actor_type);
    }

    public function test_the_preview_opens_the_portal_pages_even_before_a_password_was_set_and_shows_a_banner(): void
    {
        $company = $this->company('preview.example.test');
        $investor = $this->investor($company, 'Rahim Uddin', '01711000111');
        $token = $this->token($company, $investor);

        $this->onPortal($company);
        $this->get('http://investor.preview.example.test/investor/staff-entry/'.$token);

        $this->get('http://investor.preview.example.test/investor/dashboard')
            ->assertOk()
            ->assertSee('Staff preview: you are looking at the portal as Rahim Uddin (opened by '.$this->staff->name.')', false);
    }

    public function test_the_same_account_is_locked_out_when_it_is_not_a_preview(): void
    {
        $company = $this->company('plain.example.test');
        $investor = $this->investor($company, 'Rahim', '01711000112');
        $account = app(InvestorPortalAccountService::class)->ensureAccount($investor);

        $this->actingAs($account, 'investor');
        $this->get('http://investor.plain.example.test/investor/dashboard')->assertForbidden();
    }

    public function test_a_token_works_once_expires_and_only_fits_its_own_portal(): void
    {
        $company = $this->company('once.example.test');
        $other = $this->company('other-portal.example.test');
        $investor = $this->investor($company, 'Rahim', '01711000113');

        // Used once.
        $token = $this->token($company, $investor);
        $this->get('http://investor.once.example.test/investor/staff-entry/'.$token)->assertRedirect('http://investor.once.example.test/investor');
        auth('investor')->logout();
        $this->flushSession();
        $this->get('http://investor.once.example.test/investor/staff-entry/'.$token)->assertRedirectContains('/investor/login');
        $this->assertFalse(auth('investor')->check());

        // Expired.
        $token = $this->token($company, $investor);
        $this->travel(InvestorPortalStaffEntryService::VALID_SECONDS + 1)->seconds();
        $this->get('http://investor.once.example.test/investor/staff-entry/'.$token)->assertRedirectContains('/investor/login');
        $this->assertFalse(auth('investor')->check());
        $this->travelBack();

        // Another company's portal.
        $token = $this->token($company, $investor);
        $this->get('http://investor.other-portal.example.test/investor/staff-entry/'.$token)->assertRedirectContains('/investor/login');
        $this->assertFalse(auth('investor')->check());
        $this->assertNotNull($other->id);

        // Not a token at all.
        $this->get('http://investor.once.example.test/investor/staff-entry/'.Str::random(48))->assertRedirectContains('/investor/login');
        $this->assertFalse(auth('investor')->check());
    }

    public function test_a_token_is_refused_when_the_account_is_disabled_or_the_staff_member_lost_the_permission(): void
    {
        $company = $this->company('revoked.example.test');
        $investor = $this->investor($company, 'Rahim', '01711000114');

        $token = $this->token($company, $investor);
        app(InvestorPortalAccountService::class)->disable(app(InvestorPortalAccountService::class)->accountFor($investor));
        $this->get('http://investor.revoked.example.test/investor/staff-entry/'.$token)->assertRedirectContains('/investor/login');
        $this->assertFalse(auth('investor')->check());

        app(InvestorPortalAccountService::class)->enable(app(InvestorPortalAccountService::class)->accountFor($investor));
        $leaver = User::factory()->create(['role' => 'super_admin']);
        $token = $this->token($company, $investor, $leaver);
        $leaver->forceFill(['is_active' => false])->save();
        $this->get('http://investor.revoked.example.test/investor/staff-entry/'.$token)->assertRedirectContains('/investor/login');
        $this->assertFalse(auth('investor')->check());
    }

    public function test_a_preview_cannot_change_the_password(): void
    {
        $company = $this->company('profile.example.test');
        $investor = $this->investor($company, 'Rahim', '01711000115');
        $token = $this->token($company, $investor);
        $this->onPortal($company);
        $this->get('http://investor.profile.example.test/investor/staff-entry/'.$token);

        // The page shows no password form...
        $this->get('http://investor.profile.example.test/investor/profile')
            ->assertOk()
            ->assertSee('A staff preview cannot change a password.')
            ->assertDontSee('Current password');

        // ...and the save action refuses even if it is called directly.
        $request = Request::create('/investor/profile');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);

        try {
            (new Profile)->save();
            $this->fail('A staff preview must not be able to change the password.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_a_preview_cannot_accept_an_agreement(): void
    {
        $company = $this->company('agree.example.test');
        $account = $this->previewOf($company, $this->investor($company, 'Signer', '01711000120'));
        $template = AgreementTemplate::query()->create(['company_id' => $company->id, 'kind' => AgreementTemplate::KIND_INVESTOR, 'title' => 'Mudarabah deed', 'body' => 'Clause 1']);
        app(AgreementService::class)->publish($template, $this->staff);

        Livewire::test(Agreements::class)
            ->assertSee('Mudarabah deed')
            ->assertDontSee('I have read and agree')
            ->call('accept', $template->id)
            ->assertForbidden();

        $this->assertSame(0, AgreementAcceptance::query()->withoutGlobalScopes()->count());
        $this->assertCount(1, app(AgreementService::class)->outstanding($account));
    }

    public function test_a_preview_cannot_add_a_payment_account(): void
    {
        $company = $this->company('bank.example.test');
        $this->previewOf($company, $this->investor($company, 'Payee', '01711000121'));

        Livewire::test(BankAccounts::class)
            ->assertActionHidden('add');

        $this->assertSame(0, InvestorBankAccount::query()->withoutGlobalScopes()->count());
    }

    public function test_a_preview_cannot_send_or_cancel_a_request(): void
    {
        $company = $this->company('request.example.test');
        $account = $this->previewOf($company, $this->investor($company, 'Asker', '01711000122'));
        $pending = app(InvestorRequestService::class)->create($account, InvestorRequest::TYPE_SUPPORT, ['message' => 'When is the next payout?']);

        Livewire::test(Requests::class)
            ->assertActionHidden('new')
            ->assertTableActionHidden('cancel', $pending->company_id.'-'.$pending->id);

        $this->assertSame(InvestorRequest::STATUS_PENDING, $pending->fresh()->status);
        $this->assertSame(1, InvestorRequest::query()->withoutGlobalScopes()->count());
    }

    public function test_the_same_pages_still_work_for_the_person_themselves(): void
    {
        $company = $this->company('own.example.test');
        $account = $this->ownSessionOf($company, $this->investor($company, 'Owner Self', '01711000123'));
        $pending = app(InvestorRequestService::class)->create($account, InvestorRequest::TYPE_SUPPORT, ['message' => 'Question']);

        Livewire::test(Requests::class)
            ->assertActionVisible('new')
            ->callTableAction('cancel', $pending->company_id.'-'.$pending->id);

        $this->assertSame(InvestorRequest::STATUS_CANCELLED, $pending->fresh()->status);
    }

    public function test_a_phone_with_a_portal_application_waiting_is_not_opened(): void
    {
        $company = $this->company('applicant.example.test');
        $investor = $this->investor($company, 'Rahim', '01711000124');
        InvestorPortalAccount::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'name' => 'Rahim',
            'phone' => '01711000124',
            'status' => InvestorPortalAccount::STATUS_PENDING,
        ]);

        $this->asStaff($company)->get(route('investor-portal.open-investor', $investor))->assertRedirectContains('/admin/');

        $application = InvestorPortalAccount::query()->withoutGlobalScopes()->where('phone', '01711000124')->firstOrFail();
        $this->assertNull($application->investor_id);
        $this->assertSame(InvestorPortalAccount::STATUS_PENDING, $application->status);
    }

    // ---- Helpers ---------------------------------------------------------

    private function company(string $domain, bool $enabled = true, bool $withSms = false): Company
    {
        $company = Company::query()->create([
            'name' => 'Investor Co '.$domain,
            'slug' => Str::slug($domain),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => strtoupper(Str::random(4)),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
            'investor_portal_enabled' => $enabled,
        ]);

        StorefrontSetting::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'site_type' => StorefrontSetting::SITE_BLADE,
            'notification_credentials' => $withSms ? [
                'sms_api_url' => 'http://sms.example.test/send?key={api_key}&to={phone}&msg={message}&from={sender_id}',
                'sms_api_key' => 'key',
                'sms_sender_id' => 'INV',
            ] : [],
        ]);

        return $company;
    }

    private function investor(Company $company, string $name, string $phone): Investor
    {
        return Investor::query()->withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => $name, 'phone' => $phone]);
    }

    private function onAdmin(Company $company): void
    {
        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->staff);
    }

    private function onPortal(Company $company): void
    {
        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('investor'));
    }

    /** A staff member working in the dashboard on this company, for the plain HTTP route. */
    private function asStaff(Company $company, ?User $user = null): static
    {
        return $this->actingAs($user ?? $this->staff)
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);
    }

    /** The one-time token the dashboard route issues for this person. */
    private function token(Company $company, Investor|ChannelPartner $holder, ?User $staff = null): string
    {
        app(CompanyContext::class)->set($company);
        $url = app(InvestorPortalStaffEntryService::class)->issue($holder, $staff ?? $this->staff);

        return Str::afterLast($url, '/');
    }

    /** A staff preview of this person, as the portal's own pages see it (session + guard + panel). */
    private function previewOf(Company $company, Investor $investor): InvestorPortalAccount
    {
        $account = $this->ownSessionOf($company, $investor);
        InvestorPortalStaffPreview::start(request(), $account, $this->staff->id, $this->staff->name);

        return $account;
    }

    /** The person signed in on their own (no preview): an active account with a password. */
    private function ownSessionOf(Company $company, Investor $investor): InvestorPortalAccount
    {
        $account = app(InvestorPortalAccountService::class)->ensureAccount($investor);
        $account->forceFill(['status' => InvestorPortalAccount::STATUS_ACTIVE, 'password' => 'a-strong-password-1'])->save();

        $request = Request::create('/investor');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        $this->onPortal($company);
        $this->actingAs($account, 'investor');

        return $account;
    }
}
