<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ResellerCommission;
use App\Models\ResellerProduct;
use App\Models\StockMovement;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\ResellerPricing;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner decision (2026-10-12): the admin sets a reseller price on each
 * variation (what the reseller pays); the reseller sets their own customer
 * price per variation at or above it; the commission is the difference; the
 * reseller price is never shown on a reseller's storefront.
 */
class ResellerVariationPricingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $reseller;

    protected Product $product;

    protected ProductVariant $medium;

    protected ProductVariant $large;

    protected ProductVariant $extraLarge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->company = $this->makeCompany('var-co.example.test', 'VAR');
        app(CompanyContext::class)->set($this->company);
        $this->reseller = $this->makeReseller($this->company, '01912345678', 'margin-shop');

        $category = Category::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Shirts', 'slug' => 'shirts', 'is_active' => true]);
        $this->product = Product::query()->create([
            'company_id' => $this->company->getKey(), 'category_id' => $category->getKey(), 'name' => 'Polo Shirt', 'sku' => 'POLO-1',
            'price' => 650, 'sale_price' => 650, 'cost_price' => 300, 'stock' => 0, 'unit' => 'pcs',
            'reorder_level' => 1, 'vat_rate' => 0, 'has_variants' => true,
            'is_active' => true, 'status' => Product::STATUS_AVAILABLE,
        ])->fresh();
        // Distinctive numbers, so a leak of any reseller price is easy to spot in HTML.
        $this->medium = $this->variant('Size', 'M', sale: 691, reseller: 433);
        $this->large = $this->variant('Size', 'L', sale: 733, reseller: 521);
        $this->extraLarge = $this->variant('Size', 'XL', sale: 877, reseller: null);
        ResellerProduct::query()->create([
            'customer_id' => $this->reseller->getKey(), 'product_id' => $this->product->getKey(), 'is_active' => true,
            'variant_prices' => [$this->medium->getKey() => 659],
        ]);
    }

    public function test_a_reseller_pays_the_admins_reseller_price_and_sets_their_own_customer_price(): void
    {
        $pricing = app(ResellerPricing::class);
        $pricing->activate($this->company, $this->reseller);

        // Chosen price; else the normal price; none for a variation without a reseller price.
        $this->assertSame(659.0, $pricing->variantPriceFor($this->medium));
        $this->assertSame(733.0, $pricing->variantPriceFor($this->large));
        $this->assertNull($pricing->variantPriceFor($this->extraLarge));

        // The reseller pays exactly the admin's price: no per-unit cost is added.
        $this->company->storefrontSetting->update(['reseller_cost_per_unit' => 50]);
        $pricing->activate($this->company->fresh(), $this->reseller);
        $this->assertSame(433.0, $pricing->variantBasePriceFor($this->medium));
        $this->assertSame(521.0, $pricing->variantBasePriceFor($this->large));
        $this->assertNull($pricing->variantBasePriceFor($this->extraLarge));

        // What the storefront shows is the same number.
        $this->assertSame(659.0, $this->medium->effectiveSalePrice());
        $this->assertSame(877.0, $this->extraLarge->effectiveSalePrice());
    }

    public function test_a_price_below_what_the_reseller_pays_is_raised_to_it(): void
    {
        ResellerProduct::query()->where('customer_id', $this->reseller->getKey())->update([
            'variant_prices' => [$this->medium->getKey() => 100, $this->large->getKey() => 'abc'],
        ]);
        $pricing = app(ResellerPricing::class);
        $pricing->activate($this->company, $this->reseller);

        $this->assertSame(433.0, $pricing->variantPriceFor($this->medium)); // never below the cost
        $this->assertSame(733.0, $pricing->variantPriceFor($this->large)); // junk is ignored
    }

    public function test_nothing_changes_without_a_reseller_or_for_a_product_outside_their_store(): void
    {
        // No reseller active: the normal price, the reseller price is never used.
        $this->assertSame(691.0, $this->medium->effectiveSalePrice());
        $this->assertSame(733.0, $this->large->effectiveSalePrice());

        // A reseller who did not pick the product, and one who did but switched it off.
        $other = $this->makeReseller($this->company, '01922222222', 'other-shop');
        $pricing = app(ResellerPricing::class);
        $pricing->activate($this->company, $other);
        $this->assertNull($pricing->variantPriceFor($this->medium));

        ResellerProduct::query()->create(['customer_id' => $other->getKey(), 'product_id' => $this->product->getKey(), 'is_active' => false]);
        $pricing->activate($this->company, $other);
        $this->assertNull($pricing->variantPriceFor($this->medium));

        // Another reseller's chosen price never reaches this one.
        ResellerProduct::query()->where('customer_id', $other->getKey())->update(['is_active' => true, 'variant_prices' => [$this->medium->getKey() => 999]]);
        $pricing->activate($this->company, $other);
        $this->assertSame(999.0, $pricing->variantPriceFor($this->medium));
        $pricing->activate($this->company, $this->reseller);
        $this->assertSame(659.0, $pricing->variantPriceFor($this->medium));
    }

    public function test_a_variation_of_another_company_is_never_reseller_priced(): void
    {
        $other = $this->makeCompany('other-var.example.test', 'OTV');
        $foreign = new ProductVariant(['product_id' => $this->product->getKey(), 'options' => ['Size' => 'S'], 'sale_price' => 500, 'reseller_price' => 300, 'stock' => 5]);
        $foreign->company_id = $other->getKey();
        $foreign->save();
        $pricing = app(ResellerPricing::class);
        $pricing->activate($this->company, $this->reseller);

        $this->assertNull($pricing->variantPriceFor($foreign));
    }

    public function test_the_reseller_store_shows_the_resellers_prices_and_never_the_reseller_price(): void
    {
        $html = $this->get('http://var-co.example.test/store/margin-shop/product/'.$this->product->slug)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('659', $html);   // chosen
        $this->assertStringContainsString('733', $html);   // normal price (nothing chosen)
        $this->assertStringContainsString('877', $html);   // no reseller price: normal
        $this->assertStringNotContainsString('691', $html); // M's normal price is replaced by the reseller's
        $this->assertStringNotContainsString('433', $html); // what the reseller pays: never shown
        $this->assertStringNotContainsString('521', $html);

        // The plain store (no reseller) shows normal prices and never the reseller price either.
        $this->flushSession();
        $plain = $this->get('http://var-co.example.test/product/'.$this->product->slug)->assertOk()->getContent();
        $this->assertStringContainsString('691', $plain);
        $this->assertStringNotContainsString('433', $plain);
        $this->assertStringNotContainsString('521', $plain);
        $this->assertStringNotContainsString('659', $plain);
    }

    public function test_an_order_through_the_reseller_store_charges_their_price_and_records_the_commission(): void
    {
        $this->post("http://var-co.example.test/store/margin-shop/cart/items/{$this->product->slug}", [
            'quantities' => [$this->medium->getKey() => 2, $this->large->getKey() => 1, $this->extraLarge->getKey() => 1],
        ])->assertRedirect();
        $this->post('http://var-co.example.test/store/margin-shop/checkout', [
            'name' => 'Reseller Buyer', 'phone' => '01700999888', 'email' => 'buyer@example.test', 'address' => 'Mirpur, Dhaka',
        ])->assertRedirect();

        $order = Order::query()->where('source', Order::SOURCE_STOREFRONT)->with('items')->sole();
        $this->assertSame($this->reseller->getKey(), $order->reseller_customer_id);

        $lines = $order->items->keyBy('product_variant_id');
        $this->assertSame(659.0, (float) $lines[$this->medium->getKey()]->unit_price);
        $this->assertSame(433.0, (float) $lines[$this->medium->getKey()]->reseller_base_price);
        $this->assertSame(733.0, (float) $lines[$this->large->getKey()]->unit_price);
        $this->assertSame(521.0, (float) $lines[$this->large->getKey()]->reseller_base_price);
        $this->assertSame(877.0, (float) $lines[$this->extraLarge->getKey()]->unit_price);
        $this->assertNull($lines[$this->extraLarge->getKey()]->reseller_base_price);

        // Commission = (price - what the reseller pays) per unit, only on reseller-priced lines.
        $commission = ResellerCommission::query()->where('order_id', $order->getKey())->sole();
        $this->assertSame((659 - 433) * 2 + (733 - 521) * 1, (int) round((float) $commission->amount));
        $this->assertSame(659 * 2 + 733, (int) round((float) $commission->sales_amount));
        $this->assertSame(433 * 2 + 521, (int) round((float) $commission->base_amount));
        $this->assertSame($this->reseller->getKey(), $commission->customer_id);
    }

    public function test_an_order_from_the_plain_store_has_no_reseller_price_or_commission(): void
    {
        $this->post("http://var-co.example.test/cart/items/{$this->product->slug}", [
            'quantities' => [$this->medium->getKey() => 1],
        ])->assertRedirect();
        $this->post('http://var-co.example.test/checkout', [
            'name' => 'Plain Buyer', 'phone' => '01700999777', 'email' => 'plain@example.test', 'address' => 'Mirpur, Dhaka',
        ])->assertRedirect();

        $item = OrderItem::query()->where('product_variant_id', $this->medium->getKey())->sole();
        $this->assertSame(691.0, (float) $item->unit_price);
        $this->assertNull($item->reseller_base_price);
        $this->assertSame(0, ResellerCommission::query()->count());
    }

    public function test_staff_set_the_reseller_price_on_each_variation_and_it_is_kept(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $this->company->getKey()]);
        app(CompanyContext::class)->set($this->company);

        $mediumKey = 'record-'.$this->medium->getKey();
        $extraKey = 'record-'.$this->extraLarge->getKey();

        Livewire::test(EditProduct::class, ['record' => $this->product->getKey()])
            // The saved reseller prices are loaded into the form...
            ->assertSet("data.variants.{$mediumKey}.reseller_price", 433)
            ->assertSet("data.variants.{$extraKey}.reseller_price", null)
            // ...and changing them is kept.
            ->set("data.variants.{$mediumKey}.reseller_price", 444.5)
            ->set("data.variants.{$extraKey}.reseller_price", 600)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('444.50', $this->medium->fresh()->reseller_price);
        $this->assertSame('600.00', $this->extraLarge->fresh()->reseller_price);
        $this->assertSame('521.00', $this->large->fresh()->reseller_price);
    }

    protected function variant(string $attribute, string $value, float $sale, ?float $reseller): ProductVariant
    {
        $variant = ProductVariant::query()->create([
            'company_id' => $this->company->getKey(), 'product_id' => $this->product->getKey(),
            'sku' => 'POLO-'.$value, 'options' => [$attribute => $value],
            'sale_price' => $sale, 'cost_price' => 300, 'reseller_price' => $reseller, 'stock' => 0, 'is_active' => true,
        ]);
        StockMovement::query()->create([
            'product_id' => $this->product->getKey(), 'product_variant_id' => $variant->getKey(), 'type' => 'opening', 'quantity' => 20,
        ]);

        return $variant->fresh();
    }

    protected function makeCompany(string $domain, string $prefix): Company
    {
        $company = Company::query()->create([
            'name' => 'Company '.$prefix, 'slug' => str($domain)->slug()->toString(), 'domain' => $domain,
            'domain_verified' => true, 'invoice_prefix' => $prefix, 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka',
            'is_active' => true, 'reseller_module_enabled' => true,
        ])->fresh();
        StorefrontSetting::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(), 'theme_color' => '#0F766E', 'is_published' => true,
            'new_customer_delivery_advance_enabled' => false, 'reseller_cost_per_unit' => 20,
        ]);

        return $company->fresh();
    }

    protected function makeReseller(Company $company, string $phone, string $slug): Customer
    {
        return Customer::query()->create([
            'company_id' => $company->getKey(), 'name' => 'Reseller '.$phone, 'phone' => $phone,
            'reseller_status' => 'approved', 'reseller_slug' => $slug, 'business_name' => 'Shop '.Str::random(4), 'is_active' => true,
        ]);
    }
}
