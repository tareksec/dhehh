<?php

namespace Tests\Feature;

use App\Jobs\CheckExternalCourierFraudJob;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StorefrontCheckoutAttempt;
use App\Models\StorefrontPayment;
use App\Models\StorefrontPaymentMethod;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\ExternalCourierFraudService;
use App\Support\StorefrontApi\Signing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * /api/v1/storefront/checkout… — quote, place order, hosted-payment return and
 * order lookup for the headless Next.js site. The rules themselves live in the
 * shared StorefrontCheckoutService (covered through the Blade storefront by the
 * other Storefront* checkout tests); these tests prove the API applies them,
 * that prices are always computed server-side, and that the API is safe to
 * retry and cannot be used across companies.
 */
class StorefrontApiCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected const RETURN_URL = 'https://zamzamint.com/checkout/payment/return';

    protected const CANCEL_URL = 'https://zamzamint.com/cart';

    protected Company $company;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->company, $this->token] = $this->makeCompany('ZamZam International', 'zamzam-international', 'offer.example.test');
        app(CompanyContext::class)->set($this->company);
    }

    // ------------------------------------------------------------ the gate

    public function test_checkout_endpoints_need_the_key_and_the_enabled_next_js_switch(): void
    {
        $this->postJson('/api/v1/storefront/checkout/quote', ['items' => []])->assertStatus(401)->assertJsonPath('error', 'missing_api_key');

        [, $disabledToken] = $this->makeCompany('Retail Co', 'retail-co', 'retail.example.test', enabled: false);

        $this->call('POST', '/api/v1/storefront/checkout', [], [], [], $this->server($disabledToken))
            ->assertStatus(403)
            ->assertJsonPath('error', 'nextjs_site_disabled');
        $this->call('GET', '/api/v1/storefront/orders/ANY', [], [], [], $this->server($disabledToken))
            ->assertStatus(403);
    }

    public function test_validation_errors_are_json_even_without_an_accept_header(): void
    {
        $this->call('POST', '/api/v1/storefront/checkout/quote', ['items' => []], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    // ---------------------------------------------------------------- quote

    public function test_the_quote_prices_every_line_on_the_server_deal_then_tier_then_selling_price(): void
    {
        $plain = $this->product('Plain', 'plain', ['sale_price' => 500, 'price' => 600]);
        $tiered = $this->product('Tiered', 'tiered', [
            'sale_price' => 500,
            'tier_prices' => [['min_qty' => 10, 'price' => 450], ['min_qty' => 50, 'price' => 400]],
        ]);
        $dealt = $this->product('Dealt', 'dealt', [
            'sale_price' => 500,
            'tier_prices' => [['min_qty' => 10, 'price' => 450]],
        ]);
        $this->collection(Offer::KIND_FLASH_SALE, [[$dealt, 300]]);

        $quote = $this->quote([
            ['slug' => 'plain', 'quantity' => 2],
            ['slug' => 'tiered', 'quantity' => 12],
            ['slug' => 'dealt', 'quantity' => 12],
        ])->assertOk()->json('data');

        $prices = collect($quote['lines'])->pluck('unit_price', 'slug');
        $this->assertEquals(500, $prices['plain']);
        $this->assertEquals(450, $prices['tiered']);
        // The live deal price wins over the wholesale tier (owner decision 2026-10-04).
        $this->assertEquals(300, $prices['dealt']);
        $this->assertEquals(1000 + 5400 + 3600, $quote['subtotal']);
        $this->assertSame([], $quote['issues']);
    }

    public function test_a_price_sent_by_the_browser_is_ignored(): void
    {
        $this->product('Plain', 'plain', ['sale_price' => 500]);

        $quote = $this->quote([['slug' => 'plain', 'quantity' => 1, 'price' => 1, 'unit_price' => 1, 'subtotal' => 1]])->assertOk()->json('data');

        $this->assertEquals(500, $quote['lines'][0]['unit_price']);
    }

    public function test_deals_do_not_apply_to_variations_and_never_raise_the_price(): void
    {
        $variable = $this->product('Shirt', 'shirt', ['sale_price' => 500, 'has_variants' => true]);
        $variant = ProductVariant::query()->create([
            'product_id' => $variable->getKey(), 'sku' => 'shirt-m', 'options' => ['Size' => 'M'], 'sale_price' => 520, 'stock' => 5, 'is_active' => true,
        ]);
        $pricey = $this->product('Pricey', 'pricey', ['sale_price' => 500]);
        $this->collection(Offer::KIND_TRENDING, [[$variable, 100], [$pricey, 800]]);

        $quote = $this->quote([
            ['slug' => 'shirt', 'quantity' => 1, 'variant_id' => $variant->getKey()],
            ['slug' => 'pricey', 'quantity' => 1],
        ])->assertOk()->json('data');

        $prices = collect($quote['lines'])->pluck('unit_price', 'slug');
        $this->assertEquals(520, $prices['shirt']);
        $this->assertEquals(500, $prices['pricey']);
    }

    public function test_the_quote_reports_every_adjustment_instead_of_changing_the_cart_silently(): void
    {
        $this->product('Wholesale', 'wholesale', ['moq' => 12, 'stock' => 100]);
        $this->product('Scarce', 'scarce', ['stock' => 3]);
        $this->product('Gone', 'gone', ['stock' => 0]);
        $this->product('Hidden', 'hidden', ['is_active' => false]);
        $this->product('Needs size', 'needs-size', ['has_variants' => true]);

        $quote = $this->quote([
            ['slug' => 'wholesale', 'quantity' => 2],
            ['slug' => 'scarce', 'quantity' => 9],
            ['slug' => 'gone', 'quantity' => 1],
            ['slug' => 'hidden', 'quantity' => 1],
            ['slug' => 'missing', 'quantity' => 1],
            ['slug' => 'needs-size', 'quantity' => 1],
        ])->assertOk()->json('data');

        $issues = collect($quote['issues'])->keyBy('slug');
        $this->assertSame('quantity_adjusted', $issues['wholesale']['code']);
        $this->assertSame(12, $issues['wholesale']['quantity']);
        $this->assertSame('quantity_adjusted', $issues['scarce']['code']);
        $this->assertSame(3, $issues['scarce']['quantity']);
        $this->assertSame('out_of_stock', $issues['gone']['code']);
        $this->assertSame('unavailable', $issues['hidden']['code']);
        $this->assertSame('unavailable', $issues['missing']['code']);
        $this->assertSame('variant_required', $issues['needs-size']['code']);

        $this->assertEqualsCanonicalizing([12, 3], collect($quote['lines'])->pluck('quantity')->all());
    }

    public function test_the_quote_returns_delivery_vat_totals_and_payment_options(): void
    {
        $this->settings()->update([
            'delivery_first_kg_inside' => 70,
            'delivery_first_kg_outside' => 110,
            'vat_enabled' => true,
            'vat_rate' => 10,
            'vat_mode' => StorefrontSetting::VAT_MODE_EXCLUSIVE,
        ]);
        $this->product('Item', 'item', ['sale_price' => 1000, 'weight_kg' => 1]);
        StorefrontPaymentMethod::query()->create([
            'type' => StorefrontPaymentMethod::TYPE_MANUAL, 'name' => 'bKash', 'account_number' => '01700000000', 'is_active' => true, 'sort_order' => 5,
        ]);

        $quote = $this->quote([['slug' => 'item', 'quantity' => 1]], ['address' => 'House 1, Dhanmondi, Dhaka'])->assertOk()->json('data');

        $this->assertSame('inside', $quote['delivery']['area']);
        $this->assertEquals(70, $quote['delivery']['inside']['fee']);
        $this->assertEquals(110, $quote['delivery']['outside']['fee']);
        $this->assertEquals(100, $quote['vat']['amount']);
        $this->assertEquals(1000 + 70 + 100, $quote['totals']['inside']);
        $this->assertEquals(1000 + 110 + 100, $quote['totals']['outside']);
        $this->assertEquals(1170, $quote['total']);

        $methods = collect($quote['payment_methods'])->keyBy('type');
        $this->assertTrue($methods->has('cod'));
        $this->assertSame('01700000000', $methods['manual']['account_number']);
        $this->assertStringStartsWith('manual:', $methods['manual']['value']);
        $this->assertTrue($quote['online_payment_available']);

        // No address yet → both areas are priced, none is picked.
        $without = $this->quote([['slug' => 'item', 'quantity' => 1]])->json('data');
        $this->assertNull($without['total']);
        $this->assertNotNull($without['totals']['outside']);

        $outside = $this->quote([['slug' => 'item', 'quantity' => 1]], ['address' => 'Rangpur'])->json('data');
        $this->assertSame('outside', $outside['delivery']['area']);
        $this->assertEquals(1210, $outside['total']);
    }

    public function test_the_quote_shows_the_preorder_advance_that_checkout_will_require(): void
    {
        $this->product('Coming', 'coming', ['stock' => 0, 'is_preorder' => true, 'preorder_advance_percent' => 50, 'sale_price' => 1000]);

        $quote = $this->quote([['slug' => 'coming', 'quantity' => 2]])->assertOk()->json('data');

        $this->assertEquals(1000, $quote['advance']['preorder_due']);
        $this->assertTrue($quote['lines'][0]['is_preorder']);
    }

    // ---------------------------------------------------------------- order

    public function test_a_cash_on_delivery_order_is_placed_with_server_computed_totals(): void
    {
        Queue::fake();
        $this->settings()->update(['delivery_first_kg_inside' => 70]);
        $this->product('Item', 'item', ['sale_price' => 500, 'weight_kg' => 1, 'stock' => 10]);

        $response = $this->place([['slug' => 'item', 'quantity' => 2]], [
            'name' => 'Rahim',
            'phone' => '+8801712345678',
            'email' => 'rahim@example.test',
            'note' => 'Call first',
            'expected_total' => 1090, // 2 x 500 + 70 first kg + 20 for the second kg
        ])->assertCreated();

        $response->assertJsonPath('data.status', 'placed');
        $this->assertSame(1, Order::query()->count());

        $order = Order::query()->with('items')->sole();
        $this->assertSame(Order::SOURCE_STOREFRONT, $order->source);
        $this->assertEquals(1090, $order->total_amount);
        $this->assertEquals(90, $order->shipping_fee);
        $this->assertSame('inside', $order->shipping_zone);
        $this->assertSame(2, (int) $order->items->sole()->quantity);
        $this->assertEquals(500, $order->items->sole()->unit_price);

        $customer = Customer::query()->sole();
        $this->assertSame('01712345678', $customer->phone);
        $this->assertSame('Rahim', $customer->name);

        $summary = $response->json('data.order');
        $this->assertSame($order->order_number, $summary['order_number']);
        $this->assertSame(Signing::orderToken($order), $summary['token']);
        $this->assertEquals(1090, $summary['total_amount']);
        $this->assertSame('Item', $summary['items'][0]['name']);

        Queue::assertPushed(CheckExternalCourierFraudJob::class);
    }

    public function test_the_order_is_priced_with_the_deal_and_stock_is_taken_from_the_right_product(): void
    {
        $product = $this->product('Dealt', 'dealt', ['sale_price' => 500, 'stock' => 10]);
        $this->collection(Offer::KIND_FLASH_SALE, [[$product, 300]]);

        $this->place([['slug' => 'dealt', 'quantity' => 3]])->assertCreated();

        $order = Order::query()->with('items')->sole();
        $this->assertEquals(300, $order->items->sole()->unit_price);
        $this->assertEquals(900 + 110, $order->total_amount); // 3 kg: 70 + 2 x 20
    }

    public function test_a_changed_total_is_refused_with_a_fresh_quote_and_places_nothing(): void
    {
        $this->product('Item', 'item', ['sale_price' => 500]);

        $this->place([['slug' => 'item', 'quantity' => 1]], ['expected_total' => 100])
            ->assertStatus(409)
            ->assertJsonPath('error', 'price_changed')
            ->assertJsonPath('quote.total', 570);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_an_adjusted_or_unavailable_cart_is_refused_until_the_customer_has_seen_it(): void
    {
        $this->product('Wholesale', 'wholesale', ['moq' => 12, 'stock' => 100]);
        $this->product('Gone', 'gone', ['stock' => 0]);

        $this->place([['slug' => 'wholesale', 'quantity' => 2]])
            ->assertStatus(409)
            ->assertJsonPath('error', 'cart_changed')
            ->assertJsonPath('quote.issues.0.code', 'quantity_adjusted')
            ->assertJsonPath('quote.lines.0.quantity', 12);

        $this->place([['slug' => 'gone', 'quantity' => 1]])
            ->assertStatus(409)
            ->assertJsonPath('error', 'cart_unavailable');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_retry_with_the_same_client_request_id_never_places_a_second_order(): void
    {
        $this->product('Item', 'item', ['sale_price' => 500, 'stock' => 10]);

        $first = $this->place([['slug' => 'item', 'quantity' => 1]], ['client_request_id' => 'req-123'])->assertCreated();
        $second = $this->place([['slug' => 'item', 'quantity' => 1]], ['client_request_id' => 'req-123'])->assertOk();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame($first->json('data.order.order_number'), $second->json('data.order.order_number'));

        // A different request id is a different order.
        $this->place([['slug' => 'item', 'quantity' => 1]], ['client_request_id' => 'req-456'])->assertCreated();
        $this->assertSame(2, Order::query()->count());
    }

    public function test_the_visitors_ip_is_used_for_policy_never_the_next_js_servers(): void
    {
        $this->settings()->update(['checkout_policy_mode' => StorefrontSetting::CHECKOUT_POLICY_ENFORCE]);
        $this->product('Item', 'item', ['sale_price' => 500]);

        $this->place([['slug' => 'item', 'quantity' => 1]], ['client_ip' => '203.0.113.9'])->assertCreated();
        $this->assertNotNull(StorefrontCheckoutAttempt::query()->latest('id')->first()->ip_hash);

        // No forwarded IP: the request's own IP (the Next.js server) must not stand in for it.
        $this->place([['slug' => 'item', 'quantity' => 1]], ['phone' => '01712345600'])->assertCreated();
        $this->assertNull(StorefrontCheckoutAttempt::query()->latest('id')->first()->ip_hash);
    }

    public function test_the_checkout_policy_applies_to_api_orders(): void
    {
        $this->settings()->update(['checkout_policy_mode' => StorefrontSetting::CHECKOUT_POLICY_ENFORCE]);
        $this->product('Item', 'item', ['sale_price' => 500]);

        $this->place([['slug' => 'item', 'quantity' => 1]], ['phone' => '12345'])->assertStatus(422);

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(StorefrontCheckoutAttempt::OUTCOME_BLOCKED, StorefrontCheckoutAttempt::query()->sole()->outcome);
    }

    public function test_a_manual_send_money_payment_needs_the_sender_number_and_transaction_id(): void
    {
        $method = StorefrontPaymentMethod::query()->create([
            'type' => StorefrontPaymentMethod::TYPE_MANUAL, 'name' => 'bKash', 'account_number' => '01700000000', 'is_active' => true, 'sort_order' => 5,
        ]);
        $this->product('Item', 'item', ['sale_price' => 500]);
        $value = 'manual:'.$method->getKey();

        $this->place([['slug' => 'item', 'quantity' => 1]], ['payment_method' => $value])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sender_number', 'trx_id']);

        $this->place([['slug' => 'item', 'quantity' => 1]], ['payment_method' => $value, 'sender_number' => '01711111111', 'trx_id' => 'TX123'])
            ->assertCreated()
            ->assertJsonPath('data.order.payment.kind', 'manual');

        $payment = StorefrontPayment::query()->sole();
        $this->assertSame('manual', $payment->gateway);
        $this->assertSame($method->getKey(), $payment->storefront_payment_method_id);
    }

    public function test_an_unknown_payment_method_is_refused(): void
    {
        $this->product('Item', 'item');

        $this->place([['slug' => 'item', 'quantity' => 1]], ['payment_method' => 'bitcoin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');
    }

    // ------------------------------------------------------- hosted payments

    public function test_a_preorder_returns_a_payment_url_and_the_order_is_created_only_after_the_gateway_verifies(): void
    {
        $this->product('Coming', 'coming', ['stock' => 0, 'is_preorder' => true, 'preorder_advance_percent' => 50, 'sale_price' => 1000]);
        $this->fakeCreatePayment('https://pay.paystation.com.bd/checkout/preorder');

        $response = $this->place([['slug' => 'coming', 'quantity' => 2]])->assertOk();

        $response->assertJsonPath('data.status', 'payment_required')
            ->assertJsonPath('data.payment_url', 'https://pay.paystation.com.bd/checkout/preorder');
        $this->assertEquals(1000, $response->json('data.payment.amount'));
        $this->assertSame(0, Order::query()->count());

        $payment = StorefrontPayment::query()->sole();
        $this->assertSame(StorefrontPayment::PURPOSE_CHECKOUT_ADVANCE, $payment->purpose);

        // The gateway is told to come back to the Next.js site, carrying the signed ref.
        $callback = $this->paystationCallbackUrl();
        $this->assertStringContainsString('payment='.$payment->getKey(), $callback);

        // Wrong ref / wrong id: nothing happens.
        $this->finalize($payment->getKey(), 'not-the-ref')->assertStatus(404)->assertJsonPath('error', 'payment_not_found');
        $this->finalize($payment->getKey() + 99, Signing::paymentRef($payment))->assertStatus(404);
        $this->assertSame(0, Order::query()->count());

        // The gateway has not captured the money yet.
        $this->fakeRetrieve('Pending', 0);
        $this->finalize($payment->getKey(), Signing::paymentRef($payment))
            ->assertStatus(422)
            ->assertJsonPath('error', 'payment_not_completed');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_finalizing_a_verified_payment_places_the_order_once(): void
    {
        $this->product('Coming', 'coming', ['stock' => 0, 'is_preorder' => true, 'preorder_advance_percent' => 50, 'sale_price' => 1000]);
        $this->fakeCreatePayment('https://pay.paystation.com.bd/checkout/preorder');
        $this->place([['slug' => 'coming', 'quantity' => 2]])->assertOk();
        $payment = StorefrontPayment::query()->sole();

        $this->fakeRetrieve('Success', 1000, 'PS-1');

        $first = $this->finalize($payment->getKey(), Signing::paymentRef($payment), ['trx_id' => 'PS-1'])->assertOk();
        $first->assertJsonPath('data.status', 'placed');

        $order = Order::query()->sole();
        $this->assertEquals(1000, $order->paid_amount);
        $this->assertEquals(2090, $order->total_amount); // 2 x 1000 + 70 + 20 for the second kg
        $this->assertSame(Signing::orderToken($order), $first->json('data.order.token'));

        // Returning to the site twice (refresh, back button) must not place another order.
        $second = $this->finalize($payment->getKey(), Signing::paymentRef($payment), ['trx_id' => 'PS-1'])->assertOk();
        $this->assertSame($order->order_number, $second->json('data.order.order_number'));
        $this->assertSame(1, Order::query()->count());
    }

    public function test_a_retry_of_a_payment_required_checkout_returns_the_same_payment(): void
    {
        $this->product('Coming', 'coming', ['stock' => 0, 'is_preorder' => true, 'preorder_advance_percent' => 50, 'sale_price' => 1000]);
        $this->fakeCreatePayment('https://pay.paystation.com.bd/checkout/preorder');

        $first = $this->place([['slug' => 'coming', 'quantity' => 1]], ['client_request_id' => 'req-pay'])->assertOk();
        $second = $this->place([['slug' => 'coming', 'quantity' => 1]], ['client_request_id' => 'req-pay'])->assertOk();

        $this->assertSame($first->json('data.payment.id'), $second->json('data.payment.id'));
        $this->assertSame(1, StorefrontPayment::query()->count());
    }

    public function test_paying_the_full_order_online_is_a_normal_customer_choice(): void
    {
        StorefrontPaymentMethod::query()->updateOrCreate(
            ['type' => StorefrontPaymentMethod::TYPE_ONLINE_GATEWAY],
            ['name' => 'Pay Online', 'is_active' => true, 'sort_order' => 1],
        );
        $this->product('Item', 'item', ['sale_price' => 1000, 'weight_kg' => 1]);
        $this->fakeCreatePayment('https://pay.paystation.com.bd/checkout/full');

        $this->quote([['slug' => 'item', 'quantity' => 1]])
            ->assertJsonFragment(['type' => StorefrontPaymentMethod::TYPE_ONLINE_GATEWAY]);

        $response = $this->place([['slug' => 'item', 'quantity' => 1]], ['payment_method' => StorefrontPaymentMethod::TYPE_ONLINE_GATEWAY])->assertOk();

        $response->assertJsonPath('data.status', 'payment_required');
        $this->assertEquals(1070, $response->json('data.payment.amount'));
        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_new_customer_pays_the_delivery_fee_in_advance_when_the_owner_requires_it(): void
    {
        $this->settings()->update(['new_customer_delivery_advance_enabled' => true]);
        $this->product('Item', 'item', ['sale_price' => 1000, 'weight_kg' => 1]);
        $this->fakeCreatePayment('https://pay.paystation.com.bd/checkout/new-customer');

        $response = $this->place([['slug' => 'item', 'quantity' => 1]])->assertOk();

        $response->assertJsonPath('data.status', 'payment_required');
        $this->assertSame(['new_customer_delivery'], $response->json('data.payment.reasons'));
        $this->assertEquals(70, $response->json('data.payment.amount'));
        $this->assertNotEmpty($response->json('data.payment.message'));
        $this->assertSame(0, Order::query()->count());

        // A returning customer is not asked again. (The company context is cleared once an API request ends.)
        app(CompanyContext::class)->set($this->company);
        Customer::query()->create(['name' => 'Known', 'phone' => '01799990000', 'address' => 'Dhaka']);
        $this->place([['slug' => 'item', 'quantity' => 1]], ['phone' => '01799990000'])->assertCreated();
    }

    public function test_a_risky_courier_history_requires_the_configured_advance(): void
    {
        $this->settings()->update([
            'risk_payment_enabled' => true,
            'risk_payment_success_ratio_threshold' => 70,
            'risk_payment_advance_type' => StorefrontSetting::RISK_ADVANCE_PERCENT,
            'risk_payment_advance_percent' => 25,
        ]);
        $fraud = Mockery::mock(ExternalCourierFraudService::class);
        $fraud->shouldReceive('checkByPhone')->once()->andReturn([
            'pathao' => ['success' => 2, 'cancel' => 3, 'total' => 5],
            'overall_success_ratio' => 40,
        ]);
        $this->app->instance(ExternalCourierFraudService::class, $fraud);
        $this->product('Item', 'item', ['sale_price' => 1000, 'weight_kg' => 1]);
        $this->fakeCreatePayment('https://pay.paystation.com.bd/checkout/risk');
        Customer::query()->create(['name' => 'Known', 'phone' => '01799990000', 'address' => 'Dhaka']);

        $response = $this->place([['slug' => 'item', 'quantity' => 1]], ['phone' => '01799990000'])->assertOk();

        $response->assertJsonPath('data.status', 'payment_required');
        $this->assertContains('courier_low_success_ratio', $response->json('data.payment.reasons'));
        $this->assertEquals(267.5, $response->json('data.payment.amount')); // 25% of 1,070
        $this->assertSame(0, Order::query()->count());
    }

    // --------------------------------------------------------- return URLs

    public function test_return_and_cancel_urls_must_be_on_the_companys_own_next_js_site(): void
    {
        $this->product('Item', 'item');

        $this->place([['slug' => 'item', 'quantity' => 1]], ['return_url' => 'https://evil.example.test/steal'])
            ->assertStatus(422)->assertJsonValidationErrors('return_url');
        $this->place([['slug' => 'item', 'quantity' => 1]], ['cancel_url' => 'https://evil.example.test/steal'])
            ->assertStatus(422)->assertJsonValidationErrors('cancel_url');
        // https only on a production host, and the host must match exactly (no look-alike suffix).
        $this->place([['slug' => 'item', 'quantity' => 1]], ['return_url' => 'http://zamzamint.com/back'])
            ->assertStatus(422)->assertJsonValidationErrors('return_url');
        $this->place([['slug' => 'item', 'quantity' => 1]], ['return_url' => 'https://zamzamint.com.evil.test/back'])
            ->assertStatus(422)->assertJsonValidationErrors('return_url');

        // A local Next.js dev server is allowed outside production.
        $this->place([['slug' => 'item', 'quantity' => 1]], ['return_url' => 'http://localhost:3000/checkout/payment/return', 'cancel_url' => 'http://localhost:3000/cart'])
            ->assertCreated();

        $this->assertSame(1, Order::query()->count());
    }

    // --------------------------------------------------------------- lookup

    public function test_an_order_can_only_be_read_with_its_own_token_and_only_by_its_company(): void
    {
        $this->product('Item', 'item', ['sale_price' => 500]);
        $summary = $this->place([['slug' => 'item', 'quantity' => 1]])->assertCreated()->json('data.order');
        $number = $summary['order_number'];

        $this->getOrder($number, $summary['token'])
            ->assertOk()
            ->assertJsonPath('data.order_number', $number)
            ->assertJsonPath('data.items.0.name', 'Item');

        $this->getOrder($number, null)->assertStatus(404)->assertJsonPath('error', 'order_not_found');
        $this->getOrder($number, 'wrong')->assertStatus(404);
        $this->getOrder('NOPE-1', $summary['token'])->assertStatus(404);

        // Another company's key cannot read it even with the right token.
        [, $otherToken] = $this->makeCompany('Other Co', 'other-co', 'other.example.test');
        $this->getOrder($number, $summary['token'], $otherToken)->assertStatus(404);
    }

    public function test_another_companys_products_and_payments_are_invisible(): void
    {
        [$other, $otherToken] = $this->makeCompany('Other Co', 'other-co', 'other.example.test');
        app(CompanyContext::class)->set($other);
        $this->product('Foreign', 'foreign', ['sale_price' => 100]);
        app(CompanyContext::class)->set($this->company);
        $this->product('Mine', 'mine', ['sale_price' => 500, 'stock' => 0, 'is_preorder' => true, 'preorder_advance_percent' => 50]);
        $this->fakeCreatePayment('https://pay.paystation.com.bd/checkout/mine');

        $quote = $this->quote([['slug' => 'foreign', 'quantity' => 1]])->assertOk()->json('data');
        $this->assertSame('unavailable', $quote['issues'][0]['code']);
        $this->assertSame([], $quote['lines']);

        $this->place([['slug' => 'foreign', 'quantity' => 1]])->assertStatus(409)->assertJsonPath('error', 'cart_unavailable');

        $this->place([['slug' => 'mine', 'quantity' => 1]])->assertOk();
        $payment = StorefrontPayment::query()->sole();

        $this->call('POST', '/api/v1/storefront/checkout/payments/'.$payment->getKey().'/finalize', ['ref' => Signing::paymentRef($payment)], [], [], $this->server($otherToken))
            ->assertStatus(404);
    }

    // ----------------------------------------------------------------- helpers

    /** @param  list<array<string, mixed>>  $items */
    protected function quote(array $items, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/storefront/checkout/quote', ['items' => $items] + $extra, $this->headers());
    }

    /** @param  list<array<string, mixed>>  $items */
    protected function place(array $items, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/storefront/checkout', array_merge([
            'items' => $items,
            'name' => 'Karim',
            'phone' => '01712345678',
            'address' => 'House 1, Dhanmondi, Dhaka',
            'payment_method' => 'cod',
            'return_url' => self::RETURN_URL,
            'cancel_url' => self::CANCEL_URL,
        ], $overrides), $this->headers());
    }

    protected function finalize(int $paymentId, string $ref, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/storefront/checkout/payments/'.$paymentId.'/finalize', ['ref' => $ref] + $extra, $this->headers());
    }

    protected function getOrder(string $number, ?string $orderToken, ?string $apiToken = null): TestResponse
    {
        return $this->getJson('/api/v1/storefront/orders/'.$number.($orderToken ? '?token='.$orderToken : ''), $this->headers($apiToken));
    }

    /** @return array<string, string> */
    protected function headers(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token)];
    }

    /** @return array<string, string> */
    protected function server(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json'];
    }

    protected function settings(): StorefrontSetting
    {
        return StorefrontSetting::query()->where('company_id', $this->company->getKey())->firstOrFail();
    }

    protected function fakeCreatePayment(string $url): void
    {
        Http::fake([
            'api.paystation.com.bd/grant-token' => Http::response(['status_code' => '200', 'status' => 'success', 'token' => 'tok-abc']),
            'api.paystation.com.bd/create-payment' => Http::response(['status_code' => 200, 'status' => 'success', 'payment_url' => $url]),
        ]);
    }

    protected function fakeRetrieve(string $status, float $amount, string $trxId = 'PS-0'): void
    {
        Http::fake([
            'api.paystation.com.bd/retrive-transaction' => Http::response([
                'status_code' => 200,
                'status' => 'success',
                'trx_status' => $status,
                'payment_amount' => (string) $amount,
                'trx_id' => $trxId,
                'payment_method' => 'bkash',
            ]),
        ]);
    }

    /** The callback URL the gateway was given when the hosted payment was created. */
    protected function paystationCallbackUrl(): string
    {
        $url = '';
        Http::assertSent(function ($request) use (&$url): bool {
            if (! str_contains($request->url(), '/create-payment')) {
                return false;
            }

            $url = (string) $request['callback_url'];

            return true;
        });

        return $url;
    }

    /** @return array{0: Company, 1: string} */
    protected function makeCompany(string $name, string $slug, string $domain, bool $enabled = true): array
    {
        $company = Company::query()->create([
            'name' => $name,
            'slug' => $slug,
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => strtoupper(substr($slug, 0, 3)),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'is_published' => true,
            'nextjs_site_enabled' => $enabled,
            'nextjs_site_domain' => $slug === 'zamzam-international' ? 'zamzamint.com' : null,
            'new_customer_delivery_advance_enabled' => false,
            'delivery_first_kg_inside' => 70,
            'delivery_first_kg_outside' => 110,
            'delivery_additional_per_kg' => 20,
            'online_payment_enabled' => true,
            'online_payment_gateway' => 'paystation',
            'payment_credentials' => ['paystation_merchant_id' => 'test-mid', 'paystation_password' => 'test-pass'],
        ]);

        [, $token] = CompanyApiKey::generate($company);

        return [$company->fresh(), $token];
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

    /** @param  list<array{0: Product, 1: float|int}>  $deals */
    protected function collection(string $kind, array $deals): Offer
    {
        $offer = Offer::query()->create([
            'company_id' => $this->company->getKey(),
            'type' => Offer::TYPE_COLLECTION,
            'collection_kind' => $kind,
            'title' => 'Deals',
            'status' => Offer::STATUS_PUBLISHED,
            'ends_at' => now()->addDay(),
        ]);

        foreach ($deals as $index => [$product, $price]) {
            OfferItem::query()->create([
                'company_id' => $this->company->getKey(),
                'offer_id' => $offer->getKey(),
                'product_id' => $product->getKey(),
                'quantity' => 1,
                'deal_price' => $price,
                'sort_order' => $index,
            ]);
        }

        return $offer;
    }
}
