<?php

namespace Tests\Feature;

use App\Filament\Reseller\Pages\CreateOrder;
use App\Filament\Reseller\Pages\EditOrder;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ResellerCommission;
use App\Models\ResellerProduct;
use App\Models\StorefrontPayment;
use App\Models\StorefrontPaymentMethod;
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
use Tests\TestCase;

/**
 * Owner decisions (2026-10-12, 2026-10-14): the portal can order products with
 * variations. What the reseller pays for one is its own (wholesale) price plus
 * the company's reseller cost per unit; they set their own customer price per
 * variation at or above it and earn the difference; stock is the variation's
 * own. With the 10 cost per unit set below: M costs 701, L 743, XL 887.
 */
class ResellerVariationOrderTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $reseller;

    protected Product $product;

    protected ProductVariant $medium;

    protected ProductVariant $large;

    protected ProductVariant $extraLarge;

    protected StorefrontPaymentMethod $bkash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Var Order Co', 'slug' => 'var-order-co', 'domain' => 'var-order-co.example.test',
            'domain_verified' => true, 'invoice_prefix' => 'VOC', 'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka', 'is_active' => true, 'reseller_module_enabled' => true,
        ])->fresh();
        StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(), 'theme_color' => '#0F766E', 'is_published' => true,
            'new_customer_delivery_advance_enabled' => false, 'reseller_cost_per_unit' => 10, 'reseller_packaging_cost_per_unit' => 5,
            'delivery_first_kg_inside' => 70, 'delivery_first_kg_outside' => 110, 'delivery_additional_per_kg' => 20,
        ]);
        app(CompanyContext::class)->set($this->company);
        $this->bkash = StorefrontPaymentMethod::query()->create([
            'company_id' => $this->company->getKey(), 'type' => StorefrontPaymentMethod::TYPE_MANUAL,
            'name' => 'bKash', 'account_number' => '01800000000', 'is_active' => true,
        ]);
        $this->reseller = $this->makeReseller('01711000001');

        $category = Category::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Shirts', 'slug' => 'shirts', 'is_active' => true]);
        $this->product = Product::query()->create([
            'company_id' => $this->company->getKey(), 'category_id' => $category->getKey(), 'name' => 'Polo Shirt', 'sku' => 'POLO-1',
            'price' => 650, 'sale_price' => 650, 'cost_price' => 300, 'stock' => 0, 'unit' => 'pcs', 'reorder_level' => 1,
            'vat_rate' => 0, 'has_variants' => true, 'is_active' => true, 'status' => Product::STATUS_AVAILABLE,
        ])->fresh();
        // The admin suggests a selling price for each (790, 800, 900).
        $this->medium = $this->variant('M', sale: 691, stock: 5, suggested: 790);
        $this->large = $this->variant('L', sale: 733, stock: 5, suggested: 800);
        $this->extraLarge = $this->variant('XL', sale: 877, stock: 5, suggested: 900);
        ResellerProduct::query()->create([
            'customer_id' => $this->reseller->getKey(), 'product_id' => $this->product->getKey(), 'is_active' => true,
            'variant_prices' => [$this->medium->getKey() => 750],
        ]);
        Queue::fake();
    }

    public function test_an_order_for_variations_uses_the_resellers_prices_and_records_the_commission(): void
    {
        $order = $this->place([
            ['product_id' => $this->product->getKey(), 'variant_id' => $this->medium->getKey(), 'quantity' => 2, 'price' => 780],
            ['product_id' => $this->product->getKey(), 'variant_id' => $this->large->getKey(), 'quantity' => 1, 'price' => null],
            ['product_id' => $this->product->getKey(), 'variant_id' => $this->extraLarge->getKey(), 'quantity' => 1, 'price' => 950],
        ]);

        $items = $order->items()->get()->keyBy('product_variant_id');
        $medium = $items[$this->medium->getKey()];
        $this->assertSame(780.0, (float) $medium->unit_price);
        $this->assertSame(701.0, (float) $medium->reseller_base_price);
        $this->assertSame('Size: M', $medium->variant_label);
        $this->assertSame(300.0, (float) $medium->unit_cost);
        // Blank price = the reseller's saved price, else the admin's suggested one: L has nothing saved -> 800.
        $this->assertSame(800.0, (float) $items[$this->large->getKey()]->unit_price);
        $this->assertSame(743.0, (float) $items[$this->large->getKey()]->reseller_base_price);
        $this->assertSame(950.0, (float) $items[$this->extraLarge->getKey()]->unit_price);
        $this->assertSame(887.0, (float) $items[$this->extraLarge->getKey()]->reseller_base_price);

        $commission = ResellerCommission::query()->where('order_id', $order->getKey())->sole();
        $this->assertSame((780 - 701) * 2.0 + (800 - 743) + (950 - 887), (float) $commission->amount);
        $this->assertSame((int) $this->reseller->getKey(), (int) $order->reseller_customer_id);
    }

    public function test_a_variation_line_needs_a_valid_option_of_that_product(): void
    {
        $other = Product::query()->create([
            'company_id' => $this->company->getKey(), 'name' => 'Other Shirt', 'sku' => 'OTH-1', 'price' => 500, 'sale_price' => 500,
            'cost_price' => 200, 'stock' => 0, 'unit' => 'pcs', 'reorder_level' => 1, 'vat_rate' => 0, 'has_variants' => true,
            'is_active' => true, 'status' => Product::STATUS_AVAILABLE,
        ]);
        $foreign = ProductVariant::query()->create(['company_id' => $this->company->getKey(), 'product_id' => $other->getKey(), 'options' => ['Size' => 'S'], 'sale_price' => 500, 'stock' => 5, 'is_active' => true]);
        $this->medium->update(['is_active' => false]);

        foreach ([null, '', 0, $foreign->getKey(), $this->medium->getKey(), 987654] as $variantId) {
            $errors = $this->errorsFor(fn () => $this->place([['product_id' => $this->product->getKey(), 'variant_id' => $variantId, 'quantity' => 1, 'price' => 800]]));
            $this->assertSame(['data.items.0.variant_id'], array_keys($errors), 'variant '.var_export($variantId, true));
        }

        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_price_below_what_the_reseller_pays_for_the_variation_is_refused(): void
    {
        $errors = $this->errorsFor(fn () => $this->place([['product_id' => $this->product->getKey(), 'variant_id' => $this->medium->getKey(), 'quantity' => 1, 'price' => 700.99]]));

        $this->assertSame(['data.items.0.price'], array_keys($errors));
        $this->assertSame(0, Order::query()->count());

        $this->place([['product_id' => $this->product->getKey(), 'variant_id' => $this->medium->getKey(), 'quantity' => 1, 'price' => 701]]);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_the_stock_that_counts_is_the_variations_own(): void
    {
        $this->medium->update(['stock' => 2]);

        $errors = $this->errorsFor(fn () => $this->place([['product_id' => $this->product->getKey(), 'variant_id' => $this->medium->getKey(), 'quantity' => 3, 'price' => 720]]));
        $this->assertSame(['data.items.0.quantity'], array_keys($errors));

        // The product-level total (12) is not what limits the order; the variation's stock (2) is.
        $this->assertSame(12, (int) $this->product->fresh()->stock);
        $this->place([['product_id' => $this->product->getKey(), 'variant_id' => $this->medium->getKey(), 'quantity' => 2, 'price' => 720]]);

        // Pre-order products are not stock-checked, as at checkout.
        $this->product->update(['is_preorder' => true]);
        $this->place([['product_id' => $this->product->getKey(), 'variant_id' => $this->medium->getKey(), 'quantity' => 9, 'price' => 720]]);
        $this->assertSame(2, Order::query()->count());
    }

    public function test_the_same_variation_twice_is_refused_but_two_variations_of_one_product_are_fine(): void
    {
        $line = fn (ProductVariant $variant): array => ['product_id' => $this->product->getKey(), 'variant_id' => $variant->getKey(), 'quantity' => 1, 'price' => 800];

        $errors = $this->errorsFor(fn () => $this->place([$line($this->medium), $line($this->medium)]));
        $this->assertSame(['data.items.1.product_id'], array_keys($errors));

        $order = $this->place([$line($this->medium), $line($this->large)]);
        $this->assertSame(2, $order->items()->count());
    }

    public function test_a_product_whose_variations_are_all_off_is_not_in_the_catalog(): void
    {
        $service = app(ResellerOrderPlacementService::class);
        $this->assertTrue($service->catalog($this->reseller)->contains('id', $this->product->getKey()));

        ProductVariant::query()->where('product_id', $this->product->getKey())->update(['is_active' => false]);

        $this->assertFalse($service->catalog($this->reseller->fresh())->contains('id', $this->product->getKey()));
    }

    public function test_a_variation_of_a_product_outside_the_resellers_store_cannot_be_ordered(): void
    {
        ResellerProduct::query()->where('product_id', $this->product->getKey())->delete();

        $errors = $this->errorsFor(fn () => $this->place([['product_id' => $this->product->getKey(), 'variant_id' => $this->medium->getKey(), 'quantity' => 1, 'price' => 800]]));

        $this->assertSame(['data.items.0.product_id'], array_keys($errors));
    }

    public function test_another_resellers_variation_price_never_changes_this_resellers_order(): void
    {
        $other = $this->makeReseller('01722222222');
        ResellerProduct::query()->create(['customer_id' => $other->getKey(), 'product_id' => $this->product->getKey(), 'is_active' => true, 'variant_prices' => [$this->medium->getKey() => 999]]);

        $order = $this->place([['product_id' => $this->product->getKey(), 'variant_id' => $this->medium->getKey(), 'quantity' => 1, 'price' => null]]);

        $this->assertSame(750.0, (float) $order->items()->sole()->unit_price); // this reseller's saved price, not 999
    }

    public function test_an_own_courier_order_charges_the_store_price_of_the_variation_and_keeps_the_customer_price(): void
    {
        $order = $this->place([
            ['product_id' => $this->product->getKey(), 'variant_id' => $this->medium->getKey(), 'quantity' => 2, 'price' => 720],
            ['product_id' => $this->product->getKey(), 'variant_id' => $this->extraLarge->getKey(), 'quantity' => 1, 'price' => 950],
        ], ownCourier: true);

        $items = $order->items()->get()->keyBy('product_variant_id');
        // The reseller pays what the store charges: the variation's price + 10 per unit -- 701 for M, 887 for XL.
        $this->assertSame(701.0, (float) $items[$this->medium->getKey()]->unit_price);
        $this->assertSame(720.0, (float) $items[$this->medium->getKey()]->reseller_retail_price);
        $this->assertSame(887.0, (float) $items[$this->extraLarge->getKey()]->unit_price);
        $this->assertSame(950.0, (float) $items[$this->extraLarge->getKey()]->reseller_retail_price);
        // 701*2 + 887 + packaging 5 per piece (3 pieces).
        $this->assertSame(701 * 2 + 887 + 15.0, (float) $order->total_amount);
        // No commission on own-courier orders.
        $this->assertNull($items[$this->medium->getKey()]->reseller_base_price);
        $this->assertSame('Size: M', $items[$this->medium->getKey()]->variant_label);
        $this->assertSame(1, StorefrontPayment::query()->where('order_id', $order->getKey())->count());
    }

    public function test_the_resellers_customer_never_sees_the_store_price_of_a_variation(): void
    {
        $order = $this->place([['product_id' => $this->product->getKey(), 'variant_id' => $this->medium->getKey(), 'quantity' => 1, 'price' => 720]], ownCourier: true);

        // Buyer-facing amounts use the reseller's retail price on an own-courier order.
        $this->assertTrue($order->showsResellerPricesToBuyer());
        $this->assertSame(720.0, (float) $order->items()->sole()->buyerUnitPrice());
    }

    public function test_the_reseller_adds_a_variation_to_an_own_courier_order_and_pays_only_the_extra(): void
    {
        $service = app(ResellerOrderPlacementService::class);
        $order = $this->place([['product_id' => $this->product->getKey(), 'variant_id' => $this->medium->getKey(), 'quantity' => 1, 'price' => 720]], ownCourier: true);
        $kept = $order->items()->sole();
        $before = (float) $order->total_amount; // 701 + 5

        $lines = [
            ['item_id' => $kept->getKey(), 'product_id' => $this->product->getKey(), 'variant_id' => $this->medium->getKey(), 'quantity' => 1, 'price' => 720],
            ['item_id' => null, 'product_id' => $this->product->getKey(), 'variant_id' => $this->large->getKey(), 'quantity' => 2, 'price' => 800],
        ];
        $quote = $service->quoteEdit($this->reseller, $order, $lines);
        $this->assertSame(743 * 2 + 10.0, $quote['extra']); // 2 pieces x 743 + packaging 10

        $updated = $service->updateOwnCourierOrder($this->reseller, $order, [
            'name' => 'Buyer', 'phone' => '01722000009', 'address' => 'Dhaka, Mirpur',
        ], $lines, ['courier_name' => 'Pathao', 'parcel_id' => 'PATHAO-123'], [
            'payment_method_id' => $this->bkash->getKey(), 'sender' => '01999999999', 'transaction_id' => 'TRX2',
        ]);

        $new = $updated->items()->where('product_variant_id', $this->large->getKey())->sole();
        $this->assertSame(743.0, (float) $new->unit_price);
        $this->assertSame(800.0, (float) $new->reseller_retail_price);
        $this->assertSame('Size: L', $new->variant_label);
        $this->assertSame($before + 743 * 2 + 10, (float) $updated->total_amount);
    }

    public function test_adding_a_variation_to_an_order_checks_that_variations_own_stock(): void
    {
        $service = app(ResellerOrderPlacementService::class);
        $order = $this->place([['product_id' => $this->product->getKey(), 'variant_id' => $this->medium->getKey(), 'quantity' => 1, 'price' => 720]], ownCourier: true);
        $kept = $order->items()->sole();
        $this->large->update(['stock' => 1]);

        $errors = $this->errorsFor(fn () => $service->quoteEdit($this->reseller, $order, [
            ['item_id' => $kept->getKey(), 'product_id' => $this->product->getKey(), 'variant_id' => $this->medium->getKey(), 'quantity' => 1, 'price' => 720],
            ['item_id' => null, 'product_id' => $this->product->getKey(), 'variant_id' => $this->large->getKey(), 'quantity' => 2, 'price' => 800],
        ]));

        $this->assertSame(['data.items.1.quantity'], array_keys($errors));

        // The same variation cannot be added a second time as a new line.
        $errors = $this->errorsFor(fn () => $service->quoteEdit($this->reseller, $order, [
            ['item_id' => $kept->getKey(), 'product_id' => $this->product->getKey(), 'variant_id' => $this->medium->getKey(), 'quantity' => 1, 'price' => 720],
            ['item_id' => null, 'product_id' => $this->product->getKey(), 'variant_id' => $this->medium->getKey(), 'quantity' => 1, 'price' => 800],
        ]));
        $this->assertSame(['data.items.1.product_id'], array_keys($errors));
    }

    public function test_a_variation_with_no_suggested_price_needs_the_resellers_own_price(): void
    {
        $small = $this->variant('S', sale: 500, stock: 5); // base 510, no suggested price
        $line = fn (mixed $price): array => ['product_id' => $this->product->getKey(), 'variant_id' => $small->getKey(), 'quantity' => 1, 'price' => $price];

        // Neither a price on the line nor a saved one: it is not sold at the wholesale rate.
        $errors = $this->errorsFor(fn () => $this->place([$line(null)]));
        $this->assertSame(['data.items.0.price'], array_keys($errors));
        $errors = $this->errorsFor(fn () => $this->place([$line(null)], ownCourier: true));
        $this->assertSame(['data.items.0.price'], array_keys($errors));

        // Below what the reseller pays is still refused...
        $errors = $this->errorsFor(fn () => $this->place([$line(505)]));
        $this->assertSame(['data.items.0.price'], array_keys($errors));

        // ...their own price works...
        $order = $this->place([$line(600)]);
        $this->assertSame(600.0, (float) $order->items()->sole()->unit_price);
        $this->assertSame(510.0, (float) $order->items()->sole()->reseller_base_price);

        // ...and so does a price they saved in Variation prices.
        ResellerProduct::query()->where('customer_id', $this->reseller->getKey())->update(['variant_prices' => [$small->getKey() => 640]]);
        $order = $this->place([$line(null)]);
        $this->assertSame(640.0, (float) $order->items()->sole()->unit_price);
    }

    public function test_a_variation_without_a_price_cannot_be_ordered_at_all(): void
    {
        $free = ProductVariant::query()->create([
            'company_id' => $this->company->getKey(), 'product_id' => $this->product->getKey(), 'sku' => 'POLO-Z',
            'options' => ['Size' => 'Z'], 'sale_price' => 0, 'reseller_suggested_price' => 700, 'cost_price' => 300, 'stock' => 5, 'is_active' => true,
        ]);
        $this->product->update(['sale_price' => 0, 'price' => 0]);

        $errors = $this->errorsFor(fn () => $this->place([['product_id' => $this->product->getKey(), 'variant_id' => $free->getKey(), 'quantity' => 1, 'price' => 700]]));
        $this->assertSame(['data.items.0.variant_id'], array_keys($errors));
    }

    public function test_the_portal_form_asks_for_an_option_and_prices_the_line_once_it_is_chosen(): void
    {
        $plain = Product::query()->create([
            'company_id' => $this->company->getKey(), 'name' => 'Plain Cap', 'sku' => 'CAP-1', 'price' => 300, 'sale_price' => 300,
            'cost_price' => 150, 'stock' => 9, 'unit' => 'pcs', 'reorder_level' => 1, 'vat_rate' => 0,
            'tier_prices' => [['min_qty' => 1, 'price' => 200]], 'is_active' => true, 'status' => Product::STATUS_AVAILABLE,
        ]);
        ResellerProduct::query()->create(['customer_id' => $this->reseller->getKey(), 'product_id' => $plain->getKey(), 'is_active' => true]);
        $this->asPortal();

        $page = Livewire::test(CreateOrder::class);
        $key = array_key_first($page->get('data.items'));

        // A product with options shows the option picker and has no price until one is chosen...
        $page->set("data.items.{$key}.product_id", $this->product->getKey())
            ->assertFormFieldVisible("items.{$key}.variant_id")
            ->assertSet("data.items.{$key}.price", null)
            ->call('create')
            ->assertHasFormErrors(["items.{$key}.variant_id" => 'required']);

        // ...then it is priced with the reseller's saved price for that option.
        $page->set("data.items.{$key}.variant_id", $this->medium->getKey())
            ->assertSet("data.items.{$key}.price", 750)
            ->set("data.items.{$key}.variant_id", $this->large->getKey())
            ->assertSet("data.items.{$key}.price", 800); // nothing saved: the admin's suggested price

        // A plain product never shows it.
        $page->set("data.items.{$key}.product_id", $plain->getKey())
            ->assertFormFieldHidden("items.{$key}.variant_id")
            ->assertSet("data.items.{$key}.variant_id", null);

        // And the whole order goes through with the chosen option.
        $page->set("data.items.{$key}.product_id", $this->product->getKey())
            ->set("data.items.{$key}.variant_id", $this->medium->getKey())
            ->set("data.items.{$key}.quantity", 2)
            ->set('data.name', 'Buyer')
            ->set('data.phone', '01722000009')
            ->set('data.address', 'Dhaka, Mirpur')
            ->call('create')
            ->assertHasNoFormErrors();

        $item = Order::query()->sole()->items()->sole();
        $this->assertSame($this->medium->getKey(), $item->product_variant_id);
        $this->assertSame(750.0, (float) $item->unit_price);
        $this->assertSame(2, (int) $item->quantity);
    }

    public function test_the_edit_page_keeps_each_existing_lines_option(): void
    {
        $order = $this->place([['product_id' => $this->product->getKey(), 'variant_id' => $this->medium->getKey(), 'quantity' => 1, 'price' => 720]], ownCourier: true);
        $this->asPortal();

        $page = Livewire::test(EditOrder::class, ['record' => $order->getKey()]);
        $key = array_key_first($page->get('data.items'));

        $page->assertSet("data.items.{$key}.variant_id", $this->medium->getKey())
            ->assertSet("data.items.{$key}.product_id", $this->product->getKey())
            ->assertSee('Size: M');
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function place(array $lines, bool $ownCourier = false): Order
    {
        return app(ResellerOrderPlacementService::class)->place($this->reseller, [
            'name' => 'Buyer', 'phone' => '01722000009', 'address' => 'Dhaka, Mirpur', 'note' => null,
        ], $lines, $ownCourier ? [
            'courier_name' => 'Pathao', 'parcel_id' => 'PATHAO-'.Str::random(5),
            'payment_method_id' => $this->bkash->getKey(), 'sender' => '01999999999', 'transaction_id' => 'TRX-'.Str::random(5),
        ] : null);
    }

    /** @return array<string, array<int, string>> */
    protected function errorsFor(callable $callback): array
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('Expected a validation error.');
    }

    protected function asPortal(): void
    {
        app(CompanyContext::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $this->actingAs($this->reseller, 'customer');
    }

    protected function variant(string $size, float $sale, int $stock, ?float $suggested = null): ProductVariant
    {
        $variant = ProductVariant::query()->create([
            'company_id' => $this->company->getKey(), 'product_id' => $this->product->getKey(), 'sku' => 'POLO-'.$size,
            'options' => ['Size' => $size], 'sale_price' => $sale, 'reseller_suggested_price' => $suggested, 'cost_price' => 300,
            'stock' => $stock, 'is_active' => true,
        ]);

        return $variant->fresh();
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
}
