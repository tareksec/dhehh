<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Support\MoneyFormatter;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontMarketplaceProPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_marketplace_pro_listing_uses_its_own_view_and_card(): void
    {
        $this->createStore('mp-listing.example.test');
        $this->createProduct('Steel Bolt', 200, 150);

        $this->get('http://mp-listing.example.test/products')
            ->assertOk()
            ->assertViewIs('storefront.themes.marketplace-pro.products.index')
            ->assertSee('mp-filter-panel', false)
            ->assertSee('mp-card', false)
            ->assertSee('-25%')
            // The struck-through price is the original price, not the selling price.
            ->assertSeeInOrder([
                MoneyFormatter::currency(150),
                MoneyFormatter::currency(200),
            ])
            ->assertDontSee('noindex', false);
    }

    public function test_other_themes_keep_the_shared_listing_view(): void
    {
        $this->createStore('builtin-listing.example.test', ['storefront_theme' => 'builtin']);
        $this->createProduct('Steel Bolt', 200, 150);

        $this->get('http://builtin-listing.example.test/products')
            ->assertOk()
            ->assertViewIs('storefront.products.index')
            ->assertDontSee('mp-filter-panel', false);
    }

    public function test_listing_filters_by_stock_and_price_from_the_query_string(): void
    {
        $this->createStore('mp-filters.example.test');
        $this->createProduct('Cheap Washer', 50, null, stock: 10);
        $this->createProduct('Mid Hinge', 300, null, stock: 10);
        $this->createProduct('Sold Out Hinge', 320, null, stock: 0);
        $this->createProduct('Premium Lock', 900, null, stock: 10);

        $this->get('http://mp-filters.example.test/products?in_stock=1&min_price=100&max_price=500')
            ->assertOk()
            ->assertSee('Mid Hinge')
            ->assertDontSee('Cheap Washer')
            ->assertDontSee('Sold Out Hinge')
            ->assertDontSee('Premium Lock')
            ->assertSee('<meta name="robots" content="noindex, follow">', false);

        // Reversed bounds are swapped instead of returning nothing.
        $this->get('http://mp-filters.example.test/products?min_price=500&max_price=100')
            ->assertOk()
            ->assertSee('Mid Hinge')
            ->assertSee('Sold Out Hinge');
    }

    public function test_listing_filters_are_not_served_from_the_unfiltered_cache(): void
    {
        $this->createStore('mp-cache.example.test');
        $this->createProduct('Cheap Washer', 50, null, stock: 10);
        $this->createProduct('Premium Lock', 900, null, stock: 10);

        $this->get('http://mp-cache.example.test/products')->assertSee('Cheap Washer')->assertSee('Premium Lock');

        $this->get('http://mp-cache.example.test/products?min_price=500')
            ->assertSee('Premium Lock')
            ->assertDontSee('Cheap Washer');
    }

    public function test_category_listing_marks_the_active_category_and_breadcrumb(): void
    {
        $this->createStore('mp-category.example.test');
        $category = Category::query()->create(['name' => 'Hardware', 'slug' => 'hardware', 'is_active' => true]);
        $this->createProduct('Steel Bolt', 200, null)->update(['category_id' => $category->getKey()]);

        $this->get('http://mp-category.example.test/category/hardware')
            ->assertOk()
            ->assertSeeInOrder(['mp-filter-categories', 'aria-current="page"', 'Hardware</a>'], false)
            ->assertSee('<li aria-current="page">Hardware</li>', false)
            ->assertSee('Steel Bolt');
    }

    public function test_empty_listing_offers_a_way_out(): void
    {
        $this->createStore('mp-empty.example.test');
        $this->createProduct('Steel Bolt', 200, null);

        $this->get('http://mp-empty.example.test/products?q=nothing-matches')
            ->assertOk()
            ->assertSee('No products match “nothing-matches”.', false)
            ->assertSee('Try another search, remove a filter or browse a category.');
    }

    public function test_listing_is_translated_to_bangla(): void
    {
        $this->createStore('mp-bangla.example.test');
        $this->createProduct('Steel Bolt', 200, null);

        app()->setLocale('bn');

        $this->get('http://mp-bangla.example.test/products')
            ->assertOk()
            ->assertSee('ফিল্টার প্রয়োগ করুন')
            ->assertSee('দাম: কম থেকে বেশি');
    }

    public function test_marketplace_pro_product_page_uses_its_own_view_with_bulk_pricing(): void
    {
        $this->createStore('mp-product.example.test', [
            'delivery_time_inside' => '1-2 days',
            'marketplace_trust_strip_enabled' => true,
            'marketplace_trust_items' => [['icon' => 'truck', 'title' => 'Owner delivery promise', 'subtitle' => null]],
        ]);
        $product = $this->createProduct('Steel Bolt', 200, 150);
        $product->update(['tier_prices' => [['min_qty' => 10, 'price' => 140], ['min_qty' => 50, 'price' => 120]]]);

        $this->get('http://mp-product.example.test/product/'.$product->slug)
            ->assertOk()
            ->assertViewIs('storefront.themes.marketplace-pro.products.show')
            ->assertSee('data-product-page', false)
            ->assertSee('Save '.MoneyFormatter::currency(50))
            ->assertSee('Bulk pricing')
            ->assertSeeInOrder(['1–9 pcs', MoneyFormatter::currency(150), '10–49 pcs', MoneyFormatter::currency(140), '50+ pcs', MoneyFormatter::currency(120)])
            ->assertSee('1-2 days')
            ->assertSee('Owner delivery promise')
            ->assertSee('name="buy_now"', false)
            ->assertSee('data-mobile-purchase-bar', false);
    }

    public function test_other_themes_keep_the_shared_product_page(): void
    {
        $this->createStore('builtin-product.example.test', ['storefront_theme' => 'builtin']);
        $product = $this->createProduct('Steel Bolt', 200, 150);

        $this->get('http://builtin-product.example.test/product/'.$product->slug)
            ->assertOk()
            ->assertViewIs('storefront.products.show');
    }

    public function test_variant_rows_show_the_price_the_cart_charges(): void
    {
        foreach (['mp-variants.example.test' => 'marketplace_pro', 'builtin-variants.example.test' => 'builtin'] as $domain => $theme) {
            $this->createStore($domain, ['storefront_theme' => $theme]);
            $product = $this->createProduct('Cotton Shirt '.$theme, 500, 450);
            $product->update(['has_variants' => true]);
            ProductVariant::query()->create(['product_id' => $product->getKey(), 'sku' => 'SHIRT-XL-'.$theme, 'options' => ['Size' => 'XL'], 'sale_price' => 525, 'stock' => 4, 'is_active' => true]);

            $this->get('http://'.$domain.'/product/'.$product->slug)
                ->assertOk()
                ->assertSee('Size: XL')
                ->assertSee(MoneyFormatter::number(525));
        }
    }

    public function test_marketplace_pro_product_page_still_adds_to_cart_and_buys_now(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->createStore('mp-buy.example.test');
        $product = $this->createProduct('Steel Bolt', 200, 150);

        $this->post('http://mp-buy.example.test/cart/items/'.$product->slug, ['quantity' => 2])->assertRedirect();
        $this->get('http://mp-buy.example.test/cart')->assertSee('Steel Bolt');

        $this->post('http://mp-buy.example.test/cart/items/'.$product->slug, ['quantity' => 1, 'buy_now' => 1])
            ->assertRedirect('http://mp-buy.example.test/checkout');
    }

    public function test_product_page_is_translated_to_bangla(): void
    {
        $this->createStore('mp-product-bangla.example.test');
        $product = $this->createProduct('Steel Bolt', 200, 150);

        app()->setLocale('bn');

        $this->get('http://mp-product-bangla.example.test/product/'.$product->slug)
            ->assertOk()
            ->assertSee('এখনই কিনুন')
            ->assertSee('কার্টে যোগ করুন');
    }

    public function test_marketplace_pro_cart_uses_its_own_view_and_shows_line_totals(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->createStore('mp-cart.example.test');
        $bolt = $this->createProduct('Steel Bolt', 200, 150);
        $nut = $this->createProduct('Brass Nut', 80, null);

        $this->post('http://mp-cart.example.test/cart/items/'.$bolt->slug, ['quantity' => 2]);
        $this->post('http://mp-cart.example.test/cart/items/'.$nut->slug, ['quantity' => 1]);

        $this->get('http://mp-cart.example.test/cart')
            ->assertOk()
            ->assertViewIs('storefront.themes.marketplace-pro.cart.show')
            ->assertSee('Shopping cart')
            ->assertSee('3 items')
            ->assertSee(MoneyFormatter::currency(150).' each')
            // 2 x 150, not the unit price again.
            ->assertSeeInOrder(['data-cart-line-total', MoneyFormatter::currency(300)], false)
            ->assertSeeInOrder(['data-cart-subtotal', MoneyFormatter::currency(380)], false)
            ->assertSee('Calculated at checkout')
            ->assertSee('Proceed to checkout')
            ->assertSee('data-mobile-checkout-bar', false);
    }

    public function test_other_themes_keep_the_shared_cart_view_and_show_the_line_total(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->createStore('builtin-cart.example.test', ['storefront_theme' => 'builtin']);
        $bolt = $this->createProduct('Steel Bolt', 200, 150);
        $nut = $this->createProduct('Brass Nut', 80, null);

        $this->post('http://builtin-cart.example.test/cart/items/'.$bolt->slug, ['quantity' => 2]);
        $this->post('http://builtin-cart.example.test/cart/items/'.$nut->slug, ['quantity' => 1]);

        // 2 x 150 = 300 appears only as the bolt's line total (the subtotal is 380); the
        // row used to print the unit price (150) there.
        $this->get('http://builtin-cart.example.test/cart')
            ->assertOk()
            ->assertViewIs('storefront.cart.show')
            ->assertDontSee('data-mobile-checkout-bar', false)
            ->assertSee('BDT '.MoneyFormatter::number(300))
            ->assertSee('BDT '.MoneyFormatter::number(380));
    }

    public function test_marketplace_pro_cart_update_and_remove_still_work(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->createStore('mp-cart-edit.example.test');
        $product = $this->createProduct('Steel Bolt', 200, 150);

        $this->post('http://mp-cart-edit.example.test/cart/items/'.$product->slug, ['quantity' => 1]);
        $this->patch('http://mp-cart-edit.example.test/cart/items/'.$product->slug, ['quantity' => 4])->assertRedirect();

        $this->get('http://mp-cart-edit.example.test/cart')
            ->assertSee('4 items')
            ->assertSeeInOrder(['data-cart-subtotal', MoneyFormatter::currency(600)], false);

        $this->delete('http://mp-cart-edit.example.test/cart/items/'.$product->slug)->assertRedirect();

        $this->get('http://mp-cart-edit.example.test/cart')
            ->assertSee('Your cart is empty')
            ->assertSee('Browse products')
            ->assertDontSee('data-mobile-checkout-bar', false);
    }

    public function test_marketplace_pro_cart_hints_at_the_next_wholesale_price(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->createStore('mp-cart-tier.example.test');
        $product = $this->createProduct('Steel Bolt', 200, 150);
        $product->update(['tier_prices' => [['min_qty' => 10, 'price' => 140], ['min_qty' => 50, 'price' => 120]]]);

        $this->post('http://mp-cart-tier.example.test/cart/items/'.$product->slug, ['quantity' => 4]);

        $this->get('http://mp-cart-tier.example.test/cart')
            ->assertSee('Add 6 more to pay '.MoneyFormatter::currency(140).' each');

        $this->patch('http://mp-cart-tier.example.test/cart/items/'.$product->slug, ['quantity' => 60]);

        // Already on the deepest tier: nothing left to suggest.
        $this->get('http://mp-cart-tier.example.test/cart')
            ->assertDontSee('more to pay');
    }

    public function test_marketplace_pro_cart_shows_variant_lines_with_their_own_price(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->createStore('mp-cart-variant.example.test');
        $product = $this->createProduct('Cotton Shirt', 500, 450);
        $product->update(['has_variants' => true]);
        $variant = ProductVariant::query()->create(['product_id' => $product->getKey(), 'sku' => 'SHIRT-XL', 'options' => ['Size' => 'XL'], 'sale_price' => 525, 'stock' => 4, 'is_active' => true]);

        $this->post('http://mp-cart-variant.example.test/cart/items/'.$product->slug, ['quantities' => [$variant->getKey() => 2]]);

        $this->get('http://mp-cart-variant.example.test/cart')
            ->assertOk()
            ->assertSee('Size: XL')
            ->assertSee(MoneyFormatter::currency(525).' each')
            ->assertSeeInOrder(['data-cart-line-total', MoneyFormatter::currency(1050)], false)
            ->assertSee('name="variant" value="'.$variant->getKey().'"', false);
    }

    public function test_marketplace_pro_cart_explains_vat_when_it_is_on(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->createStore('mp-cart-vat.example.test', ['vat_enabled' => true, 'vat_rate' => 5, 'vat_mode' => StorefrontSetting::VAT_MODE_EXCLUSIVE]);
        $product = $this->createProduct('Steel Bolt', 200, 150);

        $this->post('http://mp-cart-vat.example.test/cart/items/'.$product->slug, ['quantity' => 1]);

        $this->get('http://mp-cart-vat.example.test/cart')
            ->assertSee('data-cart-vat-note', false)
            ->assertSee('VAT (5%) is added at checkout');

        StorefrontSetting::query()->update(['vat_mode' => StorefrontSetting::VAT_MODE_INCLUSIVE]);

        $this->get('http://mp-cart-vat.example.test/cart')
            ->assertSee('Prices include VAT (5%)');
    }

    public function test_marketplace_pro_cart_is_translated_to_bangla(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->createStore('mp-cart-bangla.example.test');
        $product = $this->createProduct('Steel Bolt', 200, 150);

        $this->post('http://mp-cart-bangla.example.test/cart/items/'.$product->slug, ['quantity' => 2]);

        app()->setLocale('bn');

        $this->get('http://mp-cart-bangla.example.test/cart')
            ->assertOk()
            ->assertSee('শপিং কার্ট')
            ->assertSee('চেকআউটে যান')
            ->assertSee('চেকআউটে হিসাব হবে');
    }

    private function createProduct(string $name, float $price, ?float $salePrice, int $stock = 20): Product
    {
        return Product::query()->create([
            'name' => $name,
            'sku' => str($name)->slug()->upper()->toString(),
            'price' => $price,
            'sale_price' => $salePrice,
            'cost_price' => $price * 0.5,
            'stock' => $stock,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);
    }

    private function createStore(string $domain, array $settings = []): Company
    {
        static $counter = 0;
        $counter++;

        $company = Company::query()->create([
            'name' => 'Store '.$domain,
            'slug' => str($domain)->slug()->toString(),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => 'MPP'.$counter,
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'theme_color' => '#0F766E',
            'is_published' => true,
            'storefront_theme' => 'marketplace_pro',
            ...$settings,
        ]);

        app(CompanyContext::class)->set($company);

        return $company->fresh();
    }
}
