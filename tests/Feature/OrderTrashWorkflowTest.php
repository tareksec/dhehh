<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Livewire\OrderTrashTable;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CompanyContext;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderTrashWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_orders_can_be_moved_to_trash_and_are_hidden_from_the_active_list(): void
    {
        [$admin, $company] = $this->adminForCompany();
        $order = $this->makeOrder($company, 'TRASH-0001', 'Trash Customer');

        $this->actingAs($admin);

        Livewire::test(ListOrders::class)
            ->assertTableBulkActionExists('delete')
            ->assertTableBulkActionHasLabel('delete', 'Move to trash')
            ->assertTableBulkActionHasIcon('delete', 'heroicon-o-trash')
            ->mountTableBulkAction('delete', [$order])
            ->callMountedTableBulkAction(data: ['confirmation' => '1'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSoftDeleted('orders', ['id' => $order->getKey()]);
        $this->assertNull(Order::query()->find($order->getKey()));
        $this->assertSame($order->getKey(), Order::onlyTrashed()->sole()->getKey());
    }

    public function test_trash_toolbar_modal_lists_current_company_orders_and_permanently_deletes_them(): void
    {
        [$admin, $companyA] = $this->adminForCompany();
        $companyB = $this->makeCompany('Trash Company B', 'trash-company-b');

        $orderA = $this->makeOrder($companyA, 'TRASH-A-0001', 'Company A Customer');
        $provider = CourierProvider::query()->create([
            'company_id' => $companyA->getKey(),
            'name' => 'Trash Test Courier',
            'slug' => 'trash-test-courier',
            'driver' => CourierProvider::DRIVER_MANUAL,
            'is_active' => true,
        ]);
        $booking = CourierBooking::query()->create([
            'company_id' => $companyA->getKey(),
            'courier_provider_id' => $provider->getKey(),
            'order_id' => $orderA->getKey(),
            'recipient_name' => 'Company A Customer',
        ]);
        $orderA->delete();

        app(CompanyContext::class)->set($companyB);
        $orderB = $this->makeOrder($companyB, 'TRASH-B-0001', 'Company B Customer');
        $orderB->delete();

        app(CompanyContext::class)->set($companyA);
        $this->actingAs($admin);

        $component = Livewire::test(ListOrders::class)
            ->assertTableActionExists('trash', fn (Action $action): bool => $action->getLabel() === 'Trash'
                && $action->getIcon() === 'heroicon-o-trash')
            ->assertTableActionVisible('trash')
            ->mountTableAction('trash')
            ->assertTableActionMounted('trash');

        $this->assertTrue($component->instance()->mountedActionShouldOpenModal());

        $component
            ->assertSchemaComponentExists('orderTrashTable')
            ->assertMountedActionModalSee('TRASH-A-0001')
            ->assertMountedActionModalSee('Company A Customer')
            ->assertMountedActionModalDontSee('TRASH-B-0001')
            ->assertMountedActionModalDontSee('Company B Customer')
            ->callMountedTableAction(data: ['confirmation' => '1'])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('orders', ['id' => $orderA->getKey()]);
        $this->assertDatabaseMissing('courier_bookings', ['id' => $booking->getKey()]);

        $otherCompanyOrder = Order::withoutGlobalScopes()
            ->withTrashed()
            ->find($orderB->getKey());

        $this->assertNotNull($otherCompanyOrder);
        $this->assertTrue($otherCompanyOrder->trashed());
    }

    public function test_trash_action_is_hidden_from_users_without_sensitive_delete_permission(): void
    {
        $company = Company::defaultCompany();
        $user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $user->companies()->sync([
            $company->getKey() => ['role' => 'sales_staff', 'is_default' => true],
        ]);
        app(CompanyContext::class)->set($company);

        $this->actingAs($user);

        Livewire::test(ListOrders::class)
            ->assertTableActionHidden('trash')
            ->assertTableBulkActionHidden('delete');
    }

    public function test_trashed_order_can_be_restored_from_the_popup_with_its_stock_effects(): void
    {
        [$admin, $company] = $this->adminForCompany();
        $product = Product::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Restore Test Product',
            'sku' => 'RESTORE-TEST-001',
            'price' => 500,
            'sale_price' => 500,
            'cost_price' => 300,
            'stock' => 5,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);
        StockMovement::query()->create([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'type' => 'opening',
            'quantity' => 5,
            'reference_type' => Product::class,
            'reference_id' => $product->getKey(),
            'note' => 'Restore workflow test stock',
        ]);

        $customer = Customer::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Restore Customer',
            'phone' => '+8801700000099',
            'address' => 'Dhaka',
            'opening_balance' => 0,
            'is_active' => true,
        ]);
        $order = $this->makeOrder($company, 'RESTORE-0001', 'Restore Customer');
        $order->update(['customer_id' => $customer->getKey()]);
        $order = $order->fresh();
        OrderItem::query()->create([
            'company_id' => $company->getKey(),
            'order_id' => $order->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => 2,
            'unit_price' => 500,
        ]);
        $order->update(['status' => Order::STATUS_COMPLETED]);

        $this->assertSame(3, (int) $product->refresh()->stock);

        $order->delete();
        $stayTrashed = $this->makeOrder($company, 'RESTORE-0002', 'Keep Trashed Customer');
        $stayTrashed->delete();

        $this->assertSame(5, (int) $product->refresh()->stock);
        $this->actingAs($admin);

        Livewire::test(OrderTrashTable::class)
            ->assertCanSeeTableRecords([$order, $stayTrashed])
            ->assertTableActionExists('restore', fn (Action $action): bool => $action->getLabel() === 'Restore'
                && $action->getIcon() === 'heroicon-o-arrow-uturn-left'
                && $action->getColor() === 'success', $order)
            ->mountTableAction('restore', $order)
            ->assertMountedActionModalSee('This order will return to the active Orders list.')
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertNotNull(Order::query()->find($order->getKey()));
        $this->assertNull(Order::onlyTrashed()->find($order->getKey()));
        $this->assertNotNull(Order::onlyTrashed()->find($stayTrashed->getKey()));
        $this->assertSame(3, (int) $product->refresh()->stock);
    }

    public function test_trashed_rows_can_be_selected_individually_or_together_and_restored_in_bulk(): void
    {
        [$admin, $companyA] = $this->adminForCompany();
        $companyB = $this->makeCompany('Bulk Restore Company B', 'bulk-restore-company-b');

        $orderOne = $this->makeOrder($companyA, 'BULK-RESTORE-0001', 'First Restore Customer');
        $orderTwo = $this->makeOrder($companyA, 'BULK-RESTORE-0002', 'Second Restore Customer');
        $orderThree = $this->makeOrder($companyA, 'BULK-RESTORE-0003', 'Third Restore Customer');
        $orderOne->delete();
        $orderTwo->delete();
        $orderThree->delete();

        app(CompanyContext::class)->set($companyB);
        $otherCompanyOrder = $this->makeOrder($companyB, 'BULK-RESTORE-B-0001', 'Other Company Customer');
        $otherCompanyOrder->delete();

        app(CompanyContext::class)->set($companyA);
        $this->actingAs($admin);

        $component = Livewire::test(OrderTrashTable::class)
            ->assertCanSeeTableRecords([$orderOne, $orderTwo, $orderThree])
            ->assertCanNotSeeTableRecords([$otherCompanyOrder])
            ->assertTableBulkActionExists('restoreSelected')
            ->assertTableBulkActionHasLabel('restoreSelected', 'Restore selected')
            ->assertTableBulkActionHasIcon('restoreSelected', 'heroicon-o-arrow-uturn-left');

        $this->assertTrue($component->instance()->getTable()->isSelectionEnabled());
        $this->assertFalse($component->instance()->getTable()->selectsCurrentPageOnly());

        $component
            ->mountTableBulkAction('restoreSelected', [$orderOne])
            ->callMountedTableBulkAction()
            ->assertHasNoTableBulkActionErrors();

        $this->assertNotNull(Order::query()->find($orderOne->getKey()));
        $this->assertNotNull(Order::onlyTrashed()->find($orderTwo->getKey()));
        $this->assertNotNull(Order::onlyTrashed()->find($orderThree->getKey()));

        Livewire::test(OrderTrashTable::class)
            ->mountTableBulkAction('restoreSelected', [$orderTwo, $orderThree])
            ->callMountedTableBulkAction()
            ->assertHasNoTableBulkActionErrors();

        $this->assertNotNull(Order::query()->find($orderTwo->getKey()));
        $this->assertNotNull(Order::query()->find($orderThree->getKey()));

        $otherCompanyOrder = Order::withoutGlobalScopes()
            ->withTrashed()
            ->find($otherCompanyOrder->getKey());

        $this->assertNotNull($otherCompanyOrder);
        $this->assertTrue($otherCompanyOrder->trashed());
    }

    public function test_trash_action_is_hidden_when_super_admin_is_viewing_all_companies(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        app(CompanyContext::class)->all();

        $this->actingAs($admin);

        Livewire::test(ListOrders::class)
            ->assertTableActionHidden('trash');
    }

    /**
     * @return array{User, Company}
     */
    protected function adminForCompany(): array
    {
        $company = Company::defaultCompany();
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $admin->companies()->sync([
            $company->getKey() => ['role' => 'super_admin', 'is_default' => true],
        ]);
        app(CompanyContext::class)->set($company);

        return [$admin, $company];
    }

    protected function makeCompany(string $name, string $slug): Company
    {
        return Company::query()->create([
            'name' => $name,
            'slug' => $slug,
            'invoice_prefix' => strtoupper(substr($slug, 0, 3)),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
    }

    protected function makeOrder(Company $company, string $number, string $customer): Order
    {
        app(CompanyContext::class)->set($company);

        return Order::query()->create([
            'company_id' => $company->getKey(),
            'order_number' => $number,
            'customer_name' => $customer,
            'order_date' => now()->toDateString(),
            'subtotal' => 500,
            'discount' => 0,
            'vat' => 0,
            'shipping_fee' => 0,
            'total_amount' => 500,
            'paid_amount' => 0,
            'due_amount' => 500,
            'status' => Order::STATUS_DRAFT,
            'source' => Order::SOURCE_ADMIN,
        ]);
    }
}
