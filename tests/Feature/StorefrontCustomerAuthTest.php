<?php

namespace Tests\Feature;

use App\Mail\StorefrontLoginOtp;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StorefrontCustomerActivity;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StorefrontCustomerAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_customer_can_register_log_in_and_log_out(): void
    {
        $company = $this->createPublishedStorefrontCompany('Auth Store', 'auth.example.test');

        $this->post('http://auth.example.test/account/register', [
            'name' => 'New Buyer',
            'phone' => '01711111111',
            'email' => 'buyer@example.test',
            'password' => 'super-secret',
            'password_confirmation' => 'super-secret',
        ])->assertRedirect('http://auth.example.test/account/profile');

        $customer = Customer::query()->where('phone', '01711111111')->first();
        $this->assertNotNull($customer);
        $this->assertTrue($customer->isRegistered());
        $this->assertTrue(Hash::check('super-secret', $customer->password));
        $this->assertAuthenticatedAs($customer, 'customer');

        $this->post('http://auth.example.test/account/logout')
            ->assertRedirect('http://auth.example.test');
        $this->assertGuest('customer');

        $this->get('http://auth.example.test/account/profile')
            ->assertRedirect('http://auth.example.test/account/login');

        $this->post('http://auth.example.test/account/login', [
            'identifier' => '01711111111',
            'password' => 'super-secret',
        ])->assertRedirect('http://auth.example.test/account/profile');
        $this->assertAuthenticatedAs($customer->fresh(), 'customer');

        $this->post('http://auth.example.test/account/logout');

        $this->post('http://auth.example.test/account/login', [
            'identifier' => 'buyer@example.test',
            'password' => 'super-secret',
        ])->assertRedirect('http://auth.example.test/account/profile');
        $this->assertAuthenticatedAs($customer->fresh(), 'customer');
    }

    public function test_customer_account_dashboard_and_activity_are_functional_and_private(): void
    {
        $company = $this->createPublishedStorefrontCompany('Account Store', 'account.example.test');

        $this->post('http://account.example.test/account/register', [
            'name' => 'Account Buyer',
            'phone' => '01710101010',
            'email' => 'account@example.test',
            'password' => 'account-password',
            'password_confirmation' => 'account-password',
        ]);

        $customer = Customer::query()->where('phone', '01710101010')->firstOrFail();

        $this->get('http://account.example.test/account')
            ->assertOk()
            ->assertSee('Welcome back, Account')
            ->assertSee('Overview')
            ->assertSee('Profile')
            ->assertSee('Orders')
            ->assertSee('Activity')
            ->assertSee('Account overview')
            ->assertSee('Profile settings')
            ->assertSee('My orders')
            ->assertSee('Account activity');

        $this->patch('http://account.example.test/account/profile', [
            'name' => 'Account Buyer Updated',
            'email' => 'updated-account@example.test',
            'address' => 'Updated delivery address',
        ])->assertRedirect('http://account.example.test/account/profile');

        $this->put('http://account.example.test/account/password', [
            'current_password' => 'account-password',
            'password' => 'new-account-password',
            'password_confirmation' => 'new-account-password',
        ])->assertRedirect('http://account.example.test/account/profile');

        app(CompanyContext::class)->set($company);
        $product = $this->createProduct('Account Product', 'ACCOUNT-PRODUCT-001');

        $this->post('http://account.example.test/cart/items/'.$product->slug, ['quantity' => 1])->assertRedirect();
        $this->post('http://account.example.test/checkout', [
            'name' => 'Account Buyer Updated',
            'phone' => '01710101010',
            'email' => 'updated-account@example.test',
            'address' => 'Updated delivery address',
        ])->assertRedirect();

        StorefrontCustomerActivity::query()->create([
            'company_id' => $company->getKey(),
            'customer_id' => Customer::query()->create([
                'name' => 'Private Buyer',
                'phone' => '01720202020',
                'is_active' => true,
            ])->getKey(),
            'type' => StorefrontCustomerActivity::TYPE_PROFILE_UPDATED,
            'title' => 'Private customer activity',
        ]);

        $this->get('http://account.example.test/account/activity')
            ->assertOk()
            ->assertSee('Account created')
            ->assertSee('Profile updated')
            ->assertSee('Password changed')
            ->assertSee('Order placed')
            ->assertDontSee('Private customer activity');

        $this->assertDatabaseHas('storefront_customer_activities', [
            'company_id' => $company->getKey(),
            'customer_id' => $customer->getKey(),
            'type' => StorefrontCustomerActivity::TYPE_PASSWORD_CHANGED,
        ]);
    }

    public function test_local_preview_header_account_menu_can_open_customer_profile(): void
    {
        $company = $this->createPublishedStorefrontCompany('Preview Account Store', 'preview-account.example.test');
        $baseUrl = 'http://localhost/storefront/'.$company->slug;

        $this->get($baseUrl.'/cart')
            ->assertOk()
            ->assertSee('Log in')
            ->assertSee('Create account');

        $this->get($baseUrl.'/account/profile')
            ->assertRedirect($baseUrl.'/account/login');

        $this->get($baseUrl.'/account/login')
            ->assertOk()
            ->assertSee($baseUrl.'/account/login', false)
            ->assertSee($baseUrl.'/account/register', false);

        $this->post($baseUrl.'/account/register', [
            'name' => 'Preview Customer',
            'phone' => '01730303030',
            'email' => 'preview-customer@example.test',
            'password' => 'preview-password',
            'password_confirmation' => 'preview-password',
        ])->assertRedirect($baseUrl.'/account/profile');

        $this->get($baseUrl.'/cart')
            ->assertOk()
            ->assertSee('Account overview')
            ->assertSee('Profile settings')
            ->assertSee('My orders')
            ->assertSee('Account activity')
            ->assertSee('min-h-10 items-center gap-2.5 rounded-lg', false);

        $this->get($baseUrl.'/account/profile')
            ->assertOk()
            ->assertSee('Profile settings')
            ->assertSee('Preview Customer')
            ->assertSee('lg:grid-cols-[14rem_minmax(0,1fr)]', false);

        $this->get($baseUrl.'/account/orders')
            ->assertOk()
            ->assertSee('Your order history.')
            ->assertDontSee('Checkout phone number');

        $this->post($baseUrl.'/account/logout')
            ->assertRedirect($baseUrl);
        $this->assertGuest('customer');
    }

    public function test_registration_stays_available_while_activity_table_is_temporarily_missing(): void
    {
        $this->createPublishedStorefrontCompany('Migration Safe Store', 'migration-safe.example.test');
        Schema::drop('storefront_customer_activities');

        $this->post('http://migration-safe.example.test/account/register', [
            'name' => 'Migration Safe Buyer',
            'phone' => '01740404040',
            'password' => 'migration-safe-password',
            'password_confirmation' => 'migration-safe-password',
        ])->assertRedirect('http://migration-safe.example.test/account/profile');

        $this->assertDatabaseHas('customers', [
            'phone' => '01740404040',
            'name' => 'Migration Safe Buyer',
        ]);
    }

    public function test_login_rejects_wrong_password(): void
    {
        $this->createPublishedStorefrontCompany('Wrong Password Store', 'wrongpass.example.test');

        $this->post('http://wrongpass.example.test/account/register', [
            'name' => 'Careful Buyer',
            'phone' => '01722222222',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
        ]);
        $this->post('http://wrongpass.example.test/account/logout');

        $this->post('http://wrongpass.example.test/account/login', [
            'identifier' => '01722222222',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('identifier');
        $this->assertGuest('customer');
    }

    public function test_login_locks_out_an_identifier_after_repeated_wrong_passwords(): void
    {
        $this->createPublishedStorefrontCompany('Lockout Store', 'lockout.example.test');

        $this->post('http://lockout.example.test/account/register', [
            'name' => 'Locked Buyer',
            'phone' => '01744444444',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
        ]);
        $this->post('http://lockout.example.test/account/logout');

        for ($i = 0; $i < 5; $i++) {
            $this->post('http://lockout.example.test/account/login', [
                'identifier' => '01744444444',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('identifier');
        }

        // The 6th attempt is locked out even with the correct password now -
        // this is what closes the distributed-brute-force gap the route's
        // per-IP throttle:10,1 alone can't cover.
        $this->post('http://lockout.example.test/account/login', [
            'identifier' => '01744444444',
            'password' => 'correct-password',
        ])->assertSessionHasErrors('identifier');
        $this->assertGuest('customer');
    }

    public function test_registration_reuses_existing_unregistered_customer_record(): void
    {
        $company = $this->createPublishedStorefrontCompany('Reuse Store', 'reuse.example.test', [
            'notification_credentials' => ['sms_api_url' => 'http://sms.example.test/send?to={phone}&msg={message}'],
        ]);
        Http::fake(['sms.example.test/*' => Http::response('OK')]);

        app(CompanyContext::class)->set($company);
        $existing = Customer::query()->create([
            'name' => 'Walk In',
            'phone' => '01733333333',
            'is_active' => true,
        ]);

        $this->registerWithSmsCode('http://reuse.example.test', [
            'name' => 'Walk In Registered',
            'phone' => '01733333333',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertRedirect('http://reuse.example.test/account/profile');

        $this->assertSame(1, Customer::query()->where('phone', '01733333333')->count());
        $this->assertSame($existing->getKey(), Customer::query()->where('phone', '01733333333')->first()->getKey());
        $this->assertAuthenticatedAs($existing->fresh(), 'customer');
    }

    public function test_registration_needs_the_sms_code_before_an_account_is_created(): void
    {
        $company = $this->createPublishedStorefrontCompany('Code Store', 'code.example.test', [
            'notification_credentials' => ['sms_api_url' => 'http://sms.example.test/send?to={phone}&msg={message}'],
        ]);
        Http::fake(['sms.example.test/*' => Http::response('OK')]);
        app(CompanyContext::class)->set($company);
        $existing = Customer::query()->create(['name' => 'Walk In', 'phone' => '01733333334', 'is_active' => true]);

        $this->post('http://code.example.test/account/register', [
            'name' => 'Someone Else',
            'phone' => '01733333334',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertRedirect('http://code.example.test/account/register/verify');

        $this->assertFalse($existing->fresh()->isRegistered());
        $this->assertGuest('customer');
        $this->get('http://code.example.test/account/register/verify')->assertOk()->assertSee('•••••••3334');

        $this->post('http://code.example.test/account/register/verify', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse($existing->fresh()->isRegistered());

        // A quick resend keeps the code already sent.
        $this->post('http://code.example.test/account/register/resend')->assertRedirect('http://code.example.test/account/register/verify');
        Http::assertSentCount(1);
    }

    public function test_an_existing_customers_number_cannot_register_while_sms_is_not_set_up(): void
    {
        $company = $this->createPublishedStorefrontCompany('No Code Store', 'nocode.example.test');
        app(CompanyContext::class)->set($company);
        $existing = Customer::query()->create(['name' => 'Walk In', 'phone' => '01733333335', 'is_active' => true]);

        $this->post('http://nocode.example.test/account/register', [
            'name' => 'Someone Else',
            'phone' => '01733333335',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertSessionHasErrors('phone');

        $this->assertFalse($existing->fresh()->isRegistered());

        // A brand-new number still signs up in one step.
        $this->post('http://nocode.example.test/account/register', [
            'name' => 'New Buyer',
            'phone' => '01733333336',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertRedirect('http://nocode.example.test/account/profile');
    }

    /** Sign-up on a store with an SMS gateway: the form, then the texted code. Http must be faked. */
    protected function registerWithSmsCode(string $host, array $data): TestResponse
    {
        $this->post($host.'/account/register', $data)->assertRedirect($host.'/account/register/verify');

        $sentUrl = null;
        Http::assertSent(function ($request) use (&$sentUrl): bool {
            $sentUrl = urldecode($request->url());

            return true;
        });
        preg_match('/verification code is (\d{6})/', (string) $sentUrl, $matches);
        $this->assertNotEmpty($matches, 'a sign-up code was texted');

        return $this->post($host.'/account/register/verify', ['code' => $matches[1]]);
    }

    public function test_registration_blocks_duplicate_phone(): void
    {
        $this->createPublishedStorefrontCompany('Duplicate Store', 'duplicate.example.test');

        $this->post('http://duplicate.example.test/account/register', [
            'name' => 'First Buyer',
            'phone' => '01744444444',
            'password' => 'first-password',
            'password_confirmation' => 'first-password',
        ]);
        $this->post('http://duplicate.example.test/account/logout');

        $this->post('http://duplicate.example.test/account/register', [
            'name' => 'Second Buyer',
            'phone' => '01744444444',
            'password' => 'second-password',
            'password_confirmation' => 'second-password',
        ])->assertSessionHasErrors('phone');
    }

    public function test_profile_update_and_password_change(): void
    {
        $this->createPublishedStorefrontCompany('Profile Store', 'profile.example.test');

        $this->post('http://profile.example.test/account/register', [
            'name' => 'Profile Buyer',
            'phone' => '01755555555',
            'password' => 'original-pass',
            'password_confirmation' => 'original-pass',
        ]);

        $this->patch('http://profile.example.test/account/profile', [
            'name' => 'Updated Name',
            'email' => 'updated@example.test',
            'address' => 'New address line',
        ])->assertRedirect('http://profile.example.test/account/profile');

        $customer = Customer::query()->where('phone', '01755555555')->first();
        $this->assertSame('Updated Name', $customer->name);
        $this->assertSame('updated@example.test', $customer->email);

        $this->put('http://profile.example.test/account/password', [
            'current_password' => 'wrong-current',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertSessionHasErrors('current_password');

        $this->put('http://profile.example.test/account/password', [
            'current_password' => 'original-pass',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertRedirect('http://profile.example.test/account/profile');

        $this->assertTrue(Hash::check('brand-new-pass', $customer->fresh()->password));
    }

    public function test_forgot_and_reset_password_via_sms_code(): void
    {
        $company = $this->createPublishedStorefrontCompany('Reset Store', 'reset.example.test', [
            'notification_credentials' => [
                'sms_api_url' => 'http://sms.example.test/send?key={api_key}&to={phone}&msg={message}',
                'sms_api_key' => 'test-key',
                'sms_sender_id' => 'TESTID',
            ],
        ]);

        Http::fake(['sms.example.test/*' => Http::response('OK', 200)]);

        $this->registerWithSmsCode('http://reset.example.test', [
            'name' => 'Reset Buyer',
            'phone' => '01766666666',
            'password' => 'old-password',
            'password_confirmation' => 'old-password',
        ]);
        $this->post('http://reset.example.test/account/logout');

        $this->post('http://reset.example.test/account/forgot-password', [
            'phone' => '01766666666',
        ])->assertRedirect('http://reset.example.test/account/reset-password?phone=01766666666');

        $sentUrl = null;
        Http::assertSent(function ($request) use (&$sentUrl): bool {
            $sentUrl = $request->url();

            return true;
        });

        $this->assertNotNull($sentUrl);
        preg_match('/reset code is (\d{6})/', urldecode($sentUrl), $matches);
        $this->assertNotEmpty($matches, 'Reset code was not found in the sent SMS message.');
        $code = $matches[1];

        $this->post('http://reset.example.test/account/reset-password', [
            'phone' => '01766666666',
            'code' => '000000',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertSessionHasErrors('code');

        $this->post('http://reset.example.test/account/reset-password', [
            'phone' => '01766666666',
            'code' => $code,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertRedirect('http://reset.example.test/account/profile');

        $customer = Customer::query()->where('phone', '01766666666')->first();
        $this->assertTrue(Hash::check('brand-new-pass', $customer->password));
    }

    public function test_forgot_password_reports_unavailable_when_sms_not_configured(): void
    {
        $this->createPublishedStorefrontCompany('No SMS Store', 'nosms.example.test');

        Http::fake();

        $this->post('http://nosms.example.test/account/forgot-password', [
            'phone' => '01777777777',
        ])->assertSessionHas('storefront_status', fn (string $message): bool => str_contains($message, "isn't available"));

        Http::assertNothingSent();
    }

    public function test_customer_can_log_in_with_phone_otp(): void
    {
        $this->createPublishedStorefrontCompany('Phone OTP Store', 'phone-otp.example.test', [
            'notification_credentials' => [
                'sms_api_url' => 'http://sms.example.test/send?to={phone}&msg={message}',
            ],
        ]);
        Http::fake(['sms.example.test/*' => Http::response('OK')]);
        $this->registerWithSmsCode('http://phone-otp.example.test', [
            'name' => 'Phone OTP Buyer',
            'phone' => '01790000001',
            'password' => 'phone-otp-password',
            'password_confirmation' => 'phone-otp-password',
        ]);
        $this->post('http://phone-otp.example.test/account/logout');

        $this->post('http://phone-otp.example.test/account/otp', [
            'identifier' => '01790000001',
        ])->assertRedirect('http://phone-otp.example.test/account/otp/verify');

        $sentUrl = null;
        Http::assertSent(function ($request) use (&$sentUrl): bool {
            $sentUrl = urldecode($request->url());

            return true;
        });
        preg_match('/login code is (\d{6})/', (string) $sentUrl, $matches);
        $this->assertNotEmpty($matches);

        $this->post('http://phone-otp.example.test/account/otp/verify', [
            'code' => $matches[1],
        ])->assertRedirect('http://phone-otp.example.test/account/profile');

        $customer = Customer::query()->where('phone', '01790000001')->firstOrFail();
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertNull($customer->fresh()->login_otp_code);
    }

    public function test_customer_can_log_in_with_email_otp(): void
    {
        $this->createPublishedStorefrontCompany('Email OTP Store', 'email-otp.example.test');
        $this->post('http://email-otp.example.test/account/register', [
            'name' => 'Email OTP Buyer',
            'phone' => '01790000002',
            'email' => 'otp-buyer@example.test',
            'password' => 'email-otp-password',
            'password_confirmation' => 'email-otp-password',
        ]);
        $this->post('http://email-otp.example.test/account/logout');
        Mail::fake();

        $this->post('http://email-otp.example.test/account/otp', [
            'identifier' => 'OTP-BUYER@example.test',
        ])->assertRedirect('http://email-otp.example.test/account/otp/verify');

        $code = null;
        Mail::assertSent(StorefrontLoginOtp::class, function (StorefrontLoginOtp $mail) use (&$code): bool {
            $code = $mail->code;

            return $mail->hasTo('otp-buyer@example.test');
        });

        $this->post('http://email-otp.example.test/account/otp/verify', [
            'code' => $code,
        ])->assertRedirect('http://email-otp.example.test/account/profile');

        $this->assertAuthenticated('customer');
    }

    public function test_login_otp_rejects_invalid_codes_and_does_not_reveal_unknown_accounts(): void
    {
        $this->createPublishedStorefrontCompany('Safe OTP Store', 'safe-otp.example.test');
        Mail::fake();

        $this->post('http://safe-otp.example.test/account/otp', [
            'identifier' => 'unknown@example.test',
        ])->assertRedirect('http://safe-otp.example.test/account/otp/verify')
            ->assertSessionHas('storefront_status', fn (string $message): bool => str_contains($message, 'If a matching account exists'));

        Mail::assertNothingSent();
        $this->post('http://safe-otp.example.test/account/otp/verify', [
            'code' => '123456',
        ])->assertSessionHasErrors('code');
        $this->assertGuest('customer');
    }

    public function test_customer_accounts_can_be_disabled_per_company(): void
    {
        $this->createPublishedStorefrontCompany('Disabled Accounts Store', 'disabled.example.test', [
            'customer_accounts_enabled' => false,
        ]);

        $this->get('http://disabled.example.test/account/login')->assertNotFound();
        $this->get('http://disabled.example.test/account/register')->assertNotFound();
        $this->get('http://disabled.example.test/account/orders')
            ->assertRedirect('http://disabled.example.test/track');
    }

    public function test_customer_accounts_are_isolated_per_company(): void
    {
        $this->createPublishedStorefrontCompany('Isolation Store A', 'isolation-a.example.test');
        $this->createPublishedStorefrontCompany('Isolation Store B', 'isolation-b.example.test');

        $this->post('http://isolation-a.example.test/account/register', [
            'name' => 'Company A Buyer',
            'phone' => '01788888888',
            'password' => 'shared-password',
            'password_confirmation' => 'shared-password',
        ])->assertRedirect('http://isolation-a.example.test/account/profile');
        $this->post('http://isolation-a.example.test/account/logout');

        $this->post('http://isolation-b.example.test/account/login', [
            'identifier' => '01788888888',
            'password' => 'shared-password',
        ])->assertSessionHasErrors('identifier');
        $this->assertGuest('customer');
    }

    public function test_product_search_filters_by_name(): void
    {
        $company = $this->createPublishedStorefrontCompany('Search Store', 'search.example.test');
        app(CompanyContext::class)->set($company);

        $this->createProduct('Blue Widget', 'SEARCH-BLUE-001');
        $this->createProduct('Red Gadget', 'SEARCH-RED-001');

        $this->get('http://search.example.test/products?q=widget')
            ->assertOk()
            ->assertSee('Blue Widget')
            ->assertDontSee('Red Gadget');
    }

    private function createProduct(string $name, string $sku): Product
    {
        return Product::query()->create([
            'name' => $name,
            'sku' => $sku,
            'price' => 1000,
            'sale_price' => 900,
            'cost_price' => 600,
            'stock' => 10,
            'unit' => 'pcs',
            'reorder_level' => 2,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);
    }

    private function createPublishedStorefrontCompany(string $name, string $domain, array $settingOverrides = []): Company
    {
        $company = Company::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString().'-'.str($domain)->slug()->toString(),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => str($domain)->slug('')->substr(0, 12)->upper()->toString(),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        StorefrontSetting::query()->create(array_merge([
            'company_id' => $company->getKey(),
            'theme_color' => '#0F766E',
            'whatsapp_number' => '+8801700000000',
            'meta_title' => $name,
            'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
        ], $settingOverrides));

        return $company;
    }
}
