<?php

namespace Tests\Feature;

use App\Filament\Auth\AdminLogin;
use App\Filament\Investor\Pages\Auth\Login as InvestorLogin;
use App\Filament\Pages\LoginSecuritySettings;
use App\Filament\Reseller\Pages\Auth\Login as ResellerLogin;
use App\Models\AppSetting;
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
use App\Services\TurnstileVerifier;
use App\Support\LoginCaptchaSettings;
use App\Support\LoginClient;
use App\Support\LoginThrottled;
use App\Support\StorefrontApi\Phone;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The CAPTCHA step of the sign-in gate: Cloudflare Turnstile keys are admin
 * settings (encrypted), a challenged sign-in (an unknown device while the
 * account or the site is under attack) shows the widget and goes on once the
 * token is accepted, and with no CAPTCHA set up — or Cloudflare unreachable —
 * the challenge stays a plain wait.
 */
class LoginCaptchaTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    protected Company $company;

    protected CustomerAccountService $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        // Two wrong passwords from anywhere put an account into "challenge".
        config(['login_protection.account_failures' => 2, 'login_protection.device_failures_per_minute' => 100, 'login_protection.fingerprint_failures' => 100]);

        $this->company = Company::query()->create([
            'name' => 'Captcha Co', 'slug' => 'captcha-co', 'domain' => 'captcha.example.test', 'domain_verified' => true,
            'invoice_prefix' => 'CAP', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(), 'site_type' => StorefrontSetting::SITE_BLADE,
            'is_published' => true, 'customer_accounts_enabled' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
        $this->accounts = app(CustomerAccountService::class);
    }

    // ------------------------------------------------------------- the settings

    public function test_the_captcha_is_on_only_when_switched_on_and_both_keys_are_saved(): void
    {
        $this->assertFalse(LoginCaptchaSettings::isConfigured());
        $this->assertNull(LoginCaptchaSettings::siteKey());

        LoginCaptchaSettings::save(['enabled' => true, 'site_key' => 'site-key']);
        $this->assertFalse(LoginCaptchaSettings::isConfigured(), 'a missing secret key leaves it off');

        LoginCaptchaSettings::save(['secret_key' => 'secret-key']);
        $this->assertTrue(LoginCaptchaSettings::isConfigured());
        $this->assertSame('site-key', LoginCaptchaSettings::siteKey());
        $this->assertSame('secret-key', LoginCaptchaSettings::secretKey());

        LoginCaptchaSettings::save(['enabled' => false]);
        $this->assertFalse(LoginCaptchaSettings::isConfigured());
        $this->assertNull(LoginCaptchaSettings::secretKey());
    }

    public function test_the_keys_are_stored_encrypted_and_a_blank_save_keeps_them(): void
    {
        LoginCaptchaSettings::save(['enabled' => true, 'site_key' => 'site-key', 'secret_key' => 'secret-key']);

        foreach (['login_captcha.turnstile.site_key' => 'site-key', 'login_captcha.turnstile.secret_key' => 'secret-key'] as $key => $plain) {
            $row = AppSetting::query()->where('key', $key)->firstOrFail();
            $this->assertTrue($row->is_encrypted);
            $this->assertNotSame($plain, $row->value);
        }

        LoginCaptchaSettings::save(['enabled' => true, 'site_key' => '', 'secret_key' => '  ']);

        $this->assertSame('secret-key', LoginCaptchaSettings::secretKey());
        $this->assertSame('site-key', LoginCaptchaSettings::siteKey());
    }

    public function test_only_a_super_admin_opens_login_security_and_the_secret_is_never_sent_back(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $manager = User::factory()->create(['role' => 'manager', 'is_active' => true]);

        $this->actingAs($manager)->get('/admin/settings/login-security-settings')->assertForbidden();
        $this->actingAs($superAdmin)->get('/admin/settings/login-security-settings')->assertOk()->assertSee(__('Cloudflare Turnstile keys'));

        Livewire::actingAs($superAdmin)->test(LoginSecuritySettings::class)
            ->set('settings.enabled', true)
            ->call('save')
            ->assertHasErrors(['settings.site_key'])
            ->set('settings.site_key', 'site-key')
            ->call('save')
            ->assertHasErrors(['settings.secret_key'])
            ->set('settings.secret_key', 'secret-key')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('settings.secret_key', '')
            ->assertSet('settings.has_secret_key', true)
            ->assertSet('settings.site_key', 'site-key');

        $this->assertTrue(LoginCaptchaSettings::isConfigured());

        // Saving again with the secret left blank keeps it.
        Livewire::actingAs($superAdmin)->test(LoginSecuritySettings::class)
            ->set('settings.site_key', 'new-site-key')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('secret-key', LoginCaptchaSettings::secretKey());
        $this->assertSame('new-site-key', LoginCaptchaSettings::siteKey());
    }

    // ----------------------------------------------------------- the verifier

    public function test_cloudflare_is_asked_with_the_secret_the_token_and_the_visitors_ip(): void
    {
        $this->configureCaptcha();
        Http::fake([self::VERIFY_URL => Http::response(['success' => true])]);

        $this->assertTrue(app(TurnstileVerifier::class)->verify('the-token', '203.0.113.9'));

        Http::assertSent(fn (Request $request): bool => $request->url() === self::VERIFY_URL
            && $request['secret'] === 'secret-key'
            && $request['response'] === 'the-token'
            && $request['remoteip'] === '203.0.113.9');
    }

    public function test_a_rejected_token_an_error_or_an_unreachable_cloudflare_is_not_solved(): void
    {
        $this->configureCaptcha();
        $verifier = app(TurnstileVerifier::class);

        Http::fake([self::VERIFY_URL => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);
        $this->assertFalse($verifier->verify('bad-token', '203.0.113.9'));

        Http::fake([self::VERIFY_URL => Http::response('boom', 500)]);
        $this->assertFalse($verifier->verify('a-token', '203.0.113.9'));

        Http::fake([self::VERIFY_URL => fn () => throw new ConnectionException('down')]);
        $this->assertFalse($verifier->verify('a-token', '203.0.113.9'));
    }

    public function test_nothing_is_sent_without_a_token_a_sane_token_length_or_a_configured_captcha(): void
    {
        Http::fake();
        $verifier = app(TurnstileVerifier::class);

        $this->assertFalse($verifier->verify('a-token', '203.0.113.9'), 'not configured');

        $this->configureCaptcha();
        $this->assertFalse($verifier->verify(null, '203.0.113.9'));
        $this->assertFalse($verifier->verify('', '203.0.113.9'));
        $this->assertFalse($verifier->verify(str_repeat('x', 2049), '203.0.113.9'));

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- the gate

    public function test_a_challenged_device_is_asked_for_the_captcha_and_goes_on_once_it_is_solved(): void
    {
        $this->configureCaptcha();
        $this->customer('01712345678');
        $this->putAccountInChallenge('01712345678');
        Http::fake([self::VERIFY_URL => Http::response(['success' => true])]);

        try {
            $this->accounts->attemptLogin('01712345678', 'correct-password', $this->client('198.51.100.5'));
            $this->fail('An unknown device should be asked for the security check.');
        } catch (LoginThrottled $exception) {
            $this->assertTrue($exception->captcha);
            $this->assertTrue($exception->challenge);
            $this->assertSame(__('Please complete the security check below to sign in.'), $exception->getMessage());
        }

        Http::assertNothingSent();

        $customer = $this->accounts->attemptLogin('01712345678', 'correct-password', $this->client('198.51.100.5', 'Test/1.0', null, 'solved-token'));

        $this->assertNotNull($customer);
        Http::assertSentCount(1);
    }

    public function test_a_solved_captcha_still_has_to_get_the_password_right(): void
    {
        $this->configureCaptcha();
        $this->customer('01712345678');
        $this->putAccountInChallenge('01712345678');
        Http::fake([self::VERIFY_URL => Http::response(['success' => true])]);

        $this->assertNull($this->accounts->attemptLogin('01712345678', 'wrong-password', $this->client('198.51.100.5', 'Test/1.0', null, 'solved-token')));
    }

    public function test_an_unaccepted_token_leaves_the_challenge_in_place(): void
    {
        $this->configureCaptcha();
        $this->customer('01712345678');
        $this->putAccountInChallenge('01712345678');
        Http::fake([self::VERIFY_URL => Http::response(['success' => false])]);

        $this->expectException(LoginThrottled::class);
        $this->accounts->attemptLogin('01712345678', 'correct-password', $this->client('198.51.100.5', 'Test/1.0', null, 'bad-token'));
    }

    public function test_cloudflare_being_down_cannot_open_the_gate(): void
    {
        $this->configureCaptcha();
        $this->customer('01712345678');
        $this->putAccountInChallenge('01712345678');
        Http::fake([self::VERIFY_URL => fn () => throw new ConnectionException('down')]);

        $this->expectException(LoginThrottled::class);
        $this->accounts->attemptLogin('01712345678', 'correct-password', $this->client('198.51.100.5', 'Test/1.0', null, 'a-token'));
    }

    public function test_with_no_captcha_a_challenge_is_a_wait_and_a_token_changes_nothing(): void
    {
        $this->customer('01712345678');
        $this->putAccountInChallenge('01712345678');
        Http::fake();

        try {
            $this->accounts->attemptLogin('01712345678', 'correct-password', $this->client('198.51.100.5', 'Test/1.0', null, 'a-token'));
            $this->fail('The challenge should stay a wait.');
        } catch (LoginThrottled $exception) {
            $this->assertTrue($exception->challenge);
            $this->assertFalse($exception->captcha);
            $this->assertStringContainsString('Too many login attempts', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_the_token_is_checked_once_even_when_the_account_and_the_whole_site_are_under_attack(): void
    {
        config(['login_protection.global_failures_per_minute' => 2]);
        $this->configureCaptcha();
        $this->customer('01712345678');
        $this->putAccountInChallenge('01712345678');
        RateLimiter::hit('login-gate:customer:global:fail', 60);
        RateLimiter::hit('login-gate:customer:global:fail', 60);
        Http::fake([self::VERIFY_URL => Http::response(['success' => true])]);

        $this->assertNotNull($this->accounts->attemptLogin('01712345678', 'correct-password', $this->client('198.51.100.5', 'Test/1.0', null, 'solved-token')));
        Http::assertSentCount(1);
    }

    public function test_the_captcha_does_not_lift_a_device_wait_or_a_bot_denial(): void
    {
        $this->configureCaptcha();
        $this->customer('01712345678');
        Http::fake([self::VERIFY_URL => Http::response(['success' => true])]);

        // A device that keeps guessing still waits, solved CAPTCHA or not.
        config(['login_protection.device_failures_per_minute' => 2]);
        $device = $this->client('198.51.100.5', 'Test/1.0', null, 'solved-token');
        $this->accounts->attemptLogin('01712345678', 'wrong-password', $device);
        $this->accounts->attemptLogin('01712345678', 'wrong-password', $device);

        try {
            $this->accounts->attemptLogin('01712345678', 'correct-password', $device);
            $this->fail('The device should wait.');
        } catch (LoginThrottled $exception) {
            $this->assertFalse($exception->captcha);
        }

        // A client that only ever fails is still told "login failed" without a check.
        config(['login_protection.device_failures_per_minute' => 100, 'login_protection.fingerprint_failures' => 2, 'login_protection.account_failures' => 100]);
        $bot = fn (string $ip): LoginClient => $this->client($ip, 'Bot/1.0', null, 'solved-token');
        $this->accounts->attemptLogin('01712345678', 'wrong-password', $bot('192.0.2.1'));
        $this->accounts->attemptLogin('01712345678', 'wrong-password', $bot('192.0.2.2'));
        $this->assertNull($this->accounts->attemptLogin('01712345678', 'correct-password', $bot('192.0.2.3')));
    }

    // -------------------------------------------------------- the storefront (Blade)

    public function test_the_storefront_login_shows_the_widget_after_a_challenge_and_signs_in_once_it_is_solved(): void
    {
        $this->configureCaptcha();
        $this->customer('01712345678');
        $this->putAccountInChallenge('01712345678');
        Http::fake([self::VERIFY_URL => Http::response(['success' => true])]);

        $this->get('http://captcha.example.test/account/login')->assertOk()->assertDontSee('cf-turnstile');

        $this->post('http://captcha.example.test/account/login', ['identifier' => '01712345678', 'password' => 'correct-password'])
            ->assertSessionHasErrors('identifier')
            ->assertSessionHas('captcha_required');
        $this->assertGuest('customer');

        $this->get('http://captcha.example.test/account/login')
            ->assertOk()
            ->assertSee('class="cf-turnstile" data-sitekey="site-key"', false)
            ->assertSee('https://challenges.cloudflare.com/turnstile/v0/api.js', false);

        $this->post('http://captcha.example.test/account/login', [
            'identifier' => '01712345678',
            'password' => 'correct-password',
            'cf-turnstile-response' => 'solved-token',
        ])->assertSessionHasNoErrors();
        $this->assertAuthenticated('customer');
    }

    public function test_the_storefront_login_never_shows_the_widget_without_a_captcha_set_up(): void
    {
        $this->customer('01712345678');
        $this->putAccountInChallenge('01712345678');

        $this->post('http://captcha.example.test/account/login', ['identifier' => '01712345678', 'password' => 'correct-password'])
            ->assertSessionHasErrors('identifier')
            ->assertSessionMissing('captcha_required');

        $this->get('http://captcha.example.test/account/login')->assertOk()->assertDontSee('cf-turnstile');
    }

    // --------------------------------------------------------------- the Next.js API

    public function test_the_api_asks_for_the_captcha_with_the_public_site_key_and_accepts_the_token(): void
    {
        $this->configureCaptcha();
        [, $apiToken] = CompanyApiKey::generate($this->company);
        StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(), 'site_type' => StorefrontSetting::SITE_NEXTJS, 'is_published' => true,
            'nextjs_site_enabled' => true, 'customer_accounts_enabled' => true, 'new_customer_delivery_advance_enabled' => false,
        ]);
        $this->customer('01712345678');
        $this->putAccountInChallenge('01712345678');
        Http::fake([self::VERIFY_URL => Http::response(['success' => true])]);

        $asked = $this->apiLogin($apiToken, ['client_ip' => '198.51.100.5', 'client_user_agent' => 'Visitor/1.0'])
            ->assertStatus(429)
            ->assertJsonPath('error', 'captcha_required')
            ->assertJsonPath('challenge', true)
            ->assertJsonPath('captcha.provider', 'turnstile')
            ->assertJsonPath('captcha.site_key', 'site-key');
        $this->assertGreaterThan(0, $asked->json('retry_after'));
        $this->assertStringNotContainsString('secret-key', $asked->getContent());

        $this->apiLogin($apiToken, ['client_ip' => '198.51.100.5', 'client_user_agent' => 'Visitor/1.0', 'captcha_token' => 'solved-token'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'device_token']]);
    }

    // ------------------------------------------------------------ the Filament pages

    public function test_the_admin_login_shows_the_widget_after_a_challenge_and_signs_in_once_it_is_solved(): void
    {
        $this->configureCaptcha();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = User::factory()->create(['email' => 'boss@example.test', 'password' => Hash::make('right-password'), 'role' => 'super_admin']);
        $this->putAccountInChallenge('boss@example.test', LoginGate::SURFACE_ADMIN, null);
        Http::fake([self::VERIFY_URL => Http::response(['success' => true])]);

        $page = Livewire::test(AdminLogin::class)->assertDontSee('login-captcha-reset');

        $page->fillForm(['email' => 'boss@example.test', 'password' => 'right-password'])
            ->call('authenticate')
            ->assertSet('captchaRequired', true)
            ->assertNotified()
            ->assertSee('login-captcha-reset');
        $this->assertGuest();

        $page->set('captchaToken', 'solved-token')
            ->call('authenticate')
            ->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($staff);
    }

    public function test_the_admin_login_stays_a_plain_wait_when_no_captcha_is_set_up(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        User::factory()->create(['email' => 'boss@example.test', 'password' => Hash::make('right-password'), 'role' => 'super_admin']);
        $this->putAccountInChallenge('boss@example.test', LoginGate::SURFACE_ADMIN, null);

        Livewire::test(AdminLogin::class)
            ->fillForm(['email' => 'boss@example.test', 'password' => 'right-password'])
            ->call('authenticate')
            ->assertSet('captchaRequired', false)
            ->assertNotified()
            ->assertDontSee('login-captcha-reset');
        $this->assertGuest();
    }

    public function test_the_reseller_login_shows_the_widget_after_a_challenge(): void
    {
        $this->configureCaptcha();
        $this->company->forceFill(['reseller_module_enabled' => true])->save();
        app(CompanyContext::class)->set($this->company->fresh());
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $reseller = Customer::query()->create([
            'company_id' => $this->company->getKey(), 'name' => 'Res', 'phone' => '01755555555',
            'reseller_status' => 'approved', 'reseller_slug' => 'res-'.Str::random(5), 'is_active' => true,
        ]);
        $reseller->forceFill(['password' => Hash::make('secret-password')])->save();
        $this->putAccountInChallenge('01755555555');
        Http::fake([self::VERIFY_URL => Http::response(['success' => true])]);

        Livewire::test(ResellerLogin::class)
            ->fillForm(['email' => '01755555555', 'password' => 'secret-password'])
            ->call('authenticate')
            ->assertSet('captchaRequired', true)
            ->assertSee('login-captcha-reset')
            ->set('captchaToken', 'solved-token')
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertTrue(auth('customer')->check());
    }

    public function test_the_investor_login_shows_the_widget_after_a_challenge(): void
    {
        $this->configureCaptcha();
        $company = Company::query()->create([
            'name' => 'Investor Captcha Co', 'slug' => 'investor-captcha', 'domain' => 'investor-captcha.example.test', 'domain_verified' => true,
            'invoice_prefix' => 'ICC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true, 'investor_portal_enabled' => true,
        ]);
        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('investor'));
        $investor = Investor::query()->withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Inv', 'phone' => '01711000099']);
        $account = InvestorPortalAccount::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'investor_id' => $investor->id, 'name' => 'Inv',
            'phone' => Phone::normalize('01711000099'), 'status' => InvestorPortalAccount::STATUS_ACTIVE,
        ]);
        $account->forceFill(['password' => 'a-strong-password-1'])->save();
        $this->putAccountInChallenge('01711000099', LoginGate::SURFACE_INVESTOR, $company->id);
        Http::fake([self::VERIFY_URL => Http::response(['success' => true])]);

        Livewire::test(InvestorLogin::class)
            ->fillForm(['phone' => '01711000099', 'password' => 'a-strong-password-1'])
            ->call('authenticate')
            ->assertSet('captchaRequired', true)
            ->assertSee('login-captcha-reset')
            ->set('captchaToken', 'solved-token')
            ->call('authenticate');

        $this->assertTrue(auth('investor')->check());
    }

    // ----------------------------------------------------------------- helpers

    protected function configureCaptcha(): void
    {
        LoginCaptchaSettings::save(['enabled' => true, 'site_key' => 'site-key', 'secret_key' => 'secret-key']);
    }

    /** As many wrong passwords against the account as the gate counts before it challenges unknown devices. */
    protected function putAccountInChallenge(string $subject, string $surface = LoginGate::SURFACE_CUSTOMER, ?int $companyId = -1): void
    {
        $companyId = $companyId === -1 ? $this->company->getKey() : $companyId;
        $key = "login-gate:{$surface}:".(int) $companyId.':'.sha1(Str::lower(Phone::identifier($subject)));

        foreach (range(1, (int) config('login_protection.account_failures')) as $ignored) {
            RateLimiter::hit($key, 900);
        }
    }

    protected function client(string $ip, string $agent = 'Test/1.0', ?string $deviceToken = null, ?string $captchaToken = null): LoginClient
    {
        return new LoginClient($ip, $agent, 'en', $deviceToken, $captchaToken);
    }

    protected function customer(string $phone, string $password = 'correct-password'): Customer
    {
        $customer = Customer::query()->create([
            'company_id' => $this->company->getKey(),
            'name' => 'Customer '.$phone,
            'phone' => Phone::normalize($phone),
            'is_active' => true,
        ]);
        $customer->forceFill(['password' => Hash::make($password)])->save();

        return $customer;
    }

    protected function apiLogin(string $apiToken, array $extra = []): TestResponse
    {
        return $this->json('POST', '/api/v1/storefront/account/login', ['identifier' => '01712345678', 'password' => 'correct-password'] + $extra, [
            'Authorization' => 'Bearer '.$apiToken,
        ]);
    }
}
