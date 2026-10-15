<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\StockMovements\Pages\CreateStockMovement;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\OrderWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner decision (2026-10-15): when an order is returned, its goods do NOT go
 * back on the shelf by themselves. Staff look at what came back and add the
 * pieces that are fine with "Put returned goods back in stock". A cancelled
 * order, which never left, still gives its stock back at once.
 */
class OrderReturnedStockTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected Product $cap;

    protected Product $shirt;

    protected ProductVariant $medium;

    protected Customer $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Return Co', 'slug' => 'return-co', 'domain' => 'return-co.example.test', 'domain_verified' => true,
            'invoice_prefix' => 'RET', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ])->fresh();
        app(CompanyContext::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($this->admin)->withSession(['current_company_id' => $this->company->getKey()]);

        $category = Category::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Things', 'slug' => 'things', 'is_active' => true]);
        $this->cap = $this->product($category, 'Cap', 'CAP-1');
        $this->shirt = $this->product($category, 'Shirt', 'SHIRT-1', variants: true);
        $this->medium = ProductVariant::query()->create([
            'company_id' => $this->company->getKey(), 'product_id' => $this->shirt->getKey(), 'sku' => 'SHIRT-M',
            'options' => ['Size' => 'M'], 'sale_price' => 500, 'cost_price' => 300, 'stock' => 0, 'is_active' => true,
        ])->fresh();
        $this->opening($this->cap, 10);
        $this->opening($this->shirt, 8, $this->medium);

        $this->buyer = Customer::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Buyer', 'phone' => '01700000001']);
    }

    public function test_a_returned_order_keeps_its_goods_out_of_stock(): void
    {
        $order = $this->confirmedOrder(capQuantity: 3, shirtQuantity: 2);
        $this->assertStock(cap: 7, medium: 6);

        $this->transition($order, 'returned', 'Customer refused it');

        $this->assertSame(Order::STATUS_RETURNED, $order->fresh()->status);
        $this->assertStock(cap: 7, medium: 6); // nothing came back by itself
        $this->assertSame(2, StockMovement::query()->where('type', 'sale')->where('reference_id', $order->getKey())->count());
        $this->assertSame(6, (int) $this->shirt->fresh()->stock); // a product with variations shows the sum of its variations
    }

    public function test_a_delivered_order_that_comes_back_also_keeps_its_goods_out(): void
    {
        $order = $this->confirmedOrder(capQuantity: 4, shirtQuantity: 1);
        $this->transition($order, 'completed');
        $this->transition($order, 'returned', 'Came back after delivery');

        $this->assertStock(cap: 6, medium: 7);
    }

    public function test_a_cancelled_order_still_gives_its_stock_back_at_once(): void
    {
        $order = $this->confirmedOrder(capQuantity: 3, shirtQuantity: 2);

        $this->transition($order, 'cancelled', 'Changed their mind');

        $this->assertStock(cap: 10, medium: 8);
    }

    public function test_staff_put_back_only_what_is_in_good_condition_and_can_do_it_in_parts(): void
    {
        $order = $this->confirmedOrder(capQuantity: 5, shirtQuantity: 2);
        $this->transition($order, 'returned', 'Came back');
        [$capItem, $shirtItem] = $this->itemIds($order);

        // 3 of the 5 caps are fine; the rest is left for later.
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('putReturnedGoodsBackInStock')
            ->callAction('putReturnedGoodsBackInStock', data: $this->counts($capItem, 3, 0, $shirtItem, 0, 0))
            ->assertHasNoActionErrors()
            ->assertNotified('3 pieces were added back to stock.');

        $this->assertStock(cap: 8, medium: 6);
        $movement = StockMovement::query()->where('type', 'return')->sole();
        $this->assertSame(Order::class, $movement->reference_type);
        $this->assertSame($order->getKey(), (int) $movement->reference_id);
        $this->assertSame($this->company->getKey(), (int) $movement->company_id);

        // Later the other 2 caps turn out fine too, and one shirt.
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('putReturnedGoodsBackInStock', data: $this->counts($capItem, 2, 0, $shirtItem, 1, 0))
            ->assertHasNoActionErrors();
        $this->assertStock(cap: 10, medium: 7);

        // One shirt is still waiting to be checked; the caps are done.
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('putReturnedGoodsBackInStock')
            ->callAction('putReturnedGoodsBackInStock', data: $this->counts($capItem, 0, 0, $shirtItem, 0, 0))
            ->assertNotified('Nothing was added to stock.');
        $this->assertStock(cap: 10, medium: 7);
    }

    public function test_more_than_was_sold_can_never_be_put_back(): void
    {
        $order = $this->confirmedOrder(capQuantity: 2, shirtQuantity: 1);
        $this->transition($order, 'returned', 'Came back');
        [$capItem, $shirtItem] = $this->itemIds($order);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('putReturnedGoodsBackInStock', data: $this->counts($capItem, 3, 0, $shirtItem, 1, 0))
            ->assertHasActionErrors(['good_'.$capItem]);
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('putReturnedGoodsBackInStock', data: $this->counts($capItem, -1, 0, $shirtItem, 1, 0))
            ->assertHasActionErrors(['good_'.$capItem]);
        // Good and damaged pieces together cannot pass what is left either.
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('putReturnedGoodsBackInStock', data: $this->counts($capItem, 2, 1, $shirtItem, 1, 0))
            ->assertHasActionErrors(['damaged_'.$capItem]);

        $this->assertSame(0, StockMovement::query()->where('type', 'return')->count());
        $this->assertStock(cap: 8, medium: 7);

        // Everything fine: all of it goes back, and then the action has nothing left to offer.
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('putReturnedGoodsBackInStock', data: $this->counts($capItem, 2, 0, $shirtItem, 1, 0))
            ->assertHasNoActionErrors();
        $this->assertStock(cap: 10, medium: 8);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionHidden('putReturnedGoodsBackInStock');
    }

    public function test_the_action_is_only_for_returned_or_refunded_orders_and_for_staff_who_may_add_stock(): void
    {
        $order = $this->confirmedOrder(capQuantity: 2, shirtQuantity: 1);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])->assertActionHidden('putReturnedGoodsBackInStock'); // confirmed

        $this->transition($order, 'returned', 'Came back');
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])->assertActionVisible('putReturnedGoodsBackInStock');

        // A refund after the return keeps the goods out too, and the action stays available.
        $this->transition($order, 'refunded', 'Money given back');
        $this->assertStock(cap: 8, medium: 7);
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])->assertActionVisible('putReturnedGoodsBackInStock');

        // Staff who cannot add stock movements do not get it.
        UserRole::query()->create(['name' => 'Sales only', 'slug' => 'sales-only', 'permissions' => ['sales.view', 'sales.update'], 'is_active' => true]);
        $sales = User::factory()->create(['role' => 'sales-only', 'is_active' => true]);
        $this->actingAs($sales)->withSession(['current_company_id' => $this->company->getKey()]);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])->assertActionHidden('putReturnedGoodsBackInStock');
    }

    public function test_damaged_pieces_are_counted_as_customer_return_damage_and_never_shelved(): void
    {
        $order = $this->confirmedOrder(capQuantity: 5, shirtQuantity: 2);
        $this->transition($order, 'returned', 'Came back');
        [$capItem, $shirtItem] = $this->itemIds($order);

        // 3 caps fine, 2 caps damaged; both shirts fine.
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('putReturnedGoodsBackInStock', data: $this->counts($capItem, 3, 2, $shirtItem, 2, 0))
            ->assertHasNoActionErrors()
            ->assertNotified('5 pieces were added back to stock. 2 pieces were counted as customer-return damage.');

        // Only the good pieces are back on the shelf; the damaged ones are not.
        $this->assertStock(cap: 8, medium: 8);

        $damage = StockMovement::query()->where('type', 'damage')->sole();
        $this->assertSame($this->cap->getKey(), (int) $damage->product_id);
        $this->assertSame(2, (int) $damage->quantity);
        $this->assertSame(StockMovement::DAMAGE_SOURCE_CUSTOMER_RETURN, $damage->damage_source);
        $this->assertSame($order->getKey(), (int) $damage->reference_id);
        $this->assertSame(Order::class, $damage->reference_type);

        // Every returned piece is now checked, so the action has nothing left to offer.
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])->assertActionHidden('putReturnedGoodsBackInStock');
    }

    public function test_a_returned_variation_piece_can_be_counted_as_damaged(): void
    {
        $order = $this->confirmedOrder(capQuantity: 1, shirtQuantity: 2);
        $this->transition($order, 'returned', 'Came back');
        [$capItem, $shirtItem] = $this->itemIds($order);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('putReturnedGoodsBackInStock', data: $this->counts($capItem, 1, 0, $shirtItem, 1, 1))
            ->assertHasNoActionErrors();

        $this->assertStock(cap: 10, medium: 7);
        $damage = StockMovement::query()->where('type', 'damage')->sole();
        $this->assertSame($this->medium->getKey(), (int) $damage->product_variant_id);
        $this->assertSame(1, (int) $damage->quantity);
    }

    public function test_a_returned_or_refunded_order_can_never_be_moved_to_trash(): void
    {
        $returned = $this->confirmedOrder(capQuantity: 2, shirtQuantity: 1);
        $this->transition($returned, 'returned', 'Came back');
        $refunded = $this->confirmedOrder(capQuantity: 1, shirtQuantity: 1);
        $this->transition($refunded, 'returned', 'Came back');
        $this->transition($refunded, 'refunded', 'Money given back');

        foreach ([$returned, $refunded] as $order) {
            $this->assertFalse($order->fresh()->canBeMovedToTrash());

            try {
                $order->fresh()->delete();
                $this->fail('A returned or refunded order must not be trashable.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status', $exception->errors());
            }

            $this->assertNull($order->fresh()->deleted_at);
        }

        // The page does not offer it, and the bulk action skips these orders.
        Livewire::test(EditOrder::class, ['record' => $returned->getKey()])->assertActionHidden('delete');
        Livewire::test(ListOrders::class)
            ->callTableBulkAction('delete', [$returned, $refunded]);
        $this->assertNull($returned->fresh()->deleted_at);
        $this->assertNull($refunded->fresh()->deleted_at);

        // An order that is not returned still goes to the trash as before, and its stock is released.
        $open = $this->confirmedOrder(capQuantity: 1, shirtQuantity: 1);
        $this->assertTrue($open->canBeMovedToTrash());
        Livewire::test(EditOrder::class, ['record' => $open->getKey()])->assertActionVisible('delete');
    }

    public function test_restoring_a_trashed_order_brings_its_sale_back(): void
    {
        // A confirmed order moved to trash gives its goods back, and restoring takes them again.
        $order = $this->confirmedOrder(capQuantity: 3, shirtQuantity: 2);
        $order->fresh()->delete();
        $this->assertStock(cap: 10, medium: 8);

        Order::onlyTrashed()->findOrFail($order->getKey())->restore();
        $this->assertStock(cap: 7, medium: 6);
    }

    public function test_restoring_a_returned_order_from_the_trash_recreates_its_sale(): void
    {
        // An order that was trashed while returned, before returned orders were protected:
        // its sale was released then (the shelf has its goods) and no return was recorded.
        $order = $this->confirmedOrder(capQuantity: 4, shirtQuantity: 2);
        $this->transition($order, 'returned', 'Came back');
        StockMovement::query()->where('reference_type', Order::class)->where('reference_id', $order->getKey())->get()->each->delete();
        $this->assertStock(cap: 10, medium: 8);
        DB::table('orders')->where('id', $order->getKey())->update(['deleted_at' => now()]);

        Order::onlyTrashed()->findOrFail($order->getKey())->restore();

        // The sale is back (owner decision), and the order is waiting to be checked again.
        $this->assertStock(cap: 6, medium: 6);
        $this->assertSame(2, StockMovement::query()->where('type', 'sale')->where('reference_id', $order->getKey())->count());
        $this->assertSame(Order::STATUS_RETURNED, $order->fresh()->status);
    }

    public function test_taking_an_order_s_stock_effects_out_also_removes_its_damage_write_offs(): void
    {
        $order = $this->confirmedOrder(capQuantity: 5, shirtQuantity: 1);
        $this->transition($order, 'returned', 'Came back');
        [$capItem, $shirtItem] = $this->itemIds($order);
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('putReturnedGoodsBackInStock', data: $this->counts($capItem, 2, 2, $shirtItem, 1, 0));
        $this->assertStock(cap: 7, medium: 8);

        app(OrderWorkflowService::class)->deleteStockMovements($order->fresh());

        // The sale, the returns and the write-off cancel out: the shelf is as it was.
        $this->assertStock(cap: 10, medium: 8);
        $this->assertSame(0, StockMovement::query()->where('reference_type', Order::class)->where('reference_id', $order->getKey())->count());
        $this->assertSame(0, (int) StockMovement::query()->where('type', 'damage')->sum('quantity'));
    }

    public function test_the_damage_source_is_asked_for_on_a_damage_entry_and_kept_off_other_entries(): void
    {
        // A shipment found damaged: the staff member picks "Shipment damage".
        Livewire::test(CreateStockMovement::class)
            ->fillForm(['product_id' => $this->cap->getKey(), 'type' => 'damage', 'quantity' => 2, 'reason' => 'Broken in the carton'])
            ->call('create')
            ->assertHasFormErrors(['damage_source' => 'required']);

        Livewire::test(CreateStockMovement::class)
            ->fillForm(['product_id' => $this->cap->getKey(), 'type' => 'damage', 'quantity' => 2, 'reason' => 'Broken in the carton', 'damage_source' => StockMovement::DAMAGE_SOURCE_SHIPMENT])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(StockMovement::DAMAGE_SOURCE_SHIPMENT, StockMovement::query()->where('type', 'damage')->sole()->damage_source);
        $this->assertStock(cap: 8, medium: 8);

        // Only damage carries a source, and an unknown one is refused.
        $purchase = StockMovement::query()->create(['product_id' => $this->cap->getKey(), 'type' => 'purchase', 'quantity' => 1, 'damage_source' => StockMovement::DAMAGE_SOURCE_SHIPMENT]);
        $this->assertNull($purchase->fresh()->damage_source);

        $this->expectException(ValidationException::class);
        StockMovement::query()->create(['product_id' => $this->cap->getKey(), 'type' => 'damage', 'quantity' => 1, 'reason' => 'x', 'damage_source' => 'made_up']);
    }

    public function test_a_returned_order_does_not_count_as_a_sale_or_a_customer_due(): void
    {
        $order = $this->confirmedOrder(capQuantity: 3, shirtQuantity: 1);
        $this->assertGreaterThan(0, (float) $this->buyer->fresh()->current_balance);

        $this->transition($order, 'returned', 'Came back');

        // Money and sales follow the order status as before; only the shelf waits for staff.
        $this->assertEqualsWithDelta(0.0, (float) $this->buyer->fresh()->current_balance, 0.001);
        $this->assertFalse($order->fresh()->isAccounted());
    }

    protected function confirmedOrder(int $capQuantity, int $shirtQuantity): Order
    {
        $order = Order::query()->create([
            'company_id' => $this->company->getKey(), 'customer_id' => $this->buyer->getKey(), 'customer_name' => 'Buyer',
            'order_date' => now()->toDateString(), 'status' => 'draft', 'source' => Order::SOURCE_ADMIN,
            'discount' => 0, 'vat' => 0, 'shipping_fee' => 0, 'paid_amount' => 0,
        ]);
        OrderItem::query()->create(['order_id' => $order->getKey(), 'product_id' => $this->cap->getKey(), 'quantity' => $capQuantity, 'unit_price' => 300, 'unit_cost' => 90]);
        OrderItem::query()->create([
            'order_id' => $order->getKey(), 'product_id' => $this->shirt->getKey(), 'product_variant_id' => $this->medium->getKey(),
            'variant_label' => 'Size: M', 'quantity' => $shirtQuantity, 'unit_price' => 500, 'unit_cost' => 300,
        ]);
        $order->refresh();
        $this->transition($order, 'confirmed');

        return $order->fresh();
    }

    protected function transition(Order $order, string $stage, ?string $reason = null): void
    {
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('changeWorkflowStatus', data: array_filter(['stage' => $stage, 'reason' => $reason]))
            ->assertHasNoActionErrors();
    }

    /** Form data for the "put returned goods back" action: good and damaged pieces per line. */
    protected function counts(int $firstItem, int $firstGood, int $firstDamaged, int $secondItem, int $secondGood, int $secondDamaged): array
    {
        return [
            'good_'.$firstItem => $firstGood, 'damaged_'.$firstItem => $firstDamaged,
            'good_'.$secondItem => $secondGood, 'damaged_'.$secondItem => $secondDamaged,
        ];
    }

    /** @return array{0: int, 1: int} the cap line and the shirt line */
    protected function itemIds(Order $order): array
    {
        return [
            (int) $order->items()->where('product_id', $this->cap->getKey())->value('id'),
            (int) $order->items()->where('product_id', $this->shirt->getKey())->value('id'),
        ];
    }

    protected function assertStock(int $cap, int $medium): void
    {
        $this->assertSame($cap, (int) $this->cap->fresh()->stock, 'Cap stock');
        $this->assertSame($medium, (int) $this->medium->fresh()->stock, 'Shirt M stock');
    }

    protected function product(Category $category, string $name, string $sku, bool $variants = false): Product
    {
        return Product::query()->create([
            'company_id' => $this->company->getKey(), 'category_id' => $category->getKey(), 'name' => $name, 'sku' => $sku,
            'price' => 300, 'sale_price' => 300, 'cost_price' => 90, 'stock' => 0, 'unit' => 'pcs', 'reorder_level' => 1,
            'vat_rate' => 0, 'has_variants' => $variants, 'is_active' => true, 'status' => Product::STATUS_AVAILABLE,
        ])->fresh();
    }

    protected function opening(Product $product, int $quantity, ?ProductVariant $variant = null): void
    {
        StockMovement::query()->create([
            'product_id' => $product->getKey(), 'product_variant_id' => $variant?->getKey(), 'type' => 'opening', 'quantity' => $quantity,
        ]);
    }
}
