<?php

namespace Tests\Feature;

use App\Filament\Auth\AdminLogin;
use App\Filament\Investor\Pages\Auth\Login as InvestorLogin;
use App\Filament\Reseller\Pages\Auth\Login as ResellerLogin;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Customer;
use App\Models\Investor;
use App\Models\InvestorPortalAccount;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CustomerAccountService;
use App\Services\LoginGate;
use App\Support\LoginClient;
use App\Support\LoginThrottled;
use App\Support\StorefrontApi\Phone;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * LoginGate: every sign-in is judged BEFORE the slow password hash is computed
 * (per device, per account, per client fingerprint, whole site), a device the
 * account has signed in from is never locked out by a stranger's guessing, and
 * the same rules front the storefront, the Next.js API, the reseller and
 * investor portals and the admin panel.
 */
class LoginGateTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected CustomerAccountService $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->company = $this->makeCompany('Gate Co', 'gate-co');
        app(CompanyContext::class)->set($this->company);
        $this->accounts = app(CustomerAccountService::class);
    }

    // ----------------------------------------------------------- the customer gate

    public function test_every_spelling_of_a_phone_counts_against_the_same_account(): void
    {
        config(['login_protection.account_failures' => 6]);
        $this->customer('01712345678');

        // Six wrong guesses from six different devices, each under another spelling.
        foreach (['01712345678', '+8801712345678', '8801712345678', '017 1234-5678', '+880 1712-345678', '1712345678'] as $i => $spelling) {
            $this->assertNull($this->accounts->attemptLogin($spelling, 'wrong-password', $this->client("203.0.113.{$i}")));
        }

        // A seventh device is stopped before any password is checked, whatever spelling it uses.
        $this->expectException(LoginThrottled::class);
        $this->accounts->attemptLogin('+8801712345678', 'correct-password', $this->client('198.51.100.9'));
    }

    public function test_the_same_phone_in_two_companies_has_separate_counters(): void
    {
        config(['login_protection.device_failures_per_minute' => 3]);
        $this->customer('01712345678');
        $other = $this->makeCompany('Other Co', 'other-co');

        foreach (range(1, 3) as $ignored) {
            $this->assertNull($this->accounts->attemptLogin('01712345678', 'wrong-password', $this->client('203.0.113.5')));
        }

        app(CompanyContext::class)->set($other);
        $this->customer('01712345678', 'other-password');

        $this->assertNotNull($this->accounts->attemptLogin('01712345678', 'other-password', $this->client('203.0.113.5')));
    }

    public function test_one_device_guessing_waits_but_the_owner_on_another_device_is_not_locked_out(): void
    {
        $this->customer('01712345678');

        foreach (range(1, 5) as $ignored) {
            $this->assertNull($this->accounts->attemptLogin('01712345678', 'wrong-password', $this->client('203.0.113.5')));
        }

        try {
            $this->accounts->attemptLogin('01712345678', 'correct-password', $this->client('203.0.113.5'));
            $this->fail('The guessing device should have been stopped.');
        } catch (LoginThrottled $exception) {
            $this->assertGreaterThan(0, $exception->retryAfter);
            $this->assertFalse($exception->challenge);
        }

        // Somebody who only knows the phone number cannot lock the owner out.
        $this->assertNotNull($this->accounts->attemptLogin('01712345678', 'correct-password', $this->client('192.0.2.77', 'OwnerBrowser/1.0')));
    }

    public function test_a_throttled_attempt_computes_no_password_hash(): void
    {
        config(['login_protection.device_failures_per_minute' => 2]);
        $customer = $this->customer('01712345678');
        $client = $this->client('203.0.113.5');

        $this->accounts->attemptLogin('01712345678', 'wrong-password', $client);
        $this->accounts->attemptLogin('01712345678', 'wrong-password', $client);

        Hash::spy();

        try {
            $this->accounts->attemptLogin('01712345678', 'correct-password', $client);
            $this->fail('Expected the gate to stop the attempt.');
        } catch (LoginThrottled) {
            // expected
        }

        Hash::shouldNotHaveReceived('check');
        $this->assertNotNull($customer->fresh());
    }

    public function test_a_client_that_only_fails_is_denied_without_a_password_check_and_a_signed_in_one_is_not(): void
    {
        config(['login_protection.fingerprint_failures' => 3, 'login_protection.device_failures_per_minute' => 100]);
        $this->customer('01712345678');

        // Three failures from one browser inside one network block (different addresses).
        foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3'] as $ip) {
            $this->assertNull($this->accounts->attemptLogin('01712345678', 'wrong-password', $this->client($ip)));
        }

        // The right password from the same client is still refused: it was never checked.
        $this->assertNull($this->accounts->attemptLogin('01712345678', 'correct-password', $this->client('203.0.113.4')));

        // A client of another network block is not affected ...
        $this->assertNotNull($this->accounts->attemptLogin('01712345678', 'correct-password', $this->client('198.51.100.4')));

        // ... and once a client has signed in, its earlier failures no longer brand it a bot.
        $this->assertNotNull($this->accounts->attemptLogin('01712345678', 'correct-password', $this->client('198.51.100.5')));
    }

    public function test_a_client_that_has_signed_in_is_never_denied_as_a_bot(): void
    {
        config(['login_protection.fingerprint_failures' => 3, 'login_protection.device_failures_per_minute' => 100]);
        $this->customer('01712345678');

        // The office network: one real sign-in, then a colleague mistypes a few times.
        $this->assertNotNull($this->accounts->attemptLogin('01712345678', 'correct-password', $this->client('203.0.113.1')));

        foreach (['203.0.113.2', '203.0.113.3', '203.0.113.4', '203.0.113.5'] as $ip) {
            $this->accounts->attemptLogin('01712345678', 'wrong-password', $this->client($ip));
        }

        $this->assertNotNull($this->accounts->attemptLogin('01712345678', 'correct-password', $this->client('203.0.113.6')));
    }

    public function test_an_unknown_account_costs_a_password_hash_and_counts_like_a_wrong_password(): void
    {
        $gate = $this->partialMock(LoginGate::class, fn ($mock) => $mock->shouldReceive('spendHashTime')->once());
        $accounts = app()->make(CustomerAccountService::class, ['loginGate' => $gate]);

        $this->assertNull($accounts->attemptLogin('01999999999', 'whatever-pass', $this->client('203.0.113.5')));
        $this->assertSame(1, RateLimiter::attempts($this->accountKey('01999999999')));
    }

    public function test_the_whole_site_under_attack_challenges_unknown_devices_but_not_trusted_ones(): void
    {
        config(['login_protection.global_failures_per_minute' => 3, 'login_protection.device_failures_per_minute' => 100, 'login_protection.fingerprint_failures' => 100]);
        $customer = $this->customer('01712345678');
        $token = app(LoginGate::class)->issueDeviceToken(LoginGate::SURFACE_CUSTOMER, $this->company->getKey(), $customer);

        // Failures against other accounts count towards the site-wide rate.
        foreach (range(1, 3) as $i) {
            $this->assertNull($this->accounts->attemptLogin("0179999999{$i}", 'guess-password', $this->client("203.0.113.{$i}", 'Bot/1.0')));
        }

        try {
            $this->accounts->attemptLogin('01712345678', 'correct-password', $this->client('198.51.100.20'));
            $this->fail('An unknown device should be challenged while the site is under attack.');
        } catch (LoginThrottled $exception) {
            $this->assertTrue($exception->challenge);
        }

        $this->assertNotNull($this->accounts->attemptLogin('01712345678', 'correct-password', $this->client('198.51.100.21', 'OwnerBrowser/1.0', $token)));
    }

    public function test_a_trusted_device_is_not_locked_out_by_guessing_from_everywhere_else(): void
    {
        config(['login_protection.account_failures' => 4, 'login_protection.fingerprint_failures' => 100]);
        $customer = $this->customer('01712345678');
        $token = app(LoginGate::class)->issueDeviceToken(LoginGate::SURFACE_CUSTOMER, $this->company->getKey(), $customer);

        foreach (range(1, 4) as $i) {
            $this->accounts->attemptLogin('01712345678', 'guess-password', $this->client("203.0.113.{$i}"));
        }

        try {
            $this->accounts->attemptLogin('01712345678', 'correct-password', $this->client('198.51.100.30'));
            $this->fail('A device the account never signed in from should be challenged.');
        } catch (LoginThrottled $exception) {
            $this->assertTrue($exception->challenge);
        }

        $this->assertNotNull($this->accounts->attemptLogin('01712345678', 'correct-password', $this->client('198.51.100.31', 'OwnerBrowser/1.0', $token)));
    }

    public function test_changing_the_password_ends_every_trusted_device(): void
    {
        $customer = $this->customer('01712345678');
        $gate = app(LoginGate::class);
        $token = $gate->issueDeviceToken(LoginGate::SURFACE_CUSTOMER, $this->company->getKey(), $customer);

        $this->assertTrue($gate->tokenIsValid($token, LoginGate::SURFACE_CUSTOMER, $this->company->getKey(), $customer));

        $this->accounts->updatePassword($customer, 'a-brand-new-password');

        $this->assertFalse($gate->tokenIsValid($token, LoginGate::SURFACE_CUSTOMER, $this->company->getKey(), $customer->fresh()));
    }

    public function test_a_device_token_works_only_for_its_own_account_surface_and_company(): void
    {
        $customer = $this->customer('01712345678');
        $someoneElse = $this->customer('01811111111', 'other-password');
        $gate = app(LoginGate::class);
        $token = $gate->issueDeviceToken(LoginGate::SURFACE_CUSTOMER, $this->company->getKey(), $customer);

        $this->assertFalse($gate->tokenIsValid($token, LoginGate::SURFACE_CUSTOMER, $this->company->getKey(), $someoneElse));
        $this->assertFalse($gate->tokenIsValid($token, LoginGate::SURFACE_INVESTOR, $this->company->getKey(), $customer));
        $this->assertFalse($gate->tokenIsValid($token, LoginGate::SURFACE_CUSTOMER, $this->company->getKey() + 1, $customer));
        $this->assertFalse($gate->tokenIsValid($token.'x', LoginGate::SURFACE_CUSTOMER, $this->company->getKey(), $customer));
        $this->assertFalse($gate->tokenIsValid('nonsense', LoginGate::SURFACE_CUSTOMER, $this->company->getKey(), $customer));
        $this->assertFalse($gate->tokenIsValid(null, LoginGate::SURFACE_CUSTOMER, $this->company->getKey(), $customer));

        $this->travel(config('login_protection.trusted_device_days') + 1)->days();
        $this->assertFalse($gate->tokenIsValid($token, LoginGate::SURFACE_CUSTOMER, $this->company->getKey(), $customer));
    }

    public function test_the_hash_budget_makes_unknown_devices_wait_but_never_trusted_ones(): void
    {
        config(['login_protection.bcrypt_per_minute' => 2, 'login_protection.device_failures_per_minute' => 100, 'login_protection.fingerprint_failures' => 100]);
        $customer = $this->customer('01712345678');
        $token = app(LoginGate::class)->issueDeviceToken(LoginGate::SURFACE_CUSTOMER, $this->company->getKey(), $customer);

        $this->assertNull($this->accounts->attemptLogin('01600000001', 'x-password', $this->client('203.0.113.1')));
        $this->assertNull($this->accounts->attemptLogin('01600000002', 'x-password', $this->client('203.0.113.2')));

        $this->expectException(LoginThrottled::class);

        try {
            $this->accounts->attemptLogin('01600000003', 'x-password', $this->client('203.0.113.3'));
        } finally {
            $this->assertNotNull($this->accounts->attemptLogin('01712345678', 'correct-password', $this->client('203.0.113.4', 'OwnerBrowser/1.0', $token)));
        }
    }

    public function test_a_broken_gate_does_not_stop_people_signing_in(): void
    {
        config(['app.isolate_module_errors' => true]);
        $this->customer('01712345678');
        $gate = $this->partialMock(LoginGate::class, fn ($mock) => $mock->shouldReceive('tokenIsValid')->andThrow(new \RuntimeException('the cache is down')));
        $accounts = app()->make(CustomerAccountService::class, ['loginGate' => $gate]);

        // The sign-in goes on to the normal password check, right or wrong ...
        $this->assertNotNull($accounts->attemptLogin('01712345678', 'correct-password', $this->client('203.0.113.5')));
        $this->assertNull($accounts->attemptLogin('01712345678', 'wrong-password', $this->client('203.0.113.5')));

        // ... and super admins are told (Deploy Error Logs).
        $this->assertDatabaseHas('deployment_errors', ['source' => 'module:login-gate']);
    }

    public function test_the_gate_can_be_switched_off(): void
    {
        config(['login_protection.enabled' => false, 'login_protection.device_failures_per_minute' => 1]);
        $this->customer('01712345678');

        foreach (range(1, 10) as $ignored) {
            $this->assertNull($this->accounts->attemptLogin('01712345678', 'wrong-password', $this->client('203.0.113.5')));
        }

        $this->assertNotNull($this->accounts->attemptLogin('01712345678', 'correct-password', $this->client('203.0.113.5')));
    }

    // -------------------------------------------------------------- the web storefront

    public function test_a_storefront_sign_in_remembers_the_browser_and_lets_it_through_an_attack(): void
    {
        config(['login_protection.account_failures' => 4, 'login_protection.fingerprint_failures' => 100]);
        $this->company->forceFill(['domain' => 'gate.example.test'])->save();
        StorefrontSetting::query()->where('company_id', $this->company->getKey())->update(['site_type' => StorefrontSetting::SITE_BLADE, 'customer_accounts_enabled' => true, 'is_published' => true]);
        $this->customer('01712345678');

        $signedIn = $this->post('http://gate.example.test/account/login', ['identifier' => '01712345678', 'password' => 'correct-password']);
        $token = $signedIn->getCookie(LoginGate::cookieName(LoginGate::SURFACE_CUSTOMER))?->getValue();
        $this->assertNotEmpty($token, 'The sign-in should set the device-trust cookie.');
        $this->post('http://gate.example.test/account/logout');

        foreach (range(1, 4) as $i) {
            $this->accounts->attemptLogin('01712345678', 'guess-password', $this->client("203.0.113.{$i}"));
        }

        // A stranger's browser is stopped; this browser, with its cookie, signs in.
        $this->post('http://gate.example.test/account/login', ['identifier' => '01712345678', 'password' => 'correct-password'], ['REMOTE_ADDR' => '198.51.100.40'])
            ->assertSessionHasErrors('identifier');
        $this->assertGuest('customer');

        $this->withCookie(LoginGate::cookieName(LoginGate::SURFACE_CUSTOMER), $token)
            ->post('http://gate.example.test/account/login', ['identifier' => '01712345678', 'password' => 'correct-password'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated('customer');
    }

    // ------------------------------------------------------------------ the Next.js API

    public function test_the_api_login_hands_back_a_device_token_and_answers_429_with_the_wait(): void
    {
        config(['login_protection.fingerprint_failures' => 100]);
        [, $apiToken] = CompanyApiKey::generate($this->company);
        $this->makeNextjsRow();
        $this->customer('01712345678');

        $response = $this->apiLogin($apiToken, '01712345678', 'correct-password', ['client_ip' => '203.0.113.50'])->assertOk();
        $deviceToken = $response->json('data.device_token');
        $this->assertNotEmpty($deviceToken);

        // One visitor guessing is stopped on its own address; another visitor is not.
        foreach (range(1, 5) as $ignored) {
            $this->apiLogin($apiToken, '01712345678', 'guess-password', ['client_ip' => '203.0.113.60'])->assertStatus(422)->assertJsonPath('error', 'invalid_credentials');
        }

        $stopped = $this->apiLogin($apiToken, '01712345678', 'correct-password', ['client_ip' => '203.0.113.60'])->assertStatus(429)->assertJsonPath('error', 'too_many_attempts');
        $this->assertGreaterThan(0, $stopped->json('retry_after'));
        $this->assertFalse($stopped->json('challenge'));

        // Enough guessing from elsewhere for the account to challenge unknown devices ...
        config(['login_protection.account_failures' => 8]);

        foreach (['203.0.113.61', '203.0.113.62', '203.0.113.63'] as $ip) {
            $this->apiLogin($apiToken, '01712345678', 'guess-password', ['client_ip' => $ip, 'client_user_agent' => 'Bot/'.$ip])->assertStatus(422);
        }

        $challenged = $this->apiLogin($apiToken, '01712345678', 'correct-password', ['client_ip' => '198.51.100.70', 'client_user_agent' => 'Other/1.0'])->assertStatus(429);
        $this->assertTrue($challenged->json('challenge'));

        // ... while the website's remembered device still signs its visitor in.
        $this->apiLogin($apiToken, '01712345678', 'correct-password', ['client_ip' => '198.51.100.71', 'client_user_agent' => 'Owner/1.0', 'device_token' => $deviceToken])->assertOk();
    }

    // ------------------------------------------------------------------ the portals and admin

    public function test_investor_sign_in_stops_guessing_without_flooding_the_audit_log(): void
    {
        config(['login_protection.device_failures_per_minute' => 3]);
        $company = Company::query()->create([
            'name' => 'Investor Gate Co', 'slug' => 'investor-gate', 'domain' => 'investor-gate.example.test', 'domain_verified' => true,
            'invoice_prefix' => 'IGC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true, 'investor_portal_enabled' => true,
        ]);
        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('investor'));

        $investor = Investor::query()->withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Inv', 'phone' => '01711000099']);
        $account = InvestorPortalAccount::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'investor_id' => $investor->id, 'name' => 'Inv',
            'phone' => Phone::normalize('01711000099'), 'status' => InvestorPortalAccount::STATUS_ACTIVE,
        ]);
        $account->forceFill(['password' => 'a-strong-password-1'])->save();

        foreach (range(1, 3) as $ignored) {
            Livewire::test(InvestorLogin::class)
                ->fillForm(['phone' => '01711000099', 'password' => 'wrong-password'])
                ->call('authenticate')
                ->assertHasFormErrors(['phone']);
        }

        // The right password is not even checked now.
        Livewire::test(InvestorLogin::class)
            ->fillForm(['phone' => '+880 1711-000099', 'password' => 'a-strong-password-1'])
            ->call('authenticate')
            ->assertNotified();
        $this->assertFalse(auth('investor')->check());
        $this->assertSame(3, AuditLog::query()->where('action', 'investor_portal.login_failed')->count());

        RateLimiter::clear('login-gate:'.LoginGate::SURFACE_INVESTOR.':'.$company->id.':'.sha1('01711000099').':device:'.sha1('127.0.0.1|Symfony'));
        $this->flushGate();

        Livewire::test(InvestorLogin::class)
            ->fillForm(['phone' => '01711000099', 'password' => 'a-strong-password-1'])
            ->call('authenticate')
            ->assertHasNoFormErrors();
        $this->assertTrue(auth('investor')->check());
    }

    public function test_the_reseller_login_tells_a_throttled_visitor_to_wait_instead_of_erroring(): void
    {
        config(['login_protection.device_failures_per_minute' => 2]);
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $reseller = Customer::query()->create([
            'company_id' => $this->company->getKey(), 'name' => 'Res', 'phone' => '01755555555',
            'reseller_status' => 'approved', 'reseller_slug' => 'res-'.Str::random(5), 'is_active' => true,
        ]);
        $reseller->forceFill(['password' => Hash::make('secret-password')])->save();

        foreach (range(1, 2) as $ignored) {
            Livewire::test(ResellerLogin::class)
                ->fillForm(['email' => '01755555555', 'password' => 'wrong-password'])
                ->call('authenticate')
                ->assertHasFormErrors(['email']);
        }

        Livewire::test(ResellerLogin::class)
            ->fillForm(['email' => '+8801755555555', 'password' => 'secret-password'])
            ->call('authenticate')
            ->assertNotified()
            ->assertHasNoFormErrors();
        $this->assertFalse(auth('customer')->check());
    }

    public function test_the_admin_login_is_gated_and_a_correct_sign_in_still_works(): void
    {
        config(['login_protection.device_failures_per_minute' => 2]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = User::factory()->create(['email' => 'boss@example.test', 'password' => Hash::make('right-password'), 'role' => 'super_admin']);

        foreach (range(1, 2) as $ignored) {
            Livewire::test(AdminLogin::class)
                ->fillForm(['email' => 'boss@example.test', 'password' => 'wrong-password'])
                ->call('authenticate')
                ->assertHasFormErrors(['email']);
        }

        // Stopped before Filament checks the password.
        Livewire::test(AdminLogin::class)
            ->fillForm(['email' => 'boss@example.test', 'password' => 'right-password'])
            ->call('authenticate')
            ->assertNotified();
        $this->assertGuest();

        $this->flushGate();

        Livewire::test(AdminLogin::class)
            ->fillForm(['email' => 'boss@example.test', 'password' => 'right-password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($staff);
    }

    // ----------------------------------------------------------------- helpers

    protected function flushGate(): void
    {
        cache()->flush();
    }

    protected function accountKey(string $subject): string
    {
        return 'login-gate:customer:'.$this->company->getKey().':'.sha1(Phone::identifier($subject));
    }

    protected function client(string $ip, string $agent = 'Test/1.0', ?string $deviceToken = null): LoginClient
    {
        return new LoginClient($ip, $agent, 'en', $deviceToken);
    }

    protected function customer(string $phone, string $password = 'correct-password'): Customer
    {
        $customer = Customer::query()->create([
            'company_id' => app(CompanyContext::class)->id(),
            'name' => 'Customer '.$phone,
            'phone' => Phone::normalize($phone),
            'is_active' => true,
        ]);
        $customer->forceFill(['password' => Hash::make($password)])->save();

        return $customer;
    }

    protected function makeCompany(string $name, string $slug): Company
    {
        $company = Company::query()->create([
            'name' => $name,
            'slug' => $slug,
            'domain' => $slug.'.example.test',
            'domain_verified' => true,
            'invoice_prefix' => strtoupper(substr($slug, 0, 3)),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'site_type' => StorefrontSetting::SITE_BLADE,
            'is_published' => true,
            'customer_accounts_enabled' => true,
        ]);

        return $company;
    }

    protected function makeNextjsRow(): void
    {
        StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(),
            'site_type' => StorefrontSetting::SITE_NEXTJS,
            'is_published' => true,
            'nextjs_site_enabled' => true,
            'customer_accounts_enabled' => true,
            'new_customer_delivery_advance_enabled' => false,
        ]);
    }

    protected function apiLogin(string $apiToken, string $identifier, string $password, array $extra = []): TestResponse
    {
        return $this->json('POST', '/api/v1/storefront/account/login', ['identifier' => $identifier, 'password' => $password] + $extra, [
            'Authorization' => 'Bearer '.$apiToken,
        ]);
    }
}
