<?php

namespace Tests\Feature;

use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Filament\Resources\Purchases\Pages\TrashedPurchases;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockPools\Pages\EditStockPool;
use App\Filament\Resources\StockPools\Pages\TrashedStockPools;
use App\Filament\Resources\StockPools\StockPoolResource;
use App\Models\Company;
use App\Models\Container;
use App\Models\LcFile;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\StockPool;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountBalanceService;
use App\Services\CompanyContext;
use App\Services\ReportService;
use App\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner request 2026-11-01, phase T5a of docs/delete-and-trash-plan.md: purchases and stock pools go to
 * Trash when deleted. A purchase in Trash does what a deleted one did (its goods are out of stock, its
 * amount is out of the supplier's balance, its lines and LC file are hidden) and Restore brings all of
 * it back. A pool in Trash is empty, and Restore links its products again.
 */
class PurchaseTrashTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private Product $product;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->company = $this->admin->defaultCompany();
        app(CompanyContext::class)->set($this->company->fresh());
        $this->session(['current_company_id' => $this->company->getKey()]);

        $this->product = Product::query()->create(['name' => 'Trash Widget', 'sku' => 'TRASH-W', 'price' => 500, 'sale_price' => 500, 'stock' => 0]);
        $this->supplier = Supplier::query()->create(['name' => 'Trash Supplier', 'opening_balance' => 0]);
    }

    private function receivedPurchase(int $quantity = 10, float $unitCost = 100): Purchase
    {
        $purchase = Purchase::query()->create(['supplier_id' => $this->supplier->getKey(), 'purchase_date' => '2026-11-01', 'status' => 'draft']);
        $purchase->items()->create(['product_id' => $this->product->getKey(), 'quantity' => $quantity, 'unit_cost' => $unitCost]);
        $purchase->update(['status' => 'received']);

        return $purchase->fresh();
    }

    private function stock(): int
    {
        return (int) $this->product->fresh()->stock;
    }

    // -------------------------------------------------------------- purchases

    public function test_a_purchase_in_trash_takes_its_goods_out_of_stock_and_its_amount_out_of_the_balance_and_restore_puts_them_back(): void
    {
        $purchase = $this->receivedPurchase();

        $this->assertSame(10, $this->stock());
        $this->assertEquals(1000.0, (float) $this->supplier->fresh()->current_balance);

        $purchase->delete();

        $this->assertSoftDeleted($purchase);
        $this->assertSame(0, $this->stock());
        $this->assertSame(0, StockMovement::query()->where('reference_type', Purchase::class)->where('reference_id', $purchase->getKey())->count());
        $this->assertEquals(0.0, (float) $this->supplier->fresh()->current_balance);
        // Its lines are kept, but hidden with it.
        $this->assertSame(0, PurchaseItem::query()->count());
        $this->assertSame(1, PurchaseItem::query()->withoutGlobalScope('purchaseNotInTrash')->count());

        $purchase->restore();

        $this->assertNotSoftDeleted($purchase);
        $this->assertSame(10, $this->stock());
        $this->assertSame(1, StockMovement::query()->where('reference_type', Purchase::class)->where('reference_id', $purchase->getKey())->count());
        $this->assertEquals(1000.0, (float) $this->supplier->fresh()->current_balance);
        $this->assertSame(1, PurchaseItem::query()->count());
    }

    public function test_saving_a_purchase_in_trash_does_not_bring_its_goods_back_into_stock(): void
    {
        $purchase = $this->receivedPurchase();
        $purchase->delete();

        // For example a voucher changing the paid amount of the purchase meanwhile.
        $purchase->forceFill(['paid_amount' => 300])->save();

        $this->assertSame(0, $this->stock());
        // Its stored totals are not worked out from the lines (hidden while it is in Trash), so they stay.
        $this->assertEquals(1000.0, (float) $purchase->fresh()->withTrashed()->first()->total_amount);
        $this->assertEquals(0.0, (float) $this->supplier->fresh()->current_balance);

        $purchase->restore();

        $this->assertSame(10, $this->stock());
    }

    public function test_deleting_purchases_from_the_list_asks_for_the_count_and_lands_in_trash(): void
    {
        $purchase = $this->receivedPurchase();

        Livewire::actingAs($this->admin)
            ->test(ListPurchases::class)
            ->callTableBulkAction('delete', [$purchase], data: ['confirmation' => '7'])
            ->assertHasTableBulkActionErrors(['confirmation']);
        $this->assertNotSoftDeleted($purchase);

        Livewire::actingAs($this->admin)
            ->test(ListPurchases::class)
            ->callTableBulkAction('delete', [$purchase], data: ['confirmation' => '1'])
            ->assertHasNoTableBulkActionErrors();
        $this->assertSoftDeleted($purchase);
        $this->assertSame(0, $this->stock());

        Livewire::actingAs($this->admin)
            ->test(TrashedPurchases::class)
            ->assertCanSeeTableRecords([$purchase])
            ->callTableAction('restore', $purchase)
            ->assertHasNoTableActionErrors();
        $this->assertNotSoftDeleted($purchase);
        $this->assertSame(10, $this->stock());
    }

    public function test_deleting_a_purchase_for_good_from_trash_removes_it_and_its_lines(): void
    {
        $purchase = $this->receivedPurchase();
        $purchase->delete();

        Livewire::actingAs($this->admin)
            ->test(TrashedPurchases::class)
            ->callTableAction('forceDelete', $purchase, data: ['confirmation' => $purchase->purchase_number])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('purchases', ['id' => $purchase->getKey()]);
        $this->assertDatabaseMissing('purchase_items', ['purchase_id' => $purchase->getKey()]);
        $this->assertSame(0, $this->stock());
    }

    public function test_a_new_purchase_never_takes_the_number_of_one_in_trash(): void
    {
        $purchase = $this->receivedPurchase();
        $purchase->delete();

        // The unique index still holds the number; the number generator retries and picks another one.
        $new = Purchase::query()->create(['purchase_number' => $purchase->purchase_number, 'supplier_id' => $this->supplier->getKey(), 'status' => 'draft']);

        $this->assertNotSame($purchase->purchase_number, $new->purchase_number);

        $purchase->restore();
        $this->assertNotNull(Purchase::query()->where('purchase_number', $purchase->purchase_number)->first());
    }

    public function test_reports_and_money_figures_leave_a_purchase_in_trash_out(): void
    {
        $purchase = $this->receivedPurchase();
        $container = Container::query()->create(['container_number' => 'CONT-T5A-1', 'status' => 'planned']);
        $shipment = Shipment::query()->create(['container_id' => $container->getKey(), 'purchase_id' => $purchase->getKey(), 'tracking_number' => 'TRK-T5A-1', 'status' => 'booked']);
        $balances = app(AccountBalanceService::class);
        $reports = app(ReportService::class);

        $this->assertEquals(1000.0, $balances->newShipmentValue($this->company->getKey()));
        $this->assertEquals(1000.0, (float) $reports->topSuppliers()->firstWhere('name', 'Trash Supplier')->total_purchases);

        $purchase->delete();

        $this->assertEquals(0.0, $balances->newShipmentValue($this->company->getKey()));
        $this->assertEquals(0.0, (float) $reports->topSuppliers()->firstWhere('name', 'Trash Supplier')->total_purchases);
        // The shipment stays on its list and keeps the link to its purchase in Trash.
        $this->assertSame($purchase->getKey(), $shipment->fresh()->purchase->getKey());
        $shipment->update(['carrier' => 'Still editable']);

        $purchase->restore();

        $this->assertEquals(1000.0, $balances->newShipmentValue($this->company->getKey()));
    }

    public function test_the_lc_file_of_a_purchase_in_trash_is_hidden_with_it(): void
    {
        $purchase = $this->receivedPurchase();
        LcFile::query()->create(['purchase_id' => $purchase->getKey(), 'stage' => 'lc_opened']);

        $this->assertSame(1, LcFile::query()->count());

        $purchase->delete();
        $this->assertSame(0, LcFile::query()->count());

        $purchase->restore();
        $this->assertSame(1, LcFile::query()->count());
    }

    public function test_only_someone_who_may_delete_purchases_can_use_the_trash(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'is_active' => true]);

        $this->actingAs($manager);

        // A manager works purchases (view, create, update) but has no purchasing.delete.
        $this->assertFalse(PurchaseResource::canDeleteAny());
        $this->assertFalse(PurchaseResource::canRestoreAny());
        $this->assertFalse(PurchaseResource::canForceDeleteAny());
        $this->assertFalse(TrashedPurchases::canAccess());

        $this->actingAs($this->admin);
        $this->assertTrue(TrashedPurchases::canAccess());
    }

    // ------------------------------------------------------------ stock ledger

    public function test_the_stock_movements_list_has_no_bulk_delete(): void
    {
        $movement = StockMovement::query()->create(['product_id' => $this->product->getKey(), 'type' => 'opening', 'quantity' => 4]);

        Livewire::actingAs($this->admin)
            ->test(ListStockMovements::class)
            ->assertCanSeeTableRecords([$movement])
            ->assertTableBulkActionDoesNotExist('delete');
    }

    // ------------------------------------------------------------- stock pools

    /** @return array{0: Product, 1: Product, 2: StockPool} */
    private function pooledProducts(): array
    {
        $other = Company::query()->create(['name' => 'Retail Co', 'slug' => 'retail-co-t5a', 'invoice_prefix' => 'RTL', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        $mirror = Product::query()->create(['name' => 'Trash Widget Retail', 'sku' => 'TRASH-W-R', 'price' => 600, 'sale_price' => 600, 'stock' => 0]);
        app(CompanyContext::class)->set($this->company->fresh());

        StockMovement::query()->create(['product_id' => $this->product->getKey(), 'type' => 'opening', 'quantity' => 20]);
        $pool = StockPool::query()->create(['source_product_id' => $this->product->getKey()]);
        StockPoolResource::syncMembers($pool, [$mirror->getKey()]);

        return [$this->product->fresh(), $mirror->fresh(), $pool];
    }

    public function test_a_stock_pool_in_trash_is_empty_and_restore_links_its_products_again(): void
    {
        [$source, $mirror, $pool] = $this->pooledProducts();

        $this->assertSame($pool->getKey(), $mirror->stock_pool_id);
        $this->assertSame(20, (int) $mirror->stock);

        Livewire::actingAs($this->admin)
            ->test(EditStockPool::class, ['record' => $pool->getRouteKey()])
            ->callAction('delete', data: ['confirmation' => $source->name])
            ->assertHasNoActionErrors();

        $this->assertSoftDeleted($pool);
        $this->assertNull($source->fresh()->stock_pool_id);
        $this->assertNull($mirror->fresh()->stock_pool_id);
        // Each product is back on its own ledger: the retail one has none of the units.
        $this->assertSame(20, (int) $source->fresh()->stock);
        $this->assertSame(0, (int) $mirror->fresh()->stock);

        Livewire::actingAs($this->admin)
            ->test(TrashedStockPools::class)
            ->assertCanSeeTableRecords([$pool])
            ->callTableAction('restore', $pool)
            ->assertHasNoTableActionErrors();

        $this->assertNotSoftDeleted($pool);
        $this->assertSame($pool->getKey(), $source->fresh()->stock_pool_id);
        $this->assertSame($pool->getKey(), $mirror->fresh()->stock_pool_id);
        $this->assertSame(20, (int) $mirror->fresh()->stock);
        $this->assertNull($pool->fresh()->member_product_ids);
    }

    public function test_a_stock_pool_cannot_be_restored_when_its_source_product_joined_another_pool(): void
    {
        [$source, , $pool] = $this->pooledProducts();
        $pool->delete();

        $newPool = StockPool::query()->create(['source_product_id' => $source->getKey()]);
        $source->forceFill(['stock_pool_id' => $newPool->getKey()])->save();

        Livewire::actingAs($this->admin)
            ->test(TrashedStockPools::class)
            ->callTableAction('restore', $pool);

        $this->assertSoftDeleted($pool);
        $this->assertSame($newPool->getKey(), $source->fresh()->stock_pool_id);
    }

    public function test_a_pool_in_trash_holds_back_a_product_from_nobody_and_stock_calculation_ignores_it(): void
    {
        [$source, $mirror, $pool] = $this->pooledProducts();
        $pool->delete();

        $this->assertNull(Product::query()->withoutGlobalScopes()->whereKey($mirror->getKey())->value('stock_pool_id'));

        // A sale on the retail product is no longer mirrored on the source product.
        StockMovement::query()->create(['product_id' => $mirror->getKey(), 'type' => 'opening', 'quantity' => 3]);
        app(StockMovementService::class)->syncProductStock($mirror->getKey());

        $this->assertSame(3, (int) $mirror->fresh()->stock);
        $this->assertSame(20, (int) $source->fresh()->stock);
    }

    public function test_only_a_super_admin_uses_the_stock_pool_trash(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'is_active' => true]);

        $this->actingAs($manager);
        $this->assertFalse(StockPoolResource::canDeleteAny());
        $this->assertFalse(StockPoolResource::canRestoreAny());
        $this->assertFalse(TrashedStockPools::canAccess());

        $this->actingAs($this->admin);
        $this->assertTrue(TrashedStockPools::canAccess());
    }
}
