<?php

namespace Tests\Feature;

use App\Jobs\DispatchWebsiteWebhookJob;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebsiteApiProductTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected string $token;

    protected Product $product;

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

        [, $this->token] = CompanyApiKey::generate($this->company);

        app(CompanyContext::class)->set($this->company);
        $this->product = Product::query()->create([
            'name' => 'Cotton Yarn',
            'sku' => 'YARN-001',
            'price' => 500,
            'sale_price' => 480,
            'cost_price' => 400,
            'stock' => 0,
            'unit' => 'kg',
            'reorder_level' => 5,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);
        $this->product->setStockFromProductForm(100);
        app(CompanyContext::class)->clear();
    }

    protected function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    protected function enableWebhooks(): void
    {
        StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(),
            'website_api_credentials' => [
                'webhook_url' => 'https://example.test/webhooks/zamzam',
                'webhook_secret' => 'a-real-secret',
                'is_enabled' => true,
            ],
        ]);
    }

    public function test_it_lists_active_products(): void
    {
        $this->getJson('/api/v1/products', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'YARN-001')
            ->assertJsonPath('data.0.stock', 100);
    }

    public function test_it_shows_a_single_product_by_sku(): void
    {
        $this->getJson('/api/v1/products/YARN-001', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.name', 'Cotton Yarn')
            ->assertJsonPath('data.price', 500);
    }

    public function test_updating_price_writes_the_field_directly(): void
    {
        $this->patchJson('/api/v1/products/YARN-001', ['price' => 550], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.price', 550);

        $this->assertEquals(550, $this->product->refresh()->price);
    }

    /**
     * The core "compatible with StockMovement" requirement: a stock update
     * from the website must never overwrite Product.stock directly — it has
     * to land as an auditable ledger entry, exactly like an admin editing
     * stock from the Product form does.
     */
    public function test_updating_stock_creates_a_stock_movement_instead_of_overwriting_the_column(): void
    {
        $this->patchJson('/api/v1/products/YARN-001', ['stock' => 130], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.stock', 130);

        $this->assertEquals(130, $this->product->refresh()->stock);

        $movement = StockMovement::withoutGlobalScopes()
            ->where('product_id', $this->product->getKey())
            ->where('type', 'adjustment')
            ->latest('id')
            ->first();

        $this->assertNotNull($movement);
        $this->assertSame(30, $movement->quantity);
        $this->assertSame(CompanyApiKey::class, $movement->reference_type);
    }

    public function test_at_least_one_field_is_required(): void
    {
        $this->patchJson('/api/v1/products/YARN-001', [], $this->auth())
            ->assertStatus(422);
    }

    public function test_unknown_sku_returns_not_found(): void
    {
        $this->getJson('/api/v1/products/'.Str::random(10), $this->auth())
            ->assertStatus(404);
    }

    public function test_the_product_payload_carries_the_full_product(): void
    {
        $this->product->update([
            'brand' => 'Yu Yuan',
            'description' => '<p>Rib machine</p>',
            'specifications' => ['Gauge' => '18G', 'Dia' => '34"'],
        ]);

        $this->getJson('/api/v1/products/YARN-001', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.brand', 'Yu Yuan')
            ->assertJsonPath('data.description', '<p>Rib machine</p>')
            ->assertJsonPath('data.specifications.0', ['label' => 'Gauge', 'value' => '18G'])
            ->assertJsonPath('data.specifications.1', ['label' => 'Dia', 'value' => '34"'])
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.images', []);
    }

    public function test_a_website_can_create_a_product_with_category_specs_images_and_stock(): void
    {
        Storage::fake('public');
        $this->enableWebhooks();
        Queue::fake();
        Http::fake(['images.example.com/*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

        $this->postJson('/api/v1/products', [
            'sku' => 'TEST-MACHINE-001',
            'name' => 'TEST Rib Machine',
            'brand' => 'Yu Yuan',
            'category' => 'Rib Interlock',
            'description' => 'Test machine',
            'specifications' => [['label' => 'Gauge', 'value' => '18G'], ['label' => 'Feeders', 'value' => '72']],
            'images' => ['https://images.example.com/a.jpg', 'https://images.example.com/b.jpg'],
            'price' => 750000,
            'stock' => 2,
            'is_active' => false,
        ], $this->auth())
            ->assertCreated()
            ->assertJsonPath('data.sku', 'TEST-MACHINE-001')
            ->assertJsonPath('data.category.name', 'Rib Interlock')
            ->assertJsonPath('data.specifications.1', ['label' => 'Feeders', 'value' => '72'])
            ->assertJsonPath('data.stock', 2)
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('failed_images', []);

        $product = Product::withoutGlobalScopes()->where('sku', 'TEST-MACHINE-001')->firstOrFail();

        $this->assertSame($this->company->getKey(), $product->company_id);
        $this->assertSame(['Gauge' => '18G', 'Feeders' => '72'], $product->specifications);
        $this->assertNotNull($product->image);
        $this->assertCount(1, $product->gallery_images);
        Storage::disk('public')->assertExists($product->image);

        // The website made this product: nothing is echoed back to it.
        Queue::assertNotPushed(DispatchWebsiteWebhookJob::class);
    }

    public function test_creating_an_existing_sku_is_a_conflict(): void
    {
        $this->postJson('/api/v1/products', ['sku' => 'YARN-001', 'name' => 'Duplicate', 'price' => 1], $this->auth())
            ->assertStatus(409)
            ->assertJsonPath('error', 'sku_exists');
    }

    public function test_patch_accepts_the_full_field_set_without_echoing_a_webhook(): void
    {
        $this->enableWebhooks();
        Queue::fake();

        $this->patchJson('/api/v1/products/YARN-001', [
            'name' => 'Combed Cotton Yarn',
            'brand' => 'Tasneem',
            'description' => 'Updated',
            'specifications' => ['Count' => '30s'],
            'is_active' => false,
        ], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.name', 'Combed Cotton Yarn')
            ->assertJsonPath('data.specifications.0', ['label' => 'Count', 'value' => '30s'])
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.price', 500);

        $this->product->refresh();
        $this->assertSame('Tasneem', $this->product->brand);
        $this->assertFalse($this->product->is_active);
        Queue::assertNotPushed(DispatchWebsiteWebhookJob::class);
    }

    public function test_an_image_from_a_private_address_is_never_downloaded(): void
    {
        Storage::fake('public');
        Http::fake();

        $this->patchJson('/api/v1/products/YARN-001', ['images' => ['http://127.0.0.1/secret.jpg']], $this->auth())
            ->assertOk()
            ->assertJsonPath('failed_images', ['http://127.0.0.1/secret.jpg']);

        Http::assertNothingSent();
        $this->assertNull($this->product->refresh()->image);
    }
}
