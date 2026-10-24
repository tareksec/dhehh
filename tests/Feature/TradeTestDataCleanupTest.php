<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryChallan;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FundTransfer;
use App\Models\LcFile;
use App\Models\MachineSerial;
use App\Models\Order;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\TransactionLedger;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\DeliveryChallanService;
use App\Services\LandedCostService;
use App\Services\LcFileService;
use App\Services\MachineSerialService;
use App\Services\TradeCashBookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** `trade:remove-test-data`: the QA test records of a Trade Portal company go, real ones stay. */
class TradeTestDataCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $bkash;

    protected Account $bank;

    protected Customer $realCustomer;

    protected Product $realProduct;

    protected DeliveryChallan $realChallan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create(['name' => 'Tasneem', 'slug' => 'tasneem-test', 'invoice_prefix' => 'TKI', 'is_active' => true, 'trade_portal_enabled' => true]);
        app(CompanyContext::class)->set($this->company);
        $owner = User::factory()->create(['role' => 'trade_owner', 'is_active' => true]);

        $this->bkash = Account::query()->create(['name' => 'Bkash', 'type' => 'mobile_banking', 'opening_balance' => 10000, 'is_active' => true]);
        $this->bank = Account::query()->create(['name' => 'Brac Bank', 'type' => 'bank', 'opening_balance' => 0, 'is_active' => true]);
        $challans = app(DeliveryChallanService::class);

        // Real business: an item with stock and a sale left fully due.
        $realSupplier = Supplier::query()->create(['name' => 'MO Zhen Wu Foshan DC']);
        $this->realProduct = Product::query()->create(['name' => 'Cylinder', 'sku' => 'CYL', 'unit' => 'pcs', 'price' => 1000, 'sale_price' => 1000, 'cost_price' => 0, 'is_active' => true]);
        $realPurchase = Purchase::query()->create(['supplier_id' => $realSupplier->getKey(), 'purchase_date' => '2026-07-12', 'status' => 'draft']);
        $realPurchase->items()->create(['product_id' => $this->realProduct->getKey(), 'quantity' => 10, 'unit_cost' => 500]);
        $realPurchase->update(['status' => 'received']);
        $this->realCustomer = Customer::query()->create(['name' => 'Real Knitting']);
        $this->realChallan = $challans->create(['customer_id' => $this->realCustomer->getKey(), 'challan_number' => '004', 'challan_date' => '2026-08-01', 'items' => [['product_id' => $this->realProduct->getKey(), 'quantity' => 2, 'unit_price' => 1000]]]);

        // QA test data, as entered on 23 Oct.
        $supplier = Supplier::query()->create(['name' => 'TEST-Supplier-001']);
        $machine = Product::query()->create(['name' => 'TEST-Machine-001', 'sku' => 'TM1', 'unit' => 'set', 'price' => 50000, 'sale_price' => 50000, 'cost_price' => 0, 'is_active' => true]);
        $stand = Product::query()->create(['name' => 'TEST-Stand-001', 'sku' => 'TS1', 'unit' => 'pcs', 'price' => 0, 'sale_price' => 0, 'cost_price' => 0, 'is_active' => true]);
        $purchase = Purchase::query()->create(['supplier_id' => $supplier->getKey(), 'purchase_date' => '2026-10-23', 'lc_number' => 'TEST-LC-001', 'status' => 'draft']);
        $purchase->items()->create(['product_id' => $machine->getKey(), 'quantity' => 2, 'unit_cost' => 45000]);
        $purchase->items()->create(['product_id' => $stand->getKey(), 'quantity' => 5, 'unit_cost' => 500]);
        $purchase->update(['status' => 'received']);
        $file = app(LcFileService::class)->save(null, ['purchase_id' => $purchase->getKey(), 'lc_value_usd' => 1000]);
        app(LandedCostService::class)->addLine($file, ['kind' => 'duty', 'amount' => 12000]);
        app(MachineSerialService::class)->addToPurchase($purchase, $machine->getKey(), "TEST-SN-001\nTEST-SN-002");

        $customer = Customer::query()->create(['name' => 'TEST-Customer-001']);
        $paid = $challans->create(['customer_id' => $customer->getKey(), 'challan_number' => '001', 'challan_date' => '2026-10-23', 'cash_amount' => 20000, 'payment_account_id' => $this->bkash->getKey(), 'items' => [['product_id' => $machine->getKey(), 'quantity' => 1, 'unit_price' => 50000]]]);
        $challans->receivePayment($paid, 15000, '2026-10-23', $this->bkash->getKey(), 'TEST-Payment-001');
        $challans->cancel($paid, 'TEST-Cancel-Check', $owner);
        $open = $challans->create(['customer_id' => $customer->getKey(), 'challan_number' => '003', 'challan_date' => '2026-10-23', 'items' => [['product_id' => $machine->getKey(), 'quantity' => 1, 'unit_price' => 50000]]]);
        app(MachineSerialService::class)->setForChallan($open, [MachineSerial::query()->where('serial_number', 'TEST-SN-001')->value('id')]);

        $book = app(TradeCashBookService::class);
        $book->moveMoney($this->bkash->getKey(), $this->bank->getKey(), 1000, '2026-10-23', 'TEST-Transfer-001', $owner);
        $book->paySupplier($supplier->getKey(), 5000, '2026-10-23', $this->bkash->getKey(), null);
        $book->recordCost(null, 'TEST-Expense-001', 500, '2026-10-23', $this->bkash->getKey(), 'TEST-Expense-Note');
    }

    public function test_a_dry_run_lists_the_test_records_and_changes_nothing(): void
    {
        $ledgerRows = TransactionLedger::query()->count();

        $this->artisan('trade:remove-test-data', ['company' => 'tasneem-test'])
            ->expectsOutputToContain('TEST-Customer-001')
            ->expectsOutputToContain('TEST-LC-001')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame($ledgerRows, TransactionLedger::query()->count());
        $this->assertTrue(Customer::query()->where('name', 'TEST-Customer-001')->exists());
        $this->assertSame(3, DeliveryChallan::query()->count());
    }

    public function test_apply_removes_every_test_record_and_leaves_the_real_ones_and_opening_balances(): void
    {
        $this->artisan('trade:remove-test-data', ['company' => 'tasneem-test', '--apply' => true, '--no-backup' => true])
            ->expectsOutputToContain('No ledger rows are left')
            ->assertSuccessful();

        app(CompanyContext::class)->set($this->company);

        $this->assertSame(['004'], DeliveryChallan::query()->pluck('challan_number')->all());
        $this->assertSame(['Real Knitting'], Customer::query()->pluck('name')->all());
        $this->assertSame(['MO Zhen Wu Foshan DC'], Supplier::query()->pluck('name')->all());
        $this->assertSame(['Cylinder'], Product::query()->pluck('name')->all());
        $this->assertSame(1, Order::query()->withTrashed()->count());
        $this->assertSame(1, Purchase::query()->count());
        $this->assertSame(0, LcFile::query()->count());
        $this->assertSame(0, MachineSerial::query()->count());
        $this->assertSame(0, Expense::query()->withTrashed()->count());
        $this->assertSame(0, ExpenseCategory::query()->where('name', 'like', 'TEST-%')->count());
        $this->assertSame(0, FundTransfer::query()->count());
        $this->assertSame(0, TransactionLedger::query()->count());

        $this->assertSame('10000.00', $this->bkash->refresh()->current_balance);
        $this->assertSame('0.00', $this->bank->refresh()->current_balance);
        $this->assertSame(8, (int) $this->realProduct->refresh()->stock);
        $this->assertSame(2000.0, (float) $this->realCustomer->refresh()->current_balance);
        $this->assertSame(5000.0, (float) Supplier::query()->first()->current_balance);
    }

    public function test_it_refuses_when_a_test_record_is_mixed_with_a_real_one(): void
    {
        $this->realChallan->order->items()->create(['product_id' => Product::query()->where('name', 'TEST-Stand-001')->value('id'), 'quantity' => 1, 'unit_price' => 0]);

        $this->artisan('trade:remove-test-data', ['company' => 'tasneem-test', '--apply' => true, '--no-backup' => true])
            ->expectsOutputToContain('Nothing was removed')
            ->assertFailed();

        $this->assertTrue(Customer::query()->where('name', 'TEST-Customer-001')->exists());
    }

    public function test_it_only_looks_inside_the_named_company(): void
    {
        $other = Company::query()->create(['name' => 'Other', 'slug' => 'other-co', 'invoice_prefix' => 'OTH', 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        Customer::query()->create(['name' => 'TEST-Customer-001']);

        $this->artisan('trade:remove-test-data', ['company' => 'tasneem-test', '--apply' => true, '--no-backup' => true])->assertSuccessful();

        app(CompanyContext::class)->set($other);
        $this->assertTrue(Customer::query()->where('name', 'TEST-Customer-001')->exists());
    }
}
