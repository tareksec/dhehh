<?php

namespace Tests\Feature;

use App\Filament\Resources\ResellerVariationPrices\Pages\ListResellerVariationPrices;
use App\Filament\Resources\ResellerVariationPrices\ResellerVariationPriceResource;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner decision (2026-10-15): the admin fills in the suggested selling price
 * of many variations at once. One list of variations, a price typed in the row,
 * and a bulk "set the same price". Nothing here changes the wholesale price.
 */
class ResellerVariationPriceToolTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Company $other;

    protected User $admin;

    protected Product $shirt;

    protected ProductVariant $small;

    protected ProductVariant $medium;

    protected ProductVariant $priced;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('Price Co', 'price-co');
        $this->other = $this->makeCompany('Elsewhere Co', 'elsewhere-co');
        app(CompanyContext::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($this->admin)->withSession(['current_company_id' => $this->company->getKey()]);

        $this->shirt = $this->product($this->company, 'Shirt', 'SHIRT-1', variants: true);
        $this->small = $this->variant($this->shirt, 'SHIRT-S', 'S', 400);
        $this->medium = $this->variant($this->shirt, 'SHIRT-M', 'M', 450);
        $this->priced = $this->variant($this->shirt, 'SHIRT-L', 'L', 500, suggested: 650);
    }

    public function test_it_lists_the_active_variations_of_the_companys_variable_products_only(): void
    {
        $plain = $this->product($this->company, 'Plain cap', 'CAP-1');
        $inactive = $this->variant($this->shirt, 'SHIRT-XL', 'XL', 500, active: false);
        $foreign = $this->variant($this->product($this->other, 'Their shirt', 'THEIR-1', variants: true), 'THEIR-S', 'S', 300);

        Livewire::test(ListResellerVariationPrices::class)
            ->assertCanSeeTableRecords([$this->small, $this->medium, $this->priced])
            ->assertCanNotSeeTableRecords([$inactive, $foreign])
            ->assertSee('Shirt')
            ->assertDontSee($plain->name);
    }

    public function test_the_filter_shows_what_still_has_no_suggested_price(): void
    {
        Livewire::test(ListResellerVariationPrices::class)
            ->filterTable('no_suggested_price')
            ->assertCanSeeTableRecords([$this->small, $this->medium])
            ->assertCanNotSeeTableRecords([$this->priced]);
    }

    public function test_a_price_typed_in_the_row_is_saved_and_a_blank_one_clears_it(): void
    {
        Livewire::test(ListResellerVariationPrices::class)
            ->call('updateTableColumnState', 'reseller_suggested_price', (string) $this->small->getKey(), '599.5')
            ->call('updateTableColumnState', 'reseller_suggested_price', (string) $this->priced->getKey(), '');

        $this->assertSame(599.5, $this->small->fresh()->resellerSuggestedPrice());
        $this->assertNull($this->priced->fresh()->resellerSuggestedPrice());
        // The wholesale price is never touched.
        $this->assertSame(400.0, $this->small->fresh()->baseSalePrice());
    }

    public function test_a_negative_or_text_price_is_not_saved(): void
    {
        Livewire::test(ListResellerVariationPrices::class)
            ->call('updateTableColumnState', 'reseller_suggested_price', (string) $this->small->getKey(), '-5')
            ->call('updateTableColumnState', 'reseller_suggested_price', (string) $this->medium->getKey(), 'abc');

        $this->assertNull($this->small->fresh()->resellerSuggestedPrice());
        $this->assertNull($this->medium->fresh()->resellerSuggestedPrice());
    }

    public function test_the_same_price_can_be_set_on_several_variations_and_cleared_again(): void
    {
        Livewire::test(ListResellerVariationPrices::class)
            ->callTableBulkAction('setSamePrice', [$this->small, $this->medium], data: ['price' => 750])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(750.0, $this->small->fresh()->resellerSuggestedPrice());
        $this->assertSame(750.0, $this->medium->fresh()->resellerSuggestedPrice());
        $this->assertSame(650.0, $this->priced->fresh()->resellerSuggestedPrice()); // not selected

        Livewire::test(ListResellerVariationPrices::class)
            ->callTableBulkAction('setSamePrice', [$this->small], data: ['price' => 0])
            ->assertHasTableBulkActionErrors(['price']);
        $this->assertSame(750.0, $this->small->fresh()->resellerSuggestedPrice());

        Livewire::test(ListResellerVariationPrices::class)
            ->callTableBulkAction('clearPrice', [$this->small, $this->priced]);

        $this->assertNull($this->small->fresh()->resellerSuggestedPrice());
        $this->assertNull($this->priced->fresh()->resellerSuggestedPrice());
        $this->assertSame(750.0, $this->medium->fresh()->resellerSuggestedPrice());
    }

    public function test_only_staff_who_may_view_products_and_only_with_the_reseller_module_on_get_the_page(): void
    {
        $this->assertTrue(ResellerVariationPriceResource::canViewAny());

        UserRole::query()->create(['name' => 'Sales only', 'slug' => 'sales-only', 'permissions' => ['sales.view'], 'is_active' => true]);
        $this->actingAs(User::factory()->create(['role' => 'sales-only', 'is_active' => true]));
        $this->assertFalse(ResellerVariationPriceResource::canViewAny());

        $this->actingAs($this->admin);
        $this->company->forceFill(['reseller_module_enabled' => false])->save();
        app(CompanyContext::class)->set($this->company->fresh());
        $this->assertFalse(ResellerVariationPriceResource::canViewAny());
    }

    protected function makeCompany(string $name, string $slug): Company
    {
        return Company::query()->create([
            'name' => $name, 'slug' => $slug, 'domain' => $slug.'.example.test', 'domain_verified' => true,
            'invoice_prefix' => strtoupper(substr($slug, 0, 3)), 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka',
            'is_active' => true, 'reseller_module_enabled' => true,
        ])->fresh();
    }

    protected function product(Company $company, string $name, string $sku, bool $variants = false): Product
    {
        $category = Category::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->getKey(), 'slug' => 'things'],
            ['name' => 'Things', 'is_active' => true],
        );

        return Product::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(), 'category_id' => $category->getKey(), 'name' => $name, 'sku' => $sku,
            'price' => 300, 'sale_price' => 300, 'cost_price' => 90, 'stock' => 0, 'unit' => 'pcs', 'reorder_level' => 1,
            'vat_rate' => 0, 'has_variants' => $variants, 'is_active' => true, 'status' => Product::STATUS_AVAILABLE,
        ])->fresh();
    }

    protected function variant(Product $product, string $sku, string $size, float $wholesale, ?float $suggested = null, bool $active = true): ProductVariant
    {
        return ProductVariant::withoutGlobalScopes()->create([
            'company_id' => $product->company_id, 'product_id' => $product->getKey(), 'sku' => $sku, 'options' => ['Size' => $size],
            'sale_price' => $wholesale, 'cost_price' => 100, 'reseller_suggested_price' => $suggested, 'stock' => 5, 'is_active' => $active,
        ])->fresh();
    }
}
