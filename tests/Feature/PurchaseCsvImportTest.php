<?php

namespace Tests\Feature;

use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\PurchaseCsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner request 2026-10-21: create purchases from a CSV shaped like the Stock
 * History sheet (date, product, quantity). Supplier and status are chosen at
 * upload, one purchase per date, unknown products are skipped and listed.
 */
class PurchaseCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Supplier $supplier;

    private Product $pearl;

    private Product $rose;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Purchase Import Co', 'slug' => 'purchase-import-co',
            'invoice_prefix' => 'PIC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);

        $this->supplier = Supplier::query()->create(['name' => 'Import Supplier', 'opening_balance' => 0, 'is_active' => true]);
        $this->pearl = $this->product('Love Pearl', 'LP-1', 12);
        $this->rose = $this->product('Love Rose', 'LR-1', 20);
    }

    public function test_one_purchase_is_created_per_date_and_a_blank_date_takes_the_row_above(): void
    {
        $report = $this->import([
            ['date' => '2026-02-21', 'product' => 'Love Pearl Lite', 'qty' => 7200],
            ['date' => '', 'product' => 'Love Pearl', 'qty' => 400],
            ['date' => '', 'product' => 'Love Rose', 'qty' => 359],
            ['date' => '2026-02-24', 'product' => 'Love Rose', 'qty' => 100],
        ], extraProducts: ['Love Pearl Lite' => 5]);

        $this->assertSame(2, $report['purchases']);
        $this->assertSame(4, $report['items']);
        $this->assertSame(8059, $report['quantity']);
        $this->assertSame([], $report['skipped']);

        $first = Purchase::query()->whereDate('purchase_date', '2026-02-21')->firstOrFail();
        $this->assertSame(3, $first->items()->count());
        $this->assertSame('draft', $first->status);
        $this->assertSame($this->supplier->id, $first->supplier_id);
        $this->assertSame(1, Purchase::query()->whereDate('purchase_date', '2026-02-24')->count());
    }

    public function test_a_blank_unit_cost_uses_the_product_cost_price_and_short_damaged_are_kept(): void
    {
        $this->import([
            ['date' => '2026-03-01', 'product' => 'Love Pearl', 'qty' => 100, 'cost' => '', 'short' => 5, 'damaged' => 3],
            ['date' => '2026-03-01', 'product' => 'Love Rose', 'qty' => 10, 'cost' => '1,250.50'],
        ]);

        $pearl = PurchaseItem::query()->where('product_id', $this->pearl->id)->firstOrFail();
        $this->assertEqualsWithDelta(12.0, (float) $pearl->unit_cost, 0.001);
        $this->assertSame(5, $pearl->short_quantity);
        $this->assertSame(3, $pearl->damaged_quantity);
        $this->assertEqualsWithDelta(1250.5, (float) PurchaseItem::query()->where('product_id', $this->rose->id)->firstOrFail()->unit_cost, 0.001);
    }

    public function test_draft_changes_no_stock_and_received_adds_the_sellable_units(): void
    {
        $this->import([['date' => '2026-03-01', 'product' => 'Love Pearl', 'qty' => 100, 'short' => 5, 'damaged' => 5]], status: 'draft');
        $this->assertSame(0, (int) $this->pearl->fresh()->stock);
        $this->assertEqualsWithDelta(0.0, (float) $this->supplier->fresh()->current_balance, 0.001);

        $this->import([['date' => '2026-03-02', 'product' => 'Love Pearl', 'qty' => 100, 'short' => 5, 'damaged' => 5]], status: 'received');
        $this->assertSame(90, (int) $this->pearl->fresh()->stock);
        $this->assertEqualsWithDelta(1200.0, (float) $this->supplier->fresh()->current_balance, 0.001);
    }

    public function test_sku_is_tried_before_the_name_and_unusable_rows_are_skipped_and_listed(): void
    {
        $this->product('Love Pearl', 'LP-DUP', 15);

        $path = $this->csv([
            ['date' => '2026-03-01', 'sku' => 'LR-1', 'product' => 'wrong name', 'qty' => 5],
            ['date' => '2026-03-01', 'sku' => '', 'product' => 'No Such Product', 'qty' => 5],
            ['date' => '2026-03-01', 'sku' => '', 'product' => 'Love Pearl', 'qty' => 5],
            ['date' => '2026-03-01', 'sku' => 'LP-1', 'product' => '', 'qty' => '2.5'],
            ['date' => '2026-03-01', 'sku' => 'LP-1', 'product' => '', 'qty' => 0],
            ['date' => '2026-03-01', 'sku' => 'LP-1', 'product' => '', 'qty' => 10, 'short' => 8, 'damaged' => 5],
            ['date' => 'not a date', 'sku' => 'LP-1', 'product' => '', 'qty' => 1],
            ['date' => '', 'sku' => 'LP-1', 'product' => '', 'qty' => 1],
        ], ['date', 'sku', 'product', 'qty', 'cost', 'short', 'damaged']);

        $report = app(PurchaseCsvImportService::class)->run($path, $this->supplier, 'draft');

        $this->assertSame(1, $report['purchases']);
        $this->assertSame(1, $report['items']);
        $this->assertSame($this->rose->id, PurchaseItem::query()->firstOrFail()->product_id);
        $this->assertSame([3, 4, 5, 6, 7, 8, 9], array_column($report['skipped'], 'row'));

        $reasons = implode(' | ', array_column($report['skipped'], 'reason'));
        $this->assertStringContainsString('Product not found: No Such Product', $reasons);
        $this->assertStringContainsString('More than one product is named Love Pearl', $reasons);
        $this->assertStringContainsString('Quantity must be a whole number above zero.', $reasons);
        $this->assertStringContainsString('Short plus damaged units cannot be more than the quantity.', $reasons);
        $this->assertStringContainsString('Date is missing or not understood.', $reasons);
    }

    public function test_dates_in_day_first_and_month_first_forms_are_understood(): void
    {
        $report = $this->import([
            ['date' => '22/08/2025', 'product' => 'Love Pearl', 'qty' => 1],
            ['date' => '08/28/2025', 'product' => 'Love Pearl', 'qty' => 1],
            ['date' => '10/09/2025', 'product' => 'Love Pearl', 'qty' => 1],
            ['date' => '5-3-26', 'product' => 'Love Pearl', 'qty' => 1],
            ['date' => '2026/04/09', 'product' => 'Love Pearl', 'qty' => 1],
        ]);

        $dates = Purchase::query()->orderBy('purchase_date')->get()->map(fn (Purchase $p): string => $p->purchase_date->toDateString())->all();

        $this->assertSame(['2025-08-22', '2025-08-28', '2025-09-10', '2026-03-05', '2026-04-09'], $dates);
        $this->assertSame(2, $report['ambiguous_dates']);
    }

    public function test_preview_saves_nothing_and_importing_the_same_file_again_does_not_duplicate(): void
    {
        $rows = [
            ['date' => '2026-03-01', 'product' => 'Love Pearl', 'qty' => 10],
            ['date' => '2026-03-02', 'product' => 'Love Rose', 'qty' => 20],
        ];

        $preview = $this->import($rows, dryRun: true);
        $this->assertTrue($preview['dry_run']);
        $this->assertSame(2, $preview['purchases']);
        $this->assertSame(0, Purchase::query()->count());

        $this->assertSame(2, $this->import($rows)['purchases']);
        $again = $this->import(array_reverse($rows));
        $this->assertSame(0, $again['purchases']);
        $this->assertSame(2, $again['already_imported']);
        $this->assertSame(2, Purchase::query()->count());
    }

    public function test_a_file_without_the_needed_columns_is_refused(): void
    {
        $path = $this->csv([['product' => 'Love Pearl']], ['product']);

        $this->expectException(ValidationException::class);

        app(PurchaseCsvImportService::class)->run($path, $this->supplier, 'draft');
    }

    public function test_semicolon_files_with_a_byte_order_mark_are_read(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pur');
        file_put_contents($path, "\xEF\xBB\xBFDate;Product Name;Quantity\n2026-03-01;Love Pearl;10\n");

        $report = app(PurchaseCsvImportService::class)->run($path, $this->supplier, 'draft');

        $this->assertSame(1, $report['purchases']);
        $this->assertSame(10, PurchaseItem::query()->firstOrFail()->quantity);
    }

    public function test_the_import_action_on_the_purchases_page_creates_the_purchases(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->withSession(['current_company_id' => $this->company->id]);

        $csv = "Date,Product Name,Quantity\n01/08/2025,Love Pearl,1900\n,Love Rose,600\n";

        Livewire::test(ListPurchases::class)
            ->callAction('importPurchases', [
                'csv' => UploadedFile::fake()->createWithContent('stock.csv', $csv),
                'supplier_id' => $this->supplier->id,
                'status' => 'draft',
                'preview_only' => false,
            ])
            ->assertNotified('Purchases imported');

        $purchase = Purchase::query()->firstOrFail();
        $this->assertSame('2025-08-01', $purchase->purchase_date->toDateString());
        $this->assertSame(2, $purchase->items()->count());
    }

    public function test_the_sample_csv_downloads_for_a_user_who_can_create_purchases(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $response = $this->actingAs($admin)->withSession(['current_company_id' => $this->company->id])
            ->get(route('purchases.import.sample'));

        $response->assertOk()->assertDownload('purchases-import-sample.csv');
        $this->assertStringContainsString('date,sku,product,quantity,unit_cost,short_qty,damaged_qty', $response->streamedContent());
    }

    public function test_the_purchase_form_lays_the_item_fields_out_in_a_grid_not_one_row(): void
    {
        $form = file_get_contents(app_path('Filament/Resources/Purchases/Schemas/PurchaseForm.php'));

        $items = substr($form, strpos($form, "Section::make('Items')"), strpos($form, "Section::make('China to BD Costs')") - strpos($form, "Section::make('Items')"));

        $this->assertStringNotContainsString('->table([', $items);
        $this->assertStringContainsString("->columns(['default' => 1, 'md' => 12])", $items);
        $this->assertSame(13, substr_count($items, '->columnSpan(self::itemSpan('));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $extraProducts  name => cost price
     */
    private function import(array $rows, string $status = 'draft', bool $dryRun = false, array $extraProducts = []): array
    {
        foreach ($extraProducts as $name => $cost) {
            $this->product($name, strtoupper(substr(md5($name), 0, 6)), $cost);
        }

        return app(PurchaseCsvImportService::class)->run(
            $this->csv($rows, ['date', 'product', 'qty', 'cost', 'short', 'damaged']),
            $this->supplier,
            $status,
            $dryRun,
        );
    }

    /**
     * Writes a CSV whose headings are the given keys (qty is written as
     * "quantity", cost as "unit_cost", short as "short_qty", damaged as
     * "damaged_qty"); a key a row lacks is written blank.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $keys
     */
    private function csv(array $rows, array $keys = ['date', 'product', 'qty']): string
    {
        $headings = ['qty' => 'quantity', 'cost' => 'unit_cost', 'short' => 'short_qty', 'damaged' => 'damaged_qty'];
        $path = tempnam(sys_get_temp_dir(), 'pur');
        $handle = fopen($path, 'w');
        fputcsv($handle, array_map(fn (string $key): string => $headings[$key] ?? $key, $keys), ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $key): string => (string) ($row[$key] ?? ''), $keys), ',', '"', '');
        }

        fclose($handle);

        return $path;
    }

    private function product(string $name, string $sku, float $cost): Product
    {
        $category = Category::query()->firstOrCreate(['slug' => 'purchase-import'], ['name' => 'Purchase Import']);

        return Product::query()->create([
            'name' => $name, 'sku' => $sku, 'unit' => 'pcs', 'cost_price' => $cost, 'sale_price' => $cost * 2,
            'price' => $cost * 2, 'stock' => 0, 'reorder_level' => 0, 'is_active' => true, 'category_id' => $category->id,
        ]);
    }
}
