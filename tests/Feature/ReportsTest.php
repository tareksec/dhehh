<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Services\ReportExportService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_page_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->seedReportData();

        $this->actingAs($user)
            ->get('/admin/reports/reports?date_from=2026-06-03&date_to=2026-06-03')
            ->assertOk()
            ->assertSee('Sales Report')
            ->assertSee('INV-REPORT-1')
            ->assertSee('CSV')
            ->assertSee('PDF')
            ->assertSee('class="fi-section', false)
            ->assertSee('class="fi-ta', false)
            ->assertSee('class="fi-btn', false)
            ->assertSee('class="fi-badge', false);

        $view = File::get(resource_path('views/filament/pages/reports.blade.php'));

        $this->assertStringNotContainsString('<style', $view);
        $this->assertStringNotContainsString('zz-', $view);
    }

    public function test_reports_page_switches_active_report_from_query_string(): void
    {
        $user = User::factory()->create();

        $this->seedReportData();

        $this->actingAs($user)
            ->get('/admin/reports/reports?report_type=profit&date_from=2026-06-03&date_to=2026-06-03')
            ->assertOk()
            ->assertSee('Product Profit Report')
            ->assertSee('Report Product')
            ->assertSee('৳ 100');
    }

    public function test_every_report_type_renders_through_the_native_filament_table(): void
    {
        $user = User::factory()->create();

        $this->seedReportData();

        foreach (ReportRegistry::all() as $type => $report) {
            $this->actingAs($user)
                ->get("/admin/reports/reports?report_type={$type}&date_from=2026-06-03&date_to=2026-06-03")
                ->assertOk()
                ->assertSee($report->title())
                ->assertSee('class="fi-ta', false);
        }
    }

    public function test_every_report_exports_csv_xlsx_and_print(): void
    {
        $user = User::factory()->create();

        $this->seedReportData();

        $supplier = Supplier::query()->first();

        foreach (ReportRegistry::all() as $type => $report) {
            $query = 'date_from=2026-06-03&date_to=2026-06-03';

            // A report about one supplier is refused without one, and works with one.
            if ($report->requiresSupplier()) {
                $this->actingAs($user)->get("/admin/reports/export/{$type}?{$query}")->assertStatus(422);
                $query .= "&supplier_id={$supplier->id}";
            }

            $csv = $this->actingAs($user)->get("/admin/reports/export/{$type}?{$query}");
            $csv->assertOk();
            $csv->assertDownload($report->filename().'.csv');

            $this->actingAs($user)
                ->get("/admin/reports/export/{$type}/xlsx?{$query}")
                ->assertOk()
                ->assertDownload($report->filename().'.xlsx');

            $this->actingAs($user)
                ->get("/admin/reports/print/{$type}?{$query}")
                ->assertOk()
                ->assertSee($report->title());
        }
    }

    public function test_every_report_row_carries_every_column_key(): void
    {
        $this->seedReportData();

        $filters = ReportFilters::make('2026-06-03', '2026-06-03');

        foreach (ReportRegistry::all() as $type => $report) {
            $keys = array_map(fn ($column): string => $column->key, $report->columns($filters));

            foreach ($report->rows($filters) as $row) {
                foreach ($keys as $key) {
                    $this->assertArrayHasKey($key, $row, "{$type} row is missing column {$key}");
                }
            }
        }
    }

    public function test_csv_starts_with_a_utf8_byte_order_mark_so_excel_reads_bangla(): void
    {
        $user = User::factory()->create();

        $this->seedReportData();

        $content = $this->actingAs($user)
            ->get('/admin/reports/export/sales?date_from=2026-06-03&date_to=2026-06-03')
            ->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
    }

    public function test_xlsx_export_is_a_real_workbook_with_numbers_and_an_audit_row(): void
    {
        $user = User::factory()->create();

        $this->seedReportData();

        $content = $this->actingAs($user)
            ->get('/admin/reports/export/sales/xlsx?date_from=2026-06-03&date_to=2026-06-03')
            ->streamedContent();

        $this->assertStringStartsWith('PK', $content);

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $content);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $shared = (string) $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        unlink($path);

        $this->assertStringContainsString('INV-REPORT-1', $sheet.$shared);
        $this->assertStringContainsString('Invoice', $sheet.$shared);

        $this->assertSame('xlsx', AuditLog::query()
            ->where('action', 'report_exported')
            ->latest('id')
            ->firstOrFail()
            ->new_values['format']);
    }

    public function test_print_view_shows_formatted_rows_totals_and_auto_prints(): void
    {
        $user = User::factory()->create();

        $this->seedReportData();

        $this->actingAs($user)
            ->get('/admin/reports/print/sales?date_from=2026-06-03&date_to=2026-06-03&print=1')
            ->assertOk()
            ->assertSee('INV-REPORT-1')
            ->assertSee('৳ 200')
            ->assertSee('Total')
            ->assertSee('window.print', false);

        $this->actingAs($user)
            ->get('/admin/reports/print/sales?date_from=2026-06-03&date_to=2026-06-03')
            ->assertOk()
            ->assertDontSee("addEventListener('load'", false);
    }

    public function test_reports_page_shows_totals_and_date_presets(): void
    {
        $user = User::factory()->create();

        $this->seedReportData();

        $this->actingAs($user)
            ->get('/admin/reports/reports?date_from=2026-06-03&date_to=2026-06-03')
            ->assertOk()
            ->assertSee('Totals')
            ->assertSee('Last 7 days')
            ->assertSee('This month');
    }

    public function test_bad_or_reversed_date_range_never_breaks_the_page_or_exports(): void
    {
        $user = User::factory()->create();

        $this->seedReportData();

        $this->actingAs($user)
            ->get('/admin/reports/reports?date_from=not-a-date&date_to=2026-13-99')
            ->assertOk();

        $this->actingAs($user)
            ->get('/admin/reports/export/sales?date_from=garbage&date_to=garbage')
            ->assertOk();

        [$from, $to] = app(ReportService::class)->dateRange('2026-06-10', '2026-06-03');

        $this->assertSame('2026-06-03', $from->toDateString());
        $this->assertSame('2026-06-10', $to->toDateString());

        $reversed = $this->actingAs($user)
            ->get('/admin/reports/export/sales?date_from=2026-06-10&date_to=2026-06-03')
            ->streamedContent();

        $this->assertStringContainsString('INV-REPORT-1', $reversed);
    }

    public function test_sales_staff_cannot_export_xlsx_or_print(): void
    {
        $user = User::factory()->create([
            'role' => 'sales_staff',
            'is_active' => true,
        ]);

        $this->actingAs($user)->get('/admin/reports/export/sales/xlsx')->assertForbidden();
        $this->actingAs($user)->get('/admin/reports/export/sales/pdf')->assertForbidden();
        $this->actingAs($user)->get('/admin/reports/print/sales')->assertForbidden();
    }

    public function test_unknown_report_type_is_a_404_for_every_export(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/reports/export/nope')->assertNotFound();
        $this->actingAs($user)->get('/admin/reports/export/nope/xlsx')->assertNotFound();
        $this->actingAs($user)->get('/admin/reports/export/nope/pdf')->assertNotFound();
        $this->actingAs($user)->get('/admin/reports/print/nope')->assertNotFound();
    }

    public function test_profit_report_uses_order_item_cost_snapshot(): void
    {
        $reportDate = '2026-06-03';

        $category = Category::query()->create([
            'name' => 'Snapshot Category',
            'slug' => 'snapshot-category',
        ]);
        $product = Product::query()->create([
            'name' => 'Snapshot Product',
            'sku' => 'SNAPSHOT-SKU',
            'unit' => 'pcs',
            'cost_price' => 40,
            'sale_price' => 100,
            'price' => 100,
            'stock' => 10,
            'reorder_level' => 2,
            'is_active' => true,
            'category_id' => $category->id,
        ]);
        StockMovement::query()->create([
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => 10,
            'reference_type' => Product::class,
            'reference_id' => $product->id,
            'note' => 'Snapshot test opening stock',
        ]);
        $customer = Customer::query()->create([
            'name' => 'Snapshot Customer',
            'is_active' => true,
        ]);
        $order = Order::query()->create([
            'order_number' => 'INV-SNAPSHOT-1',
            'customer_id' => $customer->id,
            'order_date' => $reportDate,
            'status' => 'completed',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 100,
        ]);

        $product->update(['cost_price' => 90]);

        [$from, $to] = app(ReportService::class)->dateRange($reportDate, $reportDate);
        $profit = app(ReportService::class)->profit($from, $to)->first();

        $this->assertSame(80.0, $profit['cost']);
        $this->assertSame(120.0, $profit['profit']);
    }

    public function test_sales_report_exports_csv(): void
    {
        $user = User::factory()->create();

        $reportDate = '2026-06-03';

        $this->seedReportData($reportDate);
        [$from, $to] = app(ReportService::class)->dateRange($reportDate, $reportDate);

        $this->assertCount(1, app(ReportService::class)->sales($from, $to));

        $response = $this->actingAs($user)
            ->get("/admin/reports/export/sales?date_from={$reportDate}&date_to={$reportDate}");

        $response->assertOk();
        $response->assertDownload('sales-report.csv');

        $content = $response->streamedContent();

        $this->assertStringContainsString('Invoice', $content);
        $this->assertStringContainsString('INV-REPORT-1', $content);
        $this->assertStringContainsString('Report Customer', $content);

        $auditLog = AuditLog::query()
            ->where('action', 'report_exported')
            ->where('auditable_type', ReportExportService::class)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($user->id, $auditLog->user_id);
        $this->assertSame('csv', $auditLog->new_values['format']);
        $this->assertSame('sales', $auditLog->new_values['type']);
    }

    public function test_sales_report_exports_pdf(): void
    {
        $user = User::factory()->create();

        $reportDate = '2026-06-03';

        $this->seedReportData($reportDate);

        $response = $this->actingAs($user)
            ->get("/admin/reports/export/sales/pdf?date_from={$reportDate}&date_to={$reportDate}");

        $response->assertOk();
        $response->assertDownload('sales-report.pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_invoice_exports_pdf(): void
    {
        $user = User::factory()->create();

        $this->seedReportData();

        $order = Order::query()->where('order_number', 'INV-REPORT-1')->firstOrFail();

        $response = $this->actingAs($user)
            ->get("/admin/orders/{$order->id}/pdf");

        $response->assertOk();
        $response->assertDownload('INV-REPORT-1.pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_purchase_report_includes_dynamic_custom_cost_fields(): void
    {
        $user = User::factory()->create();

        $reportDate = '2026-06-03';

        $this->seedReportData($reportDate);

        $this->actingAs($user)
            ->get("/admin/reports/reports?report_type=purchases&date_from={$reportDate}&date_to={$reportDate}")
            ->assertOk()
            ->assertSee('Warehouse Charge')
            ->assertSee('China to BD Costs')
            ->assertSee('৳ 25');

        $response = $this->actingAs($user)
            ->get("/admin/reports/export/purchases?date_from={$reportDate}&date_to={$reportDate}");

        $response->assertOk();
        $response->assertDownload('purchase-report.csv');

        $content = $response->streamedContent();

        $this->assertStringContainsString('Warehouse Charge', $content);
        $this->assertStringContainsString('China to BD Cost Total', $content);
        $this->assertStringContainsString('Landed Cost Total', $content);
        $this->assertStringContainsString('PUR-REPORT-1', $content);
    }

    public function test_purchase_list_shows_custom_cost_summary(): void
    {
        $user = User::factory()->create();

        $this->seedReportData();

        $this->actingAs($user)
            ->get('/admin/purchasing/purchases')
            ->assertOk()
            ->assertSee('China to BD Costs');
    }

    private function seedReportData(string $reportDate = '2026-06-03'): void
    {
        $account = Account::query()->create([
            'name' => 'Report Cash',
            'type' => 'cash',
            'opening_balance' => 10000,
            'is_active' => true,
        ]);

        $category = Category::query()->create([
            'name' => 'Report Category',
            'slug' => 'report-category',
        ]);
        $product = Product::query()->create([
            'name' => 'Report Product',
            'sku' => 'REPORT-SKU',
            'unit' => 'pcs',
            'cost_price' => 50,
            'sale_price' => 100,
            'price' => 100,
            'stock' => 20,
            'reorder_level' => 5,
            'is_active' => true,
            'category_id' => $category->id,
        ]);
        StockMovement::query()->create([
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => 20,
            'reference_type' => Product::class,
            'reference_id' => $product->id,
            'note' => 'Report test opening stock',
        ]);

        $customer = Customer::query()->create([
            'name' => 'Report Customer',
            'phone' => '01700000000',
            'opening_balance' => 0,
            'is_active' => true,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Report Supplier',
            'phone' => '01800000000',
            'opening_balance' => 0,
            'is_active' => true,
        ]);

        $order = Order::query()->create([
            'order_number' => 'INV-REPORT-1',
            'customer_id' => $customer->id,
            'order_date' => $reportDate,
            'paid_amount' => 50,
            'status' => 'completed',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 100,
        ]);

        $purchase = Purchase::query()->create([
            'purchase_number' => 'PUR-REPORT-1',
            'supplier_id' => $supplier->id,
            'purchase_date' => $reportDate,
            'freight_to_ctg' => 10,
            'custom_costs' => [
                ['label' => 'Warehouse Charge', 'amount' => 15],
            ],
            'paid_amount' => 30,
            'status' => 'received',
        ]);
        PurchaseItem::query()->create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_cost' => 50,
        ]);

        $expenseCategory = ExpenseCategory::query()->create([
            'name' => 'Report Expense Category',
            'slug' => 'report-expense-category',
        ]);

        Expense::query()->create([
            'expense_number' => 'EXP-REPORT-1',
            'expense_category_id' => $expenseCategory->id,
            'account_id' => $account->id,
            'amount' => 25,
            'expense_date' => $reportDate,
        ]);
    }
}
