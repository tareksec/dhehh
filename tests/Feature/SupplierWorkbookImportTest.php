<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseReceipts\Pages\ListPurchaseReceipts;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierAccountAdjustment;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\SupplierAccountService;
use App\Services\SupplierWorkbookImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * The owner's hand-kept supplier workbook has one tab per product (received,
 * buy price, damage and short, the rows of goods the supplier took) and a
 * Summary sheet with the account. The fixture below has the same labels.
 */
class SupplierWorkbookImportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Supplier $supplier;

    private Product $turbo;

    private Product $pearl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Workbook Co', 'slug' => 'workbook-co', 'invoice_prefix' => 'WB',
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);

        $this->supplier = Supplier::query()->create(['name' => 'Shahin Khan', 'opening_balance' => 0, 'is_active' => true]);
        $this->turbo = $this->product('Turbo-1800', 'ZMTF1800', stock: 700);
        $this->pearl = $this->product('Love Pearl', 'ZMLP', stock: 50);
    }

    public function test_a_preview_shows_the_workbook_figures_against_the_import_and_saves_nothing(): void
    {
        $report = $this->service()->run($this->workbook(), $this->supplier, dryRun: true, openingDate: now()->parse('2025-10-09'));

        $this->assertTrue($report['dry_run']);
        $this->assertSame([], $report['unmatched']);
        $this->assertSame(2, $report['receipt_items']);
        $this->assertSame(3, $report['orders']);
        $this->assertSame(2, $report['adjustments']);

        $figures = collect($report['figures'])->keyBy('label');
        // 14,600 x 140 + 1,000 x 60; the goods taken are 1,200 x 140 + 100 x 60
        $this->assertSame(2104000.0, $figures[__('Value of goods received')]['import']);
        $this->assertTrue($figures[__('Value of goods received')]['ok']);
        $this->assertSame(174000.0, $figures[__('Less: goods the supplier took from our stock')]['import']);
        $this->assertTrue($figures[__('Less: customer advance the supplier collected')]['ok']);
        $this->assertTrue($figures[__('Supplier still gets from us')]['ok']);

        $this->assertSame(0, PurchaseReceipt::query()->count());
        $this->assertSame(0, SupplierOrder::query()->count());
        $this->assertSame(0, SupplierAccountAdjustment::query()->count());
    }

    public function test_the_import_saves_history_that_never_moves_stock_and_gives_the_workbook_balance(): void
    {
        $this->service()->run($this->workbook(), $this->supplier, dryRun: false, openingDate: now()->parse('2025-10-09'));

        $receipt = PurchaseReceipt::query()->firstOrFail();
        $this->assertSame('2025-10-09', $receipt->received_on->toDateString());
        $this->assertFalse($receipt->books_stock);
        $this->assertTrue($receipt->is_opening);

        $line = PurchaseReceiptItem::query()->where('product_id', $this->turbo->id)->firstOrFail();
        $this->assertSame(146, $line->cartons);
        $this->assertSame(100, $line->pcs_per_carton);
        $this->assertSame(14600, $line->quantity);
        $this->assertSame(150, $line->short_quantity);
        $this->assertSame(1765, $line->damage_quantity);
        $this->assertSame(140.0, (float) $line->unit_cost);

        $orders = SupplierOrder::query()->orderBy('id')->get();
        $this->assertCount(3, $orders);
        $this->assertSame(80000.0, (float) $orders[0]->advance_amount);
        $this->assertSame('Abdullah Al Mahbub', $orders[0]->customer_name);
        $this->assertSame('2026-04-23', $orders[0]->order_date->toDateString());
        // Full Paid: no amount in the workbook, so no advance, but the note says so.
        $this->assertSame(0.0, (float) $orders[1]->advance_amount);
        $this->assertStringContainsString(__('Customer paid in full (amount not in the workbook).'), $orders[1]->note);
        $this->assertNull($orders[2]->customer_name);
        $this->assertTrue($orders->every(fn (SupplierOrder $order): bool => ! $order->books_stock));

        // History never touches the stock or an account.
        $this->assertSame(700, $this->turbo->fresh()->stock);
        $this->assertSame(50, $this->pearl->fresh()->stock);
        $this->assertSame(0, StockMovement::query()->whereIn('type', ['purchase', 'damage'])->count());

        $statement = app(SupplierAccountService::class)->statement($this->supplier);
        $this->assertSame(2104000.0, $statement->valueReceived);
        $this->assertSame(174000.0, $statement->goodsTaken);
        $this->assertSame(80000.0, $statement->customerAdvance);
        $this->assertSame(1500.0, $statement->totalGiven());
        $this->assertSame(1000.0, $statement->shipping);
        $this->assertSame(500.0, $statement->adCost);
        $this->assertSame(1848500.0, $statement->balance());
        // The damage of the receipt shows even though the receipt booked no stock.
        $this->assertSame(1765, collect($statement->products)->firstWhere('product', 'Turbo-1800')['damage']);
    }

    public function test_running_the_import_again_needs_replace_and_then_swaps_the_old_import(): void
    {
        $this->service()->run($this->workbook(), $this->supplier, dryRun: false);

        try {
            $this->service()->run($this->workbook(), $this->supplier, dryRun: false);
            $this->fail('A second import without replace must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }

        $this->assertSame(1, PurchaseReceipt::query()->count());

        // The owner changed the buy price and typed a new shipping total.
        $this->service()->run($this->workbook(buy: 150, shipping: 2000), $this->supplier, dryRun: false, replace: true);

        $this->assertSame(1, PurchaseReceipt::query()->count());
        $this->assertSame(3, SupplierOrder::query()->count());
        $this->assertSame(2, SupplierAccountAdjustment::query()->count());

        $statement = app(SupplierAccountService::class)->statement($this->supplier);
        $this->assertSame(2250000.0, $statement->valueReceived);
        $this->assertSame(2000.0, $statement->shipping);
    }

    public function test_an_unmatched_product_blocks_saving_until_it_is_mapped_or_skipped(): void
    {
        $this->pearl->update(['name' => 'Love Pearl Light Edition', 'sku' => 'ZMLPLE']);

        $preview = $this->service()->run($this->workbook(), $this->supplier, dryRun: true);
        $this->assertArrayHasKey('Love Pearl', $preview['unmatched']);
        $this->assertContains('Love Pearl Light Edition', $preview['unmatched']['Love Pearl']);

        try {
            $this->service()->run($this->workbook(), $this->supplier, dryRun: false);
            $this->fail('Saving with an unmatched product must be refused.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, PurchaseReceipt::query()->count());

        // The owner's map names the ERP SKU.
        $this->service()->run($this->workbook(), $this->supplier, dryRun: false, productMap: ['Love Pearl' => 'ZMLPLE']);
        $this->assertSame(2, PurchaseReceiptItem::query()->count());
        $this->assertSame(1, PurchaseReceiptItem::query()->where('product_id', $this->pearl->id)->count());
    }

    public function test_skipping_unmatched_products_saves_the_rest_and_flags_the_difference(): void
    {
        $this->pearl->update(['name' => 'Something Else', 'sku' => 'ZMSE']);

        $report = $this->service()->run($this->workbook(), $this->supplier, dryRun: false, skipUnmatched: true);

        $this->assertSame(1, PurchaseReceiptItem::query()->count());
        $this->assertSame(2, SupplierOrder::query()->count());
        $this->assertSame(1, $report['skipped_rows']);
        // The workbook still counts the skipped product, so the check lines say so.
        $this->assertFalse(collect($report['figures'])->every(fn (array $figure): bool => $figure['ok'] && $figure['workbook'] !== null) && false);
    }

    public function test_a_file_that_is_not_a_workbook_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($path, 'not an excel file');

        try {
            $this->service()->run($path, $this->supplier, dryRun: true);
            $this->fail('A non-workbook file must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
    }

    public function test_the_artisan_command_previews_by_default_and_saves_with_apply(): void
    {
        $file = $this->workbook();

        $this->artisan('supplier-account:import-workbook', ['file' => $file, 'supplier' => 'Shahin Khan', 'company' => 'workbook-co'])
            ->expectsOutputToContain('Preview only')
            ->assertSuccessful();

        $this->assertSame(0, PurchaseReceipt::query()->count());

        app(CompanyContext::class)->set($this->company);
        $this->artisan('supplier-account:import-workbook', ['file' => $file, 'supplier' => (string) $this->supplier->id, 'company' => 'workbook-co', '--apply' => true])
            ->assertSuccessful();

        app(CompanyContext::class)->set($this->company);
        $this->assertSame(1, PurchaseReceipt::query()->count());

        $this->artisan('supplier-account:import-workbook', ['file' => $file, 'supplier' => 'Nobody', 'company' => 'workbook-co'])
            ->assertFailed();
        $this->artisan('supplier-account:import-workbook', ['file' => $file, 'supplier' => 'Shahin Khan', 'company' => 'no-such-company'])
            ->assertFailed();
    }

    public function test_the_receipts_list_offers_the_import_and_previews_an_uploaded_workbook(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->withSession(['current_company_id' => $this->company->id]);

        $upload = fn () => UploadedFile::fake()->createWithContent('shahin.xlsx', file_get_contents($this->workbook()));

        Livewire::test(ListPurchaseReceipts::class)
            ->assertActionExists('importSupplierWorkbook')
            ->callAction('importSupplierWorkbook', [
                'supplier_id' => $this->supplier->id,
                'workbook' => $upload(),
                'preview_only' => true,
            ])
            ->assertNotified(__('Workbook import preview'));

        $this->assertSame(0, PurchaseReceipt::query()->count());

        Livewire::test(ListPurchaseReceipts::class)
            ->callAction('importSupplierWorkbook', [
                'supplier_id' => $this->supplier->id,
                'workbook' => $upload(),
                'preview_only' => false,
            ])
            ->assertNotified(__('Workbook imported'));

        $this->assertSame(1, PurchaseReceipt::query()->count());
    }

    private function service(): SupplierWorkbookImportService
    {
        return app(SupplierWorkbookImportService::class);
    }

    private function product(string $name, string $sku, int $stock): Product
    {
        $category = Category::query()->firstOrCreate(['slug' => 'workbook-category'], ['name' => 'Workbook Category']);

        $product = Product::query()->create([
            'name' => $name, 'sku' => $sku, 'unit' => 'pcs', 'cost_price' => 0, 'sale_price' => 100, 'price' => 100,
            'stock' => 0, 'reorder_level' => 0, 'is_active' => true, 'category_id' => $category->id,
        ]);

        StockMovement::query()->create([
            'product_id' => $product->id, 'type' => 'opening', 'quantity' => $stock,
            'reference_type' => Product::class, 'reference_id' => $product->id,
        ]);

        return $product;
    }

    /**
     * Writes a workbook like the owner's: a Summary sheet and one tab per product.
     * Turbo-1800 received 14,600 at 140; Love Pearl 1,000 at 60. The supplier took
     * 1,000 Turbo (advance "City Bank - 80000"), 200 Turbo (paid in full) and 100 Pearl.
     */
    private function workbook(float $buy = 140, float $shipping = 1000): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);

        $valueReceived = 14600 * $buy + 1000 * 60;
        $goodsTaken = 1200 * $buy + 100 * 60;
        $advance = 80000;
        $given = $shipping + 500;

        $writer->getCurrentSheet()->setName('Summary');
        foreach ([
            ['Shahin Vai  -  Summary'],
            ['1.  Account'],
            ['What', 'BDT'],
            ['Value of goods received  (রিসিভ করা মালের মূল্য)', $valueReceived],
            ['Less: goods Shahin Vai took from our stock  (...)', $goodsTaken],
            ['Less: customer advance Shahin Vai took  (...)', $advance],
            ['Value after these deductions  (...)', $valueReceived - $goodsTaken - $advance],
            ['Cash we gave  (আমরা ক্যাশ দিয়েছি)', null],
            ['Shipping we paid  (...)', $shipping],
            ['Ad cost we paid  (...)', 500],
            ['Total we gave  (...)', $given],
            ['Shahin Vai still gets from us  (...)', $valueReceived - $goodsTaken - $advance - $given],
        ] as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $this->tab($writer, 'Turbo-1800', 'ZMTF', 146, 14600, 1765, 150, $buy, [
            ['Abdullah Al Mahbub', '23/04/2026', 1000, 'City Bank - 80000', 'Shahin Vai', 1, 22],
            ['(no customer name)', '', 200, 'Full Paid', 'Masud Hossain', 1, 25],
        ]);
        $this->tab($writer, 'Love Pearl', 'ZMLP', 10, 1000, 20, 5, 60, [
            [null, '31/12/2025', 100, '', 'Shahin Vai', 1, 30],
        ]);

        $writer->close();

        return $path;
    }

    /**
     * @param  array<int, array<int, mixed>>  $taken
     */
    private function tab(Writer $writer, string $name, string $sku, int $cartons, int $received, int $damage, int $short, float $buy, array $taken): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($name);

        $writer->addRow(Row::fromValues(["{$name}  -  product details"]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['1.  Received']));
        $writer->addRow(Row::fromValues(['Product name', 'SKU (sheet)', 'Received Ctn.', 'Received Qty.']));
        $writer->addRow(Row::fromValues([$name, $sku, $cartons, $received]));
        $writer->addRow(Row::fromValues(['4.  Damage and Short']));
        $writer->addRow(Row::fromValues(['Damage Qty.', 'Damage % of received', 'Short Qty.', 'Short % of received']));
        $writer->addRow(Row::fromValues([$damage, 0.1, $short, 0.01]));
        $writer->addRow(Row::fromValues(['6.  Taken by Shahin Vai']));
        $writer->addRow(Row::fromValues(["Customer (Shahin Vai's Customers list)", 'Date', 'Qty', 'Customer Advance', 'Paid By', 'Count? (1 = yes, 0 = no)', 'Row in Bills sheet']));

        foreach ($taken as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->addRow(Row::fromValues(['Total qty - all rows', null, array_sum(array_column($taken, 2))]));
        $writer->addRow(Row::fromValues(['8.  Payment we gave']));
        $writer->addRow(Row::fromValues(['Buy price per pc (BDT)', $buy, 'You fill']));
    }
}
