<?php

namespace Tests\Feature;

use App\Filament\Reseller\Pages\Products;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ResellerProduct;
use App\Services\CompanyContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 4 of 13_RESELLER_PORTAL_PLAN.md: the portal's "Products & pricing"
 * page -- pick which products go in the reseller's own store, set a price
 * (never below the wholesale-tier-derived base), and the bulk actions.
 */
class ResellerPortalProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_only_lists_the_current_companys_own_products(): void
    {
        [$company, $reseller] = $this->setUpCompany('products-a.example.test');
        $ownProduct = $this->makeProduct($company, 'Own Product');

        [$otherCompany] = $this->setUpCompany('products-b.example.test');
        $otherProduct = $this->makeProduct($otherCompany, 'Other Company Product');

        $this->asPortal($company, $reseller);

        Livewire::test(Products::class)
            ->assertCanSeeTableRecords([$ownProduct])
            ->assertCanNotSeeTableRecords([$otherProduct]);
    }

    public function test_toggling_in_my_store_creates_and_removes_the_reseller_product_row(): void
    {
        [$company, $reseller] = $this->setUpCompany('products-toggle.example.test');
        $product = $this->makeProduct($company, 'Toggle Product');

        $this->asPortal($company, $reseller);

        Livewire::test(Products::class)
            ->assertTableColumnStateSet('in_store', false, $product)
            ->call('updateTableColumnState', 'in_store', (string) $product->getKey(), true);

        $this->assertDatabaseHas('reseller_products', [
            'customer_id' => $reseller->getKey(),
            'product_id' => $product->getKey(),
            'is_active' => true,
        ]);

        Livewire::test(Products::class)
            ->assertTableColumnStateSet('in_store', true, $product)
            ->call('updateTableColumnState', 'in_store', (string) $product->getKey(), false);

        $this->assertDatabaseMissing('reseller_products', [
            'customer_id' => $reseller->getKey(),
            'product_id' => $product->getKey(),
        ]);
    }

    public function test_your_price_cannot_go_below_the_base_price(): void
    {
        [$company, $reseller] = $this->setUpCompany('products-price.example.test');
        $product = $this->makeProduct($company, 'Priced Product', tierPrice: 100, sellingPrice: 150);

        ResellerProduct::query()->create([
            'customer_id' => $reseller->getKey(),
            'product_id' => $product->getKey(),
            'is_active' => true,
        ]);

        $this->asPortal($company, $reseller);

        // Base price = wholesale tier (100) + company's reseller_cost_per_unit (0, unset) = 100.
        Livewire::test(Products::class)
            ->call('updateTableColumnState', 'your_price', (string) $product->getKey(), '50');

        $this->assertDatabaseHas('reseller_products', [
            'customer_id' => $reseller->getKey(),
            'product_id' => $product->getKey(),
            'price' => null,
        ]);

        Livewire::test(Products::class)
            ->call('updateTableColumnState', 'your_price', (string) $product->getKey(), '120');

        $this->assertDatabaseHas('reseller_products', [
            'customer_id' => $reseller->getKey(),
            'product_id' => $product->getKey(),
            'price' => 120,
        ]);
    }

    public function test_bulk_add_to_store_and_remove_from_store(): void
    {
        [$company, $reseller] = $this->setUpCompany('products-bulk.example.test');
        $first = $this->makeProduct($company, 'Bulk First');
        $second = $this->makeProduct($company, 'Bulk Second');

        $this->asPortal($company, $reseller);

        Livewire::test(Products::class)
            ->callTableBulkAction('addToStore', [$first, $second]);

        $this->assertDatabaseHas('reseller_products', ['customer_id' => $reseller->getKey(), 'product_id' => $first->getKey()]);
        $this->assertDatabaseHas('reseller_products', ['customer_id' => $reseller->getKey(), 'product_id' => $second->getKey()]);

        Livewire::test(Products::class)
            ->callTableBulkAction('removeFromStore', [$first, $second]);

        $this->assertDatabaseMissing('reseller_products', ['customer_id' => $reseller->getKey(), 'product_id' => $first->getKey()]);
        $this->assertDatabaseMissing('reseller_products', ['customer_id' => $reseller->getKey(), 'product_id' => $second->getKey()]);
    }

    public function test_bulk_set_price_from_suggested_only_touches_products_already_in_store(): void
    {
        [$company, $reseller] = $this->setUpCompany('products-bulk-price.example.test');
        $inStore = $this->makeProduct($company, 'In Store Product', tierPrice: 100, sellingPrice: 200);
        $notInStore = $this->makeProduct($company, 'Not In Store Product', tierPrice: 100, sellingPrice: 200);

        ResellerProduct::query()->create([
            'customer_id' => $reseller->getKey(),
            'product_id' => $inStore->getKey(),
            'is_active' => true,
        ]);

        $this->asPortal($company, $reseller);

        Livewire::test(Products::class)
            ->callTableBulkAction('setPriceFromSuggested', [$inStore, $notInStore], data: ['percent' => 10]);

        // Suggested (200) + 10% = 220.
        $this->assertDatabaseHas('reseller_products', [
            'customer_id' => $reseller->getKey(),
            'product_id' => $inStore->getKey(),
            'price' => 220,
        ]);
        $this->assertDatabaseMissing('reseller_products', [
            'customer_id' => $reseller->getKey(),
            'product_id' => $notInStore->getKey(),
        ]);
    }

    public function test_the_details_slide_over_shows_the_product_and_the_resellers_own_cost(): void
    {
        [$company, $reseller] = $this->setUpCompany('products-details.example.test');
        $company->storefrontSetting()->create(['theme_color' => '#0F766E', 'is_published' => true, 'reseller_cost_per_unit' => 15]);
        $product = $this->makeProduct($company, 'Detail Product', tierPrice: 120, sellingPrice: 300);
        $key = $company->storage_key;
        $product->update([
            'description' => '<p>Waterproof and light</p>',
            'brand' => 'Acme',
            'weight_kg' => 0.5,
            'moq' => 3,
            'image' => "companies/{$key}/public/products/main.jpg",
            'gallery_images' => ["companies/{$key}/public/products/side.jpg"],
            'cost_price' => 77,
        ]);
        ResellerProduct::query()->create(['customer_id' => $reseller->getKey(), 'product_id' => $product->getKey(), 'is_active' => true, 'price' => 250]);

        $this->asPortal($company, $reseller);

        $html = $this->detailsHtml(Livewire::test(Products::class)->mountTableAction('viewDetails', $product), $product);

        foreach (['Waterproof and light', 'Acme', '0.5 kg', 'products/main.jpg', 'products/side.jpg', 'Copy description', ' 135<', ' 250<', ' 115<'] as $expected) {
            $this->assertStringContainsString($expected, $html);   // cost 120 + 15, own price, commission
        }
        $this->assertStringNotContainsString(' 77<', $html);      // the company's purchase cost stays hidden
        $this->assertStringNotContainsString(' 120<', $html);     // so do the wholesale tiers
    }

    public function test_the_details_show_variations_with_their_images_and_the_photo_zip_covers_them(): void
    {
        [$company, $reseller] = $this->setUpCompany('products-variants.example.test');
        $product = $this->makeProduct($company, 'Variant Product');
        $key = $company->storage_key;
        $product->update(['tier_prices' => [], 'has_variants' => true, 'image' => null, 'gallery_images' => []]);
        ProductVariant::query()->create([
            'company_id' => $company->getKey(), 'product_id' => $product->getKey(), 'sku' => 'VAR-RED',
            'options' => ['Color' => 'Red'], 'sale_price' => 210, 'cost_price' => 90, 'stock' => 4,
            'images' => ["companies/{$key}/public/products/red.jpg"], 'is_active' => true,
        ]);
        ProductVariant::query()->create([
            'company_id' => $company->getKey(), 'product_id' => $product->getKey(), 'sku' => 'VAR-OFF',
            'options' => ['Color' => 'Hidden'], 'sale_price' => 210, 'stock' => 1,
            'images' => ["companies/{$key}/public/products/off.jpg"], 'is_active' => false,
        ]);

        $this->assertSame(["companies/{$key}/public/products/red.jpg"], $product->fresh()->allImagePaths());

        $this->asPortal($company, $reseller);

        $page = Livewire::test(Products::class)->assertTableActionVisible('downloadPhotos', $product);
        $html = $this->detailsHtml($page->mountTableAction('viewDetails', $product), $product);

        foreach (['Color: Red', 'VAR-RED', 'products/red.jpg', 'No wholesale rate'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringNotContainsString('Color: Hidden', $html);
        $this->assertStringNotContainsString(' 90<', $html);
    }

    public function test_the_photo_zip_includes_variation_photos(): void
    {
        Storage::fake('public');
        [$company, $reseller] = $this->setUpCompany('products-zip.example.test');
        $product = $this->makeProduct($company, 'Zip Product');
        $key = $company->storage_key;
        $main = "companies/{$key}/public/products/main.jpg";
        $red = "companies/{$key}/public/products/red.jpg";
        Storage::disk('public')->put($main, 'main-bytes');
        Storage::disk('public')->put($red, 'red-bytes');
        $product->update(['image' => $main, 'gallery_images' => []]);
        ProductVariant::query()->create([
            'company_id' => $company->getKey(), 'product_id' => $product->getKey(), 'sku' => 'ZIP-RED',
            'options' => ['Color' => 'Red'], 'sale_price' => 210, 'stock' => 4, 'images' => [$red], 'is_active' => true,
        ]);

        $this->actingAs($reseller, 'customer');
        app(CompanyContext::class)->set($company);

        $response = $this->get('http://reseller.products-zip.example.test/portal/products/'.$product->getKey().'/photos')->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'zip-test-');
        file_put_contents($tmp, $response->streamedContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $this->assertSame(2, $zip->numFiles);
        $zip->close();
        @unlink($tmp);
    }

    public function test_the_list_thumbnail_falls_back_to_the_first_gallery_photo(): void
    {
        [$company, $reseller] = $this->setUpCompany('products-thumb.example.test');
        $product = $this->makeProduct($company, 'Gallery Only');
        $product->update(['image' => null, 'gallery_images' => ["companies/{$company->storage_key}/public/products/g1.jpg"]]);

        $this->asPortal($company, $reseller);

        Livewire::test(Products::class)->assertSee('products/g1.jpg');
    }

    public function test_one_resellers_details_never_show_another_resellers_price_or_picks(): void
    {
        [$company, $alice] = $this->setUpCompany('products-isolation.example.test');
        $bob = Customer::query()->create([
            'company_id' => $company->getKey(), 'name' => 'Bob Reseller', 'phone' => '01911112222',
            'reseller_status' => 'approved', 'reseller_slug' => 'bob-shop', 'business_name' => 'Bob Secret Shop',
            'password' => Hash::make('secret-password'), 'is_active' => true,
        ]);
        $product = $this->makeProduct($company, 'Shared Product', tierPrice: 100, sellingPrice: 400);
        ResellerProduct::query()->create(['customer_id' => $alice->getKey(), 'product_id' => $product->getKey(), 'is_active' => true, 'price' => 333]);
        ResellerProduct::query()->create(['customer_id' => $bob->getKey(), 'product_id' => $product->getKey(), 'is_active' => true, 'price' => 777]);

        $this->asPortal($company, $alice);

        $html = $this->detailsHtml(Livewire::test(Products::class)->mountTableAction('viewDetails', $product), $product);
        $this->assertStringContainsString(' 333<', $html);
        foreach ([' 777<', 'Bob', 'bob-shop'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }

        $this->asPortal($company, $bob);

        $html = $this->detailsHtml(Livewire::test(Products::class)->mountTableAction('viewDetails', $product), $product);
        $this->assertStringContainsString(' 777<', $html);
        foreach ([' 333<', 'Test Reseller'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
    }

    public function test_another_companys_product_cannot_open_in_the_details(): void
    {
        [$company, $reseller] = $this->setUpCompany('products-foreign-a.example.test');
        [$otherCompany] = $this->setUpCompany('products-foreign-b.example.test');
        $foreign = $this->makeProduct($otherCompany, 'Foreign Product');

        $this->asPortal($company, $reseller);

        Livewire::test(Products::class)->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_the_photo_download_route_is_scoped_to_the_reseller_and_their_own_company(): void
    {
        [$company, $reseller] = $this->setUpCompany('products-photos.example.test');
        $product = $this->makeProduct($company, 'Photo Product');
        $product->update(['image' => null, 'gallery_images' => []]);

        [$otherCompany] = $this->setUpCompany('products-photos-other.example.test');
        $otherProduct = $this->makeProduct($otherCompany, 'Other Photo Product');

        $this->actingAs($reseller, 'customer');
        app(CompanyContext::class)->set($company);

        $this->get('http://reseller.products-photos.example.test/portal/products/'.$otherProduct->getKey().'/photos')
            ->assertNotFound();
    }

    /** @return array{0: Company, 1: Customer} */
    protected function setUpCompany(string $domain): array
    {
        $company = Company::query()->create([
            'name' => 'Portal '.$domain,
            'slug' => Str::slug($domain),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => strtoupper(Str::random(4)),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
            'reseller_module_enabled' => true,
        ])->fresh();

        $reseller = Customer::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Test Reseller',
            'phone' => '01900000'.random_int(100, 999),
            'reseller_status' => 'approved',
            'reseller_slug' => 'test-reseller-'.Str::random(6),
            'business_name' => 'Test Shop',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);

        return [$company, $reseller];
    }

    protected function makeProduct(Company $company, string $name, ?float $tierPrice = 100, float $sellingPrice = 200): Product
    {
        app(CompanyContext::class)->set($company);

        return Product::query()->create([
            'company_id' => $company->getKey(),
            'name' => $name,
            'sku' => Str::upper(Str::random(8)),
            'price' => $sellingPrice,
            'sale_price' => $sellingPrice,
            'cost_price' => $tierPrice,
            'stock' => 10,
            'reorder_level' => 1,
            'unit' => 'pcs',
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
            'tier_prices' => $tierPrice === null ? [] : [['min_qty' => 1, 'price' => $tierPrice]],
        ]);
    }

    /** The details slide-over's content for $product, as the mounted page builds it. */
    protected function detailsHtml(Testable $page, Product $product): string
    {
        $this->assertSame('viewDetails', $page->instance()->mountedActions[0]['name'] ?? null);

        return view('filament.reseller.pages.partials.product-details', $page->instance()->detailsFor($product))->render();
    }

    protected function asPortal(Company $company, Customer $reseller): void
    {
        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $this->actingAs($reseller, 'customer');
    }
}
