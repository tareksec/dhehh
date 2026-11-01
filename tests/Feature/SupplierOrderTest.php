<?php

namespace Tests\Feature;

use App\Filament\Resources\SupplierOrders\Pages\CreateSupplierOrder;
use App\Filament\Resources\SupplierOrders\Pages\EditSupplierOrder;
use App\Filament\Resources\SupplierOrders\Schemas\SupplierOrderForm;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SupplierOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_order_takes_stock_out_and_fixes_the_suppliers_buy_price(): void
    {
        [$supplier, $product] = $this->stocked(quantity: 100, buyPrice: 80);

        $order = $this->order($supplier, [[$product, 30, 150]]);

        $this->assertSame(70, $product->fresh()->stock);
        $this->assertSame(30, $order->fresh()->totalPieces());
        $this->assertSame(4500.0, $order->fresh()->totalAmount());
        // The buy price came from the supplier's receipt, not from what the customer pays.
        $this->assertSame(2400.0, $order->fresh()->takenValue());

        // A later price change on a new receipt does not move an order already taken.
        $this->receipt($supplier, $product, 50, 95);
        $this->assertSame(2400.0, $order->fresh()->takenValue());
    }

    public function test_the_buy_price_falls_back_to_the_product_cost_when_nothing_was_received(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Shahin Khan', 'opening_balance' => 0, 'is_active' => true]);
        $product = $this->product('K801', stock: 10, cost: 60);

        $order = $this->order($supplier, [[$product, 5, 100]]);

        $this->assertSame(300.0, $order->fresh()->takenValue());
    }

    public function test_returned_and_cancelled_orders_give_the_stock_back(): void
    {
        [$supplier, $product] = $this->stocked(quantity: 100, buyPrice: 80);
        $order = $this->order($supplier, [[$product, 40, 150]]);

        $this->assertSame(60, $product->fresh()->stock);

        $order->update(['status' => SupplierOrder::STATUS_RETURNED]);
        $this->assertSame(100, $product->fresh()->stock);
        $this->assertFalse($order->fresh()->isTaken());

        $order->update(['status' => SupplierOrder::STATUS_OPEN]);
        $this->assertSame(60, $product->fresh()->stock);

        $order->update(['status' => SupplierOrder::STATUS_CANCELLED]);
        $this->assertSame(100, $product->fresh()->stock);
    }

    public function test_an_order_for_more_than_the_stock_is_refused_and_saves_nothing(): void
    {
        [$supplier, $product] = $this->stocked(quantity: 10, buyPrice: 80);

        try {
            $this->order($supplier, [[$product, 11, 150]]);
            $this->fail('Selling more than the stock must be refused.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(0, SupplierOrderItem::query()->count());
    }

    public function test_deleting_an_order_returns_its_stock(): void
    {
        [$supplier, $product] = $this->stocked(quantity: 100, buyPrice: 80);
        $order = $this->order($supplier, [[$product, 25, 150]]);

        $order->delete();

        $this->assertSame(100, $product->fresh()->stock);
        $this->assertSame(0, SupplierOrder::query()->count());
        $this->assertSame(0, SupplierOrderItem::query()->count());
    }

    public function test_editing_the_pieces_of_a_line_follows_the_stock(): void
    {
        [$supplier, $product] = $this->stocked(quantity: 100, buyPrice: 80);
        $order = $this->order($supplier, [[$product, 40, 150]]);

        $order->items()->first()->update(['quantity' => 60]);
        $this->assertSame(40, $product->fresh()->stock);

        $order->items()->first()->update(['quantity' => 10]);
        $this->assertSame(90, $product->fresh()->stock);
    }

    public function test_supplier_orders_never_count_as_our_sales_or_customer_dues(): void
    {
        [$supplier, $product] = $this->stocked(quantity: 100, buyPrice: 80);
        $this->order($supplier, [[$product, 30, 150]], advance: 1000);

        [$from, $to] = app(ReportService::class)->dateRange(now()->toDateString(), now()->toDateString());

        $this->assertCount(0, app(ReportService::class)->sales($from, $to));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0.0, (float) app(ReportService::class)->dashboardSummary()['customer_due']);
    }

    public function test_stock_history_is_dated_on_the_order_date(): void
    {
        [$supplier, $product] = $this->stocked(quantity: 100, buyPrice: 80);
        $order = $this->order($supplier, [[$product, 10, 150]], date: '2026-02-21');

        $movement = StockMovement::query()->where('type', 'sale')->firstOrFail();
        $this->assertSame('2026-02-21', $movement->created_at->toDateString());

        $order->update(['order_date' => '2026-03-01']);
        $this->assertSame('2026-03-01', $movement->fresh()->created_at->toDateString());
    }

    public function test_the_order_form_offers_only_the_suppliers_products_and_books_stock(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        [$supplier, $product] = $this->stocked(quantity: 100, buyPrice: 80);
        $product->update(['supplier_id' => $supplier->id]);
        $other = $this->product('Someone Elses', stock: 5, cost: 10);

        $this->actingAs($user);

        Livewire::test(CreateSupplierOrder::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
                'order_date' => '2026-03-05',
                'customer_name' => 'Our Customer',
                'customer_phone' => '01772905008',
                'status' => SupplierOrder::STATUS_OPEN,
                'advance_amount' => 10000,
                'advance_collected_by' => SupplierOrder::COLLECTED_BY_SUPPLIER,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 100,
                    'unit_price' => 120,
                    'supplier_unit_cost' => 0,
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $order = SupplierOrder::query()->firstOrFail();
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(8000.0, $order->takenValue());
        $this->assertSame(SupplierOrder::COLLECTED_BY_SUPPLIER, $order->advance_collected_by);

        // Once the supplier has products assigned, only those are offered ...
        $offered = SupplierOrderForm::productsFor(Product::query(), $supplier->id)->pluck('id')->all();
        $this->assertSame([$product->id], $offered);
        $this->assertNotContains($other->id, $offered);

        // ... until then, every active product is.
        $newSupplier = Supplier::query()->create(['name' => 'New Supplier', 'opening_balance' => 0, 'is_active' => true]);
        $this->assertEqualsCanonicalizing(
            [$product->id, $other->id],
            SupplierOrderForm::productsFor(Product::query(), $newSupplier->id)->pluck('id')->all(),
        );
    }

    public function test_a_line_that_overdraws_stock_shows_a_notification(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        [$supplier, $product] = $this->stocked(quantity: 5, buyPrice: 80);

        $this->actingAs($user);

        Livewire::test(CreateSupplierOrder::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
                'order_date' => '2026-03-05',
                'status' => SupplierOrder::STATUS_OPEN,
                'advance_amount' => 0,
                'advance_collected_by' => SupplierOrder::COLLECTED_BY_SUPPLIER,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 6,
                    'unit_price' => 120,
                ]],
            ])
            ->call('create')
            ->assertNotified(__('Could not save'));

        $this->assertSame(0, SupplierOrder::query()->count());
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_order_pages_render_and_deleting_works_from_the_edit_page(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        [$supplier, $product] = $this->stocked(quantity: 100, buyPrice: 80);
        $order = $this->order($supplier, [[$product, 10, 150]]);

        $this->actingAs($user)->get('/admin/purchasing/supplier-orders')->assertOk()->assertSee($order->order_number);
        $this->actingAs($user)->get("/admin/purchasing/supplier-orders/{$order->id}")->assertOk()->assertSee('Shahin Khan');
        $this->actingAs($user)->get('/admin/purchasing/supplier-orders/create')->assertOk();

        Livewire::test(EditSupplierOrder::class, ['record' => $order->getKey()])
            ->callAction('delete');

        $this->assertSame(0, SupplierOrder::query()->count());
        $this->assertSame(100, $product->fresh()->stock);
    }

    public function test_a_user_without_purchasing_permission_cannot_open_supplier_orders(): void
    {
        $user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);

        $this->actingAs($user)->get('/admin/purchasing/supplier-orders')->assertForbidden();
    }

    /**
     * @return array{0: Supplier, 1: Product}
     */
    private function stocked(int $quantity, float $buyPrice): array
    {
        $supplier = Supplier::query()->create(['name' => 'Shahin Khan', 'opening_balance' => 0, 'is_active' => true]);
        $product = $this->product('Love Pearl', stock: 0, cost: 0);
        $this->receipt($supplier, $product, $quantity, $buyPrice);

        return [$supplier, $product];
    }

    private function receipt(Supplier $supplier, Product $product, int $quantity, float $buyPrice): void
    {
        $receipt = PurchaseReceipt::query()->create(['supplier_id' => $supplier->id, 'received_on' => '2026-02-05']);

        PurchaseReceiptItem::query()->create([
            'purchase_receipt_id' => $receipt->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_cost' => $buyPrice,
        ]);
    }

    /**
     * @param  array<int, array{0: Product, 1: int, 2: float}>  $lines
     */
    private function order(Supplier $supplier, array $lines, float $advance = 0, string $date = '2026-03-05'): SupplierOrder
    {
        $order = SupplierOrder::query()->create([
            'supplier_id' => $supplier->id,
            'order_date' => $date,
            'customer_name' => 'Our Customer',
            'advance_amount' => $advance,
        ]);

        foreach ($lines as [$product, $quantity, $price]) {
            SupplierOrderItem::query()->create([
                'supplier_order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => $price,
            ]);
        }

        return $order;
    }

    private function product(string $name, int $stock, float $cost): Product
    {
        $category = Category::query()->firstOrCreate(['slug' => 'supplier-order-category'], ['name' => 'Supplier Order Category']);

        $product = Product::query()->create([
            'name' => $name,
            'sku' => 'SKU-'.strtoupper(str_replace(' ', '-', $name)),
            'unit' => 'pcs',
            'cost_price' => $cost,
            'sale_price' => 100,
            'price' => 100,
            'stock' => 0,
            'reorder_level' => 0,
            'is_active' => true,
            'category_id' => $category->id,
        ]);

        if ($stock > 0) {
            StockMovement::query()->create([
                'product_id' => $product->id,
                'type' => 'opening',
                'quantity' => $stock,
                'reference_type' => Product::class,
                'reference_id' => $product->id,
            ]);
        }

        return $product;
    }
}
