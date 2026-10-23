<?php

namespace Tests\Feature;

use App\Filament\Trade\Pages\Report;
use App\Http\Middleware\SetTradePortalLocale;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\DeliveryChallanService;
use App\Services\LandedCostService;
use App\Services\LcFileService;
use App\Services\TradeReportService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/** Trade Portal phase 6 (PLAN.md §6, SUMMARY.md §4): the nine reports on screen, as PDF and as Excel. */
class TradeReportsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Account $cash;

    protected Product $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create(['name' => 'Tasneem', 'slug' => 'tasneem-'.uniqid(), 'invoice_prefix' => 'TK'.random_int(1000, 9999), 'is_active' => true, 'trade_portal_enabled' => true]);
        $this->owner = User::factory()->create(['role' => 'trade_owner', 'is_active' => true]);
        $this->owner->companies()->attach($this->company->getKey(), ['role' => 'trade_owner', 'is_default' => true]);
        app(CompanyContext::class)->set($this->company);

        $this->cash = Account::query()->create(['name' => 'Cash box', 'type' => 'cash', 'opening_balance' => 10000, 'is_active' => true]);
        $this->machine = Product::query()->create(['name' => 'Circular knitting machine', 'sku' => 'CKM', 'unit' => 'set', 'sale_price' => 750000, 'price' => 750000, 'cost_price' => 550000, 'is_active' => true]);
        $this->machine->setStockFromProductForm(5);
    }

    /** A one-machine challan for the same buyer each time. */
    protected function sell(string $date, float $cash): void
    {
        $buyer = Customer::query()->firstOrCreate(['name' => 'Madina Knitwear']);

        app(DeliveryChallanService::class)->create([
            'challan_date' => $date,
            'customer_id' => $buyer->getKey(),
            'buyer_name' => 'Madina Knitwear',
            'items' => [['product_id' => $this->machine->getKey(), 'description' => 'Machine', 'quantity' => 1, 'unit', 'unit_price' => 750000]],
            'cash_amount' => $cash,
            'payment_account_id' => $this->cash->getKey(),
        ]);
    }

    public function test_sales_reports_add_up_the_days_and_the_month(): void
    {
        $this->sell('2026-10-03', 500000);
        $this->sell('2026-10-03', 750000);
        $this->sell('2026-10-20', 0);
        $reports = app(TradeReportService::class);

        $day = $reports->build('daily_sales', '2026-10-03');
        $this->assertCount(2, $day['rows']);
        $this->assertSame('Challan 001', $day['rows'][0]['number']);
        $this->assertSame(['number' => 'Total', 'total' => 1500000.0, 'received' => 1250000.0, 'due' => 250000.0], $day['totals']);

        $month = $reports->build('monthly_sales', '2026-10-15');
        $this->assertCount(2, $month['rows']);
        $this->assertSame(3.0, $month['totals']['count']);
        $this->assertSame(2250000.0, $month['totals']['total']);
        $this->assertSame(1000000.0, $month['totals']['due']);
    }

    public function test_dues_are_split_by_age_with_the_old_due_apart(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31'));
        $this->sell('2026-12-20', 700000);
        $this->sell('2026-10-15', 650000);
        $customer = Customer::query()->where('name', 'Madina Knitwear')->first();
        $customer->forceFill(['opening_balance' => 20000])->save();
        $customer->syncCurrentBalance();

        $row = app(TradeReportService::class)->build('receivables_aging')['rows'][0];

        $this->assertSame(50000.0, $row['d0_30']);
        $this->assertSame(0.0, $row['d31_60']);
        $this->assertSame(100000.0, $row['d61_90']);
        $this->assertSame(20000.0, $row['old']);
        $this->assertSame(170000.0, $row['total']);
    }

    public function test_the_stock_ledger_and_cash_and_bank_follow_the_ledgers(): void
    {
        $this->sell(now()->toDateString(), 300000);
        $reports = app(TradeReportService::class);

        $stock = $reports->build('stock_ledger', now()->startOfMonth()->toDateString(), now()->toDateString());
        $this->assertSame(['item' => 'Circular knitting machine', 'unit' => 'set', 'opening' => 0, 'received' => 5, 'sold' => 1, 'other' => 0, 'closing' => 4, 'cost' => 550000.0, 'value' => 2200000.0], $stock['rows'][0]);

        $cash = $reports->build('cash_bank', now()->startOfMonth()->toDateString(), now()->toDateString());
        $this->assertSame(['account' => 'Cash box', 'opening' => 10000.0, 'in' => 300000.0, 'out' => 0.0, 'closing' => 310000.0], $cash['rows'][0]);
    }

    public function test_the_lc_reports_and_supplier_dues(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Matill', 'opening_balance' => 120000]);
        $purchase = Purchase::query()->create(['supplier_id' => $supplier->getKey(), 'purchase_date' => '2026-09-06', 'status' => 'draft']);
        $purchase->items()->create(['product_id' => $this->machine->getKey(), 'quantity' => 8, 'unit_cost' => 0]);
        $file = app(LcFileService::class)->save(null, [
            'purchase_id' => $purchase->getKey(), 'lc_number' => '163026010167', 'lc_date' => '2026-09-07', 'lc_value_usd' => 35600,
            'margin_percent' => 30, 'margin_amount_bdt' => 1302960, 'insurer_name' => 'ISLAMI COMMERCIAL INSURANCE PLC',
            'cover_note_number' => 'ICI/BAB/MC-0118/09/2026', 'cover_note_date' => '2026-09-01', 'shipment_advice_date' => '2026-09-24', 'stage' => 'on_vessel',
        ]);
        app(LandedCostService::class)->setRate($file, 122);
        app(LandedCostService::class)->addLine($file, ['kind' => 'duty', 'amount' => 56800]);
        $reports = app(TradeReportService::class);

        $status = $reports->build('lc_status')['rows'][0];
        $this->assertSame('163026010167 07/09/2026', $status['lc']);
        $this->assertSame('On vessel', $status['stage']);

        $landed = $reports->build('landed_cost')['rows'][0];
        $this->assertSame(4400000.0, $landed['total']);
        $this->assertSame(550000.0, $landed['per_unit']);

        $register = $reports->build('shipment_advice_register', '2026-09-01', '2026-09-30');
        $this->assertSame('ICI/BAB/MC-0118/09/2026 01/09/2026', $register['rows'][0]['cover_note']);
        $this->assertSame([], $reports->build('shipment_advice_register', '2026-10-01', '2026-10-31')['rows']);

        $this->assertSame(['supplier' => 'Matill', 'phone' => null, 'open_lcs' => 1, 'due' => 120000.0], $reports->build('supplier_payable')['rows'][0]);
        $this->assertStringContainsString('1,302,960.00', implode(' ', $reports->build('cash_bank')['notes']));
    }

    public function test_every_report_opens_on_screen_and_downloads_as_pdf_and_excel(): void
    {
        $this->sell(now()->toDateString(), 300000);
        $this->assertContains('trade.reports', UserRole::query()->where('slug', 'trade_owner')->value('permissions'));
        app(CompanyContext::class)->clear();

        $this->actingAs($this->owner)->withSession([SetTradePortalLocale::SESSION_KEY => 'en'])->get('/trade/reports')->assertOk()->assertSee('Dues by age');

        foreach (array_keys(TradeReportService::REPORTS) as $key) {
            $this->actingAs($this->owner)->get('/trade/report?type='.$key)->assertOk();

            $pdf = $this->actingAs($this->owner)->get(route('filament.trade.reports.download', ['report' => $key, 'format' => 'pdf']));
            $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', (string) $pdf->getContent(), $key);

            $this->actingAs($this->owner)->get(route('filament.trade.reports.download', ['report' => $key, 'format' => 'xlsx']))->assertOk();
            $this->actingAs($this->owner)->get(route('filament.trade.reports.download', ['report' => $key, 'format' => 'print']))->assertOk();
        }
    }

    public function test_the_print_page_is_bangla_and_the_pdf_file_english(): void
    {
        $this->sell(now()->toDateString(), 300000);
        app(CompanyContext::class)->clear();

        // Bangla is the portal's default language: the print page (drawn by the browser) is in Bangla.
        $this->actingAs($this->owner)->get(route('filament.trade.reports.download', ['report' => 'daily_sales', 'format' => 'print']))
            ->assertOk()->assertSee('দিনের বিক্রি')->assertSee('৳ ৭,৫০,০০০')->assertSee('report-print', false);

        // The PDF file is English, because the PDF engine cannot shape Bangla.
        $this->actingAs($this->owner)->get(route('filament.trade.reports.download', ['report' => 'daily_sales', 'format' => 'pdf']))->assertOk();
        $this->assertSame('en', app()->getLocale());
    }

    public function test_the_excel_file_keeps_amounts_as_numbers(): void
    {
        $this->sell(now()->toDateString(), 300000);
        app(CompanyContext::class)->clear();

        $response = $this->actingAs($this->owner)->get(route('filament.trade.reports.download', ['report' => 'daily_sales', 'format' => 'xlsx', 'from' => now()->toDateString()]));
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($path, $response->streamedContent() ?: file_get_contents($response->baseResponse->getFile()->getPathname()));

        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();
        @unlink($path);

        // Title, period, the column names, then the sale (the empty spacer row is not read back).
        $this->assertStringContainsString('Tasneem', $rows[0][0]);
        $this->assertCount(8, $rows[2]);
        $this->assertSame('Madina Knitwear', $rows[3][1]);
        $this->assertSame(750000, (int) $rows[3][4]);
        $this->assertIsNotString($rows[3][4]);
    }

    public function test_the_screen_changes_with_the_period_and_needs_the_permission(): void
    {
        $this->sell('2026-10-03', 0);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('trade'));

        Livewire::withQueryParams(['type' => 'daily_sales', 'from' => '2026-10-02'])->test(Report::class)
            ->assertSee('Nothing to show for this period.')
            ->set('from', '2026-10-03')
            ->assertSee('Madina Knitwear');

        UserRole::query()->create(['name' => 'Clerk', 'slug' => 'trade_clerk', 'permissions' => ['trade.access', 'trade.sales'], 'is_active' => true]);
        $clerk = User::factory()->create(['role' => 'trade_clerk', 'is_active' => true]);
        $clerk->companies()->attach($this->company->getKey(), ['role' => 'trade_clerk', 'is_default' => true]);
        app(CompanyContext::class)->clear();

        $this->actingAs($clerk)->get('/trade/reports')->assertForbidden();
        $this->actingAs($clerk)->get(route('filament.trade.reports.download', ['report' => 'daily_sales', 'format' => 'pdf']))->assertForbidden();
    }

    public function test_another_companys_sales_are_not_in_the_reports(): void
    {
        $this->sell(now()->toDateString(), 0);
        $other = Company::query()->create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'invoice_prefix' => 'OT'.random_int(1000, 9999), 'is_active' => true]);
        app(CompanyContext::class)->set($other);

        $this->assertSame([], app(TradeReportService::class)->build('daily_sales')['rows']);
        $this->assertSame(0, Order::query()->count());
    }
}
