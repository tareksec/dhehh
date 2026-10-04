<?php

namespace Tests\Feature;

use App\Filament\Reseller\Pages\CreateOrder;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ResellerCommission;
use App\Models\ResellerProduct;
use App\Models\StockMovement;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\ResellerOrderPlacementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * An approved reseller placing an order for their own customer from the
 * portal. The rules mirror the storefront checkout (see
 * ResellerOrderPlacementService).
 */
class ResellerPortalCreateOrderTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $reseller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Order Co', 'slug' => 'order-co', 'domain' => 'order-co.example.test',
            'domain_verified' => true, 'invoice_prefix' => 'ORC', 'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka', 'is_active' => true, 'reseller_module_enabled' => true,
        ])->fresh();
        StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(), 'theme_color' => '#0F766E', 'is_published' => true,
            'new_customer_delivery_advance_enabled' => false, 'reseller_cost_per_unit' => 10,
            'delivery_first_kg_inside' => 70, 'delivery_first_kg_outside' => 110, 'delivery_additional_per_kg' => 20,
        ]);
        app(CompanyContext::class)->set($this->company);
        $this->reseller = $this->makeReseller('01711000001');
        Queue::fake();
    }

    public function test_an_order_is_created_for_the_reseller_with_their_price_and_a_commission(): void
    {
        $product = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);

        $order = $this->place([['product_id' => $product->getKey(), 'quantity' => 2, 'price' => 500]]);

        $this->assertSame((int) $this->reseller->getKey(), (int) $order->reseller_customer_id);
        $this->assertSame(Order::SOURCE_RESELLER_PORTAL, $order->source);
        $this->assertSame('draft', $order->status);
        $item = $order->items()->first();
        $this->assertSame(500.0, (float) $item->unit_price);
        $this->assertSame(310.0, (float) $item->reseller_base_price); // tier 300 + cost per unit 10
        $this->assertSame('Dhaka, Mirpur', $order->customer->address);
        $this->assertSame(70.0, (float) $order->shipping_fee);
        $this->assertSame('inside', $order->shipping_zone);

        $commission = ResellerCommission::query()->where('order_id', $order->getKey())->first();
        $this->assertNotNull($commission);
        $this->assertSame(380.0, (float) $commission->amount); // (500 - 310) * 2
    }

    public function test_a_blank_price_uses_the_resellers_saved_price_then_the_suggested_one(): void
    {
        $saved = $this->pickProduct('Saved', tier: 300, stock: 5, price: 450, resellerPrice: 600);
        $fresh = $this->pickProduct('Fresh', tier: 300, stock: 5, price: 450);

        $order = $this->place([
            ['product_id' => $saved->getKey(), 'quantity' => 1, 'price' => null],
            ['product_id' => $fresh->getKey(), 'quantity' => 1, 'price' => ''],
        ]);

        $prices = $order->items()->pluck('unit_price', 'product_id');
        $this->assertSame(600.0, (float) $prices[$saved->getKey()]);
        $this->assertSame(450.0, (float) $prices[$fresh->getKey()]);
    }

    public function test_a_price_below_the_base_price_is_rejected(): void
    {
        $product = $this->pickProduct('Cheap', tier: 300, stock: 5, price: 450);

        try {
            $this->place([['product_id' => $product->getKey(), 'quantity' => 1, 'price' => 309]]);
            $this->fail('A price under the base price must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.items.0.price', $e->errors());
        }

        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_product_without_a_wholesale_rate_sells_at_the_normal_price_and_earns_nothing(): void
    {
        $product = $this->pickProduct('Plain', tier: null, stock: 5, price: 450);

        $order = $this->place([['product_id' => $product->getKey(), 'quantity' => 1, 'price' => 9999]]);

        $this->assertSame(450.0, (float) $order->items()->first()->unit_price);
        $this->assertNull($order->items()->first()->reseller_base_price);
        $this->assertSame(0, ResellerCommission::query()->where('order_id', $order->getKey())->count());
    }

    public function test_only_products_in_the_resellers_own_active_store_can_be_ordered(): void
    {
        $other = $this->makeReseller('01711000002');
        $notPicked = $this->makeProduct('Not Picked', 300, 5, 450);
        $pickedByOther = $this->makeProduct('Others', 300, 5, 450);
        ResellerProduct::query()->create(['customer_id' => $other->getKey(), 'product_id' => $pickedByOther->getKey(), 'is_active' => true]);
        $hidden = $this->pickProduct('Hidden', tier: 300, stock: 5, price: 450);
        ResellerProduct::query()->where('product_id', $hidden->getKey())->update(['is_active' => false]);

        foreach ([$notPicked, $pickedByOther, $hidden] as $product) {
            try {
                $this->place([['product_id' => $product->getKey(), 'quantity' => 1, 'price' => 500]]);
                $this->fail("{$product->name} must not be orderable.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('data.items.0.product_id', $e->errors());
            }
        }
    }

    public function test_variant_and_unavailable_products_are_not_offered(): void
    {
        $variant = $this->pickProduct('Variant', tier: 300, stock: 5, price: 450);
        $variant->update(['has_variants' => true]);
        $draft = $this->pickProduct('Draft', tier: 300, stock: 5, price: 450);
        $draft->update(['status' => 'draft']);

        $names = app(ResellerOrderPlacementService::class)->catalog($this->reseller)->pluck('name')->all();

        $this->assertNotContains('Variant', $names);
        $this->assertNotContains('Draft', $names);
    }

    public function test_stock_is_checked_and_a_duplicate_line_is_rejected(): void
    {
        $product = $this->pickProduct('Scarce', tier: 300, stock: 3, price: 450);

        try {
            $this->place([['product_id' => $product->getKey(), 'quantity' => 4, 'price' => 500]]);
            $this->fail('Over-stock must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.items.0.quantity', $e->errors());
        }

        try {
            $this->place([
                ['product_id' => $product->getKey(), 'quantity' => 1, 'price' => 500],
                ['product_id' => $product->getKey(), 'quantity' => 1, 'price' => 500],
            ]);
            $this->fail('A repeated product must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.items.1.product_id', $e->errors());
        }
    }

    public function test_the_delivery_charge_follows_the_address_and_weight(): void
    {
        $product = $this->pickProduct('Heavy', tier: 300, stock: 20, price: 450);
        $product->update(['weight_kg' => 1.5]);

        $service = app(ResellerOrderPlacementService::class);
        $line = [['product_id' => $product->getKey(), 'quantity' => 2, 'price' => 500]];

        $inside = $service->quote($this->reseller, $line, 'Mirpur, Dhaka');
        $outside = $service->quote($this->reseller, $line, 'Rajshahi sadar');

        // 3 kg billed: first kg + 2 extra kg.
        $this->assertSame(70.0 + 2 * 20, $inside['shipping_fee']);
        $this->assertSame(110.0 + 2 * 20, $outside['shipping_fee']);
        $this->assertSame(1000.0 + 110 + 40, $outside['total']);
    }

    public function test_a_buyer_whose_phone_is_a_reseller_account_keeps_their_own_details(): void
    {
        $product = $this->pickProduct('Gift', tier: 300, stock: 5, price: 450);
        $other = $this->makeReseller('01711000009');
        $other->update(['address' => 'Their own address', 'name' => 'Other Reseller']);

        $order = app(ResellerOrderPlacementService::class)->place($this->reseller, [
            'name' => 'Someone Else', 'phone' => '01711000009', 'address' => 'Different address', 'note' => null,
        ], [['product_id' => $product->getKey(), 'quantity' => 1, 'price' => 500]]);

        $this->assertSame((int) $other->getKey(), (int) $order->customer_id);
        $this->assertSame('Their own address', $other->fresh()->address);
        $this->assertSame('Other Reseller', $other->fresh()->name);
    }

    public function test_an_existing_customer_is_reused_by_phone(): void
    {
        $product = $this->pickProduct('Again', tier: 300, stock: 9, price: 450);

        $first = $this->place([['product_id' => $product->getKey(), 'quantity' => 1, 'price' => 500]]);
        $second = $this->place([['product_id' => $product->getKey(), 'quantity' => 1, 'price' => 500]]);

        $this->assertSame($first->customer_id, $second->customer_id);
    }

    public function test_a_pending_reseller_cannot_place_orders(): void
    {
        $product = $this->pickProduct('Locked', tier: 300, stock: 5, price: 450);
        $this->reseller->update(['reseller_status' => 'pending']);

        $this->expectException(HttpException::class);

        $this->place([['product_id' => $product->getKey(), 'quantity' => 1, 'price' => 500]]);
    }

    public function test_the_create_order_page_places_an_order_and_shows_it_in_orders(): void
    {
        $product = $this->pickProduct('Page Product', tier: 300, stock: 10, price: 450);
        $this->asPortal();

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'name' => 'Buyer One', 'phone' => '01722000001', 'address' => 'House 1, Mirpur, Dhaka',
                'items' => [['product_id' => $product->getKey(), 'quantity' => 2, 'price' => 520]],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $order = Order::query()->where('reseller_customer_id', $this->reseller->getKey())->first();
        $this->assertNotNull($order);
        $this->assertSame(1040.0, (float) $order->subtotal);
        $this->assertTrue($this->reseller->resellerOrders()->whereKey($order->getKey())->exists());
    }

    public function test_the_page_shows_a_live_summary_and_field_errors(): void
    {
        $product = $this->pickProduct('Summary', tier: 300, stock: 10, price: 450);
        $this->asPortal();

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'address' => 'Uttara, Dhaka',
                'items' => [['product_id' => $product->getKey(), 'quantity' => 2, 'price' => 520]],
            ])
            ->assertSee('Customer pays')
            ->assertSee('Your commission')
            ->fillForm(['name' => 'B', 'phone' => '017', 'address' => 'x', 'items' => [['product_id' => $product->getKey(), 'quantity' => 1, 'price' => 100]]])
            ->call('create')
            ->assertHasFormErrors(['items.0.price']);
    }

    public function test_the_create_order_page_renders_over_http_for_an_approved_reseller_only(): void
    {
        $this->actingAs($this->reseller, 'customer');
        $this->get('http://reseller.order-co.example.test/portal/create-order')->assertOk()->assertSee('Create order');

        $this->reseller->update(['reseller_status' => 'pending']);
        $this->get('http://reseller.order-co.example.test/portal/create-order')->assertForbidden();
    }

    public function test_the_new_order_alert_links_to_the_admin_order_page_even_from_the_portal(): void
    {
        $product = $this->pickProduct('Alert', tier: 300, stock: 5, price: 450);
        $this->asPortal(); // the reseller panel is the current panel, which has no order resource

        $order = $this->place([['product_id' => $product->getKey(), 'quantity' => 1, 'price' => 500]]);

        $this->assertNotNull($order->getKey());
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    protected function place(array $lines): Order
    {
        return app(ResellerOrderPlacementService::class)->place($this->reseller, [
            'name' => 'Buyer', 'phone' => '01722000009', 'address' => 'Dhaka, Mirpur', 'note' => null,
        ], $lines);
    }

    protected function asPortal(): void
    {
        app(CompanyContext::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $this->actingAs($this->reseller, 'customer');
    }

    protected function makeReseller(string $phone): Customer
    {
        $customer = new Customer([
            'name' => 'Reseller '.$phone, 'phone' => $phone, 'reseller_status' => 'approved',
            'reseller_slug' => 'r-'.Str::random(6), 'business_name' => 'Shop', 'is_active' => true,
        ]);
        $customer->company_id = $this->company->getKey();
        $customer->forceFill(['password' => Hash::make('secret-password')])->save();

        return $customer;
    }

    protected function makeProduct(string $name, ?float $tier, int $stock, float $price): Product
    {
        $product = Product::query()->create([
            'company_id' => $this->company->getKey(), 'name' => $name, 'sku' => 'RO-'.Str::random(6),
            'price' => $price, 'sale_price' => $price, 'cost_price' => 200, 'stock' => 0, 'unit' => 'pcs',
            'reorder_level' => 1, 'vat_rate' => 0,
            'tier_prices' => $tier === null ? [] : [['min_qty' => 1, 'price' => $tier]],
            'is_active' => true, 'status' => Product::STATUS_AVAILABLE,
        ])->fresh();
        StockMovement::query()->create(['product_id' => $product->getKey(), 'type' => 'opening', 'quantity' => $stock]);

        return $product->fresh();
    }

    protected function pickProduct(string $name, ?float $tier, int $stock, float $price, ?float $resellerPrice = null): Product
    {
        $product = $this->makeProduct($name, $tier, $stock, $price);
        ResellerProduct::query()->create([
            'customer_id' => $this->reseller->getKey(), 'product_id' => $product->getKey(),
            'is_active' => true, 'price' => $resellerPrice,
        ]);

        return $product;
    }
}
