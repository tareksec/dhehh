<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\DeliveryChallanService;
use App\Services\TradeCashBookService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** The Trade Portal cash book and balance sheet (docs/tasneem-samples/PLAN.md §5), read from the ERP's account ledger. */
class TradeCashBookServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $cash;

    protected Account $bank;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Cash Co', 'slug' => 'cash-'.uniqid(), 'invoice_prefix' => 'CC'.random_int(1000, 9999),
            'is_active' => true, 'trade_portal_enabled' => true,
        ]);
        app(CompanyContext::class)->set($this->company);

        $this->cash = Account::query()->create(['name' => 'Cash box', 'type' => 'cash', 'opening_balance' => 50000, 'is_active' => true]);
        $this->bank = Account::query()->create(['name' => 'DBBL', 'type' => 'bank', 'opening_balance' => 200000, 'is_active' => true]);
        $this->owner = User::factory()->create(['role' => 'trade_owner', 'is_active' => true]);
    }

    protected function sell(string $date, float $price, float $cash): void
    {
        $machine = Product::query()->create(['name' => 'Machine '.uniqid(), 'sku' => 'M-'.uniqid(), 'sale_price' => $price, 'price' => $price, 'cost_price' => 500000, 'is_active' => true]);
        $machine->setStockFromProductForm(1);

        app(DeliveryChallanService::class)->create([
            'challan_date' => $date,
            'buyer_name' => 'Madina Knitwear',
            'items' => [['product_id' => $machine->getKey(), 'description' => 'Machine', 'quantity' => 1, 'unit_price' => $price]],
            'cash_amount' => $cash,
            'cash_date' => $date,
            'payment_account_id' => $this->cash->getKey(),
        ]);
    }

    public function test_a_day_reads_sales_costs_supplier_payments_and_a_bank_deposit_from_the_ledger(): void
    {
        $day = CarbonImmutable::parse('2026-10-10');
        $service = app(TradeCashBookService::class);
        $supplier = Supplier::query()->create(['name' => 'Hengli', 'opening_balance' => 300000]);

        $this->sell('2026-10-10', 750000, 400000);
        $service->recordCost(null, 'Truck fare', 3000, '2026-10-10', $this->cash->getKey(), 'Narayanganj');
        $service->paySupplier($supplier->getKey(), 100000, '2026-10-10', $this->bank->getKey(), null);
        $service->moveMoney($this->cash->getKey(), $this->bank->getKey(), 300000, '2026-10-10', 'Deposit slip 12', $this->owner);

        $book = $service->day($day);

        $this->assertSame(250000.0, $book['opening']);
        $this->assertSame(400000.0, $book['in']);
        $this->assertSame(103000.0, $book['out']);
        $this->assertSame(547000.0, $book['closing']);
        $this->assertSame(['sale', 'cost', 'supplier', 'move', 'move'], array_column($book['lines'], 'kind'));
        $this->assertSame('Sale — challan 001', $book['lines'][0]['label']);
        $this->assertSame('Madina Knitwear', $book['lines'][0]['detail']);
        $this->assertSame('Truck fare', $book['lines'][1]['label']);
        $this->assertSame('Hengli', $book['lines'][2]['detail']);
        $this->assertSame('Moved to DBBL', $book['lines'][3]['label']);

        $balances = array_column($book['accounts'], 'balance', 'name');
        $this->assertSame(147000.0, $balances['Cash box']);
        $this->assertSame(400000.0, $balances['DBBL']);

        // The next day starts where this one ended, and nothing of the 10th shows on it.
        $next = $service->day($day->addDay());
        $this->assertSame(547000.0, $next['opening']);
        $this->assertSame([], $next['lines']);
    }

    public function test_the_month_sums_days_kinds_and_costs(): void
    {
        $service = app(TradeCashBookService::class);
        $this->sell('2026-10-03', 100000, 100000);
        $this->sell('2026-10-20', 200000, 150000);
        $service->recordCost(null, 'Truck fare', 2000, '2026-10-03', $this->cash->getKey(), null);
        $service->recordCost(null, 'Truck fare', 1000, '2026-10-20', $this->cash->getKey(), null);
        $this->sell('2026-11-01', 50000, 50000);

        $month = $service->month(CarbonImmutable::parse('2026-10-15'));

        $this->assertSame(250000.0, $month['opening']);
        $this->assertSame(250000.0, $month['in']);
        $this->assertSame(3000.0, $month['out']);
        $this->assertSame(497000.0, $month['closing']);
        $this->assertCount(2, $month['days']);
        $this->assertSame(['in' => 250000.0, 'out' => 0.0], $month['kinds']['sale']);
        $this->assertSame(['Truck fare' => 3000.0], $month['costs']);
    }

    public function test_the_balance_sheet_adds_cash_bank_stock_and_dues_less_what_suppliers_are_owed(): void
    {
        $this->sell('2026-10-10', 750000, 400000);
        $part = Product::query()->create(['name' => 'Feeder', 'sku' => 'F-1', 'sale_price' => 100, 'price' => 100, 'cost_price' => 40, 'is_active' => true]);
        $part->setStockFromProductForm(100);
        Supplier::query()->create(['name' => 'Hengli', 'opening_balance' => 300000]);

        $sheet = app(TradeCashBookService::class)->balanceSheet();

        $this->assertSame(450000.0, $sheet['cash']);
        $this->assertSame(200000.0, $sheet['bank']);
        $this->assertSame(4000.0, $sheet['stock']);
        $this->assertSame(350000.0, $sheet['receivable']);
        $this->assertSame(300000.0, $sheet['payable']);
        $this->assertSame(1004000.0 - 300000.0, $sheet['net']);
    }

    public function test_the_erp_rules_stay_in_force(): void
    {
        $service = app(TradeCashBookService::class);
        $supplier = Supplier::query()->create(['name' => 'Small debt', 'opening_balance' => 1000]);

        $this->assertThrows(fn () => $service->moveMoney($this->cash->getKey(), $this->bank->getKey(), 60000, '2026-10-10', null, $this->owner), ValidationException::class);
        $this->assertThrows(fn () => $service->moveMoney($this->cash->getKey(), $this->cash->getKey(), 10, '2026-10-10', null, $this->owner), ValidationException::class);
        $this->assertThrows(fn () => $service->recordCost(null, 'Rent', 60000, '2026-10-10', $this->cash->getKey(), null), ValidationException::class);
        $this->assertThrows(fn () => $service->paySupplier($supplier->getKey(), 5000, '2026-10-10', $this->cash->getKey(), null), ValidationException::class);
        $this->assertThrows(fn () => $service->recordCost(null, '', 10, '2026-10-10', $this->cash->getKey(), null), ValidationException::class);
    }

    public function test_another_companys_money_is_not_in_the_book(): void
    {
        $other = Company::query()->create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'invoice_prefix' => 'OT'.random_int(1000, 9999), 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        Account::query()->create(['name' => 'Their cash', 'type' => 'cash', 'opening_balance' => 999999, 'is_active' => true]);
        Customer::query()->create(['name' => 'Their buyer', 'opening_balance' => 5000]);

        app(CompanyContext::class)->set($this->company);
        $sheet = app(TradeCashBookService::class)->balanceSheet();

        $this->assertSame(50000.0, $sheet['cash']);
        $this->assertSame(0.0, $sheet['receivable']);
        $this->assertSame(250000.0, app(TradeCashBookService::class)->openingOn(CarbonImmutable::parse('2026-10-01')));
    }
}
