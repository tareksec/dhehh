<?php

namespace Tests\Feature;

use App\Filament\Reseller\Pages\CreateOrder;
use App\Filament\Reseller\Pages\Orders as PortalOrders;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ResellerCommission;
use App\Models\ResellerDepositEntry;
use App\Models\ResellerProduct;
use App\Models\StockMovement;
use App\Models\StorefrontPaymentMethod;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\ResellerOrderPlacementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Audit (owner request 2026-10-15): does the reseller portal stay in step with
 * the admin dashboard? An order a reseller places must reach staff correctly,
 * stock must follow the order's status (plain products and variations), and
 * what staff do must show in the portal. Everything here goes through the real
 * pages and actions on both sides; nothing is written to the tables by hand
 * except the opening stock.
 */
class ResellerPortalAdminFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $reseller;

    protected User $admin;

    protected Product $cap;

    protected Product $shirt;

    protected ProductVariant $medium;

    protected ProductVariant $large;

    protected StorefrontPaymentMethod $bkash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('Flow Co', 'flow-co');
        app(CompanyContext::class)->set($this->company);
        $this->bkash = StorefrontPaymentMethod::query()->create([
            'company_id' => $this->company->getKey(), 'type' => StorefrontPaymentMethod::TYPE_MANUAL,
            'name' => 'bKash', 'account_number' => '01800000000', 'is_active' => true,
        ]);
        $this->reseller = $this->makeReseller($this->company, '01711000001');
        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $category = Category::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Things', 'slug' => 'things', 'is_active' => true]);

        // A plain product with a wholesale rate (the reseller pays 120 + 10).
        $this->cap = Product::query()->create([
            'company_id' => $this->company->getKey(), 'category_id' => $category->getKey(), 'name' => 'Cap', 'sku' => 'CAP-1',
            'price' => 300, 'sale_price' => 300, 'cost_price' => 90, 'stock' => 0, 'unit' => 'pcs', 'reorder_level' => 1,
            'vat_rate' => 0, 'tier_prices' => [['min_qty' => 1, 'price' => 120]], 'is_active' => true, 'status' => Product::STATUS_AVAILABLE,
        ])->fresh();
        $this->openingStock($this->cap, 10);

        // A product with variations (the reseller pays 691 + 10 / 733 + 10).
        $this->shirt = Product::query()->create([
            'company_id' => $this->company->getKey(), 'category_id' => $category->getKey(), 'name' => 'Shirt', 'sku' => 'SHIRT-1',
            'price' => 650, 'sale_price' => 650, 'cost_price' => 300, 'stock' => 0, 'unit' => 'pcs', 'reorder_level' => 1,
            'vat_rate' => 0, 'has_variants' => true, 'is_active' => true, 'status' => Product::STATUS_AVAILABLE,
        ])->fresh();
        $this->medium = $this->variant($this->shirt, 'M', 691, 799);
        $this->large = $this->variant($this->shirt, 'L', 733, 849);
        $this->openingStock($this->shirt, 8, $this->medium);
        $this->openingStock($this->shirt, 6, $this->large);

        foreach ([$this->cap, $this->shirt] as $product) {
            ResellerProduct::query()->create(['customer_id' => $this->reseller->getKey(), 'product_id' => $product->getKey(), 'is_active' => true]);
        }

        Queue::fake();
    }

    public function test_an_order_from_the_portal_reaches_the_admin_with_the_right_company_source_lines_and_prices(): void
    {
        $order = $this->placeThroughThePortal(capQuantity: 2, shirtVariant: $this->medium, shirtQuantity: 3);

        $this->assertSame($this->company->getKey(), (int) $order->company_id);
        $this->assertSame(Order::SOURCE_RESELLER_PORTAL, $order->source);
        $this->assertSame(Order::STATUS_DRAFT, $order->status);
        $this->assertSame($this->reseller->getKey(), (int) $order->reseller_customer_id);
        $this->assertSame('Buyer One', $order->customer?->name);
        $this->assertNotSame($this->reseller->getKey(), (int) $order->customer_id); // the buyer, not the reseller

        $items = $order->items()->get();
        $this->assertCount(2, $items);
        $cap = $items->firstWhere('product_id', $this->cap->getKey());
        $this->assertSame(2, (int) $cap->quantity);
        $this->assertSame(130.0, (float) $cap->reseller_base_price); // 120 wholesale + 10 cost per unit
        $shirt = $items->firstWhere('product_id', $this->shirt->getKey());
        $this->assertSame($this->medium->getKey(), (int) $shirt->product_variant_id);
        $this->assertSame('Size: M', $shirt->variant_label);
        $this->assertSame(701.0, (float) $shirt->reseller_base_price); // 691 + 10
        $this->assertSame(799.0, (float) $shirt->unit_price);          // the admin's suggested price

        // Staff see it in the Orders list and on its page.
        $this->asAdmin();
        Livewire::test(ListOrders::class)->assertCanSeeTableRecords([$order]);
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSuccessful()
            ->assertSee($order->order_number)
            ->assertSee('Size: M');
    }

    public function test_a_draft_order_holds_no_stock_and_confirming_it_takes_it_from_the_exact_item(): void
    {
        $order = $this->placeThroughThePortal(capQuantity: 2, shirtVariant: $this->medium, shirtQuantity: 3);

        // Draft: nothing has moved.
        $this->assertSame(0, StockMovement::query()->where('type', 'sale')->count());
        $this->assertStock(cap: 10, medium: 8, large: 6);

        $this->transition($order, 'confirmed');

        $this->assertSame(Order::STATUS_CONFIRMED, $order->fresh()->status);
        $sales = StockMovement::query()->where('type', 'sale')->where('reference_id', $order->getKey())->get();
        $this->assertCount(2, $sales);
        $this->assertSame(3, (int) $sales->firstWhere('product_variant_id', $this->medium->getKey())->quantity);
        $this->assertSame($this->company->getKey(), (int) $sales->first()->company_id);
        $this->assertStock(cap: 8, medium: 5, large: 6);
        // A product with variations shows the sum of its variations.
        $this->assertSame(11, (int) $this->shirt->fresh()->stock);
    }

    public function test_cancelling_gives_the_stock_back_and_the_portal_shows_the_new_status(): void
    {
        $order = $this->placeThroughThePortal(capQuantity: 2, shirtVariant: $this->large, shirtQuantity: 4);
        $this->transition($order, 'confirmed');
        $this->assertStock(cap: 8, medium: 8, large: 2);

        $this->asPortal();
        Livewire::test(PortalOrders::class)->assertSee($order->order_number)->assertSee('Confirmed');

        $this->transition($order, 'cancelled', 'Customer changed their mind');

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(0, StockMovement::query()->where('type', 'sale')->where('reference_id', $order->getKey())->count());
        $this->assertStock(cap: 10, medium: 8, large: 6);
        $this->assertSame(14, (int) $this->shirt->fresh()->stock);

        $this->asPortal();
        Livewire::test(PortalOrders::class)->assertSee($order->order_number)->assertSee('Cancelled');
    }

    public function test_a_delivered_order_makes_the_profit_payable_and_a_later_return_takes_it_back_and_staff_restock_the_goods(): void
    {
        $order = $this->placeThroughThePortal(capQuantity: 2, shirtVariant: $this->medium, shirtQuantity: 1);
        $this->transition($order, 'confirmed');

        $commission = ResellerCommission::query()->where('order_id', $order->getKey())->sole();
        $this->assertSame(ResellerCommission::STATUS_PENDING, $commission->status);
        // (300 - 130) * 2 + (799 - 701) * 1
        $this->assertSame(438.0, (float) $commission->amount);

        $this->transition($order, 'completed');
        $this->assertSame(ResellerCommission::STATUS_PAYABLE, $commission->fresh()->status);
        $this->assertStock(cap: 8, medium: 7, large: 6);

        $this->transition($order, 'returned', 'Customer sent it back');

        $this->assertSame(Order::STATUS_RETURNED, $order->fresh()->status);
        $this->assertSame(ResellerCommission::STATUS_CANCELLED, $commission->fresh()->status); // taken back
        // Owner decision 2026-10-15: the goods stay out of stock until staff have checked them...
        $this->assertStock(cap: 8, medium: 7, large: 6);
        $this->asAdmin();
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('putReturnedGoodsBackInStock')
            ->callAction('putReturnedGoodsBackInStock', data: ['qty_'.$order->items()->where('product_id', $this->cap->getKey())->value('id') => 2, 'qty_'.$order->items()->where('product_id', $this->shirt->getKey())->value('id') => 1])
            ->assertHasNoActionErrors();
        // ...and then they are back on the shelf.
        $this->assertStock(cap: 10, medium: 8, large: 6);
        // The return charge (default 50) is taken from the deposit, once.
        $charge = ResellerDepositEntry::query()->where('type', ResellerDepositEntry::TYPE_RETURN_CHARGE)->sole();
        $this->assertSame(-50.0, (float) $charge->amount);
    }

    public function test_when_two_resellers_ask_for_the_last_pieces_only_the_first_confirmation_wins_and_stock_never_goes_negative(): void
    {
        $other = $this->makeReseller($this->company, '01711000002');
        ResellerProduct::query()->create(['customer_id' => $other->getKey(), 'product_id' => $this->cap->getKey(), 'is_active' => true]);

        // Each order alone fits the 10 on the shelf, so both can be placed as drafts.
        $first = $this->place($this->reseller, [['product_id' => $this->cap->getKey(), 'quantity' => 6, 'price' => 200]]);
        $second = $this->place($other, [['product_id' => $this->cap->getKey(), 'quantity' => 6, 'price' => 200]]);

        $this->transition($first, 'confirmed');
        $this->assertStock(cap: 4, medium: 8, large: 6);

        // Staff are told why it cannot be confirmed; the order stays a draft.
        $this->transition($second, 'confirmed')->assertHasActionErrors();

        $this->assertSame(Order::STATUS_DRAFT, $second->fresh()->status);
        $this->assertStock(cap: 4, medium: 8, large: 6);
        $this->assertSame(0, StockMovement::query()->where('type', 'sale')->where('reference_id', $second->getKey())->count());
    }

    public function test_the_portal_will_not_take_more_than_the_stock_in_the_first_place(): void
    {
        $errors = $this->errorsFor(fn () => $this->place($this->reseller, [['product_id' => $this->cap->getKey(), 'quantity' => 11, 'price' => 200]]));
        $this->assertSame(['data.items.0.quantity'], array_keys($errors));

        $errors = $this->errorsFor(fn () => $this->place($this->reseller, [['product_id' => $this->shirt->getKey(), 'variant_id' => $this->large->getKey(), 'quantity' => 7, 'price' => 900]]));
        $this->assertSame(['data.items.0.quantity'], array_keys($errors));

        $this->assertSame(0, Order::query()->count());
    }

    public function test_an_own_courier_order_with_a_variation_follows_the_same_stock_rules_and_the_deposit_comes_back_on_cancel(): void
    {
        $order = $this->place($this->reseller, [
            ['product_id' => $this->shirt->getKey(), 'variant_id' => $this->large->getKey(), 'quantity' => 2, 'price' => 900],
        ], ownCourier: true);

        $this->assertTrue((bool) $order->reseller_own_courier);
        $this->assertSame(Order::SOURCE_RESELLER_PORTAL, $order->source);
        $this->assertStock(cap: 10, medium: 8, large: 6); // drafts hold nothing

        $this->transition($order, 'confirmed');
        $this->assertStock(cap: 10, medium: 8, large: 4);

        $this->transition($order, 'cancelled', 'Wrong size');
        $this->assertStock(cap: 10, medium: 8, large: 6);
        $this->assertSame(14, (int) $this->shirt->fresh()->stock);
    }

    public function test_each_company_sees_only_its_own_portal_orders_and_a_reseller_only_theirs(): void
    {
        // Companies are created before any panel is selected (their notifications build admin URLs).
        $otherCompany = $this->makeCompany('Elsewhere Co', 'elsewhere-co');
        $mine = $this->placeThroughThePortal(capQuantity: 1, shirtVariant: $this->medium, shirtQuantity: 1);

        app(CompanyContext::class)->set($otherCompany);
        $stranger = $this->makeReseller($otherCompany, '01899000001');
        $theirs = Order::query()->create([
            'company_id' => $otherCompany->getKey(), 'customer_id' => $stranger->getKey(), 'customer_name' => 'Elsewhere Buyer',
            'reseller_customer_id' => $stranger->getKey(), 'order_date' => now()->toDateString(), 'status' => 'draft',
            'source' => Order::SOURCE_RESELLER_PORTAL, 'discount' => 0, 'vat' => 0, 'shipping_fee' => 0, 'paid_amount' => 0,
        ]);

        app(CompanyContext::class)->set($this->company);
        $sibling = $this->makeReseller($this->company, '01711000003');

        $this->asAdmin();
        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);

        // Another reseller of the same company does not see this reseller's order.
        $this->asPortal($sibling);
        Livewire::test(PortalOrders::class)->assertDontSee($mine->order_number);
        $this->asPortal();
        Livewire::test(PortalOrders::class)->assertSee($mine->order_number);
    }

    /** Places an order the way a reseller does: through the portal's Create order page. */
    protected function placeThroughThePortal(int $capQuantity, ProductVariant $shirtVariant, int $shirtQuantity): Order
    {
        $this->asPortal();

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'name' => 'Buyer One',
                'phone' => '01722000009',
                'address' => 'Dhaka, Mirpur',
                'items' => [
                    ['product_id' => $this->cap->getKey(), 'variant_id' => null, 'quantity' => $capQuantity, 'price' => 300],
                    // No price typed: the line takes the admin's suggested price for that option.
                    ['product_id' => $this->shirt->getKey(), 'variant_id' => $shirtVariant->getKey(), 'quantity' => $shirtQuantity, 'price' => null],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        return Order::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function place(Customer $reseller, array $lines, bool $ownCourier = false): Order
    {
        return app(ResellerOrderPlacementService::class)->place($reseller, [
            'name' => 'Buyer', 'phone' => '01722000009', 'address' => 'Dhaka, Mirpur', 'note' => null,
        ], $lines, $ownCourier ? [
            'courier_name' => 'Pathao', 'parcel_id' => 'PATHAO-'.Str::random(5),
            'payment_method_id' => $this->bkash->getKey(), 'sender' => '01999999999', 'transaction_id' => 'TRX-'.Str::random(5),
        ] : null);
    }

    /** Staff change the order's stage through the admin's own "Change status" action. */
    protected function transition(Order $order, string $stage, ?string $reason = null): Testable
    {
        $this->asAdmin();

        return Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('changeWorkflowStatus', data: array_filter(['stage' => $stage, 'reason' => $reason]));
    }

    protected function assertStock(int $cap, int $medium, int $large): void
    {
        $this->assertSame($cap, (int) $this->cap->fresh()->stock, 'Cap stock');
        $this->assertSame($medium, (int) $this->medium->fresh()->stock, 'Shirt M stock');
        $this->assertSame($large, (int) $this->large->fresh()->stock, 'Shirt L stock');
    }

    /** @return array<string, array<int, string>> */
    protected function errorsFor(callable $callback): array
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    }

    protected function asAdmin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin)->withSession(['current_company_id' => $this->company->getKey()]);
        app(CompanyContext::class)->set($this->company);
    }

    protected function asPortal(?Customer $reseller = null): void
    {
        app(CompanyContext::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $this->actingAs($reseller ?? $this->reseller, 'customer');
    }

    protected function openingStock(Product $product, int $quantity, ?ProductVariant $variant = null): void
    {
        StockMovement::query()->create([
            'product_id' => $product->getKey(), 'product_variant_id' => $variant?->getKey(), 'type' => 'opening', 'quantity' => $quantity,
        ]);
    }

    protected function variant(Product $product, string $size, float $sale, float $suggested): ProductVariant
    {
        return ProductVariant::query()->create([
            'company_id' => $this->company->getKey(), 'product_id' => $product->getKey(), 'sku' => 'SHIRT-'.$size,
            'options' => ['Size' => $size], 'sale_price' => $sale, 'reseller_suggested_price' => $suggested,
            'cost_price' => 300, 'stock' => 0, 'is_active' => true,
        ])->fresh();
    }

    protected function makeCompany(string $name, string $slug): Company
    {
        $company = Company::query()->create([
            'name' => $name, 'slug' => $slug, 'domain' => $slug.'.example.test', 'domain_verified' => true,
            'invoice_prefix' => strtoupper(substr($slug, 0, 3)), 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka',
            'is_active' => true, 'reseller_module_enabled' => true,
        ])->fresh();
        StorefrontSetting::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(), 'theme_color' => '#0F766E', 'is_published' => true,
            'new_customer_delivery_advance_enabled' => false, 'reseller_cost_per_unit' => 10, 'reseller_packaging_cost_per_unit' => 5,
            'delivery_first_kg_inside' => 70, 'delivery_first_kg_outside' => 110, 'delivery_additional_per_kg' => 20,
        ]);

        return $company;
    }

    protected function makeReseller(Company $company, string $phone): Customer
    {
        $customer = new Customer([
            'name' => 'Reseller '.$phone, 'phone' => $phone, 'reseller_status' => 'approved',
            'reseller_slug' => 'r-'.Str::random(6), 'business_name' => 'Shop '.$phone, 'is_active' => true,
        ]);
        $customer->company_id = $company->getKey();
        $customer->forceFill(['password' => Hash::make('secret-password')])->save();

        return $customer;
    }
}
