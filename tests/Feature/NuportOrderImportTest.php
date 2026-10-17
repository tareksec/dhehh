<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\AppSetting;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\Customer;
use App\Models\FraudCheck;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\TransactionLedger;
use App\Models\User;
use App\Observers\OrderNotificationObserver;
use App\Services\BusinessNotificationService;
use App\Services\CompanyContext;
use App\Services\NuportOrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Owner request 2026-10-17: bring the wholesale customers' past orders (what
 * they bought, payment, status) from Nuport's "Export As CSV" into the ERP.
 * The CSV layout below is the real export's header row.
 */
class NuportOrderImportTest extends TestCase
{
    use RefreshDatabase;

    protected const HEADERS = [
        'Creation Date', 'Shipping Date', 'Shipped At', 'Delivered At', 'Invoice', 'Pick Up Location', 'Customer Name',
        'Customer Phone Number', 'Map Location', 'Customer Address', 'Customer Tag', 'Product SKU', 'Product Name',
        'Product Specifications', 'Product Attributes', 'Product Qty', 'Unit Price', 'Total Price', 'Product Discount',
        'Price After Discount', 'Order Discount', 'Sales Amount', 'Delivery Fee', 'Payments/Paid Amount', 'Due Amount',
        'Status', 'Sub-Status', 'Cancelled/Flagged Reason', 'Cancelled/Returned/Damaged Qty', 'Delivery Partner Type',
        'Delivery Partner', 'Delivery Partner Status', 'Delivery ID', 'Payment Method', 'Order Source', 'Website Order ID',
        'Source Info', 'Website Order Creation Date', 'IP Address', 'Origin', 'Campaign ID', 'Entry By', 'Additional Note',
        'Internal Notes',
    ];

    protected Company $company;

    protected Product $fan;

    protected ProductVariant $blackFan;

    protected Product $keyChain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Nuport Import Co', 'slug' => 'nuport-import-co',
            'invoice_prefix' => 'NIC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);

