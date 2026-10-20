<?php

namespace Tests\Feature;

use App\Filament\Investor\Pages\Auth\Login;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Investor;
use App\Models\InvestorPortalAccount;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\InvestorBusinessSwitchService;
use App\Services\InvestorPortalAccountService;
use App\Support\InvestorPortalStaffPreview;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 17_IMS_IN_PORTAL_PLAN.md §5 (Phase M): one person with an active portal
 * account under the same phone in several businesses moves between them from
 * the portal's top bar, without signing in again. Each business keeps its own
 * host, account and company context.
 */
class InvestorBusinessSwitchTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '01711000500';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    public function test_the_other_businesses_are_those_with_an_active_account_under_the_same_phone(): void
    {
        $a = $this->company('alpha.example.test');
        $b = $this->company('beta.example.test');
        $c = $this->company('gamma.example.test');
        $d = $this->company('delta.example.test');
        $e = $this->company('epsilon.example.test');

        $current = $this->activeAccount($a, self::PHONE);
        $this->activeAccount($b, self::PHONE);
        $this->activeAccount($c, '01711000999'); // another person
        app(InvestorPortalAccountService::class)->disable($this->activeAccount($d, self::PHONE));
        $this->activeAccount($e, self::PHONE);
        $e->update(['investor_portal_enabled' => false]);

        $ids = app(InvestorBusinessSwitchService::class)->otherBusinesses($current)->map(fn (array $entry): int => $entry['company']->id)->all();

        $this->assertSame([$b->id], $ids);
    }

    public function test_an_account_without_a_password_is_not_offered(): void
    {
        $a = $this->company('nopass-a.example.test');
        $b = $this->company('nopass-b.example.test');
        $current = $this->activeAccount($a, self::PHONE);
        $invited = app(InvestorPortalAccountService::class)->ensureAccount($this->investor($b, self::PHONE));
        $this->assertNull($invited->password);

        $this->assertTrue(app(InvestorBusinessSwitchService::class)->otherBusinesses($current)->isEmpty());
    }

    public function test_a_staff_preview_cannot_switch(): void
    {
        $a = $this->company('preview-a.example.test');
        $b = $this->company('preview-b.example.test');
        $current = $this->activeAccount($a, self::PHONE);
        $this->activeAccount($b, self::PHONE);

        $this->signedInOn($a, $current);
        InvestorPortalStaffPreview::start(request(), $current, 1, 'Staff');

        $switches = app(InvestorBusinessSwitchService::class);
        $this->assertTrue($switches->otherBusinesses($current)->isEmpty());
        $this->assertNull($this->issue($current, $b->id));
    }

    public function test_the_switcher_shows_in_the_top_bar_only_when_there_is_another_business(): void
    {
        $a = $this->company('bar-a.example.test');
        $b = $this->company('bar-b.example.test', name: 'Second Business Ltd');
        $current = $this->activeAccount($a, self::PHONE);

        $this->signedInOn($a, $current);
        $this->get('http://investor.bar-a.example.test/investor/dashboard')->assertOk()->assertDontSee('Switch business');

        $this->activeAccount($b, self::PHONE);
        $this->get('http://investor.bar-a.example.test/investor/dashboard')
            ->assertOk()
            ->assertSee('Switch business')
            ->assertSee('Second Business Ltd');
    }

    public function test_switching_signs_the_person_in_to_their_own_account_on_the_other_business(): void
    {
        $a = $this->company('go-a.example.test');
        $b = $this->company('go-b.example.test');
        $current = $this->activeAccount($a, self::PHONE);
        $target = $this->activeAccount($b, self::PHONE);

        $this->signedInOn($a, $current);
        $response = $this->post('http://investor.go-a.example.test/investor/switch-business/'.$b->id);
        $response->assertRedirectContains('http://investor.go-b.example.test/investor/business-entry/');
        $token = Str::afterLast($response->headers->get('Location'), '/');

        auth('investor')->logout();
        $this->flushSession();

        $this->get('http://investor.go-b.example.test/investor/business-entry/'.$token)
            ->assertRedirect('http://investor.go-b.example.test/investor');

        $this->assertSame($target->id, auth('investor')->id());
        $this->assertNotNull($target->fresh()->last_login_at);
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'investor_portal.business_switched')->count());
    }

    public function test_a_business_that_is_not_theirs_cannot_be_opened(): void
    {
        $a = $this->company('deny-a.example.test');
        $b = $this->company('deny-b.example.test');
        $current = $this->activeAccount($a, self::PHONE);
        $this->activeAccount($b, '01711000777');

        $this->signedInOn($a, $current);
        $this->post('http://investor.deny-a.example.test/investor/switch-business/'.$b->id)
            ->assertRedirect('http://investor.deny-a.example.test/investor');
    }

    public function test_a_token_works_once_expires_and_only_fits_its_own_portal(): void
    {
        $a = $this->company('once-a.example.test');
        $b = $this->company('once-b.example.test');
        $this->company('once-c.example.test');
        $current = $this->activeAccount($a, self::PHONE);
        $this->activeAccount($b, self::PHONE);
        $switches = app(InvestorBusinessSwitchService::class);

        // Used once.
        $token = Str::afterLast($this->issue($current, $b->id), '/');
        $this->get('http://investor.once-b.example.test/investor/business-entry/'.$token)->assertRedirect('http://investor.once-b.example.test/investor');
        auth('investor')->logout();
        $this->flushSession();
        $this->get('http://investor.once-b.example.test/investor/business-entry/'.$token)->assertRedirectContains('/investor/login');
        $this->assertFalse(auth('investor')->check());

        // Expired.
        $token = Str::afterLast($this->issue($current, $b->id), '/');
        $this->travel(InvestorBusinessSwitchService::VALID_SECONDS + 1)->seconds();
        $this->get('http://investor.once-b.example.test/investor/business-entry/'.$token)->assertRedirectContains('/investor/login');
        $this->assertFalse(auth('investor')->check());
        $this->travelBack();

        // Another business's portal.
        $token = Str::afterLast($this->issue($current, $b->id), '/');
        $this->get('http://investor.once-c.example.test/investor/business-entry/'.$token)->assertRedirectContains('/investor/login');
        $this->assertFalse(auth('investor')->check());
    }

    public function test_a_token_is_refused_when_either_account_was_disabled_after_it_was_issued(): void
    {
        $a = $this->company('late-a.example.test');
        $b = $this->company('late-b.example.test');
        $current = $this->activeAccount($a, self::PHONE);
        $target = $this->activeAccount($b, self::PHONE);
        $switches = app(InvestorBusinessSwitchService::class);
        $service = app(InvestorPortalAccountService::class);

        $token = Str::afterLast($this->issue($current, $b->id), '/');
        $service->disable($target);
        $this->get('http://investor.late-b.example.test/investor/business-entry/'.$token)->assertRedirectContains('/investor/login');
        $this->assertFalse(auth('investor')->check());

        $service->enable($target);
        $token = Str::afterLast($this->issue($current, $b->id), '/');
        $service->disable($current);
        $this->get('http://investor.late-b.example.test/investor/business-entry/'.$token)->assertRedirectContains('/investor/login');
        $this->assertFalse(auth('investor')->check());
    }

    // ---- Central sign-in address (one login for every business) ---------

    public function test_the_central_address_serves_only_the_portal_and_sends_business_only_pages_to_sign_in(): void
    {
        $this->centralHost();

        $this->get('http://ims.central.test/')->assertRedirect('/investor');
        $this->get('http://ims.central.test/admin')->assertRedirect('/investor');
        $this->get('http://ims.central.test/investor/login')->assertOk()->assertDontSee('Forgot your password?');
        $this->get('http://ims.central.test/investor/register?code=ABC')->assertRedirect('/investor/login');
        $this->get('http://ims.central.test/investor/password-reset/request')->assertRedirect('/investor/login');
        $this->get('http://ims.central.test/investor/dashboard')->assertRedirectContains('/investor/login');

        $this->assertTrue(Company::hostIsTakenByThePlatform('ims.central.test'));
    }

    public function test_the_central_address_may_be_a_new_name_under_a_company_domain_but_not_a_used_one(): void
    {
        $company = $this->company('zamzam.example.test');
        $company->forceFill(['reseller_module_enabled' => true, 'reseller_subdomains_enabled' => true])->save();

        // A new name under the company's own domain is fine...
        $this->assertFalse(Company::centralHostIsTaken('ims.zamzam.example.test'));
        // ...a host that already answers is not.
        $this->assertTrue(Company::centralHostIsTaken('zamzam.example.test'));
        $this->assertTrue(Company::centralHostIsTaken('investor.zamzam.example.test'));

        // Once it is the central address, no reseller can take that store subdomain.
        AppSetting::setValue(Company::IMS_CENTRAL_DOMAIN_SETTING, 'ims.zamzam.example.test');
        $this->assertContains('ims', $company->reservedResellerSubdomains());
        $this->assertNull(Company::forResellerSubdomainHost('ims.zamzam.example.test'));
    }

    public function test_the_central_sign_in_takes_the_password_of_any_of_the_persons_accounts(): void
    {
        $a = $this->company('central-a.example.test');
        $b = $this->company('central-b.example.test');
        $this->activeAccount($a, self::PHONE, 'password-of-alpha-1');
        $inB = $this->activeAccount($b, self::PHONE, 'password-of-beta-22');
        $switches = app(InvestorBusinessSwitchService::class);

        $match = $switches->attemptCentralLogin('+880 1711-000500', 'password-of-beta-22');
        $this->assertSame($inB->id, $match['account']->id);
        $this->assertSame($b->id, $match['company']->id);

        $this->assertNull($switches->attemptCentralLogin(self::PHONE, 'wrong-password-123'));
        $this->assertNull($switches->attemptCentralLogin('01711000888', 'password-of-beta-22'));
    }

    public function test_the_sign_in_form_on_the_central_address_opens_the_business_of_that_password(): void
    {
        $this->centralHost();
        $a = $this->company('form-a.example.test');
        $b = $this->company('form-b.example.test');
        $this->activeAccount($a, self::PHONE, 'password-of-alpha-1');
        $inB = $this->activeAccount($b, self::PHONE, 'password-of-beta-22');

        $request = Request::create('http://ims.central.test/investor/login');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        app(CompanyContext::class)->none();
        Filament::setCurrentPanel(Filament::getPanel('investor'));

        Livewire::test(Login::class)
            ->set('data.phone', self::PHONE)
            ->set('data.password', 'password-of-beta-22')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertSame($inB->id, auth('investor')->id());
        $this->assertSame($b->id, session(InvestorBusinessSwitchService::SESSION_KEY));
        $this->assertSame($b->id, app(CompanyContext::class)->id());
    }

    public function test_on_the_central_address_switching_changes_the_business_in_the_same_session(): void
    {
        $this->centralHost();
        $a = $this->company('same-a.example.test', name: 'Alpha Traders');
        $b = $this->company('same-b.example.test', name: 'Beta Imports');
        $inA = $this->activeAccount($a, self::PHONE);
        $inB = $this->activeAccount($b, self::PHONE);

        $this->actingAs($inA, 'investor')->withSession([InvestorBusinessSwitchService::SESSION_KEY => $a->id]);
        $this->get('http://ims.central.test/investor/dashboard')->assertOk()->assertSee('Switch business')->assertSee('Beta Imports');

        $this->post('http://ims.central.test/investor/switch-business/'.$b->id)
            ->assertRedirect('http://ims.central.test/investor');

        $this->assertSame($inB->id, auth('investor')->id());
        $this->assertSame($b->id, session(InvestorBusinessSwitchService::SESSION_KEY));
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'investor_portal.business_switched')->count());

        $this->get('http://ims.central.test/investor/dashboard')->assertOk()->assertSee('Alpha Traders');
    }

    public function test_on_the_central_address_an_account_never_opens_under_another_business(): void
    {
        $this->centralHost();
        $a = $this->company('mix-a.example.test');
        $b = $this->company('mix-b.example.test');
        $this->activeAccount($a, self::PHONE);
        $inB = $this->activeAccount($b, self::PHONE);

        // Session says business A, but the signed-in account is B's: refused.
        $this->actingAs($inB, 'investor')->withSession([InvestorBusinessSwitchService::SESSION_KEY => $a->id]);
        $this->get('http://ims.central.test/investor/dashboard')->assertForbidden();
    }

    // ---- A business's IMS domain is a sign-in for every business ---------

    public function test_the_sign_in_on_an_ims_domain_opens_another_business_of_that_password(): void
    {
        $a = $this->imsCompany('ims-one.example.test', 'imsone.test');
        $b = $this->company('ims-two.example.test');
        $this->activeAccount($a, self::PHONE, 'password-of-alpha-1');
        $inB = $this->activeAccount($b, self::PHONE, 'password-of-beta-22');

        $this->signInOnImsDomain($a, 'imsone.test', 'password-of-beta-22');

        $this->assertSame($inB->id, auth('investor')->id());
        $this->assertSame($b->id, session(InvestorBusinessSwitchService::SESSION_KEY));
        $this->assertSame($b->id, app(CompanyContext::class)->id());
    }

    public function test_on_an_ims_domain_the_domains_own_business_is_tried_first(): void
    {
        $other = $this->company('ims-first-0.example.test');
        $a = $this->imsCompany('ims-first-a.example.test', 'imsfirst.test');
        $this->activeAccount($other, self::PHONE, 'same-password-123');
        $inA = $this->activeAccount($a, self::PHONE, 'same-password-123');
        $this->assertLessThan($a->id, $other->id);

        $this->signInOnImsDomain($a, 'imsfirst.test', 'same-password-123');

        $this->assertSame($inA->id, auth('investor')->id());
        $this->assertSame($a->id, app(CompanyContext::class)->id());
    }

    public function test_on_an_ims_domain_a_person_of_one_business_signs_in_as_before(): void
    {
        $a = $this->imsCompany('ims-solo.example.test', 'imssolo.test');
        $inA = $this->activeAccount($a, self::PHONE, 'password-of-alpha-1');

        $this->signInOnImsDomain($a, 'imssolo.test', 'password-of-alpha-1');

        $this->assertSame($inA->id, auth('investor')->id());
        $this->assertNull(session(InvestorBusinessSwitchService::SESSION_KEY));
    }

    public function test_on_an_ims_domain_switching_stays_on_that_domain_and_the_office_keeps_working(): void
    {
        $a = $this->imsCompany('ims-sw-a.example.test', 'imsswitch.test', name: 'Alpha Traders');
        $b = $this->company('ims-sw-b.example.test', name: 'Beta Imports');
        $inA = $this->activeAccount($a, self::PHONE);
        $inB = $this->activeAccount($b, self::PHONE);

        $this->actingAs($inA, 'investor')->withSession([auth('investor')->getName() => $inA->id]);
        $this->get('http://imsswitch.test/investor/dashboard')->assertOk()->assertSee('Switch business')->assertSee('Beta Imports');

        $this->post('http://imsswitch.test/investor/switch-business/'.$b->id)
            ->assertRedirect('http://imsswitch.test/investor');
        $this->assertSame($inB->id, auth('investor')->id());
        $this->assertSame($b->id, session(InvestorBusinessSwitchService::SESSION_KEY));

        $this->get('http://imsswitch.test/investor/dashboard')->assertOk()->assertSee('Alpha Traders');
        $this->assertSame($b->id, app(CompanyContext::class)->id());

        $this->get('http://imsswitch.test/office/login')->assertOk();
    }

    public function test_on_an_ims_domain_a_stale_choice_never_opens_another_business(): void
    {
        $a = $this->imsCompany('ims-stale-a.example.test', 'imsstale.test');
        $b = $this->company('ims-stale-b.example.test');
        $inA = $this->activeAccount($a, self::PHONE);
        $this->activeAccount($b, self::PHONE);

        // The session still names business B, but the account signed in is A's.
        $this->actingAs($inA, 'investor')->withSession([
            InvestorBusinessSwitchService::SESSION_KEY => $b->id,
            auth('investor')->getName() => $inA->id,
        ]);
        $this->get('http://imsstale.test/investor/dashboard')->assertOk();
        $this->assertSame($a->id, app(CompanyContext::class)->id());
    }

    // ---- My businesses ----------------------------------------------------

    public function test_my_businesses_lists_every_business_with_its_own_figures(): void
    {
        $a = $this->company('list-a.example.test', name: 'Alpha Traders');
        $b = $this->company('list-b.example.test', name: 'Beta Imports');
        $inA = $this->activeAccount($a, self::PHONE);
        $this->activeAccount($b, self::PHONE);

        $this->signedInOn($a, $inA);
        $this->get('http://investor.list-a.example.test/investor/my-businesses')
            ->assertOk()
            ->assertSee('Alpha Traders')
            ->assertSee('Beta Imports')
            ->assertSee('Open this business')
            ->assertSee('Capital at work');

        // The page left the context on the business being viewed.
        $this->assertSame($a->id, app(CompanyContext::class)->id());
    }

    public function test_my_businesses_is_not_offered_with_only_one_business(): void
    {
        $a = $this->company('single.example.test');
        $inA = $this->activeAccount($a, self::PHONE);

        $this->signedInOn($a, $inA);
        $this->get('http://investor.single.example.test/investor/my-businesses')->assertForbidden();
    }

    // ---- Helpers ---------------------------------------------------------

    private function centralHost(): void
    {
        AppSetting::setValue(Company::IMS_CENTRAL_DOMAIN_SETTING, 'ims.central.test');
    }

    /** A host-to-host switch as the portal makes it (not on the central address). */
    private function issue(InvestorPortalAccount $current, int $companyId): ?string
    {
        return app(InvestorBusinessSwitchService::class)->switchTo($current, $companyId, Request::create('http://investor.example.test/investor'));
    }

    private function company(string $domain, ?string $name = null): Company
    {
        $company = Company::query()->create([
            'name' => $name ?? 'Investor Co '.$domain,
            'slug' => Str::slug($domain),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => strtoupper(Str::random(4)),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
            'investor_portal_enabled' => true,
        ]);

        StorefrontSetting::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'site_type' => StorefrontSetting::SITE_BLADE,
            'notification_credentials' => [],
        ]);

        return $company;
    }

    /** A business whose portal lives on its IMS v2 domain only (the investor portal itself is off, as on the live system). */
    private function imsCompany(string $domain, string $imsDomain, ?string $name = null): Company
    {
        $company = $this->company($domain, $name);
        $company->forceFill(['investor_portal_enabled' => false, 'ims_enabled' => true, 'ims_domain' => $imsDomain])->save();

        return $company;
    }

    private function signInOnImsDomain(Company $company, string $imsDomain, string $password): void
    {
        $request = Request::create('http://'.$imsDomain.'/investor/login');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('investor'));

        Livewire::test(Login::class)
            ->set('data.phone', self::PHONE)
            ->set('data.password', $password)
            ->call('authenticate')
            ->assertHasNoErrors();
    }

    private function investor(Company $company, string $phone): Investor
    {
        return Investor::query()->withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Rahim', 'phone' => $phone]);
    }

    /** A signed-up account (active, with a password) for a new investor of this business. */
    private function activeAccount(Company $company, string $phone, string $password = 'a-strong-password-1'): InvestorPortalAccount
    {
        $account = app(InvestorPortalAccountService::class)->ensureAccount($this->investor($company, $phone));
        $account->forceFill(['status' => InvestorPortalAccount::STATUS_ACTIVE, 'password' => $password])->save();

        return $account;
    }

    private function signedInOn(Company $company, InvestorPortalAccount $account): void
    {
        $request = Request::create('/investor');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('investor'));
        $this->actingAs($account, 'investor');
    }
}
