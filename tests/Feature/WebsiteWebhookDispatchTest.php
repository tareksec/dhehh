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
use App\Models\WebsiteWebhookDelivery;
use App\Services\CompanyContext;
use App\Services\WebsiteWebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    protected function makeProduct(array $attributes = []): Product
    {
        app(CompanyContext::class)->set($this->company);

        return Product::query()->create([
            'name' => 'TEST Rib Machine', 'sku' => 'TEST-SYNC-002', 'price' => 750000,
            'sale_price' => 750000, 'cost_price' => 0, 'stock' => 0, 'unit' => 'pcs',
            'reorder_level' => 0, 'vat_rate' => 0, 'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
            ...$attributes,
        ]);
    }

    public function test_creating_a_product_dispatches_a_delayed_product_created_with_the_full_product(): void
    {
        Queue::fake();

        $product = $this->makeProduct(['brand' => 'Yu Yuan', 'specifications' => ['Gauge' => '18G']]);

        Queue::assertPushed(DispatchWebsiteWebhookJob::class, fn (DispatchWebsiteWebhookJob $job): bool => $job->event === 'product.created'
            && $job->productId === $product->getKey()
            && $job->companyId === $this->company->getKey()
            && $job->data['brand'] === 'Yu Yuan'
            && $job->data['specifications'] === [['label' => 'Gauge', 'value' => '18G']]
            && $job->data['changed_fields'] === WebsiteWebhookDispatcher::ALL_PRODUCT_FIELDS
            && $job->delay !== null);
        Queue::assertPushed(DispatchWebsiteWebhookJob::class, 1);
    }

    /**
     * The product form saves opening stock right after the insert; that
     * change rides on the pending product.created (built at send time)
     * instead of reaching the website before the product exists there.
     */
    public function test_a_change_while_product_created_is_pending_is_not_sent_separately(): void
    {
        config(['queue.default' => 'redis']);
        Queue::fake();

        $product = $this->makeProduct();
        $product->setStockFromProductForm(3);
        $product->update(['name' => 'TEST Rib Machine v2']);

        Queue::assertPushed(DispatchWebsiteWebhookJob::class, 1);
        Queue::assertPushed(DispatchWebsiteWebhookJob::class, fn (DispatchWebsiteWebhookJob $job): bool => $job->event === 'product.created');
    }

    public function test_editing_a_product_sends_only_the_changed_fields_as_changed(): void
    {
        $product = $this->makeProduct();

        Queue::fake();
        $product->update(['name' => 'TEST Rib Machine v2', 'gallery_images' => ['a.webp']]);

        Queue::assertPushed(DispatchWebsiteWebhookJob::class, fn (DispatchWebsiteWebhookJob $job): bool => $job->event === 'product.updated'
            && $job->data['name'] === 'TEST Rib Machine v2'
            && $job->data['changed_fields'] === ['name', 'image', 'images']);
    }

    public function test_archiving_a_product_sends_is_active_false(): void
    {
        $product = $this->makeProduct();

        Queue::fake();
        $product->update(['is_active' => false]);

        Queue::assertPushed(DispatchWebsiteWebhookJob::class, fn (DispatchWebsiteWebhookJob $job): bool => $job->event === 'product.updated'
            && $job->data['is_active'] === false
            && $job->data['changed_fields'] === ['is_active']);
    }

    public function test_a_stock_movement_marks_only_stock_as_changed(): void
    {
        $product = $this->makeProduct();

        Queue::fake();
        $product->setStockFromProductForm(4);

        Queue::assertPushed(DispatchWebsiteWebhookJob::class, fn (DispatchWebsiteWebhookJob $job): bool => $job->event === 'product.updated'
            && $job->data['stock'] === 4
            && $job->data['changed_fields'] === ['stock']);
    }

    public function test_deleting_a_product_dispatches_product_deleted(): void
    {
        $product = $this->makeProduct();

        Queue::fake();
        $product->delete();

        Queue::assertPushed(DispatchWebsiteWebhookJob::class, fn (DispatchWebsiteWebhookJob $job): bool => $job->event === 'product.deleted'
            && $job->data['sku'] === 'TEST-SYNC-002');
    }

    public function test_a_delivery_is_logged_with_its_http_status(): void
    {
        Http::fake(['example.test/*' => Http::response(['success' => true], 200)]);

        (new DispatchWebsiteWebhookJob('https://example.test/webhooks/zamzam', 'a-real-secret', 'product.updated', ['sku' => 'TEST-SYNC-002'], $this->company->getKey()))->handle();

        $delivery = WebsiteWebhookDelivery::withoutGlobalScopes()->where('event', 'product.updated')->sole();
        $this->assertSame(WebsiteWebhookDelivery::STATUS_DELIVERED, $delivery->status);
        $this->assertSame(200, $delivery->response_status);
        $this->assertSame('TEST-SYNC-002', $delivery->sku);
        $this->assertSame($this->company->getKey(), $delivery->company_id);

        Http::assertSent(fn ($request): bool => $request->hasHeader('X-ZamZam-Signature', hash_hmac('sha256', $request->body(), 'a-real-secret')));
    }

    public function test_a_rejected_delivery_is_logged_as_failed_after_its_retries(): void
    {
        Http::fake(['example.test/*' => Http::response(['error' => 'Machine not found'], 404)]);

        (new DispatchWebsiteWebhookJob('https://example.test/webhooks/zamzam', 'a-real-secret', 'product.updated', ['sku' => 'NOPE'], $this->company->getKey()))->handle();

        $delivery = WebsiteWebhookDelivery::withoutGlobalScopes()->where('event', 'product.updated')->sole();
        $this->assertSame(WebsiteWebhookDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame(404, $delivery->response_status);
        $this->assertSame(2, $delivery->attempts);
        $this->assertStringContainsString('Machine not found', $delivery->response_excerpt);
    }

    public function test_a_delayed_product_created_is_built_from_the_product_as_it_is_when_sent(): void
    {
        Queue::fake();
        $product = $this->makeProduct();
        $product->setStockFromProductForm(7);

        Http::fake(['example.test/*' => Http::response([], 200)]);
        app(CompanyContext::class)->clear();

        (new DispatchWebsiteWebhookJob('https://example.test/webhooks/zamzam', 'a-real-secret', 'product.created', ['stock' => 0, 'changed_fields' => ['stock']], $this->company->getKey(), $product->getKey()))->handle();

        Http::assertSent(fn ($request): bool => $request['event'] === 'product.created'
            && $request['data']['stock'] === 7
            && $request['data']['sku'] === 'TEST-SYNC-002'
            && $request['data']['changed_fields'] === ['stock']);
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
