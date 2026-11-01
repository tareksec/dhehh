<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\SupplierAccountService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class SupplierAccountStatementTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Product $pearl;

    private Product $turbo;

    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::query()->create(['name' => 'Shahin Khan', 'phone' => '01700000001', 'opening_balance' => 0, 'is_active' => true]);
        $this->cash = Account::query()->create(['name' => 'Cash', 'type' => 'cash', 'opening_balance' => 1000000, 'is_active' => true]);
        $this->pearl = $this->product('Love Pearl');
        $this->turbo = $this->product('Turbo-1800');
    }

    public function test_the_balance_chain_matches_the_suppliers_rule(): void
    {
        $this->seedAccount();

        $statement = app(SupplierAccountService::class)->statement($this->supplier);

        // 1000 x 80 + 500 x 300
        $this->assertSame(230000.0, $statement->valueReceived);
        // 100 x 80 + (50 + 5) x 300: the order where ZamZam holds the advance still takes goods; the returned order does not
        $this->assertSame(24500.0, $statement->goodsTaken);
        // only what the supplier holds: 5,000 + 10,000 (the ZamZam-held 7,000 does not count)
        $this->assertSame(15000.0, $statement->customerAdvance);
        $this->assertSame(190500.0, $statement->valueAfterDeductions());
        $this->assertSame(50000.0, $statement->cash);
        $this->assertSame(20000.0, $statement->shipping);
        $this->assertSame(5000.0, $statement->adCost);
        $this->assertSame(75000.0, $statement->totalGiven());
        $this->assertSame(115500.0, $statement->balance());
        $this->assertTrue($statement->isFinal());
    }

    public function test_the_product_table_ties_out_and_flags_a_difference(): void
    {
        $this->seedAccount();

        $statement = app(SupplierAccountService::class)->statement($this->supplier);
        $rows = collect($statement->products)->keyBy('product');

        $pearl = $rows['Love Pearl'];
        $this->assertSame(1000, $pearl['received']);
        $this->assertSame(10, $pearl['short']);
        $this->assertSame(20, $pearl['damage']);
        $this->assertSame(300, $pearl['sold']);
        $this->assertSame(36000.0, $pearl['sales_amount']);
        $this->assertSame(120.0, $pearl['avg_rate']);
        $this->assertSame(40, $pearl['outside']);
        $this->assertSame(100, $pearl['taken']);
        $this->assertSame(530, $pearl['stock']);
        $this->assertSame(0, $pearl['difference']);
        $this->assertSame(80000.0, $pearl['value_received']);
        $this->assertSame(8000.0, $pearl['goods_taken']);
        $this->assertSame(5000.0, $pearl['advance']);

        $turbo = $rows['Turbo-1800'];
        $this->assertSame(500, $turbo['received']);
        $this->assertSame(55, $turbo['taken']);
        $this->assertSame(445, $turbo['stock']);
        $this->assertSame(10000.0, $turbo['advance']);
        $this->assertSame(0, $turbo['difference']);

        $this->assertSame(1500, $statement->productTotals['received']);
        $this->assertSame(0, $statement->productsWithDifference);

        // Stock that left outside the books shows up as a difference, not silently.
        StockMovement::query()->create([
            'product_id' => $this->turbo->id,
            'type' => 'adjustment',
            'quantity' => -7,
            'reason' => 'count',
            'reference_type' => Product::class,
            'reference_id' => $this->turbo->id,
        ]);

        $again = app(SupplierAccountService::class)->statement($this->supplier);
        $this->assertSame(7, collect($again->products)->keyBy('product')['Turbo-1800']['difference']);
        $this->assertSame(1, $again->productsWithDifference);
    }

    public function test_everything_after_the_as_of_date_is_left_out(): void
    {
        $this->seedAccount();

        $early = app(SupplierAccountService::class)->statement($this->supplier, Carbon::parse('2026-02-10'));

        // Only the first receipt (2026-02-05, 1000 pearls) is in; no orders, payments or costs yet.
        $this->assertSame(80000.0, $early->valueReceived);
        $this->assertSame(0.0, $early->goodsTaken);
        $this->assertSame(0.0, $early->customerAdvance);
        $this->assertSame(0.0, $early->totalGiven());
        $this->assertSame(80000.0, $early->balance());
    }

    public function test_a_received_product_without_a_buy_price_marks_the_account_not_final(): void
    {
        $free = $this->product('Free Gift');
        $receipt = PurchaseReceipt::query()->create(['supplier_id' => $this->supplier->id, 'received_on' => '2026-02-05']);
        PurchaseReceiptItem::query()->create([
            'purchase_receipt_id' => $receipt->id,
            'product_id' => $free->id,
            'quantity' => 10,
            'unit_cost' => 0,
        ]);

        $statement = app(SupplierAccountService::class)->statement($this->supplier, Carbon::parse('2026-03-31'));

        $this->assertFalse($statement->isFinal());
        $this->assertSame(1, $statement->productsWithoutBuyPrice);
        $this->assertStringContainsString('NOT FINAL', collect($statement->lines())->last()['note']);
    }

    public function test_an_advance_is_spread_over_the_lines_of_its_order_by_their_value(): void
    {
        $order = SupplierOrder::query()->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => '2026-03-01',
            'advance_amount' => 1000,
            'advance_collected_by' => SupplierOrder::COLLECTED_BY_SUPPLIER,
        ]);
        $this->receive($this->pearl, 100, 80);
        $this->receive($this->turbo, 100, 300);
        SupplierOrderItem::query()->create(['supplier_order_id' => $order->id, 'product_id' => $this->pearl->id, 'quantity' => 10, 'unit_price' => 100]);
        SupplierOrderItem::query()->create(['supplier_order_id' => $order->id, 'product_id' => $this->turbo->id, 'quantity' => 10, 'unit_price' => 300]);

        $statement = app(SupplierAccountService::class)->statement($this->supplier, Carbon::parse('2026-03-31'));
        $rows = collect($statement->products)->keyBy('product');

        // 1,000 x 1,000/4,000 and 1,000 x 3,000/4,000
        $this->assertSame(250.0, $rows['Love Pearl']['advance']);
        $this->assertSame(750.0, $rows['Turbo-1800']['advance']);
        $this->assertSame(1000.0, $statement->customerAdvance);
    }

    public function test_the_report_page_shows_the_account_and_asks_for_a_supplier(): void
    {
        $user = User::factory()->create();
        $this->seedAccount();

        $this->actingAs($user)
            ->get('/admin/reports/reports?report_type=supplier-account')
            ->assertOk()
            ->assertSee('Choose a supplier to see the account.');

        $this->actingAs($user)
            ->get("/admin/reports/reports?report_type=supplier-account&supplier_id={$this->supplier->id}&date_to=".now()->toDateString())
            ->assertOk()
            ->assertSee('Supplier still gets from us')
            ->assertSee('৳ 115,500')
            ->assertSee('Love Pearl')
            ->assertSee('Turbo-1800')
            ->assertDontSee('Choose a supplier to see the account.');
    }

    public function test_the_account_exports_with_its_block_on_top(): void
    {
        $user = User::factory()->create();
        $this->seedAccount();
        $query = "supplier_id={$this->supplier->id}&date_to=".now()->toDateString();

        $this->actingAs($user)->get('/admin/reports/export/supplier-account')->assertStatus(422);
        $this->actingAs($user)->get('/admin/reports/export/supplier-account/xlsx')->assertStatus(422);
        $this->actingAs($user)->get('/admin/reports/print/supplier-account')->assertStatus(422);

        $csv = $this->actingAs($user)->get("/admin/reports/export/supplier-account?{$query}")->streamedContent();
        $this->assertStringContainsString('"Supplier still gets from us",115500', $csv);
        $this->assertStringContainsString('Love Pearl', $csv);
        $this->assertLessThan(strpos($csv, 'Love Pearl'), strpos($csv, 'Supplier still gets from us'));

        $xlsx = $this->actingAs($user)->get("/admin/reports/export/supplier-account/xlsx?{$query}")->streamedContent();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $xlsx);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $content = (string) $zip->getFromName('xl/worksheets/sheet1.xml').(string) $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        unlink($path);
        $this->assertStringContainsString('Supplier still gets from us', $content);
        $this->assertStringContainsString('115500', $content);

        $this->actingAs($user)
            ->get("/admin/reports/print/supplier-account?{$query}")
            ->assertOk()
            ->assertSee('Supplier still gets from us')
            ->assertSee('৳ 115,500')
            ->assertSee('As of '.now()->format('d M Y'));
    }

    public function test_returned_pieces_and_line_discount_come_off_what_was_sold(): void
    {
        $this->receive($this->pearl, 500, 80);
        $customer = Customer::query()->create(['name' => 'Wholesale', 'phone' => '01800000001', 'opening_balance' => 0, 'is_active' => true]);
        $order = Order::query()->create(['customer_id' => $customer->id, 'order_date' => '2026-03-05', 'status' => Order::STATUS_COMPLETED]);
        $item = OrderItem::query()->create(['order_id' => $order->id, 'product_id' => $this->pearl->id, 'quantity' => 100, 'unit_price' => 120]);

        // 30 of the 100 came back, and the line carried a 1,000 discount: 70 pieces sold for (12,000 - 1,000) x 70 / 100.
        $item->forceFill(['returned_quantity' => 30, 'discount_amount' => 1000])->saveQuietly();

        $row = collect(app(SupplierAccountService::class)->statement($this->supplier)->products)->firstWhere('product', 'Love Pearl');

        $this->assertSame(70, $row['sold']);
        $this->assertSame(7700.0, $row['sales_amount']);
        $this->assertSame(110.0, $row['avg_rate']);

        $detail = app(SupplierAccountService::class)->productDetail($this->supplier, $this->pearl);
        $this->assertSame([['price' => 110.0, 'quantity' => 70, 'amount' => 7700.0]], $detail['sales_by_price']);
    }

    public function test_the_product_detail_page_shows_every_part_of_the_product(): void
    {
        $user = User::factory()->create();
        $this->seedAccount();
        $asOf = now()->toDateString();

        // The statement links each product name to its page.
        $this->actingAs($user)
            ->get("/admin/reports/reports?report_type=supplier-account&supplier_id={$this->supplier->id}&date_to={$asOf}")
            ->assertOk()
            ->assertSee("supplier-product-account?supplier={$this->supplier->id}&amp;product={$this->pearl->id}", false);

        $this->actingAs($user)
            ->get("/admin/reports/supplier-product-account?supplier={$this->supplier->id}&product={$this->pearl->id}&as_of={$asOf}")
            ->assertOk()
            ->assertSee('Love Pearl')
            ->assertSee('1. Goods received')
            ->assertSee('GRN-')
            ->assertSee('2. Sales by price')
            ->assertSee('৳ 120')
            ->assertSee('6. Taken by the supplier')
            ->assertSee('Our Customer')
            ->assertSee('7. Advance the supplier collected')
            ->assertSee('৳ 5,000')
            ->assertSee('Back to supplier account');

        $this->actingAs($user)
            ->get("/admin/reports/supplier-product-account?supplier={$this->supplier->id}&product=999999&as_of={$asOf}")
            ->assertNotFound();
    }

    public function test_the_supplier_page_shows_the_account_and_the_shortcuts(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->seedAccount();
        $plain = Supplier::query()->create(['name' => 'Plain Supplier', 'opening_balance' => 0, 'is_active' => true]);

        $this->actingAs($user)
            ->get("/admin/purchasing/suppliers/{$this->supplier->id}")
            ->assertOk()
            ->assertSee('Supplier still gets from us')
            ->assertSee('৳ 115,500')
            ->assertSee('New goods receipt')
            ->assertSee('New supplier order')
            ->assertSee('report_type=supplier-account', false);

        // A supplier whose goods never came through receipts has no account block.
        $this->actingAs($user)
            ->get("/admin/purchasing/suppliers/{$plain->id}")
            ->assertOk()
            ->assertDontSee('Supplier still gets from us');

        $this->actingAs($user)
            ->get("/admin/purchasing/purchase-receipts/create?supplier={$this->supplier->id}")
            ->assertOk();
    }

    public function test_the_all_suppliers_report_lists_the_account_next_to_the_invoice_payable(): void
    {
        $user = User::factory()->create();
        $this->seedAccount();
        Supplier::query()->create(['name' => 'No Goods Supplier', 'opening_balance' => 0, 'is_active' => true]);

        $this->actingAs($user)
            ->get('/admin/reports/reports?report_type=supplier-accounts&date_to='.now()->toDateString())
            ->assertOk()
            ->assertSee('Shahin Khan')
            ->assertSee('৳ 115,500')
            ->assertDontSee('No Goods Supplier');
    }

    /**
     * Two products received over two dates, goods taken by the supplier, the
     * advance he collected, and the cash, shipping and ad cost we gave.
     */
    private function seedAccount(): void
    {
        $this->receive($this->pearl, 1000, 80, short: 10, damage: 20, date: '2026-02-05');
        $this->receive($this->turbo, 500, 300, date: '2026-03-01');

        // Our own sales of the pearls: 300 delivered, 40 still in the courier pipeline.
        $this->ownOrder(Order::STATUS_COMPLETED, $this->pearl, 300, 120);
        $this->ownOrder(Order::STATUS_PROCESSING, $this->pearl, 40, 120);

        // Goods the supplier gave his own customers.
        $this->supplierOrder($this->pearl, 100, 150, advance: 5000, by: SupplierOrder::COLLECTED_BY_SUPPLIER, date: '2026-03-10');
        $this->supplierOrder($this->turbo, 50, 400, advance: 10000, by: SupplierOrder::COLLECTED_BY_SUPPLIER, date: '2026-03-12');
        $this->supplierOrder($this->turbo, 5, 400, advance: 7000, by: SupplierOrder::COLLECTED_BY_COMPANY, date: '2026-03-13');
        $order = $this->supplierOrder($this->turbo, 20, 400, advance: 3000, by: SupplierOrder::COLLECTED_BY_SUPPLIER, date: '2026-03-14');
        $order->update(['status' => SupplierOrder::STATUS_RETURNED]);

        // What we gave: cash into the account, shipping and ad cost; plus one ordinary expense that is not his.
        SupplierPayment::query()->create([
            'supplier_id' => $this->supplier->id, 'account_id' => $this->cash->id, 'amount' => 50000,
            'payment_date' => '2026-03-15', 'method' => 'cash', 'for_supplier_account' => true,
        ]);
        $this->expense(20000, Expense::SUPPLIER_COST_SHIPPING, '2026-03-16');
        $this->expense(5000, Expense::SUPPLIER_COST_AD, '2026-03-17');
        $this->expense(999, null, '2026-03-18');
    }

    private function receive(Product $product, int $quantity, float $price, int $short = 0, int $damage = 0, string $date = '2026-02-05'): void
    {
        $receipt = PurchaseReceipt::query()->create(['supplier_id' => $this->supplier->id, 'received_on' => $date]);

        PurchaseReceiptItem::query()->create([
            'purchase_receipt_id' => $receipt->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'short_quantity' => $short,
            'damage_quantity' => $damage,
            'unit_cost' => $price,
        ]);
    }

    private function ownOrder(string $status, Product $product, int $quantity, float $price): void
    {
        $customer = Customer::query()->firstOrCreate(['name' => 'Report Customer'], ['phone' => '01800000000', 'opening_balance' => 0, 'is_active' => true]);
        $order = Order::query()->create([
            'customer_id' => $customer->id,
            'order_date' => '2026-03-05',
            'status' => $status,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $price,
        ]);
    }

    private function supplierOrder(Product $product, int $quantity, float $price, float $advance, string $by, string $date): SupplierOrder
    {
        $order = SupplierOrder::query()->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => $date,
            'customer_name' => 'Our Customer',
            'advance_amount' => $advance,
            'advance_collected_by' => $by,
        ]);

        SupplierOrderItem::query()->create([
            'supplier_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $price,
        ]);

        return $order;
    }

    private function expense(float $amount, ?string $type, string $date): void
    {
        $category = ExpenseCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);

        Expense::query()->create([
            'expense_category_id' => $category->id,
            'supplier_id' => $type ? $this->supplier->id : null,
            'supplier_cost_type' => $type,
            'account_id' => $this->cash->id,
            'amount' => $amount,
            'expense_date' => $date,
        ]);
    }

    private function product(string $name): Product
    {
        $category = Category::query()->firstOrCreate(['slug' => 'statement-category'], ['name' => 'Statement Category']);

        return Product::query()->create([
            'name' => $name,
            'sku' => 'SKU-'.strtoupper(str_replace(' ', '-', $name)),
            'unit' => 'pcs',
            'cost_price' => 0,
            'sale_price' => 100,
            'price' => 100,
            'stock' => 0,
            'reorder_level' => 0,
            'is_active' => true,
            'category_id' => $category->id,
            'supplier_id' => $this->supplier->id,
        ]);
    }
}
