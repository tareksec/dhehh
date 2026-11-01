<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\StorefrontPayment;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The payment webhooks are public, so whatever invoice id they carry is
 * caller-controlled. A payment is only ever settled by the invoice stored for it
 * when it was created, and one paid gateway invoice settles one payment.
 */
class PaymentWebhookReplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_a_paid_invoice_named_in_the_webhook_body_cannot_settle_another_payment(): void
    {
        $company = $this->createStore('replay.example.test');
        $victim = $this->createPayment($company, 'INV-VICTIM', 500);

        // The gateway reports INV-ATTACKER (the attacker's own, genuinely paid
        // 500 invoice) as completed, and INV-VICTIM as still unpaid.
        Http::fake(function ($request) {
            $invoice = $request['invoice_id'] ?? null;

            return Http::response($invoice === 'INV-ATTACKER'
                ? ['status' => 'COMPLETED', 'amount' => 500, 'invoice_id' => 'INV-ATTACKER', 'transaction_id' => 'TRX-ATTACKER']
                : ['status' => 'PENDING', 'amount' => 500, 'invoice_id' => (string) $invoice]);
        });

        $this->postJson('http://replay.example.test/webhooks/zinipay/'.$victim->getKey(), [
            'invoice_id' => 'INV-ATTACKER',
            'status' => 'true',
        ]);

        $victim->refresh();
        $this->assertSame(StorefrontPayment::STATUS_PENDING, $victim->status);
        $this->assertSame('INV-VICTIM', $victim->invoice_id);
        Http::assertSent(fn ($request) => ($request['invoice_id'] ?? null) === 'INV-VICTIM');
        Http::assertNotSent(fn ($request) => ($request['invoice_id'] ?? null) === 'INV-ATTACKER');
    }

    public function test_the_stored_invoice_is_still_verified_and_completes_the_payment(): void
    {
        $company = $this->createStore('legit.example.test');
        $payment = $this->createPayment($company, 'INV-LEGIT', 500);

        Http::fake([
            'api.zinipay.com/v1/payment/verify' => Http::response([
                'status' => 'COMPLETED', 'amount' => 500, 'invoice_id' => 'INV-LEGIT', 'transaction_id' => 'TRX-1',
            ]),
        ]);

        $this->postJson('http://legit.example.test/webhooks/zinipay/'.$payment->getKey(), ['invoice_id' => 'INV-LEGIT'])->assertOk();

        $this->assertSame(StorefrontPayment::STATUS_COMPLETED, $payment->refresh()->status);
        $this->assertSame('TRX-1', $payment->transaction_id);
    }

    public function test_a_gateway_transaction_that_already_settled_a_payment_cannot_settle_a_second_one(): void
    {
        $company = $this->createStore('twice.example.test');
        $first = $this->createPayment($company, 'INV-A', 500);
        $second = $this->createPayment($company, 'INV-B', 500);

        // A gateway answering both invoices with the same completed transaction.
        Http::fake([
            'api.zinipay.com/v1/payment/verify' => Http::response([
                'status' => 'COMPLETED', 'amount' => 500, 'transaction_id' => 'TRX-SHARED',
            ]),
        ]);

        $this->postJson('http://twice.example.test/webhooks/zinipay/'.$first->getKey(), [])->assertOk();
        $this->postJson('http://twice.example.test/webhooks/zinipay/'.$second->getKey(), [])->assertOk();

        $this->assertSame(StorefrontPayment::STATUS_COMPLETED, $first->refresh()->status);
        $this->assertSame(StorefrontPayment::STATUS_PENDING, $second->refresh()->status);
    }

    public function test_a_payment_with_no_stored_invoice_accepts_the_supplied_one_once(): void
    {
        $company = $this->createStore('blank.example.test');
        $payment = $this->createPayment($company, '', 500);
        $other = $this->createPayment($company, 'INV-OTHER', 500);
        $other->update(['status' => StorefrontPayment::STATUS_COMPLETED, 'invoice_id' => 'INV-REUSED']);

        Http::fake([
            'api.zinipay.com/v1/payment/verify' => Http::response([
                'status' => 'COMPLETED', 'amount' => 500, 'transaction_id' => 'TRX-NEW',
            ]),
        ]);

        // INV-REUSED already settled another payment: refused.
        $this->postJson('http://blank.example.test/webhooks/zinipay/'.$payment->getKey(), ['invoice_id' => 'INV-REUSED'])->assertOk();
        $this->assertSame(StorefrontPayment::STATUS_PENDING, $payment->refresh()->status);

        // A fresh invoice is accepted.
        $this->postJson('http://blank.example.test/webhooks/zinipay/'.$payment->getKey(), ['invoice_id' => 'INV-FRESH'])->assertOk();
        $this->assertSame(StorefrontPayment::STATUS_COMPLETED, $payment->refresh()->status);
        $this->assertSame('INV-FRESH', $payment->invoice_id);
    }

    public function test_an_amount_mismatch_never_completes_the_payment(): void
    {
        $company = $this->createStore('amount.example.test');
        $payment = $this->createPayment($company, 'INV-AMT', 500);

        Http::fake([
            'api.zinipay.com/v1/payment/verify' => Http::response(['status' => 'COMPLETED', 'amount' => 50, 'transaction_id' => 'TRX-LOW']),
        ]);

        $this->postJson('http://amount.example.test/webhooks/zinipay/'.$payment->getKey(), ['invoice_id' => 'INV-AMT'])->assertOk();

        $this->assertSame(StorefrontPayment::STATUS_PENDING, $payment->refresh()->status);
    }

    private function createPayment(Company $company, string $invoice, float $amount): StorefrontPayment
    {
        app(CompanyContext::class)->set($company);

        $order = Order::query()->create(['customer_name' => 'Buyer', 'status' => 'draft', 'source' => Order::SOURCE_STOREFRONT]);

        return StorefrontPayment::query()->create([
            'company_id' => $company->getKey(),
            'order_id' => $order->getKey(),
            'gateway' => 'zinipay',
            'invoice_id' => $invoice,
            'amount' => $amount,
            'status' => StorefrontPayment::STATUS_PENDING,
        ]);
    }

    private function createStore(string $domain): Company
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
            'online_payment_gateway' => 'zinipay',
            'payment_credentials' => ['zinipay_api_key' => 'test_key'],
        ]);

        return $company;
    }
}
