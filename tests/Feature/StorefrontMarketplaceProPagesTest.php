<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StorefrontPaymentMethod;
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

    public function test_marketplace_pro_checkout_uses_its_own_view_and_keeps_every_form_field(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->createStore('mp-checkout.example.test', ['delivery_time_inside' => '1-2 working days']);
        $bolt = $this->createProduct('Steel Bolt', 200, 150);
        $nut = $this->createProduct('Brass Nut', 80, null);

        $this->post('http://mp-checkout.example.test/cart/items/'.$bolt->slug, ['quantity' => 2]);
        $this->post('http://mp-checkout.example.test/cart/items/'.$nut->slug, ['quantity' => 1]);

        $this->get('http://mp-checkout.example.test/checkout')
            ->assertOk()
            ->assertViewIs('storefront.themes.marketplace-pro.checkout.show')
            ->assertSee('Contact details')
            ->assertSee('id="checkout-form"', false)
            ->assertSee('name="name"', false)
            ->assertSee('name="phone"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="address"', false)
            ->assertSee('name="note"', false)
            ->assertSee('Cash on Delivery')
            ->assertSee('Steel Bolt')
            // 2 x 150 as the line total, 380 as the subtotal.
            ->assertSee(MoneyFormatter::currency(300))
            ->assertSee(MoneyFormatter::currency(380))
            ->assertSee('data-checkout-delivery-time', false)
            ->assertSee('1-2 working days')
            ->assertSee('data-mobile-checkout-bar', false);
    }

    public function test_other_themes_keep_the_shared_checkout_view(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->createStore('builtin-checkout.example.test', ['storefront_theme' => 'builtin']);
        $product = $this->createProduct('Steel Bolt', 200, 150);

        $this->post('http://builtin-checkout.example.test/cart/items/'.$product->slug, ['quantity' => 1]);

        $this->get('http://builtin-checkout.example.test/checkout')
            ->assertOk()
            ->assertViewIs('storefront.checkout.show')
            ->assertSee('Delivery details')
            ->assertDontSee('data-mobile-checkout-bar', false);
    }

    public function test_marketplace_pro_checkout_with_an_empty_cart_goes_back_to_the_cart(): void
    {
        $this->createStore('mp-checkout-empty.example.test');

        $this->get('http://mp-checkout-empty.example.test/checkout')
            ->assertRedirect('http://mp-checkout-empty.example.test/cart');
    }

    public function test_marketplace_pro_checkout_shows_vat_and_the_manual_payment_instructions(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $company = $this->createStore('mp-checkout-vat.example.test', ['vat_enabled' => true, 'vat_rate' => 5, 'vat_mode' => StorefrontSetting::VAT_MODE_EXCLUSIVE]);
        StorefrontPaymentMethod::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'type' => StorefrontPaymentMethod::TYPE_MANUAL,
            'name' => 'bKash (Send Money)',
            'account_number' => '01700000000',
            'instructions' => 'Send the full amount, then enter the transaction ID.',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $product = $this->createProduct('Steel Bolt', 200, null);

        $this->post('http://mp-checkout-vat.example.test/cart/items/'.$product->slug, ['quantity' => 2]);

        $this->get('http://mp-checkout-vat.example.test/checkout')
            ->assertOk()
            ->assertSee('data-checkout-vat', false)
            ->assertSee('VAT (5%)')
            ->assertSee(MoneyFormatter::currency(20))
            ->assertSee('bKash (Send Money)')
            ->assertSee('Send Money to')
            ->assertSee('01700000000')
            ->assertSee('Send the full amount, then enter the transaction ID.')
            ->assertSee('name="sender_number"', false)
            ->assertSee('name="trx_id"', false);

        StorefrontSetting::query()->update(['vat_mode' => StorefrontSetting::VAT_MODE_INCLUSIVE]);

        $this->get('http://mp-checkout-vat.example.test/checkout')
            ->assertSee('data-checkout-vat-included', false)
            ->assertSee('Includes VAT (5%)');
    }

    public function test_marketplace_pro_checkout_blocks_ordering_without_a_payment_path(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $company = $this->createStore('mp-checkout-nopay.example.test');
        StorefrontPaymentMethod::withoutGlobalScopes()->where('company_id', $company->getKey())->update(['is_active' => false]);
        $product = $this->createProduct('Steel Bolt', 200, null);

        $this->post('http://mp-checkout-nopay.example.test/cart/items/'.$product->slug, ['quantity' => 1]);

        $response = $this->get('http://mp-checkout-nopay.example.test/checkout')
            ->assertOk()
            ->assertSee('No payment method is available right now.');

        // Both order buttons (the summary one and the phone bar) are disabled.
        $this->assertSame(2, preg_match_all('/<button(?=[^>]*data-checkout-submit)(?=[^>]*disabled)[^>]*>/', $response->getContent()));
    }

    public function test_marketplace_pro_checkout_blocks_a_preorder_when_online_payment_is_unavailable(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->createStore('mp-checkout-preorder.example.test');
        $product = $this->createProduct('Blocked Preorder', 200, null, stock: 0);
        $product->update(['is_preorder' => true, 'preorder_advance_percent' => 30]);

        $this->post('http://mp-checkout-preorder.example.test/cart/items/'.$product->slug, ['quantity' => 1]);

        $response = $this->get('http://mp-checkout-preorder.example.test/checkout')
            ->assertOk()
            ->assertSee('Advance payable online now')
            ->assertSee(MoneyFormatter::currency(60))
            ->assertSee('Online payment is currently unavailable.');

        $this->assertSame(2, preg_match_all('/<button(?=[^>]*data-checkout-submit)(?=[^>]*disabled)[^>]*>/', $response->getContent()));
    }

    public function test_marketplace_pro_checkout_lists_validation_errors(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->createStore('mp-checkout-errors.example.test');
        $product = $this->createProduct('Steel Bolt', 200, null);

        $this->post('http://mp-checkout-errors.example.test/cart/items/'.$product->slug, ['quantity' => 1]);
        $this->post('http://mp-checkout-errors.example.test/checkout', ['name' => '', 'phone' => '', 'address' => '', 'payment_method' => 'cod'])
            ->assertSessionHasErrors(['name', 'phone', 'address']);

        $this->get('http://mp-checkout-errors.example.test/checkout')
            ->assertOk()
            ->assertSee('Please review the highlighted checkout details.')
            ->assertSee('data-checkout-errors', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('mp-field-error', false)
            // An empty address comes back as null; the page's delivery maths needs a string.
            ->assertSee("address: ''", false)
            ->assertDontSee('address: null', false);
    }

    public function test_shared_checkout_keeps_a_string_address_after_a_failed_submit(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->createStore('builtin-checkout-errors.example.test', ['storefront_theme' => 'builtin']);
        $product = $this->createProduct('Steel Bolt', 200, null);

        $this->post('http://builtin-checkout-errors.example.test/cart/items/'.$product->slug, ['quantity' => 1]);
        $this->post('http://builtin-checkout-errors.example.test/checkout', ['name' => '', 'phone' => '', 'address' => '', 'payment_method' => 'cod'])
            ->assertSessionHasErrors(['address']);

        $this->get('http://builtin-checkout-errors.example.test/checkout')
            ->assertOk()
            ->assertViewIs('storefront.checkout.show')
            ->assertSee("address: ''", false)
            ->assertDontSee('address: null', false);
    }

    public function test_marketplace_pro_checkout_still_places_an_order_and_shows_the_success_page(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->createStore('mp-checkout-place.example.test', ['new_customer_delivery_advance_enabled' => false]);
        $product = $this->createProduct('Steel Bolt', 200, 150);

        $this->post('http://mp-checkout-place.example.test/cart/items/'.$product->slug, ['quantity' => 2]);

        $this->post('http://mp-checkout-place.example.test/checkout', [
            'name' => 'Rahim Uddin',
            'phone' => '01712345678',
            'address' => 'House 5, Road 3, Dhanmondi, Dhaka',
            'payment_method' => 'cod',
        ])->assertRedirectContains('/checkout/success/');
    }

    public function test_marketplace_pro_checkout_is_translated_to_bangla(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->createStore('mp-checkout-bangla.example.test');
        $product = $this->createProduct('Steel Bolt', 200, 150);

        $this->post('http://mp-checkout-bangla.example.test/cart/items/'.$product->slug, ['quantity' => 1]);

        app()->setLocale('bn');

        $this->get('http://mp-checkout-bangla.example.test/checkout')
            ->assertOk()
            ->assertSee('চেকআউট')
            ->assertSee('যোগাযোগের তথ্য')
            ->assertSee('অর্ডার করুন / পেমেন্টে যান');
    }

    public function test_marketplace_pro_success_page_shows_the_real_order_amounts(): void
    {
        $this->createStore('mp-success.example.test', ['delivery_first_kg_inside' => 60, 'delivery_time_inside' => '1-2 working days']);
        $product = $this->createProduct('Steel Bolt', 200, 150);

        $successUrl = $this->placeOrder('mp-success.example.test', $product, 2);

        $this->get($successUrl)
            ->assertOk()
            ->assertViewIs('storefront.themes.marketplace-pro.checkout.success')
            ->assertSee('Thank you, Rahim Uddin.')
            ->assertSee('Awaiting confirmation')
            ->assertSee('Steel Bolt')
            ->assertSee(MoneyFormatter::currency(150).' &times; 2', false)
            ->assertSeeInOrder(['Subtotal', MoneyFormatter::currency(300), 'Delivery charge', MoneyFormatter::currency(60), 'Total', MoneyFormatter::currency(360), 'Amount due on delivery', MoneyFormatter::currency(360)])
            ->assertSee('data-order-delivery-time', false)
            ->assertSee('1-2 working days')
            ->assertSee('Track this order')
            ->assertSee('Submit a complaint');
    }

    public function test_shared_success_page_no_longer_prints_the_order_total_on_every_line(): void
    {
        $this->createStore('builtin-success.example.test', ['storefront_theme' => 'builtin', 'delivery_first_kg_inside' => 60]);
        $product = $this->createProduct('Steel Bolt', 200, 150);

        $successUrl = $this->placeOrder('builtin-success.example.test', $product, 2);

        // The subtotal is 300 and the delivery 60 (they used to print the 360 total); the amount due is 360.
        $this->get($successUrl)
            ->assertOk()
            ->assertViewIs('storefront.checkout.success')
            ->assertSeeInOrder(['Subtotal', MoneyFormatter::currency(300), 'Delivery charge', MoneyFormatter::currency(60), 'Amount due on delivery', MoneyFormatter::currency(360)]);
    }

    public function test_marketplace_pro_success_page_lists_vat_and_the_pending_manual_payment(): void
    {
        $company = $this->createStore('mp-success-pay.example.test', [
            'delivery_first_kg_inside' => 60,
            'vat_enabled' => true,
            'vat_rate' => 5,
            'vat_mode' => StorefrontSetting::VAT_MODE_EXCLUSIVE,
        ]);
        $method = StorefrontPaymentMethod::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'type' => StorefrontPaymentMethod::TYPE_MANUAL,
            'name' => 'bKash (Send Money)',
            'account_number' => '01700000000',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $product = $this->createProduct('Steel Bolt', 200, null);

        $successUrl = $this->placeOrder('mp-success-pay.example.test', $product, 2, [
            'payment_method' => 'manual:'.$method->getKey(),
            'sender_number' => '01799998888',
            'trx_id' => 'TRX12345',
        ]);

        $this->get($successUrl)
            ->assertOk()
            // 400 + 5% VAT 20 + delivery 60 = 480.
            ->assertSeeInOrder(['Subtotal', MoneyFormatter::currency(400), 'VAT', MoneyFormatter::currency(20), 'Delivery charge', MoneyFormatter::currency(60), 'Total', MoneyFormatter::currency(480)])
            ->assertSee('data-order-payment', false)
            ->assertSee('bKash (Send Money) payment')
            ->assertSee('We are verifying your payment and will contact you if needed.');
    }

    public function test_marketplace_pro_success_page_is_translated_to_bangla(): void
    {
        $this->createStore('mp-success-bangla.example.test');
        $product = $this->createProduct('Steel Bolt', 200, 150);

        $successUrl = $this->placeOrder('mp-success-bangla.example.test', $product, 1);

        app()->setLocale('bn');

        $this->get($successUrl)
            ->assertOk()
            ->assertSee('ধন্যবাদ, Rahim Uddin।')
            ->assertSee('নিশ্চিতকরণের অপেক্ষায়')
            ->assertSee('এই অর্ডার ট্র্যাক করুন');
    }

    public function test_marketplace_pro_track_page_uses_its_own_view_and_finds_an_order(): void
    {
        $this->createStore('mp-track.example.test', ['delivery_first_kg_inside' => 60, 'delivery_time_inside' => '1-2 working days']);
        $product = $this->createProduct('Steel Bolt', 200, 150);
        $this->placeOrder('mp-track.example.test', $product, 2);
        $order = Order::query()->latest('id')->firstOrFail();

        $this->get('http://mp-track.example.test/track')
            ->assertOk()
            ->assertViewIs('storefront.themes.marketplace-pro.track.show')
            ->assertSee('Track your order')
            ->assertSee('Enter an order number')
            ->assertDontSee('data-track-result', false)
            ->assertDontSee('data-track-not-found', false);

        $this->get('http://mp-track.example.test/track?order_number='.$order->order_number.'&phone=01712345678')
            ->assertRedirect('http://mp-track.example.test/track/'.$order->order_number.'?phone=01712345678');

        $this->get('http://mp-track.example.test/track/'.$order->order_number.'?phone=01712345678')
            ->assertOk()
            ->assertViewIs('storefront.themes.marketplace-pro.track.show')
            ->assertSee('data-track-result', false)
            ->assertSee($order->order_number)
            ->assertSee('Awaiting confirmation')
            ->assertSee('Steel Bolt')
            ->assertSee(MoneyFormatter::currency(150).' &times; 2', false)
            ->assertSeeInOrder(['Subtotal', MoneyFormatter::currency(300), 'Delivery charge', MoneyFormatter::currency(60), 'Total', MoneyFormatter::currency(360), 'Amount due on delivery', MoneyFormatter::currency(360)])
            ->assertSee('1-2 working days')
            ->assertSee('Submit a complaint')
            // The order was only placed: step one is where the order is, the rest is still ahead.
            ->assertSeeInOrder(['data-track-step="placed" data-state="current"', 'data-track-step="confirmed" data-state="todo"', 'data-track-step="on_the_way" data-state="todo"', 'data-track-step="delivered" data-state="todo"'], false);
    }

    public function test_other_themes_keep_the_shared_track_view_and_each_line_shows_its_own_price(): void
    {
        $this->createStore('builtin-track.example.test', ['storefront_theme' => 'builtin', 'delivery_first_kg_inside' => 60]);
        $bolt = $this->createProduct('Steel Bolt', 200, 150);
        $pipe = $this->createProduct('Copper Pipe', 100, null);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->post('http://builtin-track.example.test/cart/items/'.$pipe->slug, ['quantity' => 1]);
        $this->placeOrder('builtin-track.example.test', $bolt, 2);
        $order = Order::query()->latest('id')->firstOrFail();

        // 300 for the bolts and 100 for the pipe; the page used to print the amount due on every line.
        $this->get('http://builtin-track.example.test/track/'.$order->order_number.'?phone=01712345678')
            ->assertOk()
            ->assertViewIs('storefront.track.show')
            ->assertDontSee('data-track-result', false)
            ->assertSeeInOrder(['Steel Bolt', 'Qty 2', MoneyFormatter::currency(300)])
            ->assertSeeInOrder(['Copper Pipe', 'Qty 1', MoneyFormatter::currency(100)]);
    }

    public function test_track_page_needs_the_order_phone_and_never_shows_another_customers_order(): void
    {
        $this->createStore('mp-track-privacy.example.test');
        $product = $this->createProduct('Steel Bolt', 200, 150);
        $this->placeOrder('mp-track-privacy.example.test', $product, 1);
        $order = Order::query()->latest('id')->firstOrFail();

        foreach (['', '?phone=01799999999'] as $query) {
            $this->get('http://mp-track-privacy.example.test/track/'.$order->order_number.$query)
                ->assertOk()
                ->assertSee('data-track-not-found', false)
                ->assertSee('find an order matching')
                ->assertDontSee('Steel Bolt')
                ->assertDontSee('data-track-result', false);
        }

        $this->get('http://mp-track-privacy.example.test/track/NO-SUCH-ORDER?phone=01712345678')
            ->assertOk()
            ->assertSee('data-track-not-found', false);
    }

    public function test_the_track_link_on_the_success_page_opens_the_order_without_a_phone(): void
    {
        $this->createStore('mp-track-link.example.test');
        $product = $this->createProduct('Steel Bolt', 200, 150);
        $successUrl = $this->placeOrder('mp-track-link.example.test', $product, 1);
        $order = Order::query()->latest('id')->firstOrFail();

        $html = $this->get($successUrl)->getContent();
        preg_match('/href="([^"]*\/track\/[^"]+)"/', $html, $match);
        $this->assertNotEmpty($match, 'The success page should link to the order tracking page.');

        $this->get(html_entity_decode($match[1]))
            ->assertOk()
            ->assertViewIs('storefront.themes.marketplace-pro.track.show')
            ->assertSee('data-track-result', false)
            ->assertSee($order->order_number);
    }

    public function test_track_page_progress_follows_the_order_and_delivery_status(): void
    {
        $this->createStore('mp-track-steps.example.test');
        $product = $this->createProduct('Steel Bolt', 200, 150);
        $this->placeOrder('mp-track-steps.example.test', $product, 1);
        $order = Order::query()->latest('id')->firstOrFail();
        $url = 'http://mp-track-steps.example.test/track/'.$order->order_number.'?phone=01712345678';

        $this->setOrderState($order, ['status' => Order::STATUS_CONFIRMED]);
        $this->get($url)
            ->assertSee('Confirmed')
            ->assertSeeInOrder(['data-track-step="placed" data-state="done"', 'data-track-step="confirmed" data-state="current"', 'data-track-step="on_the_way" data-state="todo"'], false);

        $this->setOrderState($order, ['delivery_status' => CourierBooking::STATUS_IN_TRANSIT]);
        $this->get($url)
            ->assertSeeInOrder(['data-track-step="confirmed" data-state="done"', 'data-track-step="on_the_way" data-state="current"', 'data-track-step="delivered" data-state="todo"'], false)
            ->assertDontSee('data-track-delivered', false);

        $this->setOrderState($order, ['delivery_status' => CourierBooking::STATUS_DELIVERED]);
        $this->get($url)
            ->assertSeeInOrder(['data-track-step="on_the_way" data-state="done"', 'data-track-step="delivered" data-state="done"'], false)
            ->assertDontSee('data-state="current"', false)
            ->assertSee('data-track-delivered', false)
            ->assertSee('Your order has been delivered.');
    }

    public function test_a_cancelled_order_stops_the_progress_and_says_so(): void
    {
        $this->createStore('mp-track-cancel.example.test');
        $product = $this->createProduct('Steel Bolt', 200, 150);
        $this->placeOrder('mp-track-cancel.example.test', $product, 1);
        $order = Order::query()->latest('id')->firstOrFail();
        $this->setOrderState($order, ['status' => Order::STATUS_CANCELLED]);

        $this->get('http://mp-track-cancel.example.test/track/'.$order->order_number.'?phone=01712345678')
            ->assertOk()
            ->assertSee('data-track-exception', false)
            ->assertSee('This order was cancelled.')
            ->assertDontSee('data-track-delivered', false)
            ->assertDontSee('data-state="current"', false)
            ->assertDontSee('data-track-delivery-time', false);
    }

    public function test_track_page_shows_the_courier_parcel_and_the_status_updates(): void
    {
        $this->createStore('mp-track-courier.example.test');
        $product = $this->createProduct('Steel Bolt', 200, 150);
        $this->placeOrder('mp-track-courier.example.test', $product, 1);
        $order = Order::query()->latest('id')->firstOrFail();
        $order->update(['delivery_status' => CourierBooking::STATUS_IN_TRANSIT]);
        $provider = CourierProvider::query()->create([
            'name' => 'Manual Courier',
            'driver' => CourierProvider::DRIVER_MANUAL,
            'is_active' => true,
        ]);
        CourierBooking::query()->create([
            'courier_provider_id' => $provider->getKey(),
            'order_id' => $order->getKey(),
            'tracking_id' => 'TRK-998877',
            'recipient_name' => 'Rahim Uddin',
            'status' => CourierBooking::STATUS_IN_TRANSIT,
        ]);

        $this->get('http://mp-track-courier.example.test/track/'.$order->order_number.'?phone=01712345678')
            ->assertOk()
            ->assertSee('data-track-delivery-status', false)
            ->assertSee('Manual Courier')
            ->assertSee('TRK-998877')
            ->assertSee('data-track-updates', false)
            ->assertSee('Delivery status updated to In Transit.')
            ->assertSee('Latest');
    }

    public function test_a_resellers_customer_only_sees_the_resellers_prices_on_the_track_page(): void
    {
        $this->createStore('mp-track-reseller.example.test', ['delivery_first_kg_inside' => 60]);
        $product = $this->createProduct('Steel Bolt', 200, 150);
        $this->placeOrder('mp-track-reseller.example.test', $product, 2);
        $order = Order::query()->latest('id')->firstOrFail();
        $this->setOrderState($order, ['reseller_own_courier' => true]);
        OrderItem::query()->where('order_id', $order->getKey())->update(['reseller_retail_price' => 275]);

        // 2 x 275 = 550 is the reseller's price; the store's 150 / 300 / 360 never show.
        $this->get('http://mp-track-reseller.example.test/track/'.$order->order_number.'?phone=01712345678')
            ->assertOk()
            ->assertSee(MoneyFormatter::currency(550))
            ->assertSee(MoneyFormatter::currency(275).' &times; 2', false)
            ->assertDontSee(MoneyFormatter::currency(150))
            ->assertDontSee(MoneyFormatter::currency(300))
            ->assertDontSee(MoneyFormatter::currency(360))
            ->assertDontSee('Subtotal')
            ->assertDontSee('Delivery charge')
            ->assertDontSee('Amount due on delivery');
    }

    public function test_marketplace_pro_track_page_is_translated_to_bangla(): void
    {
        $this->createStore('mp-track-bangla.example.test');
        $product = $this->createProduct('Steel Bolt', 200, 150);
        $this->placeOrder('mp-track-bangla.example.test', $product, 1);
        $order = Order::query()->latest('id')->firstOrFail();
        $this->setOrderState($order, ['status' => Order::STATUS_CONFIRMED]);

        app()->setLocale('bn');

        $this->get('http://mp-track-bangla.example.test/track/'.$order->order_number.'?phone=01712345678')
            ->assertOk()
            ->assertSee('আপনার অর্ডার ট্র্যাক করুন')
            ->assertSee('অর্ডারের অগ্রগতি')
            ->assertSee('ডেলিভারির সময় দেয়')
            ->assertSee('অর্ডার হয়েছে')
            ->assertDontSee('Order progress');

        $this->get('http://mp-track-bangla.example.test/track/NO-SUCH-ORDER?phone=01712345678')
            ->assertSee('পাওয়া যায়নি')
            ->assertSee('অনুগ্রহ করে');
    }

    /** Moves the order to a status without the stock and audit side effects of a staff status change. */
    private function setOrderState(Order $order, array $state): void
    {
        Order::query()->whereKey($order->getKey())->toBase()->update($state);
    }

    /** Puts the product in the cart, places a cash-on-delivery order and returns the signed success URL. */
    private function placeOrder(string $host, Product $product, int $quantity, array $checkout = []): string
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        StorefrontSetting::query()->update(['new_customer_delivery_advance_enabled' => false]);

        $this->post('http://'.$host.'/cart/items/'.$product->slug, ['quantity' => $quantity]);

        $response = $this->post('http://'.$host.'/checkout', $checkout + [
            'name' => 'Rahim Uddin',
            'phone' => '01712345678',
            'address' => 'House 5, Road 3, Dhanmondi, Dhaka',
            'payment_method' => 'cod',
        ])->assertRedirectContains('/checkout/success/');

        return $response->headers->get('Location');
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
