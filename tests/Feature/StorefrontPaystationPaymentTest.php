<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\StorefrontPayment;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\StorefrontCart;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StorefrontPaystationPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    /**
     * The highest-risk regression this phase introduces: PayStation redirects
     * the browser back to the signed callback_url with invoice_number/trx_id
     * appended, which were never part of the originally-signed query string.
     * This exercises hasValidSignatureWhileIgnoring() end-to-end, not just
     * its own unit behaviour.
     */
    public function test_preorder_checkout_via_paystation_creates_order_only_after_the_signed_return_is_verified(): void
    {
        $company = $this->createStore('paystation.example.test', gateway: 'paystation');

        app(CompanyContext::class)->set($company);

        $product = $this->createProduct('PayStation Preorder', 'PS-PRE-01', [
            'stock' => 0,
            'is_preorder' => true,
            'preorder_advance_percent' => 50,
            'sale_price' => 1000,
        ]);

        Http::fake([
            'api.paystation.com.bd/grant-token' => Http::response(['status_code' => '200', 'status' => 'success', 'token' => 'tok-abc']),
            'api.paystation.com.bd/create-payment' => Http::response([
                'status_code' => 200,
                'status' => 'success',
                'payment_url' => 'https://pay.paystation.com.bd/checkout/abc123',
            ]),
        ]);

        app(StorefrontCart::class)->add($company, $product, 2);

        $response = $this->post('http://paystation.example.test/checkout', [
            'name' => 'PayStation Buyer',
            'phone' => '01712341234',
            'address' => 'Dhaka',
        ]);

        $response->assertRedirect('https://pay.paystation.com.bd/checkout/abc123');

        $payment = StorefrontPayment::withoutGlobalScopes()->first();
        $this->assertNotNull($payment);
        $this->assertSame(1000.0, (float) $payment->amount); // 2 x 1000 x 50%
        $this->assertSame('pending', $payment->status);
        $this->assertSame('paystation', $payment->gateway);
        // The invoice number is no longer just the payment's raw primary
        // key (see CheckoutController::startCheckoutAdvancePayment()'s
        // comment: a local table reset can restart that sequence and PayStation
        // rejects a number it already saw as a duplicate) — it now carries a
        // random suffix, but still starts with the payment id for traceability.
        $this->assertStringStartsWith($payment->getKey().'-', $payment->invoice_id);
        $this->assertSame(0, Order::withoutGlobalScopes()->count());

        $capturedCallbackUrl = null;
        $capturedInvoiceNumber = null;

        Http::assertSent(function ($request) use (&$capturedCallbackUrl, &$capturedInvoiceNumber, $payment): bool {
            if (! str_contains($request->url(), '/create-payment')) {
                return false;
            }

            $capturedCallbackUrl = (string) $request['callback_url'];
            $capturedInvoiceNumber = (string) $request['invoice_number'];

            return str_starts_with($capturedInvoiceNumber, $payment->getKey().'-')
                && $request['cust_phone'] === '01712341234'
                && $request['cust_address'] === 'Dhaka'
                && $request['currency'] === 'BDT'
                && str_contains($capturedCallbackUrl, '/checkout/payment/')
                && str_contains($capturedCallbackUrl, 'signature=');
        });

        $this->assertNotNull($capturedCallbackUrl);
        $this->assertSame($payment->invoice_id, $capturedInvoiceNumber);

        Http::fake([
            'api.paystation.com.bd/grant-token' => Http::response(['token' => 'tok-abc']),
            'api.paystation.com.bd/retrive-transaction' => Http::response([
                'status_code' => 200,
                'status' => 'success',
                'trx_status' => 'Success',
                'payment_amount' => '1000',
                'trx_id' => 'PS-TRX-1',
                'payment_method' => 'bkash',
            ]),
        ]);

        // Simulate PayStation's real redirect: it appends invoice_number and
        // trx_id onto the callback_url we sent, which already carries its
        // own ?expires=&signature= query string.
        $returnUrl = $capturedCallbackUrl.'&invoice_number='.$capturedInvoiceNumber.'&trx_id=PS-TRX-1';

        $this->get($returnUrl)->assertRedirectContains('/checkout/success/');

        $order = Order::withoutGlobalScopes()->sole();
        $this->assertSame(Order::SOURCE_STOREFRONT, $order->source);
        $this->assertSame(1000.0, (float) $order->paid_amount);
        $this->assertSame($order->getKey(), $payment->fresh()->order_id);
        $this->assertSame('PS-TRX-1', $payment->fresh()->transaction_id);
        $this->assertSame('bkash', $payment->fresh()->payment_method);
    }

    public function test_a_return_missing_the_original_signature_is_still_rejected(): void
    {
        $company = $this->createStore('badsig.example.test', gateway: 'paystation');

        app(CompanyContext::class)->set($company);

        $payment = StorefrontPayment::query()->create([
            'company_id' => $company->getKey(),
            'gateway' => 'paystation',
            'invoice_id' => '1',
            'amount' => 500,
            'status' => StorefrontPayment::STATUS_PENDING,
        ]);

        // hasValidSignatureWhileIgnoring only ignores invoice_number/trx_id/
        // status/cancel — a request with no signature at all (or a tampered
        // one) must still be rejected, proving the fix didn't just disable
        // signing. It's now a friendly redirect back to checkout instead of
        // a raw 403 (see CheckoutController::paymentReturn), but the payment
        // itself must still not be finalized.
        $this->get('http://badsig.example.test/checkout/payment/'.$payment->getKey().'/return?invoice_number=1&trx_id=X')
            ->assertRedirect('http://badsig.example.test/checkout')
            ->assertSessionHasErrors('payment');

        $this->assertSame(StorefrontPayment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_paystation_webhook_fallback_marks_payment_completed_after_verification(): void
    {
        $company = $this->createStore('hook.paystation.example.test', gateway: 'paystation');

        app(CompanyContext::class)->set($company);

        $order = Order::query()->create([
            'customer_name' => 'Hook Buyer',
            'status' => 'draft',
            'source' => Order::SOURCE_STOREFRONT,
        ]);

        $payment = StorefrontPayment::query()->create([
            'company_id' => $company->getKey(),
            'order_id' => $order->getKey(),
            'gateway' => 'paystation',
            'invoice_id' => '1',
            'amount' => 500,
            'status' => StorefrontPayment::STATUS_PENDING,
        ]);

        Http::fake([
            'api.paystation.com.bd/grant-token' => Http::response(['token' => 'tok-abc']),
            'api.paystation.com.bd/retrive-transaction' => Http::response([
                'status_code' => 200, 'status' => 'success', 'trx_status' => 'Success',
                'payment_amount' => '500', 'trx_id' => 'TRX999', 'payment_method' => 'nagad',
            ]),
        ]);

        $this->postJson('http://hook.paystation.example.test/webhooks/paystation/'.$payment->getKey(), [
            'invoice_number' => '1',
            'trx_id' => 'TRX999',
        ])->assertOk();

        $payment->refresh();
        $this->assertSame(StorefrontPayment::STATUS_COMPLETED, $payment->status);
        $this->assertSame('TRX999', $payment->transaction_id);
        $this->assertSame('nagad', $payment->payment_method);
    }

    /**
     * Regression test for the isConfigured() bug found while planning this
     * feature: a company that switched its selected gateway to PayStation
     * but left stale ZiniPay credentials in the same JSON blob must not be
     * told payment is available when PayStation itself has no credentials.
     */
    public function test_online_payment_is_unavailable_when_the_selected_gateway_lacks_credentials_even_if_another_gateways_stale_keys_remain(): void
    {
        $company = Company::query()->create([
            'name' => 'Stale Gateway Co', 'slug' => 'stale-gateway-co',
            'domain' => 'stale.example.test', 'domain_verified' => true,
            'invoice_prefix' => 'SGC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'theme_color' => '#0F766E',
            'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
            'online_payment_enabled' => true,
            'online_payment_gateway' => 'paystation',
            // Stale ZiniPay key left over from before switching gateways —
            // PayStation's own credentials are missing.
            'payment_credentials' => ['zinipay_api_key' => 'old_key'],
        ]);

        app(CompanyContext::class)->set($company);

        $product = $this->createProduct('Stale Gateway Item', 'STALE-01', ['stock' => 0, 'is_preorder' => true]);
        app(StorefrontCart::class)->add($company, $product, 1);

        $this->get('http://stale.example.test/checkout')
            ->assertOk()
            ->assertSee('Online payment is currently unavailable.');
    }

    private function createProduct(string $name, string $sku, array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'name' => $name,
            'sku' => $sku,
            'price' => 1100,
            'sale_price' => 1000,
            'cost_price' => 600,
            'stock' => 10,
            'unit' => 'pcs',
            'reorder_level' => 2,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ], $overrides));
    }

    public function test_the_webhook_uses_the_gateway_keys_of_the_site_the_payment_was_started_on(): void
    {
        // The Blade row only has ZiniPay; the Next.js site has its own PayStation keys.
        $company = $this->createStore('multi.paystation.example.test');
        app(CompanyContext::class)->set($company);
        $nextjs = StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'site_type' => StorefrontSetting::SITE_NEXTJS,
            'nextjs_site_enabled' => true,
            'online_payment_enabled' => true,
            'online_payment_gateway' => 'paystation',
            'payment_credentials' => ['paystation_merchant_id' => 'next-mid', 'paystation_password' => 'next-pass'],
        ]);
        $order = Order::query()->create(['customer_name' => 'Hook Buyer', 'status' => 'draft', 'source' => Order::SOURCE_STOREFRONT]);
        $payment = fn (?int $site, string $invoice): StorefrontPayment => StorefrontPayment::query()->create([
            'company_id' => $company->getKey(),
            'storefront_setting_id' => $site,
            'order_id' => $order->getKey(),
            'gateway' => 'paystation',
            'invoice_id' => $invoice,
            'amount' => 500,
            'status' => StorefrontPayment::STATUS_PENDING,
        ]);
        $fromNextjs = $payment($nextjs->getKey(), '1');
        $fromBlade = $payment(null, '2');

        Http::fake([
            'api.paystation.com.bd/grant-token' => Http::response(['token' => 'tok-abc']),
            'api.paystation.com.bd/retrive-transaction' => Http::response([
                'status_code' => 200, 'status' => 'success', 'trx_status' => 'Success',
                'payment_amount' => '500', 'trx_id' => 'TRX1', 'payment_method' => 'nagad',
            ]),
        ]);

        $this->postJson('http://multi.paystation.example.test/webhooks/paystation/'.$fromNextjs->getKey(), ['invoice_number' => '1', 'trx_id' => 'TRX1'])->assertOk();
        $this->assertSame(StorefrontPayment::STATUS_COMPLETED, $fromNextjs->refresh()->status);

        // A payment of the Blade storefront is verified with the Blade row, which has no PayStation keys.
        $this->postJson('http://multi.paystation.example.test/webhooks/paystation/'.$fromBlade->getKey(), ['invoice_number' => '2', 'trx_id' => 'TRX2'])->assertStatus(422);
        $this->assertSame(StorefrontPayment::STATUS_PENDING, $fromBlade->refresh()->status);
    }

    private function createStore(string $domain, string $gateway = 'zinipay'): Company
    {
        $company = Company::query()->create([
            'name' => 'Store '.$domain,
            'slug' => str($domain)->slug()->toString(),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => str($domain)->slug()->substr(0, 4)->upper()->toString(),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'theme_color' => '#0F766E',
            'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
            'online_payment_enabled' => true,
            'online_payment_gateway' => $gateway,
            'payment_credentials' => $gateway === 'paystation'
                ? ['paystation_merchant_id' => 'test-mid', 'paystation_password' => 'test-pass']
                : ['zinipay_api_key' => 'test_key'],
        ]);

        return $company;
    }
}
