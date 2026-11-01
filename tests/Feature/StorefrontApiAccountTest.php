<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\StorefrontCustomerActivity;
use App\Models\StorefrontCustomerToken;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\CustomerAccountService;
use App\Support\StorefrontApi\Signing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * /api/v1/storefront/account… and /orders/track — customer sign-in, the account
 * area, order tracking and reviews for the headless Next.js site. The rules live
 * in the services the Blade storefront uses (CustomerAccountService, the tracking
 * service); these tests prove the API applies them, never leaks one customer's or
 * company's data to another, and cannot be brute-forced.
 */
class StorefrontApiAccountTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected string $apiToken;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->company, $this->apiToken] = $this->makeCompany('ZamZam International', 'zamzam-international');
        app(CompanyContext::class)->set($this->company);
    }

    // ------------------------------------------------------------ the gates

    public function test_account_endpoints_need_the_key_the_enabled_switches_and_a_customer_token(): void
    {
        $this->postJson('/api/v1/storefront/account/login', [])->assertStatus(401)->assertJsonPath('error', 'missing_api_key');

        $this->api('GET', '/account')->assertStatus(401)->assertJsonPath('error', 'customer_unauthenticated');
        $this->api('GET', '/account/orders', [], 'not-a-real-token')->assertStatus(401)->assertJsonPath('error', 'customer_unauthenticated');

        $this->settings()->update(['customer_accounts_enabled' => false]);
        $this->api('POST', '/account/login', ['identifier' => '01712345678', 'password' => 'secret-pass-1'])
            ->assertStatus(403)->assertJsonPath('error', 'customer_accounts_disabled');

        [, $offToken] = $this->makeCompany('Retail Co', 'retail-co', enabled: false);
        $this->api('POST', '/account/login', ['identifier' => 'a@b.test', 'password' => 'secret-pass-1'], null, $offToken)
            ->assertStatus(403)->assertJsonPath('error', 'nextjs_site_disabled');
    }

    // --------------------------------------------------------- registration

    public function test_registering_creates_a_login_and_stores_only_a_hash_of_the_token(): void
    {
        $response = $this->register()->assertCreated();

        $token = $response->json('data.token');
        $this->assertSame(64, strlen($token));
        $this->assertSame('Rahim', $response->json('data.customer.name'));
        $this->assertArrayNotHasKey('password', $response->json('data.customer'));

        $customer = Customer::query()->sole();
        $this->assertNotNull($customer->password);
        $this->assertSame('website', $customer->customer_source);

        $row = StorefrontCustomerToken::query()->sole();
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertNotSame($token, $row->token_hash);
        $this->assertTrue($row->expires_at->between(now()->addHours(47), now()->addHours(49)), 'a plain login lasts about two days');

        $this->api('GET', '/account', [], $token)->assertOk()->assertJsonPath('data.customer.phone', '01712345678');
        $this->assertSame(1, StorefrontCustomerActivity::query()->where('type', StorefrontCustomerActivity::TYPE_ACCOUNT_CREATED)->count());
    }

    public function test_remember_me_keeps_the_login_for_thirty_days(): void
    {
        $this->register(['remember' => true])->assertCreated();

        $this->assertTrue(StorefrontCustomerToken::query()->sole()->expires_at->between(now()->addDays(29), now()->addDays(31)));
    }

    public function test_registration_rejects_a_taken_phone_or_email_and_bad_input(): void
    {
        $this->register(['email' => 'rahim@example.test'])->assertCreated();

        $this->register(['name' => 'Someone else', 'email' => 'other@example.test'])
            ->assertStatus(409)->assertJsonPath('error', 'account_exists');
        $this->register(['phone' => '01811111111', 'email' => 'rahim@example.test'])
            ->assertStatus(409)->assertJsonPath('error', 'email_taken');

        $this->register(['phone' => '123'])->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->register(['password_confirmation' => 'different'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->register(['password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_registering_adopts_the_customer_record_of_an_earlier_order_without_duplicating_it(): void
    {
        Queue::fake();
        $this->configureSms();
        Http::fake(['sms.example.test/*' => Http::response('OK')]);
        $this->product('Item', 'item');
        $this->placeOrder(['name' => 'Guest buyer', 'phone' => '01712345678'])->assertCreated();
        $this->assertSame(1, Customer::query()->count());

        $this->register()->assertStatus(202)
            ->assertJsonPath('verification_required', true)
            ->assertJsonPath('masked_identifier', '•••••••5678');
        $token = $this->register(['code' => $this->sentCode('OTP is')])->assertCreated()->json('data.token');

        $this->assertSame(1, Customer::query()->count(), 'the CRM record is reused');
        $this->api('GET', '/account/orders', [], $token)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_an_existing_customers_number_cannot_be_registered_without_its_sms_code(): void
    {
        Queue::fake();
        $this->product('Item', 'item');
        $this->placeOrder(['name' => 'Guest buyer', 'phone' => '01712345678'])->assertCreated();

        // No SMS gateway: the number can't be proven, so no account.
        $this->register()->assertStatus(422)->assertJsonPath('error', 'verification_unavailable');

        $this->configureSms();
        Http::fake(['sms.example.test/*' => Http::response('OK')]);
        $this->register()->assertStatus(202);
        $code = $this->sentCode('OTP is');

        $this->register(['code' => $code === '111111' ? '222222' : '111111'])->assertStatus(422)->assertJsonPath('error', 'otp_invalid');
        $this->assertFalse(Customer::query()->first()->isRegistered());

        $this->register(['code' => $code])->assertCreated();
        // The code is spent.
        $this->register(['code' => $code])->assertStatus(409)->assertJsonPath('error', 'account_exists');
    }

    public function test_five_wrong_sign_up_codes_burn_the_code_and_a_quick_resend_keeps_it(): void
    {
        $this->configureSms();
        Http::fake(['sms.example.test/*' => Http::response('OK')]);

        $this->register()->assertStatus(202);
        $this->register()->assertStatus(202);
        Http::assertSentCount(1);
        $code = $this->sentCode('OTP is');

        foreach (range(1, 5) as $ignored) {
            $this->register(['code' => $code === '111111' ? '222222' : '111111'])->assertStatus(422);
        }

        $this->register(['code' => $code])->assertStatus(422)->assertJsonPath('error', 'otp_invalid');
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_a_taken_number_is_refused_before_any_code_is_sent(): void
    {
        $this->register()->assertCreated();
        $this->configureSms();
        Http::fake(['sms.example.test/*' => Http::response('OK')]);

        $this->register(['phone' => '+880 1712-345678'])->assertStatus(409)->assertJsonPath('error', 'account_exists');
        Http::assertNothingSent();
    }

    public function test_a_failed_sms_is_reported_instead_of_waiting_for_a_code(): void
    {
        $this->configureSms();
        Http::fake(['sms.example.test/*' => Http::response('down', 500)]);

        $this->register()->assertStatus(422)->assertJsonPath('error', 'verification_unavailable');
    }

    // ---------------------------------------------------------------- login

    public function test_login_by_phone_or_email_with_the_password(): void
    {
        $this->register(['email' => 'rahim@example.test'])->assertCreated();

        $this->login('01712345678', 'secret-pass-1')->assertOk()->assertJsonStructure(['data' => ['token', 'expires_at', 'customer' => ['name']]]);
        $this->login('+880 1712-345678', 'secret-pass-1')->assertOk();
        $this->login('RAHIM@example.test', 'secret-pass-1')->assertOk();
        $this->assertSame(3, StorefrontCustomerActivity::query()->where('type', StorefrontCustomerActivity::TYPE_LOGIN)->count());
    }

    public function test_a_wrong_password_is_refused_and_five_wrong_tries_lock_the_identifier(): void
    {
        $this->register()->assertCreated();

        foreach (range(1, 5) as $ignored) {
            $this->login('01712345678', 'wrong-password')->assertStatus(422)->assertJsonPath('error', 'invalid_credentials');
        }

        // Locked: even the right password is refused for now, and the answer says for how long.
        $locked = $this->login('01712345678', 'secret-pass-1')->assertStatus(429)->assertJsonPath('error', 'too_many_attempts');
        $this->assertGreaterThan(0, $locked->json('retry_after'));

        // An identifier nobody owns gets the same answer as a wrong password.
        $this->login('01999999999', 'whatever-pass')->assertStatus(422)->assertJsonPath('error', 'invalid_credentials');
    }

    public function test_a_deactivated_customer_cannot_sign_in_and_loses_the_existing_login(): void
    {
        $token = $this->register()->assertCreated()->json('data.token');

        Customer::query()->sole()->update(['is_active' => false]);

        $this->login('01712345678', 'secret-pass-1')->assertStatus(403)->assertJsonPath('error', 'account_disabled');
        $this->api('GET', '/account', [], $token)->assertStatus(401);
    }

    // --------------------------------------------------------- the token

    public function test_logout_ends_only_that_login(): void
    {
        $this->register()->assertCreated();
        $first = $this->login('01712345678', 'secret-pass-1')->json('data.token');
        $second = $this->login('01712345678', 'secret-pass-1')->json('data.token');

        $this->api('POST', '/account/logout', [], $first)->assertOk();

        $this->api('GET', '/account', [], $first)->assertStatus(401);
        $this->api('GET', '/account', [], $second)->assertOk();
    }

    public function test_an_expired_token_is_refused(): void
    {
        $token = $this->register()->assertCreated()->json('data.token');

        StorefrontCustomerToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->api('GET', '/account', [], $token)->assertStatus(401);
    }

    public function test_a_token_only_works_for_the_company_it_was_issued_in(): void
    {
        $token = $this->register()->assertCreated()->json('data.token');

        [, $otherApiKey] = $this->makeCompany('Gadget', 'gadget');

        $this->api('GET', '/account', [], $token, $otherApiKey)->assertStatus(401);
        $this->api('GET', '/account', [], $token)->assertOk();
    }

    // ----------------------------------------------------------------- OTP

    public function test_login_codes_need_a_configured_sms_gateway_for_phones(): void
    {
        $this->register()->assertCreated();

        $this->api('POST', '/account/otp', ['identifier' => '01712345678'])
            ->assertStatus(422)->assertJsonPath('error', 'otp_unavailable');
        $this->api('POST', '/account/otp', ['identifier' => '123'])->assertStatus(422)->assertJsonValidationErrors('identifier');
    }

    public function test_a_login_code_signs_the_customer_in_once(): void
    {
        $this->register()->assertCreated();
        $this->configureSms();
        Http::fake(['sms.example.test/*' => Http::response('OK')]);

        $this->api('POST', '/account/otp', ['identifier' => '01712345678'])
            ->assertStatus(202)->assertJsonPath('masked_identifier', '•••••••5678');
        $code = $this->sentCode('OTP is');

        $this->api('POST', '/account/otp/verify', ['identifier' => '01712345678', 'code' => '000000'])
            ->assertStatus(422)->assertJsonPath('error', 'otp_invalid');

        $this->api('POST', '/account/otp/verify', ['identifier' => '01712345678', 'code' => $code, 'remember' => true])
            ->assertOk()->assertJsonStructure(['data' => ['token']]);

        // The code is spent.
        $this->api('POST', '/account/otp/verify', ['identifier' => '01712345678', 'code' => $code])
            ->assertStatus(422)->assertJsonPath('error', 'otp_invalid');
    }

    public function test_asking_for_a_code_does_not_reveal_whether_an_account_exists(): void
    {
        $this->configureSms();
        Http::fake(['sms.example.test/*' => Http::response('OK')]);

        $this->api('POST', '/account/otp', ['identifier' => '01755555555'])->assertStatus(202);

        Http::assertNothingSent();
    }

    public function test_five_wrong_codes_burn_the_login_code(): void
    {
        $this->register()->assertCreated();
        $this->configureSms();
        Http::fake(['sms.example.test/*' => Http::response('OK')]);
        $this->api('POST', '/account/otp', ['identifier' => '01712345678'])->assertStatus(202);
        $code = $this->sentCode('OTP is');

        foreach (range(1, 5) as $ignored) {
            $this->api('POST', '/account/otp/verify', ['identifier' => '01712345678', 'code' => $code === '111111' ? '222222' : '111111'])->assertStatus(422);
        }

        $this->api('POST', '/account/otp/verify', ['identifier' => '01712345678', 'code' => $code])
            ->assertStatus(422)->assertJsonPath('error', 'otp_invalid');
    }

    // -------------------------------------------------------- password reset

    public function test_a_password_reset_signs_every_other_login_out_and_signs_in_with_the_new_password(): void
    {
        $old = $this->register()->assertCreated()->json('data.token');
        $this->configureSms();
        Http::fake(['sms.example.test/*' => Http::response('OK')]);

        $this->api('POST', '/account/password/forgot', ['phone' => '01712345678'])->assertStatus(202);
        $code = $this->sentCode('OTP is');

        $response = $this->api('POST', '/account/password/reset', [
            'phone' => '01712345678', 'code' => $code, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass',
        ])->assertOk();

        $this->api('GET', '/account', [], $old)->assertStatus(401);
        $this->api('GET', '/account', [], $response->json('data.token'))->assertOk();
        $this->login('01712345678', 'brand-new-pass')->assertOk();
        $this->login('01712345678', 'secret-pass-1')->assertStatus(422);
    }

    public function test_forgot_password_is_generic_and_needs_sms(): void
    {
        $this->register()->assertCreated();

        $this->api('POST', '/account/password/forgot', ['phone' => '01712345678'])
            ->assertStatus(422)->assertJsonPath('error', 'sms_unavailable');

        $this->configureSms();
        Http::fake(['sms.example.test/*' => Http::response('OK')]);

        $this->api('POST', '/account/password/forgot', ['phone' => '01733333333'])->assertStatus(202);
        Http::assertNothingSent();
    }

    public function test_five_wrong_reset_codes_lock_the_reset_even_for_the_right_code(): void
    {
        $this->register()->assertCreated();
        $this->configureSms();
        Http::fake(['sms.example.test/*' => Http::response('OK')]);
        $this->api('POST', '/account/password/forgot', ['phone' => '01712345678'])->assertStatus(202);
        $code = $this->sentCode('OTP is');
        $payload = fn (string $c): array => ['phone' => '01712345678', 'code' => $c, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'];

        foreach (range(1, 5) as $ignored) {
            $this->api('POST', '/account/password/reset', $payload($code === '123456' ? '654321' : '123456'))
                ->assertStatus(422)->assertJsonPath('error', 'reset_code_invalid');
        }

        $this->api('POST', '/account/password/reset', $payload($code))->assertStatus(429)->assertJsonPath('error', 'too_many_attempts');
        $this->login('01712345678', 'brand-new-pass')->assertStatus(422);
    }

    public function test_the_blade_password_reset_is_locked_after_five_wrong_codes_too(): void
    {
        $this->register()->assertCreated();
        $customer = Customer::query()->sole();
        $accounts = app(CustomerAccountService::class);
        $setting = $this->settings()->forceFill(['notification_credentials' => ['sms_api_url' => 'http://sms.example.test/send?to={phone}&msg={message}']]);
        $setting->save();
        Http::fake(['sms.example.test/*' => Http::response('OK')]);
        $accounts->sendPasswordResetCode($this->company, $setting, $customer);
        $code = $this->sentCode('OTP is');

        foreach (range(1, 5) as $ignored) {
            $this->assertFalse($accounts->resetPassword($customer->fresh(), $code === '000000' ? '111111' : '000000', 'another-pass-1'));
        }

        $this->assertFalse($accounts->resetPassword($customer->fresh(), $code, 'another-pass-1'));
        $this->assertGreaterThan(0, $accounts->resetLockedFor($customer->fresh()));
    }

    // ------------------------------------------------------ profile/password

    public function test_the_profile_can_be_updated_but_not_onto_another_accounts_email(): void
    {
        $token = $this->register(['email' => 'rahim@example.test'])->assertCreated()->json('data.token');
        $this->register(['phone' => '01822222222', 'name' => 'Karim', 'email' => 'karim@example.test'])->assertCreated();

        $this->api('PATCH', '/account/profile', ['name' => 'Rahim Uddin', 'email' => 'rahim@example.test', 'address' => 'Mirpur, Dhaka'], $token)
            ->assertOk()->assertJsonPath('data.name', 'Rahim Uddin')->assertJsonPath('data.address', 'Mirpur, Dhaka');

        $this->api('PATCH', '/account/profile', ['name' => 'Rahim', 'email' => 'karim@example.test'], $token)
            ->assertStatus(409)->assertJsonPath('error', 'email_taken');
        $this->api('PATCH', '/account/profile', ['name' => ''], $token)->assertStatus(422);

        $this->assertSame('01712345678', Customer::query()->where('name', 'Rahim Uddin')->value('phone'), 'the phone cannot be changed here');
    }

    public function test_changing_the_password_signs_the_other_devices_out_but_not_this_one(): void
    {
        $this->register()->assertCreated();
        $phone = $this->login('01712345678', 'secret-pass-1')->json('data.token');
        $laptop = $this->login('01712345678', 'secret-pass-1')->json('data.token');

        $this->api('PUT', '/account/password', ['current_password' => 'wrong', 'password' => 'new-secret-9', 'password_confirmation' => 'new-secret-9'], $phone)
            ->assertStatus(422)->assertJsonPath('error', 'current_password_incorrect');

        $this->api('PUT', '/account/password', ['current_password' => 'secret-pass-1', 'password' => 'new-secret-9', 'password_confirmation' => 'new-secret-9'], $phone)
            ->assertOk();

        $this->api('GET', '/account', [], $phone)->assertOk();
        $this->api('GET', '/account', [], $laptop)->assertStatus(401);
        $this->login('01712345678', 'new-secret-9')->assertOk();
    }

    // ----------------------------------------------------------- the orders

    public function test_the_account_lists_only_the_customers_own_orders_with_totals(): void
    {
        Queue::fake();
        $this->product('Item', 'item', ['sale_price' => 500]);
        $mine = $this->register()->assertCreated()->json('data.token');
        $other = $this->register(['phone' => '01822222222', 'name' => 'Karim'])->assertCreated()->json('data.token');

        $number = $this->placeOrder(['phone' => '01712345678'], $mine)->assertCreated()->json('data.order.order_number');
        $this->placeOrder(['phone' => '01822222222', 'name' => 'Karim'], $other)->assertCreated();
        Order::query()->where('order_number', $number)->update(['status' => Order::STATUS_COMPLETED]);

        $home = $this->api('GET', '/account', [], $mine)->assertOk();
        $this->assertSame(1, $home->json('data.stats.order_count'));
        $this->assertSame(1, $home->json('data.stats.completed_order_count'));
        $this->assertSame(0, $home->json('data.stats.active_order_count'));
        $this->assertEquals(570, $home->json('data.stats.total_spent'));
        $this->assertSame($number, $home->json('data.recent_orders.0.order_number'));
        $this->assertTrue($home->json('data.recent_orders.0.can_review'));

        $list = $this->api('GET', '/account/orders', [], $mine)->assertOk();
        $this->assertSame([$number], array_column($list->json('data'), 'order_number'));
        $this->assertSame(1, $list->json('meta.total'));

        $theirNumber = Order::query()->where('order_number', '!=', $number)->value('order_number');
        $this->api('GET', '/account/orders/'.$theirNumber, [], $mine)->assertStatus(404)->assertJsonPath('error', 'order_not_found');
    }

    public function test_an_order_placed_while_signed_in_is_recorded_in_the_account_activity(): void
    {
        Queue::fake();
        $this->product('Item', 'item');
        $token = $this->register()->assertCreated()->json('data.token');

        $this->placeOrder(['phone' => '01712345678'], $token)->assertCreated();

        $this->assertSame(1, StorefrontCustomerActivity::query()->where('type', StorefrontCustomerActivity::TYPE_ORDER_PLACED)->count());
        $this->api('GET', '/account/activity', [], $token)->assertOk()->assertJsonPath('data.0.type', StorefrontCustomerActivity::TYPE_ORDER_PLACED);

        // A bad or missing token never blocks an order and records nothing extra.
        $this->placeOrder(['phone' => '01712345678'], 'expired-token')->assertCreated();
        $this->assertSame(1, StorefrontCustomerActivity::query()->where('type', StorefrontCustomerActivity::TYPE_ORDER_PLACED)->count());
    }

    public function test_the_order_detail_shows_the_status_history_and_the_courier(): void
    {
        Queue::fake();
        $this->product('Item', 'item');
        $token = $this->register()->assertCreated()->json('data.token');
        $number = $this->placeOrder(['phone' => '01712345678'], $token)->assertCreated()->json('data.order.order_number');
        $order = Order::query()->where('order_number', $number)->firstOrFail();

        app(CompanyContext::class)->set($this->company);
        AuditLog::query()->create([
            'auditable_type' => Order::class,
            'auditable_id' => $order->getKey(),
            'action' => 'updated',
            'new_values' => ['status' => 'confirmed', 'delivery_status' => 'in_transit'],
        ]);

        $data = $this->api('GET', '/account/orders/'.$number, [], $token)->assertOk()->json('data');

        $this->assertSame($number, $data['order_number']);
        $this->assertSame('Item', $data['items'][0]['name']);
        $this->assertSame('item', $data['items'][0]['slug']);
        $this->assertFalse($data['items'][0]['can_review'], 'only a completed order can be reviewed');
        $this->assertSame('Rahim', $data['customer']['name']);

        $types = collect($data['tracking']['updates'])->keyBy('type');
        $this->assertSame('Confirmed', $types['order']['label']);
        $this->assertSame('In Transit', $types['delivery']['label']);
        $this->assertSame('confirmed', $types['order']['status']);
    }

    public function test_reorder_returns_the_available_lines_and_skips_gone_products(): void
    {
        Queue::fake();
        $kept = $this->product('Kept', 'kept');
        $gone = $this->product('Gone', 'gone');
        $token = $this->register()->assertCreated()->json('data.token');
        $number = $this->placeOrder(['phone' => '01712345678'], $token, [['slug' => 'kept', 'quantity' => 3], ['slug' => 'gone', 'quantity' => 1]])
            ->assertCreated()->json('data.order.order_number');

        $gone->update(['is_active' => false]);

        $this->api('POST', '/account/orders/'.$number.'/reorder', [], $token)->assertOk()
            ->assertJsonPath('data.lines.0.slug', 'kept')
            ->assertJsonPath('data.lines.0.quantity', 3)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonCount(1, 'data.lines');

        $this->assertSame(1, StorefrontCustomerActivity::query()->where('type', StorefrontCustomerActivity::TYPE_ORDER_REORDERED)->count());
        $this->api('POST', '/account/orders/NOPE-1/reorder', [], $token)->assertStatus(404);
        $this->assertNotNull($kept);
    }

    // ----------------------------------------------------------- the reviews

    public function test_a_review_needs_a_completed_order_and_waits_for_approval(): void
    {
        Queue::fake();
        $product = $this->product('Item', 'item');
        $token = $this->register()->assertCreated()->json('data.token');
        $number = $this->placeOrder(['phone' => '01712345678'], $token)->assertCreated()->json('data.order.order_number');
        $review = ['product_slug' => 'item', 'rating' => 5, 'comment' => 'Very good'];

        $this->api('POST', '/account/orders/'.$number.'/reviews', $review, $token)
            ->assertStatus(409)->assertJsonPath('error', 'order_not_completed');

        Order::query()->where('order_number', $number)->update(['status' => Order::STATUS_COMPLETED]);

        $this->api('POST', '/account/orders/'.$number.'/reviews', ['product_slug' => 'item', 'rating' => 9], $token)->assertStatus(422);
        $this->api('POST', '/account/orders/'.$number.'/reviews', ['product_slug' => 'not-on-this-order', 'rating' => 4], $token)
            ->assertStatus(404)->assertJsonPath('error', 'product_not_in_order');

        $this->api('POST', '/account/orders/'.$number.'/reviews', $review, $token)
            ->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->api('POST', '/account/orders/'.$number.'/reviews', $review, $token)
            ->assertStatus(409)->assertJsonPath('error', 'already_reviewed');

        $saved = ProductReview::query()->sole();
        $this->assertSame($product->getKey(), $saved->product_id);
        $this->assertSame('Rahim', $saved->customer_name);

        // Not public until the owner approves it in the ERP.
        $this->api('GET', '/products/item')->assertOk()->assertJsonCount(0, 'data.reviews');
        $saved->update(['status' => ProductReview::STATUS_APPROVED]);
        $this->api('GET', '/products/item')->assertOk()->assertJsonPath('data.reviews.0.comment', 'Very good');

        $detail = $this->api('GET', '/account/orders/'.$number, [], $token)->json('data.items.0');
        $this->assertFalse($detail['can_review']);
        $this->assertSame('approved', $detail['review']['status']);
    }

    public function test_a_review_the_owner_moved_to_trash_still_counts_as_reviewed(): void
    {
        Queue::fake();
        $this->product('Item', 'item');
        $token = $this->register()->assertCreated()->json('data.token');
        $number = $this->placeOrder(['phone' => '01712345678'], $token)->assertCreated()->json('data.order.order_number');
        Order::query()->where('order_number', $number)->update(['status' => Order::STATUS_COMPLETED]);
        $review = ['product_slug' => 'item', 'rating' => 5, 'comment' => 'Very good'];

        $this->api('POST', '/account/orders/'.$number.'/reviews', $review, $token)->assertCreated();
        ProductReview::query()->sole()->update(['status' => ProductReview::STATUS_APPROVED]);
        ProductReview::query()->sole()->delete();

        // Gone from the product page...
        $this->api('GET', '/products/item')->assertOk()->assertJsonCount(0, 'data.reviews');
        // ...but one review per product and order still holds: a clear answer, not a database error.
        $this->api('POST', '/account/orders/'.$number.'/reviews', $review, $token)
            ->assertStatus(409)->assertJsonPath('error', 'already_reviewed');
        $this->assertSame(1, ProductReview::onlyTrashed()->count());
    }

    public function test_a_customer_cannot_review_somebody_elses_order(): void
    {
        Queue::fake();
        $this->product('Item', 'item');
        $owner = $this->register()->assertCreated()->json('data.token');
        $intruder = $this->register(['phone' => '01822222222', 'name' => 'Karim'])->assertCreated()->json('data.token');
        $number = $this->placeOrder(['phone' => '01712345678'], $owner)->assertCreated()->json('data.order.order_number');
        Order::query()->where('order_number', $number)->update(['status' => Order::STATUS_COMPLETED]);

        $this->api('POST', '/account/orders/'.$number.'/reviews', ['product_slug' => 'item', 'rating' => 1], $intruder)->assertStatus(404);
        $this->assertSame(0, ProductReview::query()->count());
    }

    // ---------------------------------------------------- public tracking

    public function test_an_order_can_be_tracked_with_its_number_and_phone_without_exposing_the_customer(): void
    {
        Queue::fake();
        $this->product('Item', 'item');
        $number = $this->placeOrder(['phone' => '01712345678', 'email' => 'rahim@example.test'])->assertCreated()->json('data.order.order_number');

        $data = $this->api('POST', '/orders/track', ['order_number' => $number, 'phone' => '+880 1712 345678'])->assertOk()->json('data');

        $this->assertSame($number, $data['order_number']);
        $this->assertSame('Item', $data['items'][0]['name']);
        $this->assertEquals(570, $data['total_amount']);
        $this->assertSame('not_booked', $data['tracking']['delivery_status']);
        $this->assertArrayNotHasKey('customer', $data, 'a phone number alone must not reveal the name, e-mail or address');
        $this->assertStringNotContainsString('rahim@example.test', json_encode($data));
    }

    public function test_tracking_answers_the_same_for_a_wrong_phone_and_a_missing_order_and_stops_guessing(): void
    {
        Queue::fake();
        $this->product('Item', 'item');
        $number = $this->placeOrder(['phone' => '01712345678'])->assertCreated()->json('data.order.order_number');

        $this->api('POST', '/orders/track', ['order_number' => $number, 'phone' => '01999999999'])->assertStatus(404)->assertJsonPath('error', 'order_not_found');
        $this->api('POST', '/orders/track', ['order_number' => 'ZZZ-0001', 'phone' => '01712345678'])->assertStatus(404)->assertJsonPath('error', 'order_not_found');
        $this->api('POST', '/orders/track', ['order_number' => $number])->assertStatus(422);

        foreach (range(1, 7) as $i) {
            $this->api('POST', '/orders/track', ['order_number' => $number, 'phone' => '0199999000'.$i])->assertStatus(404);
        }

        // Eight wrong guesses lock this order number, even for the right phone.
        $this->api('POST', '/orders/track', ['order_number' => $number, 'phone' => '01712345678'])->assertStatus(429)->assertJsonPath('error', 'too_many_attempts');
    }

    public function test_the_success_link_token_opens_the_tracking_without_a_phone(): void
    {
        Queue::fake();
        $this->product('Item', 'item');
        $placed = $this->placeOrder(['phone' => '01712345678'])->assertCreated()->json('data.order');

        $this->api('GET', '/orders/'.$placed['order_number'].'/tracking?token='.$placed['token'])
            ->assertOk()->assertJsonPath('data.order_number', $placed['order_number'])->assertJsonPath('data.customer.name', 'Rahim');
        $this->api('GET', '/orders/'.$placed['order_number'].'/tracking?token=nope')->assertStatus(404);
        $this->api('GET', '/orders/'.$placed['order_number'].'/tracking')->assertStatus(404);
    }

    public function test_tracking_never_crosses_companies(): void
    {
        Queue::fake();
        $this->product('Item', 'item');
        $placed = $this->placeOrder(['phone' => '01712345678'])->assertCreated()->json('data.order');

        [, $otherApiKey] = $this->makeCompany('Gadget', 'gadget');

        $this->api('POST', '/orders/track', ['order_number' => $placed['order_number'], 'phone' => '01712345678'], null, $otherApiKey)->assertStatus(404);
        $this->api('GET', '/orders/'.$placed['order_number'].'/tracking?token='.$placed['token'], [], null, $otherApiKey)->assertStatus(404);
        $this->assertSame(Signing::orderToken(Order::query()->firstOrFail()), $placed['token']);
    }

    public function test_tracking_works_while_customer_accounts_are_switched_off(): void
    {
        Queue::fake();
        $this->product('Item', 'item');
        $number = $this->placeOrder(['phone' => '01712345678'])->assertCreated()->json('data.order.order_number');
        $this->settings()->update(['customer_accounts_enabled' => false]);

        $this->api('POST', '/orders/track', ['order_number' => $number, 'phone' => '01712345678'])->assertOk();
    }

    // -------------------------------------------------- the visitor throttle

    public function test_sign_in_attempts_are_limited_per_visitor_not_per_website_server(): void
    {
        foreach (range(10, 29) as $n) {
            $this->api('POST', '/account/login', ['identifier' => '017000000'.$n, 'password' => 'whatever-pass', 'client_ip' => '203.0.113.9'])->assertStatus(422);
        }

        $this->api('POST', '/account/login', ['identifier' => '01700000030', 'password' => 'whatever-pass', 'client_ip' => '203.0.113.9'])->assertStatus(429);
        // Another visitor behind the same website server is unaffected.
        $this->api('POST', '/account/login', ['identifier' => '01700000002', 'password' => 'whatever-pass', 'client_ip' => '198.51.100.7'])->assertStatus(422);
    }

    // ------------------------------------------------------------- helpers

    /** @param  array<string, mixed>  $data */
    protected function api(string $method, string $path, array $data = [], ?string $customerToken = null, ?string $apiToken = null): TestResponse
    {
        $headers = ['Authorization' => 'Bearer '.($apiToken ?? $this->apiToken)];

        if ($customerToken !== null) {
            $headers['X-Customer-Token'] = $customerToken;
        }

        return $this->json($method, '/api/v1/storefront'.$path, $data, $headers);
    }

    protected function register(array $overrides = []): TestResponse
    {
        return $this->api('POST', '/account/register', array_merge([
            'name' => 'Rahim',
            'phone' => '01712345678',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ], $overrides));
    }

    protected function login(string $identifier, string $password): TestResponse
    {
        return $this->api('POST', '/account/login', ['identifier' => $identifier, 'password' => $password]);
    }

    /**
     * Cash-on-delivery order through the real checkout API, optionally as a signed-in customer.
     *
     * @param  list<array<string, mixed>>|null  $items
     */
    protected function placeOrder(array $overrides = [], ?string $customerToken = null, ?array $items = null): TestResponse
    {
        $headers = ['Authorization' => 'Bearer '.$this->apiToken];

        if ($customerToken !== null) {
            $headers['X-Customer-Token'] = $customerToken;
        }

        return $this->postJson('/api/v1/storefront/checkout', array_merge([
            'items' => $items ?? [['slug' => 'item', 'quantity' => 1]],
            'name' => 'Rahim',
            'phone' => '01712345678',
            'address' => 'House 1, Dhanmondi, Dhaka',
            'payment_method' => 'cod',
            'return_url' => 'https://zamzamint.com/checkout/payment/return',
            'cancel_url' => 'https://zamzamint.com/cart',
        ], $overrides), $headers);
    }

    protected function configureSms(): void
    {
        $this->settings()->update(['notification_credentials' => ['sms_api_url' => 'http://sms.example.test/send?to={phone}&msg={message}']]);
    }

    /** The 6-digit code in the most recent fake SMS. */
    protected function sentCode(string $after): string
    {
        $url = null;
        Http::assertSent(function ($request) use (&$url): bool {
            $url = urldecode($request->url());

            return true;
        });
        preg_match('/'.preg_quote($after, '/').' (\d{6})/', (string) $url, $matches);
        $this->assertNotEmpty($matches, 'an SMS with a code was sent');

        return $matches[1];
    }

    protected function settings(): StorefrontSetting
    {
        return StorefrontSetting::query()->where('company_id', $this->company->getKey())->firstOrFail();
    }

    protected function product(string $name, string $sku, array $attributes = []): Product
    {
        return Product::query()->create($attributes + [
            'name' => $name,
            'sku' => $sku,
            'price' => 500,
            'sale_price' => 500,
            'cost_price' => 300,
            'stock' => 20,
            'unit' => 'pcs',
            'weight_kg' => 1,
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);
    }

    /** @return array{0: Company, 1: string} */
    protected function makeCompany(string $name, string $slug, bool $enabled = true): array
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
            'site_type' => StorefrontSetting::SITE_NEXTJS,
            'is_published' => true,
            'nextjs_site_enabled' => $enabled,
            'nextjs_site_domain' => $slug === 'zamzam-international' ? 'zamzamint.com' : null,
            'customer_accounts_enabled' => true,
            'new_customer_delivery_advance_enabled' => false,
            'delivery_first_kg_inside' => 70,
            'delivery_first_kg_outside' => 110,
            'delivery_additional_per_kg' => 20,
        ]);

        [, $token] = CompanyApiKey::generate($company);

        return [$company->fresh(), $token];
    }
}