        $this->fan = Product::query()->create([
            'name' => 'X05 Fan', 'sku' => 'ZMTFX05', 'price' => 500, 'sale_price' => 500, 'cost_price' => 300,
            'stock' => 0, 'unit' => 'pcs', 'is_active' => true, 'status' => 'available', 'has_variants' => true,
        ]);
        $this->blackFan = ProductVariant::query()->create([
            'product_id' => $this->fan->id, 'sku' => 'ZMTFX05B', 'options' => ['Color' => 'Black'],
            'sale_price' => 500, 'stock' => 40, 'is_active' => true,
        ]);
        $this->keyChain = Product::query()->create([
            'name' => 'Key Chain', 'sku' => 'ZMKMQ', 'price' => 20, 'sale_price' => 20, 'cost_price' => 8,
            'stock' => 100, 'unit' => 'pcs', 'is_active' => true, 'status' => 'available',
        ]);
    }

    public function test_a_delivered_paid_order_is_imported_with_lines_discount_fee_and_payment(): void
    {
        $report = $this->import($this->order('SO-1001', [
            'Sales Amount' => '1180.00', 'Delivery Fee' => '110.00', 'Payments/Paid Amount' => '1290.00', 'Due Amount' => '0.00',
            'Additional Note' => 'Call before delivery',
        ], [
            ['sku' => 'ZMTFX05B', 'name' => 'X05 Fan - Black', 'qty' => 2, 'unit' => 500],
            ['sku' => 'ZMKMQ', 'name' => 'Key Chain', 'qty' => 10, 'unit' => 20, 'discount' => 20],
        ]), dryRun: false);

        $this->assertSame(1, $report['created']);
        $this->assertSame([], $report['errors']);

        $order = Order::query()->where('external_reference', 'SO-1001')->firstOrFail();
        $this->assertSame('SO-1001', $order->order_number);
        $this->assertSame(Order::SOURCE_NUPORT_IMPORT, $order->source);
        $this->assertSame(Order::STATUS_COMPLETED, $order->status);
        $this->assertSame(CourierBooking::STATUS_DELIVERED, $order->delivery_status);
        $this->assertSame('2025-08-21', $order->order_date->toDateString());
        $this->assertSame('2025-08-21 18:19', $order->created_at->copy()->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'));

        // Lines at list price; the 20 product discount lands in the order discount.
        $this->assertEqualsWithDelta(1200.0, (float) $order->subtotal, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $order->discount, 0.001);
        $this->assertEqualsWithDelta(110.0, (float) $order->shipping_fee, 0.001);
        $this->assertEqualsWithDelta(1290.0, (float) $order->total_amount, 0.001);
        $this->assertEqualsWithDelta(1290.0, (float) $order->paid_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $order->due_amount, 0.001);
        $this->assertStringContainsString('Call before delivery', $order->note);
        $this->assertStringContainsString('Steadfast consignment: 123', $order->note);

        $items = $order->items()->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertSame($this->fan->id, $items[0]->product_id);
        $this->assertSame($this->blackFan->id, $items[0]->product_variant_id);
        $this->assertSame('Color: Black', $items[0]->variant_label);
        $this->assertSame(2, $items[0]->quantity);
        $this->assertEqualsWithDelta(300.0, (float) $items[0]->unit_cost, 0.001);
        $this->assertNull($items[1]->product_variant_id);

        $payment = $order->payments()->sole();
        $this->assertEqualsWithDelta(1290.0, (float) $payment->amount, 0.001);
        $this->assertSame('cash', $payment->method);
        $this->assertSame(OrderPayment::TYPE_FINAL, $payment->type);
        $this->assertNull($payment->account_id);
        $this->assertSame('2025-08-21', $payment->paid_at->toDateString());

        $customer = $order->customer;
        $this->assertSame('01712345678', $customer->phone);
        $this->assertSame('Rahim', $customer->name);
        $this->assertEqualsWithDelta(0.0, (float) $customer->current_balance, 0.001);
    }

    public function test_importing_history_never_touches_stock_account_ledgers_or_risk_checks(): void
    {
        $this->import($this->order('SO-1001', [
            'Sales Amount' => '1000.00', 'Delivery Fee' => '110.00', 'Payments/Paid Amount' => '1110.00',
        ], [['sku' => 'ZMTFX05B', 'name' => 'X05 Fan - Black', 'qty' => 2, 'unit' => 500]]), dryRun: false);

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, TransactionLedger::query()->count());
        $this->assertSame(0, FraudCheck::query()->count());
        $this->assertSame(40, $this->blackFan->fresh()->stock);
        $this->assertSame(100, $this->keyChain->fresh()->stock);

        // Editing the imported order later must not replay the sale either.
        $order = Order::query()->where('external_reference', 'SO-1001')->firstOrFail();
        $order->update(['status' => Order::STATUS_PROCESSING]);
        $order->update(['status' => Order::STATUS_COMPLETED]);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(40, $this->blackFan->fresh()->stock);
    }

    public function test_an_import_does_not_alert_the_owner_for_every_order(): void
    {
        $service = Mockery::mock(BusinessNotificationService::class);
        $service->shouldNotReceive('notifyCompany');

        $order = new Order(['order_number' => 'SO-1', 'source' => Order::SOURCE_NUPORT_IMPORT]);
        $order->setRelation('company', $this->company);

        $observer = new OrderNotificationObserver($service);
        $observer->created($order);
        $this->assertTrue(true);
    }

    public function test_a_delivered_order_with_payment_due_adds_to_the_customer_balance(): void
    {
        $this->import($this->order('SO-1002', [
            'Customer Name' => 'Karim', 'Customer Phone Number' => '01811111111',
            'Sub-Status' => 'PAYMENT_DUE', 'Sales Amount' => '1000.00', 'Delivery Fee' => '110.00',
            'Payments/Paid Amount' => '300.00', 'Due Amount' => '810.00',
        ], [['sku' => 'ZMKMQ', 'name' => 'Key Chain', 'qty' => 10, 'unit' => 100]]), dryRun: false);

        $order = Order::query()->where('external_reference', 'SO-1002')->firstOrFail();
        $this->assertEqualsWithDelta(810.0, (float) $order->due_amount, 0.001);
        $this->assertSame(OrderPayment::TYPE_PARTIAL, $order->payments()->sole()->type);
        $this->assertEqualsWithDelta(810.0, (float) $order->customer->current_balance, 0.001);
    }

    public function test_statuses_map_and_test_or_fake_cancellations_are_skipped(): void
    {
        $line = [['sku' => 'ZMKMQ', 'name' => 'Key Chain', 'qty' => 1, 'unit' => 100]];
        $money = ['Sales Amount' => '100.00', 'Delivery Fee' => '0.00', 'Payments/Paid Amount' => '0.00'];

        $path = $this->csv(array_merge(
            $this->order('SO-2001', array_merge($money, ['Status' => 'APPROVED', 'Sub-Status' => '', 'Delivery ID' => '', 'Delivery Partner Status' => '']), $line),
            $this->order('SO-2002', array_merge($money, ['Status' => 'APPROVED', 'Sub-Status' => '', 'Delivery ID' => '55', 'Delivery Partner Status' => 'pending']), $line),
            $this->order('SO-2003', array_merge($money, ['Status' => 'IN_TRANSIT', 'Sub-Status' => '']), $line),
            $this->order('SO-2004', array_merge($money, ['Status' => 'FLAGGED', 'Sub-Status' => 'RETURNED']), $line),
            $this->order('SO-2005', array_merge($money, ['Status' => 'CANCELLED', 'Sub-Status' => '', 'Cancelled/Flagged Reason' => 'Customer Unreachable', 'Payments/Paid Amount' => '100.00']), $line),
            $this->order('SO-2006', array_merge($money, ['Status' => 'CANCELLED', 'Sub-Status' => '', 'Cancelled/Flagged Reason' => 'Test Order']), $line),
            $this->order('SO-2007', array_merge($money, ['Status' => 'CANCELLED', 'Sub-Status' => '', 'Cancelled/Flagged Reason' => 'fake order']), $line),
            $this->order('SO-2008', array_merge($money, ['Status' => 'ON_HOLD', 'Sub-Status' => '']), $line),
            $this->order('SO-2009', array_merge($money, ['Status' => 'PROCESSING', 'Sub-Status' => '', 'Delivery ID' => '']), $line),
            $this->order('SO-2010', array_merge($money, ['Sub-Status' => 'PAYMENT_COLLECTED', 'Delivery Partner Status' => 'partial_delivered']), $line),
        ));

        $report = app(NuportOrderImportService::class)->run($path, dryRun: false);

        $this->assertSame(7, $report['created']);
        $this->assertSame(['test_or_fake' => 2, 'unsupported_status' => 1], $report['skipped']);

        $expected = [
            'SO-2001' => [Order::STATUS_CONFIRMED, CourierBooking::STATUS_NOT_BOOKED],
            'SO-2002' => [Order::STATUS_CONFIRMED, CourierBooking::STATUS_BOOKED],
            'SO-2003' => [Order::STATUS_PROCESSING, CourierBooking::STATUS_IN_TRANSIT],
            'SO-2004' => [Order::STATUS_RETURNED, CourierBooking::STATUS_RETURNED],
            'SO-2005' => [Order::STATUS_CANCELLED, CourierBooking::STATUS_CANCELLED],
            'SO-2009' => [Order::STATUS_PROCESSING, CourierBooking::STATUS_NOT_BOOKED],
            'SO-2010' => [Order::STATUS_COMPLETED, CourierBooking::STATUS_PARTIAL_DELIVERED],
        ];

        foreach ($expected as $invoice => [$status, $delivery]) {
            $order = Order::query()->where('external_reference', $invoice)->firstOrFail();
            $this->assertSame($status, $order->status, $invoice);
            $this->assertSame($delivery, $order->delivery_status, $invoice);
        }

        $this->assertSame(7, Order::query()->count());

        // A cancelled order keeps no payment even if Nuport had recorded some.
        $cancelled = Order::query()->where('external_reference', 'SO-2005')->firstOrFail();
        $this->assertSame(0, $cancelled->payments()->count());
        $this->assertEqualsWithDelta(0.0, (float) $cancelled->paid_amount, 0.001);
        $this->assertStringContainsString('Reason: Customer Unreachable', $cancelled->note);
    }

    public function test_an_order_with_an_unknown_product_is_skipped_until_the_product_exists(): void
    {
        $path = $this->csv($this->order('SO-3001', [
            'Sales Amount' => '100.00', 'Delivery Fee' => '0.00', 'Payments/Paid Amount' => '100.00',
        ], [
            ['sku' => 'ZMKMQ', 'name' => 'Key Chain', 'qty' => 1, 'unit' => 50],
            ['sku' => 'ZZ-OLD', 'name' => 'Retired gift box', 'qty' => 1, 'unit' => 50],
        ]));

        $report = app(NuportOrderImportService::class)->run($path, dryRun: false);

        $this->assertSame(0, $report['created']);
        $this->assertSame(['unmatched_sku' => 1], $report['skipped']);
        $this->assertSame(['ZZ-OLD' => 1], $report['unmatched']);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Customer::query()->count());

        Product::query()->create([
            'name' => 'Retired gift box', 'sku' => 'ZZ-OLD', 'price' => 50, 'sale_price' => 50, 'cost_price' => 20,
            'stock' => 0, 'unit' => 'pcs', 'is_active' => false, 'status' => 'available',
        ]);

        $report = app(NuportOrderImportService::class)->run($path, dryRun: false);

        $this->assertSame(1, $report['created']);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_a_line_without_a_sku_is_matched_by_exact_product_name(): void
    {
        Product::query()->create([
            'name' => 'Courier Poly', 'sku' => 'ZMCP', 'price' => 5, 'sale_price' => 5, 'cost_price' => 2,
            'stock' => 0, 'unit' => 'pcs', 'is_active' => true, 'status' => 'available',
        ]);

        $report = $this->import($this->order('SO-3002', [
            'Sales Amount' => '15.00', 'Delivery Fee' => '0.00', 'Payments/Paid Amount' => '15.00',
        ], [['sku' => '', 'name' => 'Courier Poly', 'qty' => 3, 'unit' => 5]]), dryRun: false);

        $this->assertSame(1, $report['created']);
        $this->assertSame('ZMCP', Order::query()->firstOrFail()->items()->firstOrFail()->product->sku);
    }

    public function test_importing_the_same_file_again_never_duplicates_and_a_newer_export_refreshes_status_and_payment(): void
    {
        $line = [['sku' => 'ZMKMQ', 'name' => 'Key Chain', 'qty' => 10, 'unit' => 100]];
        $first = $this->csv($this->order('SO-4001', [
            'Status' => 'APPROVED', 'Sub-Status' => '', 'Delivery ID' => '', 'Delivery Partner Status' => '',
            'Sales Amount' => '1000.00', 'Delivery Fee' => '110.00', 'Payments/Paid Amount' => '0.00',
        ], $line));
        $service = app(NuportOrderImportService::class);

        $report = $service->run($first, dryRun: false);
        $this->assertSame(1, $report['created']);
        $order = Order::query()->where('external_reference', 'SO-4001')->firstOrFail();
        $this->assertSame(Order::STATUS_CONFIRMED, $order->status);
        $this->assertEqualsWithDelta(1110.0, (float) $order->customer->current_balance, 0.001);

        $report = $service->run($first, dryRun: false);
        $this->assertSame(0, $report['created']);
        $this->assertSame(1, $report['unchanged']);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Customer::query()->count());

        // The same order, now delivered and paid.
        $second = $this->csv($this->order('SO-4001', [
            'Sub-Status' => 'PAYMENT_COLLECTED', 'Sales Amount' => '1000.00', 'Delivery Fee' => '110.00',
            'Payments/Paid Amount' => '1110.00',
        ], $line));

        $preview = $service->run($second, dryRun: true);
        $this->assertSame(1, $preview['refreshed']);
        $this->assertSame(Order::STATUS_CONFIRMED, $order->fresh()->status);

        $report = $service->run($second, dryRun: false);
        $this->assertSame(1, $report['refreshed']);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, $order->payments()->count());

        $order->refresh();
        $this->assertSame(Order::STATUS_COMPLETED, $order->status);
        $this->assertSame(CourierBooking::STATUS_DELIVERED, $order->delivery_status);
        $this->assertEqualsWithDelta(1110.0, (float) $order->paid_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $order->due_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $order->customer->fresh()->current_balance, 0.001);
        $this->assertSame(1, $order->items()->count());

        $this->assertSame(1, $service->run($second, dryRun: false)['unchanged']);
    }

    public function test_customers_are_matched_by_phone_in_any_spelling_and_an_opening_balance_is_flagged(): void
    {
        $existing = Customer::query()->create([
            'name' => 'Old Name', 'phone' => '+8801712345678', 'opening_balance' => 500, 'is_active' => true,
        ]);

        $report = $this->import($this->order('SO-5001', [
            'Customer Phone Number' => '01712345678', 'Sales Amount' => '100.00', 'Delivery Fee' => '0.00', 'Payments/Paid Amount' => '100.00',
        ], [['sku' => 'ZMKMQ', 'name' => 'Key Chain', 'qty' => 1, 'unit' => 100]]), dryRun: false);

        $this->assertSame(0, $report['customers_new']);
        $this->assertSame(1, $report['customers_existing']);
        $this->assertSame(1, $report['customers_with_balance']);
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame($existing->id, Order::query()->firstOrFail()->customer_id);
        $this->assertSame('Old Name', $existing->fresh()->name);
    }

    public function test_junk_phone_numbers_only_merge_when_phone_and_name_match(): void
    {
        $line = [['sku' => 'ZMKMQ', 'name' => 'Key Chain', 'qty' => 1, 'unit' => 100]];
        $cancelled = ['Status' => 'CANCELLED', 'Sub-Status' => '', 'Cancelled/Flagged Reason' => 'Customer Unreachable',
            'Sales Amount' => '100.00', 'Delivery Fee' => '0.00', 'Payments/Paid Amount' => '0.00'];

        $path = $this->csv(array_merge(
            $this->order('SO-5101', array_merge($cancelled, ['Customer Name' => 'test', 'Customer Phone Number' => '+8890184923741']), $line),
            $this->order('SO-5102', array_merge($cancelled, ['Customer Name' => 'test', 'Customer Phone Number' => '+8890184923741']), $line),
            $this->order('SO-5103', array_merge($cancelled, ['Customer Name' => 'other', 'Customer Phone Number' => '+8890184923741']), $line),
        ));

        $report = app(NuportOrderImportService::class)->run($path, dryRun: false);

        $this->assertSame(3, $report['created']);
        $this->assertSame(2, Customer::query()->count());
    }

    public function test_discounts_land_in_the_order_discount_and_mismatched_totals_are_flagged_but_the_file_total_wins(): void
    {
        $line = [['sku' => 'ZMKMQ', 'name' => 'Key Chain', 'qty' => 10, 'unit' => 100]];
        $path = $this->csv(array_merge(
            // Order discount 100 on lines of 1000: consistent.
            $this->order('SO-6001', ['Order Discount' => '100.00', 'Sales Amount' => '900.00', 'Delivery Fee' => '50.00', 'Payments/Paid Amount' => '950.00'], $line),
            // Sales Amount edited in Nuport afterwards: lines say 1000, Sales Amount says 800.
            $this->order('SO-6002', ['Sales Amount' => '800.00', 'Delivery Fee' => '50.00', 'Payments/Paid Amount' => '850.00'], $line),
        ));

        $report = app(NuportOrderImportService::class)->run($path, dryRun: false);

        $this->assertSame(['SO-6002'], $report['mismatch']);

        $consistent = Order::query()->where('external_reference', 'SO-6001')->firstOrFail();
        $this->assertEqualsWithDelta(100.0, (float) $consistent->discount, 0.001);
        $this->assertEqualsWithDelta(950.0, (float) $consistent->total_amount, 0.001);

        $edited = Order::query()->where('external_reference', 'SO-6002')->firstOrFail();
        $this->assertEqualsWithDelta(200.0, (float) $edited->discount, 0.001);
        $this->assertEqualsWithDelta(850.0, (float) $edited->total_amount, 0.001);
    }

    public function test_the_shipping_fee_in_the_file_is_kept_even_when_it_is_zero(): void
    {
        $this->import($this->order('SO-6101', [
            'Customer Address' => 'Mirpur, Dhaka', 'Sales Amount' => '100.00', 'Delivery Fee' => '0.00', 'Payments/Paid Amount' => '100.00',
        ], [['sku' => 'ZMKMQ', 'name' => 'Key Chain', 'qty' => 1, 'unit' => 100]]), dryRun: false);

        $order = Order::query()->firstOrFail();
        $this->assertEqualsWithDelta(0.0, (float) $order->shipping_fee, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $order->total_amount, 0.001);
    }

    public function test_a_preview_reports_everything_and_saves_nothing(): void
    {
        $report = $this->import($this->order('SO-7001', [
            'Sales Amount' => '1000.00', 'Delivery Fee' => '110.00', 'Payments/Paid Amount' => '1110.00',
        ], [['sku' => 'ZMKMQ', 'name' => 'Key Chain', 'qty' => 10, 'unit' => 100]]), dryRun: true);

        $this->assertTrue($report['dry_run']);
        $this->assertSame(1, $report['created']);
        $this->assertSame(1, $report['customers_new']);
        $this->assertEqualsWithDelta(1000.0, $report['by_status'][Order::STATUS_COMPLETED]['sales'], 0.001);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, OrderPayment::query()->count());

        $summary = implode("\n", app(NuportOrderImportService::class)->summary($report));
        $this->assertStringContainsString('Preview only: nothing was saved.', $summary);
        $this->assertStringContainsString('Stock and account balances are never changed by this import.', $summary);
    }

    public function test_a_file_without_the_expected_columns_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nuport');
        file_put_contents($path, "Invoice,Customer Name\nSO-1,Rahim\n");

        try {
            app(NuportOrderImportService::class)->run($path);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('salesamount', $exception->errors()['csv'][0]);
        }
    }

    public function test_the_import_needs_exactly_one_company(): void
    {
        app(CompanyContext::class)->clear();

        $this->expectException(ValidationException::class);

        app(NuportOrderImportService::class)->run($this->csv([]));
    }

    public function test_the_artisan_command_previews_by_default_and_saves_with_apply(): void
    {
        $path = $this->csv($this->order('SO-8001', [
            'Sales Amount' => '100.00', 'Delivery Fee' => '0.00', 'Payments/Paid Amount' => '100.00',
        ], [['sku' => 'ZMKMQ', 'name' => 'Key Chain', 'qty' => 1, 'unit' => 100]]));

        $this->artisan('orders:import-nuport', ['file' => $path, 'company' => $this->company->slug])
            ->expectsOutputToContain('Preview only: nothing was saved.')
            ->assertSuccessful();

        app(CompanyContext::class)->set($this->company);
        $this->assertSame(0, Order::query()->count());

        $this->artisan('orders:import-nuport', ['file' => $path, 'company' => $this->company->slug, '--apply' => true])
            ->assertSuccessful();

        app(CompanyContext::class)->set($this->company);
        $this->assertSame(1, Order::query()->count());

        $this->artisan('orders:import-nuport', ['file' => $path, 'company' => 'missing-co'])->assertFailed();
    }

    public function test_a_sku_mapping_points_a_nuport_sku_at_an_erp_variant_or_product(): void
    {
        $rows = array_merge(
            $this->order('SO-4001', [
                'Sales Amount' => '1000.00', 'Delivery Fee' => '0.00', 'Payments/Paid Amount' => '1000.00',
            ], [['sku' => 'zmtfx05b-old', 'name' => 'X05 Fan - Black (old)', 'qty' => 2, 'unit' => 500]]),
            $this->order('SO-4002', [
                'Sales Amount' => '40.00', 'Delivery Fee' => '0.00', 'Payments/Paid Amount' => '40.00',
            ], [['sku' => 'ZMKMQ-RETIRED', 'name' => 'Key Chain old', 'qty' => 2, 'unit' => 20]]),
        );
        $map = "# retired Nuport SKUs\nZMTFX05B-OLD = ZMTFX05B\nZMKMQ-RETIRED => zmkmq\nNO-SUCH = NOT-IN-ERP\nbroken line";

        $dry = app(NuportOrderImportService::class)->run($this->csv($rows), dryRun: true, skuMap: $map);

        $this->assertSame(2, $dry['created']);
        $this->assertSame([], $dry['skipped']);
        $this->assertSame(['zmtfx05b-old' => 1, 'zmkmq-retired' => 1], array_map(fn (array $map): int => $map['orders'], $dry['mapped']));
        $this->assertSame(['NO-SUCH = NOT-IN-ERP', 'broken line'], $dry['map_ignored']);
        $this->assertSame(0, Order::query()->count());

        $report = app(NuportOrderImportService::class)->run($this->csv($rows), dryRun: false, skuMap: $map);

        $this->assertSame(2, $report['created']);

        $variantItem = Order::query()->where('order_number', 'SO-4001')->firstOrFail()->items()->firstOrFail();
        $this->assertSame($this->blackFan->id, $variantItem->product_variant_id);
        $this->assertSame($this->fan->id, $variantItem->product_id);
        $this->assertEqualsWithDelta(500.0, (float) $variantItem->unit_price, 0.001);

        $productItem = Order::query()->where('order_number', 'SO-4002')->firstOrFail()->items()->firstOrFail();
        $this->assertSame($this->keyChain->id, $productItem->product_id);
        $this->assertNull($productItem->product_variant_id);

        $lines = app(NuportOrderImportService::class)->summary($dry);
        $this->assertTrue(collect($lines)->contains(fn (string $line): bool => str_contains($line, 'ZMTFX05B-OLD → ZMTFX05B (1)')));
        $this->assertTrue(collect($lines)->contains(fn (string $line): bool => str_contains($line, 'NO-SUCH = NOT-IN-ERP')));
    }

    public function test_a_sku_mapping_wins_over_a_direct_match_and_never_guesses(): void
    {
        $other = Product::query()->create([
            'name' => 'Other', 'sku' => 'ZMOTHER', 'price' => 10, 'sale_price' => 10, 'cost_price' => 4,
            'stock' => 0, 'unit' => 'pcs', 'is_active' => true, 'status' => 'available',
        ]);

        $path = $this->csv($this->order('SO-4101', [
            'Sales Amount' => '20.00', 'Delivery Fee' => '0.00', 'Payments/Paid Amount' => '20.00',
        ], [['sku' => 'ZMKMQ', 'name' => 'Key Chain', 'qty' => 2, 'unit' => 10]]));

        app(NuportOrderImportService::class)->run($path, dryRun: false, skuMap: 'ZMKMQ = ZMOTHER');

        $this->assertSame($other->id, Order::query()->firstOrFail()->items()->firstOrFail()->product_id);

        // The ERP SKU is missing: the mapping is dropped, the order is skipped, nothing is guessed.
        $report = app(NuportOrderImportService::class)->run(
            $this->csv($this->order('SO-4102', [
                'Sales Amount' => '20.00', 'Delivery Fee' => '0.00', 'Payments/Paid Amount' => '20.00',
            ], [['sku' => 'ZZ-GONE', 'name' => 'Gone', 'qty' => 1, 'unit' => 20]])),
            dryRun: false,
            skuMap: 'ZZ-GONE = ZZ-ALSO-GONE',
        );

        $this->assertSame(0, $report['created']);
        $this->assertSame(['ZZ-GONE = ZZ-ALSO-GONE'], $report['map_ignored']);
        $this->assertSame(['ZZ-GONE' => 1], $report['unmatched']);
    }

    public function test_missing_products_are_created_inactive_only_when_asked_and_only_once(): void
    {
        $rows = array_merge(
            $this->order('SO-4201', [
                'Sales Amount' => '1300.00', 'Delivery Fee' => '0.00', 'Payments/Paid Amount' => '1300.00',
            ], [
                ['sku' => 'ZMGRRLP', 'name' => 'Red Rose & Oyster Gold Pearl Necklace', 'qty' => 1, 'unit' => 1250],
                ['sku' => '', 'name' => 'Courier Poly', 'qty' => 5, 'unit' => 10],
            ]),
            $this->order('SO-4202', [
                'Sales Amount' => '1300.00', 'Delivery Fee' => '0.00', 'Payments/Paid Amount' => '1300.00',
            ], [
                ['sku' => 'ZMGRRLP', 'name' => 'Red Rose & Oyster Gold Pearl Necklace', 'qty' => 1, 'unit' => 1300],
            ]),
        );
        $path = $this->csv($rows);
        $service = app(NuportOrderImportService::class);

        // Off by default: both orders are skipped and listed.
        $off = $service->run($path, dryRun: false);
        $this->assertSame(0, $off['created']);
        $this->assertSame(['ZMGRRLP' => 2, 'Courier Poly (no SKU)' => 1], $off['unmatched']);
        $this->assertSame(0, Product::query()->where('sku', 'ZMGRRLP')->count());

        // Preview: lists what would be created, saves nothing.
        $dry = $service->run($path, dryRun: true, createMissing: true);
        $this->assertSame(2, $dry['created']);
        $this->assertSame(['ZMGRRLP', 'NUPORT-COURIER-POLY'], array_keys($dry['new_products']));
        $this->assertSame(0, Product::query()->where('sku', 'ZMGRRLP')->count());

        $report = $service->run($path, dryRun: false, createMissing: true);
        $this->assertSame(2, $report['created']);
        $this->assertSame([], $report['errors']);

        $necklace = Product::query()->where('sku', 'ZMGRRLP')->firstOrFail();
        $this->assertFalse($necklace->is_active);
        $this->assertSame(0, (int) $necklace->stock);
        $this->assertEqualsWithDelta(0.0, (float) $necklace->cost_price, 0.001);
        $this->assertEqualsWithDelta(1300.0, (float) $necklace->sale_price, 0.001);
        $this->assertSame('Courier Poly', Product::query()->where('sku', 'NUPORT-COURIER-POLY')->firstOrFail()->name);
        $this->assertSame(0, StockMovement::query()->where('product_id', $necklace->id)->count());
        $this->assertSame(2, Order::query()->count());

        // A second run finds those products by SKU and name: nothing new, nothing duplicated.
        $again = $service->run($path, dryRun: false, createMissing: true);
        $this->assertSame(0, $again['created']);
        $this->assertSame([], $again['new_products']);
        $this->assertSame(1, Product::query()->where('sku', 'ZMGRRLP')->count());
        $this->assertSame(1, Product::query()->where('sku', 'NUPORT-COURIER-POLY')->count());
    }

    public function test_the_artisan_command_reads_a_sku_mapping_file_and_can_create_missing_products(): void
    {
        $path = $this->csv(array_merge(
            $this->order('SO-4301', [
                'Sales Amount' => '40.00', 'Delivery Fee' => '0.00', 'Payments/Paid Amount' => '40.00',
            ], [['sku' => 'OLD-KEYCHAIN', 'name' => 'Key Chain old', 'qty' => 2, 'unit' => 20]]),
            $this->order('SO-4302', [
                'Sales Amount' => '90.00', 'Delivery Fee' => '0.00', 'Payments/Paid Amount' => '90.00',
            ], [['sku' => 'ZMNEW', 'name' => 'Brand new', 'qty' => 3, 'unit' => 30]]),
        ));
        $map = tempnam(sys_get_temp_dir(), 'nuportmap');
        file_put_contents($map, "OLD-KEYCHAIN = ZMKMQ\n");

        $this->artisan('orders:import-nuport', [
            'file' => $path, 'company' => $this->company->slug, '--map' => $map, '--create-missing' => true, '--apply' => true,
        ])->assertSuccessful();

        app(CompanyContext::class)->set($this->company);
        $this->assertSame(2, Order::query()->count());
        $this->assertSame($this->keyChain->id, Order::query()->where('order_number', 'SO-4301')->firstOrFail()->items()->firstOrFail()->product_id);
        $this->assertFalse(Product::query()->where('sku', 'ZMNEW')->firstOrFail()->is_active);

        $this->artisan('orders:import-nuport', ['file' => $path, 'company' => $this->company->slug, '--map' => '/no/such/file'])->assertFailed();
    }

    public function test_the_import_form_remembers_the_sku_mapping_for_the_next_time(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->withSession(['current_company_id' => $this->company->id]);

        $csv = file_get_contents($this->csv($this->order('SO-4401', [
            'Sales Amount' => '40.00', 'Delivery Fee' => '0.00', 'Payments/Paid Amount' => '40.00',
        ], [['sku' => 'OLD-KEYCHAIN', 'name' => 'Key Chain old', 'qty' => 2, 'unit' => 20]])));

        Livewire::test(ListOrders::class)
            ->callAction('importNuportOrders', [
                'csv' => UploadedFile::fake()->createWithContent('nuport.csv', $csv),
                'sku_map' => 'OLD-KEYCHAIN = ZMKMQ',
                'create_missing' => false,
                'preview_only' => false,
            ])
            ->assertNotified('Nuport orders imported');

        $this->assertSame(1, Order::query()->count());
        $this->assertSame('OLD-KEYCHAIN = ZMKMQ', AppSetting::getValue('nuport_import_sku_map_'.$this->company->id));

        Livewire::test(ListOrders::class)
            ->mountAction('importNuportOrders')
            ->assertActionDataSet(['sku_map' => 'OLD-KEYCHAIN = ZMKMQ']);
    }

    public function test_the_orders_list_offers_the_import_and_previews_an_uploaded_file(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->withSession(['current_company_id' => $this->company->id]);

        $csv = file_get_contents($this->csv($this->order('SO-9001', [
            'Sales Amount' => '100.00', 'Delivery Fee' => '0.00', 'Payments/Paid Amount' => '100.00',
        ], [['sku' => 'ZMKMQ', 'name' => 'Key Chain', 'qty' => 1, 'unit' => 100]])));

        Livewire::test(ListOrders::class)
            ->assertActionExists('importNuportOrders')
            ->callAction('importNuportOrders', [
                'csv' => UploadedFile::fake()->createWithContent('nuport.csv', $csv),
                'preview_only' => true,
            ])
            ->assertNotified('Nuport import preview');

        $this->assertSame(0, Order::query()->count());

        Livewire::test(ListOrders::class)
            ->callAction('importNuportOrders', [
                'csv' => UploadedFile::fake()->createWithContent('nuport.csv', $csv),
                'preview_only' => false,
            ])
            ->assertNotified('Nuport orders imported');

        $this->assertSame(1, Order::query()->count());
    }

    /** @param  array<int, array<string, string>>  $rows */
    protected function import(array $rows, bool $dryRun): array
    {
        return app(NuportOrderImportService::class)->run($this->csv($rows), $dryRun);
    }

    /**
     * One Nuport invoice: the first row carries the order-level fields (and the
     * money), later rows only the line, exactly like the real export.
     *
     * @param  array<string, string>  $head
     * @param  array<int, array{sku: string, name: string, qty: int, unit: int|float, discount?: int|float}>  $lines
     * @return array<int, array<string, string>>
     */
    protected function order(string $invoice, array $head, array $lines = []): array
    {
        $lines = $lines ?: [['sku' => 'ZMKMQ', 'name' => 'Key Chain', 'qty' => 1, 'unit' => 100]];
        $head += [
            'Creation Date' => 'August 21st 2025, 6:19:19 pm', 'Shipping Date' => 'August 21st 2025, 6:25:44 pm',
            'Customer Name' => 'Rahim', 'Customer Phone Number' => '+8801712345678', 'Customer Address' => 'Dhaka',
            'Status' => 'DELIVERED', 'Sub-Status' => 'PAYMENT_COLLECTED', 'Payment Method' => 'Cash On Delivery (COD)',
            'Delivery Partner' => 'Steadfast', 'Delivery Partner Status' => 'delivered', 'Delivery ID' => '123',
            'Order Discount' => '0.00',
        ];

        $rows = [];

        foreach ($lines as $index => $line) {
            $discount = $line['discount'] ?? 0;
            $row = [
                'Invoice' => $invoice, 'Status' => $head['Status'], 'Sub-Status' => $head['Sub-Status'],
                'Product SKU' => $line['sku'], 'Product Name' => $line['name'], 'Product Qty' => (string) $line['qty'],
                'Unit Price' => number_format($line['unit'], 2, '.', ''),
                'Total Price' => number_format($line['qty'] * $line['unit'], 2, '.', ''),
                'Product Discount' => (string) $discount,
                'Price After Discount' => number_format($line['qty'] * $line['unit'] - $discount, 2, '.', ''),
            ];

            $rows[] = $index === 0 ? $row + $head : $row;
        }

        return $rows;
    }

    /** @param  array<int, array<string, string>>  $rows */
    protected function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nuport');
        $handle = fopen($path, 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::HEADERS);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $heading): string => $row[$heading] ?? '', self::HEADERS));
        }

        fclose($handle);

        return $path;
    }
}
