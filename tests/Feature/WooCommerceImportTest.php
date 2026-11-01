<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\WooCommerceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class WooCommerceImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_creates_and_updates_products_by_sku(): void
    {
        $company = $this->createCompanyWithWooCredentials('woo-import');

        Http::fake([
            'old-shop.example.test/wp-json/wc/v3/products*' => Http::response([
                [
                    'name' => 'Imported Fan',
                    'sku' => 'WOO-FAN-01',
                    'slug' => 'imported-fan',
                    'regular_price' => '1500',
                    'sale_price' => '1350',
                    'short_description' => '<p>A nice fan.</p>',
                    'categories' => [['name' => 'Fans']],
                    'images' => [],
                ],
                [
                    'name' => 'Imported Light',
                    'sku' => 'WOO-LIGHT-01',
                    'slug' => 'imported-light',
                    'regular_price' => '400',
                    'sale_price' => '',
                    'categories' => [],
                    'images' => [],
                ],
            ]),
        ]);

        $result = app(WooCommerceImportService::class)->importProducts($company, downloadImages: false);

        $this->assertSame(['created' => 2, 'updated' => 0, 'skipped' => 0], $result);

        $fan = Product::withoutGlobalScopes()->where('sku', 'WOO-FAN-01')->first();
        $this->assertNotNull($fan);
        $this->assertSame($company->getKey(), $fan->company_id);
        $this->assertSame(1350.0, (float) $fan->sale_price);
        $this->assertSame(1500.0, (float) $fan->price);
        $this->assertSame(0, (int) $fan->stock);
        $this->assertSame('<p>A nice fan.</p>', $fan->description);
        $this->assertNotNull(Category::withoutGlobalScopes()->where('name', 'Fans')->where('company_id', $company->getKey())->first());

        // Re-running updates instead of duplicating.
        $result = app(WooCommerceImportService::class)->importProducts($company, downloadImages: false);
        $this->assertSame(['created' => 0, 'updated' => 2, 'skipped' => 0], $result);
        $this->assertSame(2, Product::withoutGlobalScopes()->where('company_id', $company->getKey())->count());
    }

    public function test_downloaded_images_are_written_to_the_company_public_prefix(): void
    {
        Storage::fake('public');
        $company = $this->createCompanyWithWooCredentials('woo-images');

        Http::fake([
            'old-shop.example.test/wp-json/wc/v3/products*' => Http::response([[
                'name' => 'Imported Camera',
                'sku' => 'WOO-CAMERA-01',
                'slug' => 'imported-camera',
                'regular_price' => '1500',
                'categories' => [],
                'images' => [
                    ['src' => 'https://cdn.example.test/camera.jpg'],
                    ['src' => 'https://cdn.example.test/camera-side.png'],
                ],
            ]]),
            'cdn.example.test/*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        app(WooCommerceImportService::class)->importProducts($company, downloadImages: true);

        $product = Product::withoutGlobalScopes()->where('sku', 'WOO-CAMERA-01')->firstOrFail();
        $prefix = $company->storageRoot().'/public/products/';

        $this->assertStringStartsWith($prefix, $product->image);
        $this->assertStringStartsWith($company->storageRoot().'/public/products/gallery/', $product->gallery_images[0]);
        Storage::disk('public')->assertExists($product->image);
        Storage::disk('public')->assertExists($product->gallery_images[0]);
    }

    public function test_variable_product_imports_its_variations(): void
    {
        $company = $this->createCompanyWithWooCredentials('woo-variable');

        $productPayload = [
            'id' => 77,
            'type' => 'variable',
            'name' => 'Vari Hoodie',
            'sku' => 'WOO-HOODIE',
            'slug' => 'vari-hoodie',
            'regular_price' => '',
            'price' => '900',
            'description' => '<p>Full hoodie description with all the details.</p>',
            'short_description' => '<p>Short.</p>',
            'attributes' => [
                ['name' => 'Size', 'options' => ['M', 'L'], 'variation' => true],
                ['name' => 'Brand', 'options' => ['ZamZam'], 'variation' => false],
            ],
            'categories' => [['name' => 'Clothing']],
            'images' => [],
        ];

        $variations = [
            [
                'id' => 771,
                'sku' => 'WOO-HOODIE-M',
                'regular_price' => '900',
                'sale_price' => '850',
                'weight' => '2.5',
                'status' => 'publish',
                'menu_order' => 0,
                'attributes' => [['name' => 'Size', 'option' => 'M']],
                'image' => null,
            ],
            [
                'id' => 772,
                'sku' => '',
                'regular_price' => '950',
                'sale_price' => '',
                'weight' => '',
                'status' => 'publish',
                'menu_order' => 1,
                'attributes' => [['name' => 'Size', 'option' => 'L']],
                'image' => null,
            ],
        ];

        Http::fake([
            'old-shop.example.test/wp-json/wc/v3/products/77/variations*' => Http::response($variations),
            'old-shop.example.test/wp-json/wc/v3/products*' => Http::response([$productPayload]),
        ]);

        $result = app(WooCommerceImportService::class)->importProducts($company, downloadImages: false);
        $this->assertSame(['created' => 1, 'updated' => 0, 'skipped' => 0], $result);

        $product = Product::withoutGlobalScopes()->where('sku', 'WOO-HOODIE')->first();
        $this->assertNotNull($product);
        $this->assertTrue((bool) $product->has_variants);
        $this->assertSame(['Size' => ['M', 'L']], $product->variant_attributes);
        $this->assertSame('<p>Full hoodie description with all the details.</p>', $product->description);
        $this->assertSame('ZamZam', $product->brand);

        $variants = ProductVariant::withoutGlobalScopes()->where('product_id', $product->getKey())->orderBy('sort_order')->get();
        $this->assertCount(2, $variants);
        $this->assertSame('WOO-HOODIE-M', $variants[0]->sku);
        $this->assertSame(['Size' => 'M'], $variants[0]->options);
        $this->assertSame(850.0, (float) $variants[0]->sale_price);
        $this->assertSame(2.5, $variants[0]->effectiveWeightKg());
        $this->assertNull($variants[1]->weight_kg, 'An empty WooCommerce variation weight stays empty (uses the product weight).');
        $this->assertNull($variants[1]->sku);
        $this->assertSame(['Size' => 'L'], $variants[1]->options);
        $this->assertSame(950.0, (float) $variants[1]->sale_price);
        $this->assertSame($company->getKey(), $variants[0]->company_id);

        // Re-sync updates the same variants instead of duplicating them.
        $result = app(WooCommerceImportService::class)->importProducts($company, downloadImages: false);
        $this->assertSame(['created' => 0, 'updated' => 1, 'skipped' => 0], $result);
        $this->assertSame(2, ProductVariant::withoutGlobalScopes()->where('product_id', $product->getKey())->count());
    }

    public function test_import_keeps_the_description_formatting(): void
    {
        $company = $this->createCompanyWithWooCredentials('woo-format');

        Http::fake([
            'old-shop.example.test/wp-json/wc/v3/products*' => Http::response([[
                'name' => 'Solar Fan',
                'sku' => 'WOO-SOLAR-FAN',
                'slug' => 'solar-fan',
                'regular_price' => '1500',
                'description' => '<h3>Features</h3><p>Stay <b>cool</b>.</p><ul><li>Dual charging</li><li>3000mAh battery</li></ul>',
                'categories' => [],
                'images' => [],
            ]]),
        ]);

        app(WooCommerceImportService::class)->importProducts($company, downloadImages: false);

        $product = Product::withoutGlobalScopes()->where('sku', 'WOO-SOLAR-FAN')->firstOrFail();

        $this->assertSame(
            '<h3>Features</h3><p>Stay <strong>cool</strong>.</p><ul><li>Dual charging</li><li>3000mAh battery</li></ul>',
            $product->description,
        );
    }

    public function test_resync_never_overwrites_a_description_the_team_edited(): void
    {
        $company = $this->createCompanyWithWooCredentials('woo-resync');

        $payload = [[
            'name' => 'Synced Light',
            'sku' => 'WOO-SYNC-01',
            'slug' => 'synced-light',
            'regular_price' => '400',
            'description' => '<p>Old site text.</p><ul><li>One</li><li>Two</li></ul>',
            'categories' => [],
            'images' => [],
        ]];

        Http::fake(['old-shop.example.test/wp-json/wc/v3/products*' => Http::response($payload)]);

        app(WooCommerceImportService::class)->importProducts($company, downloadImages: false);

        $product = Product::withoutGlobalScopes()->where('sku', 'WOO-SYNC-01')->firstOrFail();

        // The team rewrites the description in the editor (saved as HTML)...
        $product->forceFill(['description' => '<p>Written by the owner.</p>'])->save();

        // ...and a plain-text description typed by hand is protected as well.
        $plain = Product::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'name' => 'Hand Typed',
            'slug' => 'hand-typed',
            'sku' => 'WOO-SYNC-02',
            'unit' => 'pcs',
            'price' => 10,
            'sale_price' => 10,
            'cost_price' => 0,
            'stock' => 0,
            'reorder_level' => 0,
            'vat_rate' => 0,
            'description' => 'Typed by hand, not from the old site.',
        ]);

        Http::fake(['old-shop.example.test/wp-json/wc/v3/products*' => Http::response([
            $payload[0],
            [...$payload[0], 'name' => 'Hand Typed', 'sku' => 'WOO-SYNC-02', 'slug' => 'hand-typed'],
        ])]);

        app(CompanyContext::class)->clear();
        app(WooCommerceImportService::class)->importProducts($company, downloadImages: false);

        $this->assertSame('<p>Written by the owner.</p>', $product->fresh()->description);
        $this->assertSame('Typed by hand, not from the old site.', $plain->fresh()->description);
    }

    public function test_resync_upgrades_a_description_left_as_plain_text_by_the_old_importer(): void
    {
        $company = $this->createCompanyWithWooCredentials('woo-legacy');

        Product::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'name' => 'Legacy Fan',
            'slug' => 'legacy-fan',
            'sku' => 'WOO-LEGACY-01',
            'unit' => 'pcs',
            'price' => 10,
            'sale_price' => 10,
            'cost_price' => 0,
            'stock' => 0,
            'reorder_level' => 0,
            'vat_rate' => 0,
            // What the old importer saved: tags stripped, list items run together.
            'description' => 'Main featuresDual chargingLong battery',
        ]);

        Http::fake(['old-shop.example.test/wp-json/wc/v3/products*' => Http::response([[
            'name' => 'Legacy Fan',
            'sku' => 'WOO-LEGACY-01',
            'slug' => 'legacy-fan',
            'regular_price' => '10',
            'description' => '<p><b>Main features</b></p><ul><li>Dual charging</li><li>Long battery</li></ul>',
            'categories' => [],
            'images' => [],
        ]])]);

        app(WooCommerceImportService::class)->importProducts($company, downloadImages: false);

        $this->assertSame(
            '<p><strong>Main features</strong></p><ul><li>Dual charging</li><li>Long battery</li></ul>',
            Product::withoutGlobalScopes()->where('sku', 'WOO-LEGACY-01')->firstOrFail()->description,
        );
    }

    public function test_two_companies_can_each_import_a_category_with_the_same_slug(): void
    {
        // Regression test: categories.slug used to have a database-wide unique
        // constraint even though Category is company-scoped, so a second
        // company syncing a category name that slugs the same as one another
        // company already has (e.g. "Audio") crashed with a 1062 duplicate
        // entry error instead of getting its own company-scoped category row.
        $companyA = $this->createCompanyWithWooCredentials('woo-audio-a', 'WAA');
        $companyB = $this->createCompanyWithWooCredentials('woo-audio-b', 'WAB');

        $payloadFor = fn (string $sku) => [[
            'name' => 'Speaker',
            'sku' => $sku,
            'slug' => 'speaker-'.$sku,
            'regular_price' => '500',
            'categories' => [['name' => 'Audio']],
            'images' => [],
        ]];

        Http::fake([
            'old-shop.example.test/wp-json/wc/v3/products*' => Http::sequence()
                ->push($payloadFor('WOO-SPEAKER-A'))
                ->push($payloadFor('WOO-SPEAKER-B')),
        ]);
        app(WooCommerceImportService::class)->importProducts($companyA, downloadImages: false);
        app(CompanyContext::class)->clear();
        app(WooCommerceImportService::class)->importProducts($companyB, downloadImages: false);

        $categoryA = Category::withoutGlobalScopes()->where('company_id', $companyA->getKey())->where('slug', 'audio')->first();
        $categoryB = Category::withoutGlobalScopes()->where('company_id', $companyB->getKey())->where('slug', 'audio')->first();

        $this->assertNotNull($categoryA);
        $this->assertNotNull($categoryB);
        $this->assertNotSame($categoryA->getKey(), $categoryB->getKey());
    }

    public function test_import_fails_without_configured_credentials(): void
    {
        $company = Company::query()->create([
            'name' => 'No Woo Store',
            'slug' => 'no-woo-store',
            'invoice_prefix' => 'NWS',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'theme_color' => '#0F766E',
            'is_published' => true,
        ]);

        $this->expectException(RuntimeException::class);

        app(WooCommerceImportService::class)->importProducts($company);
    }

    public function test_command_imports_products(): void
    {
        $company = $this->createCompanyWithWooCredentials('woo-cmd');

        Http::fake([
            'old-shop.example.test/wp-json/wc/v3/products*' => Http::response([
                [
                    'name' => 'Command Product',
                    'sku' => 'WOO-CMD-01',
                    'slug' => 'command-product',
                    'regular_price' => '999',
                    'categories' => [],
                    'images' => [],
                ],
            ]),
        ]);

        $this->artisan('woocommerce:import-products', ['company' => $company->slug, '--no-images' => true])
            ->expectsOutputToContain('Created: 1')
            ->assertSuccessful();
    }

    private function createCompanyWithWooCredentials(string $slug, string $invoicePrefix = 'WOO'): Company
    {
        $company = Company::query()->create([
            'name' => 'Woo Store '.$slug,
            'slug' => $slug,
            'invoice_prefix' => $invoicePrefix,
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'theme_color' => '#0F766E',
            'is_published' => true,
            'woocommerce_base_url' => 'https://old-shop.example.test',
            'woocommerce_credentials' => [
                'consumer_key' => 'ck_test',
                'consumer_secret' => 'cs_test',
            ],
        ]);

        return $company;
    }
}
