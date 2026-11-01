<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\SupplierReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_works_out_landed_cost_loss_gross_and_net_profit(): void
    {
        $supplier = $this->supplier('Alpha Traders');
        $product = $this->product('Widget');

        // 100 billed at 10 + 100 import cost = 1,100 landed (11 a unit);
        // 5 never arrived and 5 arrived broken, so 90 are sellable.
        $purchase = $this->purchase($supplier, '2026-09-01', ['misc' => 100, 'product_costs' => [
            ['product_id' => $product->id, 'label' => 'Packaging cost', 'amount' => 200],
        ]]);
        $this->item($purchase, $product, 100, 10, short: 5, damaged: 5);
        $this->sell($product, 40, 25, '2026-09-10');

        $report = app(SupplierReportService::class)->build($supplier);
        $summary = $report['summary'];

        $this->assertSame(1, $summary['purchase_count']);
        $this->assertSame(100, $summary['ordered']);
        $this->assertSame(5, $summary['short']);
        $this->assertSame(5, $summary['damaged']);
        $this->assertSame(90, $summary['sellable']);
        $this->assertSame(40, $summary['sold']);
        $this->assertSame(50, $summary['in_stock']);
        $this->assertEqualsWithDelta(1000.0, $summary['purchase_value'], 0.01);
        $this->assertEqualsWithDelta(100.0, $summary['import_cost'], 0.01);
        $this->assertEqualsWithDelta(1100.0, $summary['landed_cost'], 0.01);
        $this->assertEqualsWithDelta(1000.0, $summary['revenue'], 0.01);
        $this->assertEqualsWithDelta(25.0, $summary['avg_price'], 0.01);
        $this->assertEqualsWithDelta(440.0, $summary['cogs'], 0.01);
        $this->assertEqualsWithDelta(110.0, $summary['loss'], 0.01);
        $this->assertEqualsWithDelta(450.0, $summary['gross_profit'], 0.01);
        // 200 of packaging, counted for the 40 of 90 sellable units sold.
        $this->assertEqualsWithDelta(88.89, $summary['extra_cost'], 0.01);
        $this->assertEqualsWithDelta(200.0, $summary['extra_cost_entered'], 0.01);
        $this->assertEqualsWithDelta(361.11, $summary['net_profit'], 0.01);
        $this->assertSame(['Packaging cost'], $report['cost_labels']);
    }

    public function test_short_and_damaged_units_do_not_reach_stock(): void
    {
        $supplier = $this->supplier('Beta Traders');
        $product = $this->product('Gadget');

        $purchase = $this->purchase($supplier, '2026-09-01');
        $this->item($purchase, $product, 100, 10, short: 5, damaged: 5);

        $this->assertSame(90, (int) $product->fresh()->stock);
    }

    public function test_short_plus_damaged_cannot_exceed_the_billed_quantity(): void
    {
        $supplier = $this->supplier('Gamma Traders');
        $product = $this->product('Gizmo');
        $purchase = $this->purchase($supplier, '2026-09-01');

        $this->expectException(ValidationException::class);

        $this->item($purchase, $product, 10, 10, short: 6, damaged: 5);
    }

    public function test_sales_are_handed_to_the_oldest_purchase_first_across_suppliers(): void
    {
        $first = $this->supplier('First Supplier');
        $second = $this->supplier('Second Supplier');
        $product = $this->product('Shared Item');

        $this->item($this->purchase($first, '2026-08-01'), $product, 10, 10);
        $this->item($this->purchase($second, '2026-08-15'), $product, 10, 12);
        $this->sell($product, 12, 30, '2026-09-01');

        $reports = app(SupplierReportService::class);

        $this->assertSame(10, $reports->build($first)['summary']['sold']);
        $this->assertEqualsWithDelta(300.0, $reports->build($first)['summary']['revenue'], 0.01);
        $this->assertSame(2, $reports->build($second)['summary']['sold']);
        $this->assertEqualsWithDelta(60.0, $reports->build($second)['summary']['revenue'], 0.01);
    }

    public function test_opening_stock_is_sold_before_purchases(): void
    {
        $supplier = $this->supplier('Late Supplier');
        $product = $this->product('Old Stock Item', openingStock: 5);

        $this->item($this->purchase($supplier, '2026-08-01'), $product, 10, 10);
        $this->sell($product, 6, 30, '2026-09-01');

        $summary = app(SupplierReportService::class)->build($supplier)['summary'];

        $this->assertSame(1, $summary['sold']);
        $this->assertSame(9, $summary['in_stock']);
    }

    public function test_date_range_and_product_filters_limit_the_report(): void
    {
        $supplier = $this->supplier('Delta Traders');
        $one = $this->product('Product One');
        $two = $this->product('Product Two');

        $early = $this->purchase($supplier, '2026-07-01');
        $this->item($early, $one, 10, 10);
        $late = $this->purchase($supplier, '2026-09-01');
        $this->item($late, $one, 20, 10);
        $this->item($late, $two, 30, 10);

        $reports = app(SupplierReportService::class);

        $this->assertSame(60, $reports->build($supplier)['summary']['ordered']);
        $this->assertSame(50, $reports->build($supplier, now()->setDate(2026, 8, 1))['summary']['ordered']);
        $this->assertSame(30, $reports->build($supplier, null, null, $one->id)['summary']['ordered']);
        $this->assertSame(1, $reports->build($supplier, now()->setDate(2026, 8, 1), null, $two->id)['summary']['product_count']);
        $this->assertSame([$one->id => 'Product One', $two->id => 'Product Two'], $reports->productOptions($supplier));
    }

    public function test_other_suppliers_and_unreceived_purchases_are_left_out(): void
    {
        $supplier = $this->supplier('Epsilon Traders');
        $other = $this->supplier('Other Traders');
        $product = $this->product('Plain Item');

        $this->item($this->purchase($supplier, '2026-09-01'), $product, 10, 10);
        $this->item($this->purchase($other, '2026-09-01'), $product, 99, 10);
        $this->item($this->purchase($supplier, '2026-09-02', ['status' => 'draft']), $product, 77, 10);

        $this->assertSame(10, app(SupplierReportService::class)->build($supplier)['summary']['ordered']);
    }

    public function test_report_page_and_pdf_download_work(): void
    {
        $user = User::factory()->create();
        $supplier = $this->supplier('Zeta Traders');
        $product = $this->product('Page Item');
        $this->item($this->purchase($supplier, '2026-09-01'), $product, 10, 10);
        $this->sell($product, 4, 20, '2026-09-05');

        $this->actingAs($user)
            ->get('/admin/reports/supplier-report?supplier='.$supplier->id)
            ->assertOk()
            ->assertSee('Zeta Traders')
            ->assertSee('Page Item')
            ->assertSee('Net profit')
            ->assertSee('Download PDF');

        $response = $this->actingAs($user)
            ->get(route('reports.supplier.pdf', ['supplier' => $supplier->id, 'product' => $product->id]));

        $response->assertOk();
        $response->assertDownload('supplier-report-'.$supplier->id.'-product-'.$product->id.'-'.now()->format('Ymd').'.pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $log = AuditLog::query()->where('action', 'report_exported')->latest('id')->firstOrFail();
        $this->assertSame('supplier', $log->new_values['type']);
        $this->assertSame($supplier->id, $log->new_values['supplier_id']);
    }

    public function test_every_product_gets_its_own_account_and_the_units_tie_out(): void
    {
        $supplier = $this->supplier('Account Traders');
        $widget = $this->product('Widget');
        $gadget = $this->product('Gadget');

        $purchase = $this->purchase($supplier, '2026-09-01');
        $this->item($purchase, $widget, 100, 10, short: 5, damaged: 5);
        $this->item($purchase, $gadget, 50, 20, short: 2);
        $this->sell($widget, 40, 25, '2026-09-10');
        $this->sell($gadget, 10, 50, '2026-09-11');

        $pages = collect(app(SupplierReportService::class)->build($supplier)['product_pages'])->keyBy('product');

        $this->assertSame(['Gadget', 'Widget'], $pages->keys()->sort()->values()->all());

        $units = $pages['Widget']['units'];
        $this->assertSame(100, $units['billed']);
        $this->assertSame(5, $units['short']);
        $this->assertSame(5, $units['damaged']);
        $this->assertSame(90, $units['received']);
        $this->assertSame(40, $units['sold']);
        $this->assertSame(50, $units['in_stock']);
        $this->assertSame(0, $units['difference']);

        $units = $pages['Gadget']['units'];
        $this->assertSame(50, $units['billed']);
        $this->assertSame(2, $units['short']);
        $this->assertSame(10, $units['sold']);
        $this->assertSame(38, $units['in_stock']);
        $this->assertSame(0, $units['difference']);

        // The products' figures are not mixed: each page only has its own purchase lines.
        $this->assertCount(1, $pages['Widget']['batches']);
        $this->assertEqualsWithDelta(1000.0, $pages['Widget']['totals']['revenue'], 0.01);
        $this->assertEqualsWithDelta(500.0, $pages['Gadget']['totals']['revenue'], 0.01);
    }

    public function test_sold_pieces_are_listed_by_the_price_they_sold_at(): void
    {
        $supplier = $this->supplier('Price Traders');
        $product = $this->product('Priced Item');

        $this->item($this->purchase($supplier, '2026-09-01'), $product, 100, 10);
        $this->sell($product, 30, 25, '2026-09-10');
        $this->sell($product, 10, 22.5, '2026-09-11');
        $this->sell($product, 5, 25, '2026-09-12');

        $page = app(SupplierReportService::class)->build($supplier)['product_pages'][0];

        $this->assertCount(2, $page['prices']);
        $this->assertEqualsWithDelta(25.0, $page['prices'][0]['price'], 0.001);
        $this->assertSame(35, $page['prices'][0]['pieces']);
        $this->assertEqualsWithDelta(875.0, $page['prices'][0]['amount'], 0.01);
        $this->assertEqualsWithDelta(22.5, $page['prices'][1]['price'], 0.001);
        $this->assertSame(10, $page['prices'][1]['pieces']);
        $this->assertEqualsWithDelta(225.0, $page['prices'][1]['amount'], 0.01);
        $this->assertSame(45, $page['totals']['sold']);
        $this->assertEqualsWithDelta(1100.0, $page['totals']['revenue'], 0.01);
        $this->assertEqualsWithDelta(1100.0 / 45, $page['totals']['avg_price'], 0.001);
    }

    public function test_damage_after_arrival_and_stock_adjustments_take_pieces_from_the_oldest_lot(): void
    {
        $supplier = $this->supplier('Damage Traders');
        $product = $this->product('Fragile Item');

        $this->item($this->purchase($supplier, '2026-09-01'), $product, 100, 10);
        $this->sell($product, 40, 25, '2026-09-10');
        $this->stockMovement($product, 'damage', 3, ['damage_source' => StockMovement::DAMAGE_SOURCE_SHIPMENT]);
        $this->stockMovement($product, 'damage', 2, ['damage_source' => StockMovement::DAMAGE_SOURCE_CUSTOMER_RETURN]);
        $this->stockMovement($product, 'adjustment', -5);

        $report = app(SupplierReportService::class)->build($supplier);
        $page = $report['product_pages'][0];

        $this->assertSame(5, $page['units']['damaged_after']);
        $this->assertSame(
            [StockMovement::DAMAGE_SOURCE_SHIPMENT => 3, StockMovement::DAMAGE_SOURCE_CUSTOMER_RETURN => 2],
            $page['units']['damage_sources'],
        );
        $this->assertSame(5, $page['units']['adjusted_out']);
        $this->assertSame(50, $page['units']['in_stock']);
        $this->assertSame(0, $page['units']['difference']);
        // 5 damaged pieces at 10 landed each.
        $this->assertEqualsWithDelta(50.0, $report['summary']['loss_after'], 0.01);
        $this->assertEqualsWithDelta(550.0, $report['summary']['gross_profit'], 0.01);
        $this->assertSame(0, $page['stock_check']['difference']);
        $this->assertSame(50, $page['stock_check']['system']);
    }

    public function test_positive_stock_adjustments_are_a_lot_of_their_own(): void
    {
        $supplier = $this->supplier('Count Traders');
        $product = $this->product('Counted Item');

        $this->item($this->purchase($supplier, '2026-09-01'), $product, 10, 10);
        $this->stockMovement($product, 'adjustment', 4);
        $this->sell($product, 12, 30, '2026-09-10');

        $page = app(SupplierReportService::class)->build($supplier)['product_pages'][0];

        // The supplier's 10 go first; the 2 left over come from the counted-in lot.
        $this->assertSame(10, $page['units']['sold']);
        $this->assertSame(0, $page['units']['in_stock']);
        $this->assertSame(2, $page['sales_split']['other_lots']);
        $this->assertSame(2, $page['stock_check']['other_lots']);
        $this->assertSame(0, $page['stock_check']['difference']);
    }

    public function test_sales_with_no_recorded_stock_are_listed_apart_and_the_stock_difference_shows(): void
    {
        $supplier = $this->supplier('Gap Traders');
        $product = $this->product('Gap Item');

        $this->item($this->purchase($supplier, '2026-09-01'), $product, 10, 10);
        // Imported history never touches stock, so it can sell more than was ever recorded.
        $this->sell($product, 15, 30, '2026-09-10', ['source' => Order::SOURCE_NUPORT_IMPORT]);

        $page = app(SupplierReportService::class)->build($supplier)['product_pages'][0];

        $this->assertSame(10, $page['units']['sold']);
        $this->assertSame(0, $page['units']['in_stock']);
        $this->assertSame(0, $page['units']['difference']);
        $this->assertSame(15, $page['sales_split']['all']);
        $this->assertSame(10, $page['sales_split']['this_supplier']);
        $this->assertSame(0, $page['sales_split']['other_lots']);
        $this->assertSame(5, $page['sales_split']['unmatched']);
        // The system still holds the 10 pieces the imported sale never took out.
        $this->assertSame(10, $page['stock_check']['system']);
        $this->assertSame(10, $page['stock_check']['difference']);
    }

    public function test_pdf_has_a_cover_an_overview_and_one_page_for_each_product(): void
    {
        $user = User::factory()->create();
        $supplier = $this->supplier('Pages Traders');
        $purchase = $this->purchase($supplier, '2026-09-01');

        foreach (['Page One', 'Page Two', 'Page Three'] as $name) {
            $product = $this->product($name);
            $this->item($purchase, $product, 10, 10);
            $this->sell($product, 4, 20, '2026-09-05');
        }

        $response = $this->actingAs($user)->get(route('reports.supplier.pdf', ['supplier' => $supplier->id]));

        $response->assertOk();
        // Cover, products overview, then one page for each of the 3 products.
        $this->assertSame(5, preg_match_all('#/Type\s*/Page\b(?!s)#', $response->getContent()));
    }

    public function test_report_page_shows_an_account_for_every_product(): void
    {
        $user = User::factory()->create();
        $supplier = $this->supplier('Screen Traders');
        $purchase = $this->purchase($supplier, '2026-09-01');
        $one = $this->product('Screen One');
        $two = $this->product('Screen Two');
        $this->item($purchase, $one, 10, 10);
        $this->item($purchase, $two, 10, 10);
        $this->sell($one, 4, 20, '2026-09-05');

        $this->actingAs($user)
            ->get('/admin/reports/supplier-report?supplier='.$supplier->id)
            ->assertOk()
            ->assertSee('Units account')
            ->assertSee('Sold at these prices')
            ->assertSee('Stock today')
            ->assertSee('id="product-'.$one->id.'"', false)
            ->assertSee('id="product-'.$two->id.'"', false);
    }

    public function test_purchase_form_renders_with_loss_and_cost_fields(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/purchasing/purchases/create')
            ->assertOk()
            ->assertSee('Short Qty')
            ->assertSee('Costs After Arrival');
    }

    private function supplier(string $name): Supplier
    {
        return Supplier::query()->create(['name' => $name, 'opening_balance' => 0, 'is_active' => true]);
    }

    private function product(string $name, int $openingStock = 0): Product
    {
        $category = Category::query()->firstOrCreate(['slug' => 'supplier-report'], ['name' => 'Supplier Report']);
        $product = Product::query()->create([
            'name' => $name,
            'sku' => 'SR-'.strtoupper(substr(md5($name), 0, 8)),
            'unit' => 'pcs',
            'cost_price' => 10,
            'sale_price' => 30,
            'price' => 30,
            'stock' => 0,
            'reorder_level' => 0,
            'is_active' => true,
            'category_id' => $category->id,
        ]);

        if ($openingStock > 0) {
            $product->setStockFromProductForm($openingStock);
        }

        return $product;
    }

    private function purchase(Supplier $supplier, string $date, array $attributes = []): Purchase
    {
        return Purchase::query()->create([
            'supplier_id' => $supplier->id,
            'purchase_date' => $date,
            'status' => 'received',
            ...$attributes,
        ]);
    }

    private function item(Purchase $purchase, Product $product, int $quantity, float $unitCost, int $short = 0, int $damaged = 0): PurchaseItem
    {
        return PurchaseItem::query()->create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'short_quantity' => $short,
            'damaged_quantity' => $damaged,
        ]);
    }

    private function stockMovement(Product $product, string $type, int $quantity, array $attributes = []): StockMovement
    {
        return StockMovement::query()->create([
            'product_id' => $product->id,
            'type' => $type,
            'quantity' => $quantity,
            'reason' => 'Supplier report test',
            ...$attributes,
        ]);
    }

    private function sell(Product $product, int $quantity, float $price, string $date, array $attributes = []): void
    {
        $customer = Customer::query()->firstOrCreate(['name' => 'Supplier Report Customer'], ['opening_balance' => 0, 'is_active' => true]);
        $order = Order::query()->create([
            'order_number' => 'INV-SR-'.uniqid(),
            'customer_id' => $customer->id,
            'order_date' => $date,
            'status' => 'completed',
            ...$attributes,
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $price,
        ]);
    }
}
