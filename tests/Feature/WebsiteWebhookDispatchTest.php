<?php

namespace Tests\Feature;

use App\Jobs\DispatchWebsiteWebhookJob;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\WebsiteWebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebsiteWebhookDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Tasneem Knitting',
            'slug' => 'tasneem-knitting',
            'invoice_prefix' => 'TK',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(),
            'website_api_credentials' => [
                'webhook_url' => 'https://example.test/webhooks/zamzam',
                'webhook_secret' => 'a-real-secret',
                'is_enabled' => true,
            ],
        ]);
    }

    public function test_order_status_change_dispatches_a_webhook(): void
    {
        Queue::fake();

        app(CompanyContext::class)->set($this->company);
        $customer = Customer::query()->create([
            'name' => 'Test Customer',
            'phone' => '01711223344',
            'customer_type' => 'regular',
            'customer_source' => 'other',
            'opening_balance' => 0,
            'is_active' => true,
        ]);
        $order = Order::query()->create([
            'customer_id' => $customer->getKey(),
            'customer_name' => 'Test Customer',
            'order_date' => now()->toDateString(),
            'status' => Order::STATUS_DRAFT,
            'source' => Order::SOURCE_ADMIN,
            'paid_amount' => 0,
            'discount' => 0,
            'vat' => 0,
        ]);

        Queue::assertNotPushed(DispatchWebsiteWebhookJob::class);

        $order->update(['status' => Order::STATUS_CONFIRMED]);

        Queue::assertPushed(DispatchWebsiteWebhookJob::class, fn (DispatchWebsiteWebhookJob $job): bool => $job->event === 'order.status_updated'
            && $job->data['status'] === Order::STATUS_CONFIRMED);
    }

    public function test_admin_side_price_change_dispatches_a_webhook(): void
    {
        Queue::fake();

        app(CompanyContext::class)->set($this->company);
        $product = Product::query()->create([
            'name' => 'Cotton Yarn', 'sku' => 'YARN-001', 'price' => 500,
            'sale_price' => 480, 'cost_price' => 400, 'stock' => 0, 'unit' => 'kg',
            'reorder_level' => 5, 'vat_rate' => 0, 'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);

        Queue::fake();
        $product->update(['price' => 550]);

        Queue::assertPushed(DispatchWebsiteWebhookJob::class, fn (DispatchWebsiteWebhookJob $job): bool => $job->event === 'product.updated'
            && $job->data['price'] === 550.0);
    }

    public function test_admin_side_stock_adjustment_dispatches_a_webhook(): void
    {
        app(CompanyContext::class)->set($this->company);
        $product = Product::query()->create([
            'name' => 'Cotton Yarn', 'sku' => 'YARN-001', 'price' => 500,
            'sale_price' => 480, 'cost_price' => 400, 'stock' => 0, 'unit' => 'kg',
            'reorder_level' => 5, 'vat_rate' => 0, 'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);

        Queue::fake();
        $product->setStockFromProductForm(50);

        Queue::assertPushed(DispatchWebsiteWebhookJob::class, fn (DispatchWebsiteWebhookJob $job): bool => $job->event === 'product.updated'
            && $job->data['stock'] === 50);
    }

    /**
     * The anti-echo requirement: a stock change the website itself just made
     * through the API must not immediately trigger a webhook back to that
     * same website.
     */
    public function test_api_originated_stock_change_does_not_echo_a_webhook(): void
    {
        app(CompanyContext::class)->set($this->company);
        $product = Product::query()->create([
            'name' => 'Cotton Yarn', 'sku' => 'YARN-001', 'price' => 500,
            'sale_price' => 480, 'cost_price' => 400, 'stock' => 0, 'unit' => 'kg',
            'reorder_level' => 5, 'vat_rate' => 0, 'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);
        [$apiKey] = CompanyApiKey::generate($this->company);

        Queue::fake();
        $product->setStockFromProductForm(
            50,
            referenceType: CompanyApiKey::class,
            referenceId: $apiKey->getKey(),
            reason: 'Website API sync',
        );

        Queue::assertNotPushed(DispatchWebsiteWebhookJob::class);
    }

    public function test_disabled_integration_never_dispatches(): void
    {
        StorefrontSetting::withoutGlobalScopes()->where('company_id', $this->company->getKey())->first()->update([
            'website_api_credentials' => [
                'webhook_url' => 'https://example.test/webhooks/zamzam',
                'webhook_secret' => 'a-real-secret',
                'is_enabled' => false,
            ],
        ]);

        app(CompanyContext::class)->set($this->company);
        $product = Product::query()->create([
            'name' => 'Cotton Yarn', 'sku' => 'YARN-001', 'price' => 500,
            'sale_price' => 480, 'cost_price' => 400, 'stock' => 0, 'unit' => 'kg',
            'reorder_level' => 5, 'vat_rate' => 0, 'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);

        Queue::fake();
        $product->update(['price' => 999]);

        Queue::assertNotPushed(DispatchWebsiteWebhookJob::class);
    }

    public function test_website_lead_status_change_dispatches_a_webhook(): void
    {
        app(CompanyContext::class)->set($this->company);
        $lead = Lead::query()->create([
            'name' => 'Karim Traders', 'phone' => '01811223344', 'source' => 'website',
            'external_reference' => 'TK-QUOTE-123456', 'status' => 'new',
        ]);

        Queue::fake();
        $lead->update(['status' => 'quoted']);

        Queue::assertPushed(DispatchWebsiteWebhookJob::class, fn (DispatchWebsiteWebhookJob $job): bool => $job->event === 'lead.status_updated'
            && $job->data['external_reference'] === 'TK-QUOTE-123456'
            && $job->data['status'] === 'quoted'
            && $job->data['id'] === $lead->getKey());
    }

    public function test_lead_without_a_website_reference_never_dispatches(): void
    {
        app(CompanyContext::class)->set($this->company);
        $lead = Lead::query()->create([
            'name' => 'Walk-in buyer', 'phone' => '01811223344', 'source' => 'walk_in', 'status' => 'new',
        ]);

        Queue::fake();
        $lead->update(['status' => 'contacted']);

        Queue::assertNotPushed(DispatchWebsiteWebhookJob::class);
    }

    public function test_lead_edit_without_a_status_change_never_dispatches(): void
    {
        app(CompanyContext::class)->set($this->company);
        $lead = Lead::query()->create([
            'name' => 'Karim Traders', 'phone' => '01811223344', 'source' => 'website',
            'external_reference' => 'TK-QUOTE-123456', 'status' => 'new',
        ]);

        Queue::fake();
        $lead->update(['note' => 'Called, will send catalog']);

        Queue::assertNotPushed(DispatchWebsiteWebhookJob::class);
    }

    public function test_every_site_with_a_webhook_is_notified_and_a_shared_endpoint_only_once(): void
    {
        $shared = StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(),
            'site_type' => StorefrontSetting::SITE_NEXTJS,
            'website_api_credentials' => [
                'webhook_url' => 'https://example.test/webhooks/zamzam',
                'webhook_secret' => 'a-real-secret',
                'is_enabled' => true,
            ],
        ]);

        Queue::fake();
        app(WebsiteWebhookDispatcher::class)->dispatch($this->company->getKey(), 'ping', []);
        Queue::assertPushed(DispatchWebsiteWebhookJob::class, 1);

        $shared->update(['website_api_credentials' => [
            'webhook_url' => 'https://nextjs.example.test/api/erp/webhook',
            'webhook_secret' => 'nextjs-secret',
            'is_enabled' => true,
        ]]);

        Queue::fake();
        app(WebsiteWebhookDispatcher::class)->dispatch($this->company->getKey(), 'ping', []);
        Queue::assertPushed(DispatchWebsiteWebhookJob::class, 2);
    }
}
