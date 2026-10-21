<?php

namespace Tests\Feature\Ims;

use App\Filament\Auth\AdminLogin;
use App\Filament\Investor\Pages\Auth\Login;
use App\Models\Company;
use App\Models\Investor;
use App\Models\InvestorPortalAccount;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\InvestorPortalAccountService;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One browser, both IMS panels (owner report 2026-10-21): the investor portal
 * (guard "investor") and the office (guard "web") share the IMS domain's one
 * session cookie. Signing in or out of one must never send the person to the
 * other panel or sign them out of it.
 *
 * Every request here starts with fresh guards, as a real browser's next
 * request does, so only the session carries anything from one to the next.
 */
class OfficeAndPortalSessionTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'ims-session.test';

    private const PHONE = '01711000111';

    public function test_an_investor_session_still_opens_the_office_sign_in(): void
    {
        $company = $this->company();
        $this->signInInvestor($company);

        $this->browserGet('/investor/dashboard')->assertOk();
        $this->browserGet('/office/login')->assertOk();
    }

    public function test_the_office_sign_in_never_lands_on_a_portal_page_left_from_before(): void
    {
        $company = $this->company();
        $this->staff($company);

        // A signed-out visit to the portal leaves its address behind in the session.
        $this->browserGet('/investor/dashboard')->assertRedirect('http://'.self::HOST.'/investor/login');
        $this->browserGet('/office/login')->assertOk();

        $this->signInOffice($company)->assertRedirect('http://'.self::HOST.'/office');
    }

    public function test_an_office_person_already_signed_in_goes_to_the_office_not_the_portal(): void
    {
        $company = $this->company();
        $this->staff($company);
        $this->browserGet('/investor/dashboard')->assertRedirect('http://'.self::HOST.'/investor/login');
        $this->signInOffice($company);

        $this->browserGet('/office/login')->assertRedirect('http://'.self::HOST.'/office');
    }

    public function test_the_investor_sign_in_never_lands_on_an_office_page_left_from_before(): void
    {
        $company = $this->company();
        $this->browserGet('/office')->assertRedirect('http://'.self::HOST.'/office/login');

        $this->signInInvestor($company)->assertRedirect('http://'.self::HOST.'/investor');
    }

    public function test_both_sign_ins_live_side_by_side_in_one_browser(): void
    {
        $company = $this->company();
        $this->staff($company);

        $this->signInInvestor($company);
        $this->browserGet('/office/login')->assertOk();
        $this->signInOffice($company)->assertRedirect('http://'.self::HOST.'/office');

        $this->browserGet('/office')->assertOk();
        $this->assertPortalStillSignedIn();
    }

    public function test_signing_out_of_the_office_keeps_the_investor_signed_in(): void
    {
        $company = $this->company();
        $this->staff($company);
        $this->signInInvestor($company);
        $this->signInOffice($company);

        $this->browserPost('/office/logout')->assertRedirect();

        $this->browserGet('/office')->assertRedirect('http://'.self::HOST.'/office/login');
        $this->assertPortalStillSignedIn();
    }

    public function test_signing_out_of_the_portal_keeps_the_office_signed_in(): void
    {
        $company = $this->company();
        $this->staff($company);
        $this->signInInvestor($company);
        $this->signInOffice($company);

        $this->browserPost('/investor/logout')->assertRedirect();

        $this->browserGet('/investor/dashboard')->assertRedirect('http://'.self::HOST.'/investor/login');
        $this->browserGet('/office')->assertOk();
    }

    public function test_signing_out_with_only_one_sign_in_still_clears_the_whole_session(): void
    {
        $company = $this->company();
        $this->signInInvestor($company);
        session()->put('something_else', 'left over');

        $this->browserPost('/investor/logout')->assertRedirect();

        $this->assertNull(session('something_else'));
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * The portal's sign-in page sends a signed-in investor on to the portal.
     * (Not a portal page itself: in one test process an office page leaves its
     * render hooks behind, which a real browser's next request never sees.)
     */
    private function assertPortalStillSignedIn(): void
    {
        $this->browserGet('/investor/login')->assertRedirect('http://'.self::HOST.'/investor');
    }

    private function browserGet(string $path)
    {
        $this->freshRequest();

        return $this->get('http://'.self::HOST.$path);
    }

    private function browserPost(string $path)
    {
        $this->freshRequest();

        return $this->post('http://'.self::HOST.$path);
    }

    /** What a new PHP request starts without: signed-in guards, the open panel, Filament's per-request navigation. */
    private function freshRequest(): void
    {
        app('auth')->forgetGuards();
        app()->forgetInstance(NavigationManager::class);
        foreach (Filament::getPanels() as $panel) {
            (fn () => $this->navigationManager = null)->call($panel);
        }
        Filament::setCurrentPanel(null);
        Livewire::flushState();
    }

    private function company(): Company
    {
        $company = Company::query()->create([
            'name' => 'IMS Session Co',
            'slug' => 'ims-session-'.Str::random(6),
            'domain' => 'ims-session-shop.test',
            'domain_verified' => true,
            'invoice_prefix' => strtoupper(Str::random(4)),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
            'ims_enabled' => true,
            'ims_domain' => self::HOST,
        ]);

        StorefrontSetting::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'site_type' => StorefrontSetting::SITE_BLADE,
            'notification_credentials' => [],
        ]);

        $investor = Investor::query()->withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Rahim', 'phone' => self::PHONE]);
        $account = app(InvestorPortalAccountService::class)->ensureAccount($investor);
        $account->forceFill(['status' => InvestorPortalAccount::STATUS_ACTIVE, 'password' => 'investor-password-1'])->save();

        return $company;
    }

    private function staff(Company $company): User
    {
        $manager = User::factory()->create(['email' => 'office@example.test', 'password' => Hash::make('office-password-1'), 'role' => 'manager', 'is_active' => true]);
        $manager->companies()->attach($company->getKey(), ['role' => 'manager', 'is_default' => true]);

        return $manager;
    }

    private function signInInvestor(Company $company)
    {
        $this->onLoginPage($company, 'investor');

        return Livewire::test(Login::class)
            ->set('data.phone', self::PHONE)
            ->set('data.password', 'investor-password-1')
            ->call('authenticate')
            ->assertHasNoErrors();
    }

    private function signInOffice(Company $company)
    {
        $this->onLoginPage($company, 'office');

        return Livewire::test(AdminLogin::class)
            ->fillForm(['email' => 'office@example.test', 'password' => 'office-password-1'])
            ->call('authenticate')
            ->assertHasNoFormErrors();
    }

    private function onLoginPage(Company $company, string $panel): void
    {
        $this->freshRequest();
        $request = Request::create('http://'.self::HOST.'/'.$panel.'/login');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel($panel));
    }
}
