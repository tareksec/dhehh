<?php

namespace Tests\Feature;

use App\Filament\Reseller\Pages\Products;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Services\CompanyContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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

    protected function asPortal(Company $company, Customer $reseller): void
    {
        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $this->actingAs($reseller, 'customer');
    }
}
