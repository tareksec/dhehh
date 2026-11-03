<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\TrashedProducts;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Models\StockPool;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountBalanceService;
use App\Services\CompanyContext;
use App\Services\ProductCsvService;
use App\Services\ProductTrashService;
use App\Services\StockPoolMembership;
use App\Services\WebsiteWebhookDispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner request 2026-11-01, phase T5b of docs/delete-and-trash-plan.md; owner answers of 2026-11-03: a product
 * in Trash is hidden everywhere, the orders and purchases that contain it go to Trash with it and come back
 * with it, a product ordered more than twice (or with money received or goods shipped) stays out of Trash
 * unless the reason is "Test product", and the Next.js site is told.
 */
class ProductTrashTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private Product $product;

    private Customer $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->company = $this->admin->defaultCompany();
        app(CompanyContext::class)->set($this->company->fresh());
        $this->session(['current_company_id' => $this->company->getKey()]);
        $this->actingAs($this->admin);

        $this->buyer = Customer::query()->create(['name' => 'Buyer', 'phone' => '01711000000']);
        $this->product = $this->makeProduct('Trash Phone', 'TP-1');
    }

    private function makeProduct(string $name, string $sku, int $stock = 100): Product
    {
        $product = Product::query()->create(['name' => $name, 'sku' => $sku, 'price' => 500, 'sale_price' => 500, 'cost_price' => 100, 'stock' => 0]);
        $product->setStockFromProductForm($stock);

        return $product->fresh();
    }

    private function receive(Product $product, int $quantity = 10): Purchase
    {
        $supplier = Supplier::query()->firstOrCreate(['name' => 'Trash Supplier'], ['opening_balance' => 0]);
        $purchase = Purchase::query()->create(['supplier_id' => $supplier->getKey(), 'purchase_date' => '2026-11-01', 'status' => 'draft']);
        $purchase->items()->create(['product_id' => $product->getKey(), 'quantity' => $quantity, 'unit_cost' => 100]);
        $purchase->update(['status' => 'received']);

        return $purchase->fresh();
    }

    /** @param array<string, mixed> $attributes */
    private function order(Product $product, int $quantity = 2, string $status = 'confirmed', array $attributes = []): Order
    {
        $order = Order::query()->create(array_merge([
            'company_id' => $this->company->getKey(), 'customer_id' => $this->buyer->getKey(), 'customer_name' => 'Buyer', 'order_date' => now()->toDateString(),
            'status' => 'draft', 'source' => Order::SOURCE_ADMIN, 'discount' => 0, 'vat' => 0, 'shipping_fee' => 0, 'paid_amount' => 0,
        ], $attributes));
        OrderItem::query()->create(['order_id' => $order->getKey(), 'product_id' => $product->getKey(), 'quantity' => $quantity, 'unit_price' => 500, 'unit_cost' => 100]);
        $order->refresh();
        $order->update(['status' => $status]);

        return $order->fresh();
    }

    private function trash(Product $product, string $reason = 'other'): ?string
    {
        return app(ProductTrashService::class)->tryMoveToTrash($product, $reason);
    }

    private function sales(Order $order): int
    {
        return StockMovement::query()->where('reference_type', Order::class)->where('reference_id', $order->getKey())->where('type', 'sale')->count();
    }

    public function test_a_product_without_history_goes_to_trash_and_back(): void
    {
        $this->assertNull($this->trash($this->product, 'duplicate'));

        $this->assertNull(Product::query()->find($this->product->getKey()));
        $this->assertSame('duplicate', Product::withTrashed()->find($this->product->getKey())->trash_reason);

        Product::onlyTrashed()->find($this->product->getKey())->restore();

        $restored = Product::query()->find($this->product->getKey());
        $this->assertNotNull($restored);
        $this->assertNull($restored->trash_reason);
    }

    public function test_the_orders_and_purchases_of_the_product_go_to_trash_with_it_and_restore_brings_them_back(): void
    {
        $purchase = $this->receive($this->product, 10);
        $order = $this->order($this->product, 3);
        $this->assertSame(1, $this->sales($order));
        $this->assertSame(107, (int) $this->product->fresh()->stock);

        $this->assertNull($this->trash($this->product->fresh()));

        $this->assertNull(Order::query()->find($order->getKey()));
        $this->assertNull(Purchase::query()->find($purchase->getKey()));
        $this->assertSame(0, $this->sales($order));

        Product::onlyTrashed()->find($this->product->getKey())->restore();

        $this->assertNotNull(Order::query()->find($order->getKey()));
        $this->assertNotNull(Purchase::query()->find($purchase->getKey()));
        $this->assertSame(1, $this->sales($order));
        $this->assertSame(107, (int) $this->product->fresh()->stock);
    }

    public function test_restore_brings_back_only_what_went_to_trash_with_the_product(): void
    {
        $alreadyTrashed = $this->order($this->product, 1);
        $alreadyTrashed->delete();
        $kept = $this->order($this->product, 1);

        $this->assertNull($this->trash($this->product->fresh()));
        $this->assertNotNull(Order::onlyTrashed()->find($kept->getKey()));

        Product::onlyTrashed()->find($this->product->getKey())->restore();

        $this->assertNotNull(Order::query()->find($kept->getKey()));
        $this->assertNotNull(Order::onlyTrashed()->find($alreadyTrashed->getKey()), 'An order that was in Trash before stays there.');
    }

    public function test_an_order_with_other_products_goes_to_trash_whole(): void
    {
        $other = $this->makeProduct('Other', 'OT-1');
        $order = $this->order($this->product, 1);
        OrderItem::query()->create(['order_id' => $order->getKey(), 'product_id' => $other->getKey(), 'quantity' => 1, 'unit_price' => 100, 'unit_cost' => 50]);

        $this->assertNull($this->trash($this->product->fresh()));

        $this->assertNotNull(Order::onlyTrashed()->find($order->getKey()));
        $this->assertNotNull(Product::query()->find($other->getKey()), 'The other product stays.');
    }

    public function test_a_product_ordered_more_than_twice_cannot_go_to_trash_but_a_test_product_can(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->order($this->product, 1, 'draft');
        }

        $why = $this->trash($this->product->fresh());
        $this->assertNotNull($why);
        $this->assertStringContainsString('3', $why);
        $this->assertNotNull(Product::query()->find($this->product->getKey()));
        $this->assertSame(3, Order::query()->count());

        $this->assertNull($this->trash($this->product->fresh(), ProductTrashService::REASON_TEST));
        $this->assertSame(0, Order::query()->count());
    }

    public function test_exactly_two_orders_are_still_allowed(): void
    {
        $this->order($this->product, 1, 'draft');
        $this->order($this->product, 1, 'draft');

        $this->assertNull($this->trash($this->product->fresh()));
    }

    public function test_money_received_or_goods_shipped_keep_the_product_out_unless_it_is_a_test_product(): void
    {
        $paid = $this->order($this->product, 1, 'draft', ['paid_amount' => 200]);

        $why = $this->trash($this->product->fresh());
        $this->assertNotNull($why);
        $this->assertStringContainsString((string) $paid->order_number, $why);

        $this->assertNull($this->trash($this->product->fresh(), ProductTrashService::REASON_TEST));
        $this->assertNotNull(Order::onlyTrashed()->find($paid->getKey()));
    }

    public function test_a_completed_order_keeps_the_product_out(): void
    {
        $this->order($this->product, 1, 'completed');

        $this->assertNotNull($this->trash($this->product->fresh()));
        $this->assertNotNull(Product::query()->find($this->product->getKey()));
    }

    public function test_a_returned_order_keeps_the_product_out_even_for_a_test_product(): void
    {
        $this->order($this->product, 1, 'returned');

        $this->assertNotNull($this->trash($this->product->fresh(), ProductTrashService::REASON_TEST));
        $this->assertNotNull(Product::query()->find($this->product->getKey()));
    }

    public function test_a_reason_is_needed_when_the_product_has_orders_or_purchases(): void
    {
        $this->receive($this->product);

        $this->assertNotNull(app(ProductTrashService::class)->tryMoveToTrash($this->product->fresh(), null));
        $this->assertNotNull(Product::query()->find($this->product->getKey()));
    }

    public function test_a_stock_pool_source_stays_and_a_pool_member_leaves_the_pool_and_rejoins(): void
    {
        $member = $this->makeProduct('Member', 'MB-1');
        $pool = StockPool::query()->create(['source_product_id' => $this->product->getKey()]);
        app(StockPoolMembership::class)->sync($pool, [$member->getKey()]);

        $this->assertNotNull($this->trash($this->product->fresh()), 'The source product stays.');

        $this->assertNull($this->trash($member->fresh()));
        $this->assertNull(Product::withTrashed()->find($member->getKey())->stock_pool_id);

        Product::onlyTrashed()->find($member->getKey())->restore();
        $this->assertSame($pool->getKey(), $member->fresh()->stock_pool_id);
    }

    public function test_history_keeps_showing_the_name_of_a_product_in_trash(): void
    {
        $order = $this->order($this->product, 1);
        $purchase = $this->receive($this->product, 1);
        $this->assertNull($this->trash($this->product->fresh()));

        $item = OrderItem::query()->where('order_id', $order->getKey())->first();
        $this->assertSame('Trash Phone', $item->product->name);
        $this->assertSame('Trash Phone', PurchaseItem::query()->withoutGlobalScopes()->where('purchase_id', $purchase->getKey())->first()->product->name);
    }

    public function test_a_product_in_trash_is_out_of_the_stock_value_and_keeps_its_sku_reserved(): void
    {
        $this->receive($this->product, 10);
        $before = app(AccountBalanceService::class)->inventoryValue($this->company->getKey());
        $this->assertGreaterThan(0, $before);

        $this->assertNull($this->trash($this->product->fresh()));
        $this->assertEqualsWithDelta(0.0, app(AccountBalanceService::class)->inventoryValue($this->company->getKey()), 0.001);

        $this->expectException(QueryException::class);
        $this->makeProduct('Same SKU again', 'TP-1');
    }

    public function test_the_website_is_told_when_a_product_goes_to_trash(): void
    {
        $dispatcher = \Mockery::mock(WebsiteWebhookDispatcher::class)->makePartial();
        $dispatcher->shouldReceive('dispatchProductRemoved')->once();
        $dispatcher->shouldReceive('dispatchProductUpdated')->zeroOrMoreTimes();
        $this->app->instance(WebsiteWebhookDispatcher::class, $dispatcher);

        $this->assertNull($this->trash($this->product->fresh()));
    }

    public function test_the_bulk_delete_asks_for_the_word_and_a_reason_and_reports_a_refused_product(): void
    {
        $free = $this->makeProduct('Free', 'FR-1');
        foreach (range(1, 3) as $ignored) {
            $this->order($this->product, 1, 'draft');
        }

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('delete', [$free, $this->product], data: ['confirmation' => 'wrong', 'trash_reason' => 'other'])
            ->assertHasTableBulkActionErrors(['confirmation']);
        Livewire::test(ListProducts::class)
            ->callTableBulkAction('delete', [$free, $this->product], data: ['confirmation' => 'DELETE'])
            ->assertHasTableBulkActionErrors(['trash_reason']);
        $this->assertNotNull(Product::query()->find($free->getKey()));

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('delete', [$free, $this->product], data: ['confirmation' => 'DELETE', 'trash_reason' => 'other']);

        $this->assertNull(Product::query()->find($free->getKey()));
        $this->assertNotNull(Product::query()->find($this->product->getKey()), 'The product ordered three times was refused.');
    }

    public function test_the_edit_page_moves_the_product_to_trash_after_the_name_is_typed(): void
    {
        Livewire::test(EditProduct::class, ['record' => $this->product->getKey()])
            ->callAction('delete', data: ['confirmation' => 'Wrong', 'trash_reason' => 'other'])
            ->assertHasActionErrors(['confirmation']);
        $this->assertNotNull(Product::query()->find($this->product->getKey()));

        Livewire::test(EditProduct::class, ['record' => $this->product->getKey()])
            ->callAction('delete', data: ['confirmation' => 'Trash Phone', 'trash_reason' => 'discontinued', 'trash_note' => 'old model']);

        $trashed = Product::onlyTrashed()->find($this->product->getKey());
        $this->assertSame('old model', $trashed->trash_note);
    }

    public function test_only_people_who_may_delete_products_see_delete_and_the_trash(): void
    {
        $staff = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $staff->companies()->syncWithoutDetaching([$this->company->getKey()]);
        $this->actingAs($staff);

        $this->assertFalse(TrashedProducts::canAccess());

        $this->actingAs($this->admin);
        $this->assertTrue(TrashedProducts::canAccess());
    }

    public function test_the_trash_page_lists_the_trashed_product_and_restores_it(): void
    {
        $this->assertNull($this->trash($this->product->fresh()));

        Livewire::test(TrashedProducts::class)
            ->assertCanSeeTableRecords([Product::onlyTrashed()->find($this->product->getKey())])
            ->callTableAction('restore', Product::onlyTrashed()->find($this->product->getKey()));

        $this->assertNotNull(Product::query()->find($this->product->getKey()));
    }

    public function test_a_csv_import_refuses_a_sku_held_by_a_product_in_trash(): void
    {
        $this->assertNull($this->trash($this->product->fresh()));

        Storage::fake('local');
        $path = Storage::disk('local')->path('trash-sku.csv');
        file_put_contents($path, implode(PHP_EOL, [
            implode(',', ProductCsvService::HEADINGS),
            'TP-1,Same SKU,,,,pcs,10,20,1,0,0,available,yes,',
        ]));

        try {
            $result = app(ProductCsvService::class)->import($path);
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Trash', json_encode($exception->errors()));

            return;
        }

        $this->assertSame(0, $result['created'] ?? 0, 'The SKU in Trash must not be created again.');
    }
}
