<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryChallan;
use App\Models\Order;
use App\Models\Product;
use App\Services\CompanyContext;
use App\Services\DeliveryChallanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** The Trade Portal's Delivery Challan (docs/tasneem-samples/PLAN.md §4), checked against the owner's paper. */
class DeliveryChallanServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Tasneem Test', 'slug' => 'tasneem-'.uniqid(), 'invoice_prefix' => 'TK'.random_int(1000, 9999),
            'is_active' => true, 'trade_portal_enabled' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
    }

    protected function product(string $name, int $stock, string $unit = 'pcs'): Product
    {
        $product = Product::query()->create(['name' => $name, 'sku' => 'T-'.uniqid(), 'unit' => $unit, 'sale_price' => 0, 'price' => 0, 'is_active' => true]);
        $product->setStockFromProductForm($stock);

        return $product->refresh();
    }

    /** The challan in the owner's photo (SL 026). */
    protected function paperChallan(): array
    {
        return [
            'challan_number' => '026',
            'challan_date' => '2026-08-16',
            'buyer_name' => 'Madina Knitwear',
            'buyer_address' => 'BSCIC, Narayanganj',
            'items' => [
                ['product_id' => $this->machine->getKey(), 'description' => 'Brand: Chuantian Rib+Interlock 34X72F 24G+28G', 'quantity' => 2, 'unit' => 'set', 'unit_price' => 750000],
                ['product_id' => $this->stand->getKey(), 'description' => 'Used China Side Stand', 'quantity' => 2, 'unit' => 'set'],
                ['product_id' => $this->cam->getKey(), 'description' => 'CAM: 432 pcs per machine x 2', 'quantity' => 864, 'unit' => 'pcs', 'remarks' => 'Att. M/C'],
                ['description' => 'MC 2 pcs + extra 2 pcs'],
            ],
            'advance_amount' => 100000,
            'advance_date' => '2026-08-10',
            'advance_note' => 'To Afsar Boss',
            'cash_amount' => 800000,
            'cheque_number' => '3097332',
            'cheque_bank' => 'DBBL',
            'due_terms' => 'Due Taka 6,00,000 to be paid within next 2 months.',
        ];
    }

    protected Product $machine;

    protected Product $stand;

    protected Product $cam;

    protected function stock(): void
    {
        $this->machine = $this->product('Circular knitting machine 34x72F', 5, 'set');
        $this->stand = $this->product('Side stand', 4, 'set');
        $this->cam = $this->product('CAM', 2000);
    }

    public function test_the_paper_challan_adds_up_takes_stock_and_leaves_the_due_on_the_customer(): void
    {
        $this->stock();
        $challan = app(DeliveryChallanService::class)->create($this->paperChallan());

        $this->assertSame('1500000.00', $challan->total);
        $this->assertSame(600000.0, $challan->dueOnPaper());
        $this->assertSame(600000.0, $challan->dueNow());
        $this->assertSame(900000.0, $challan->paidSoFar());
        $this->assertCount(4, $challan->items);

        $order = $challan->order;
        $this->assertSame(Order::SOURCE_TRADE_CHALLAN, $order->source);
        $this->assertSame(Order::STATUS_COMPLETED, $order->status);
        $this->assertSame('0.00', $order->shipping_fee);
        $this->assertCount(3, $order->items);
        $this->assertCount(2, $order->payments);

        $this->assertSame(3, (int) $this->machine->refresh()->stock);
        $this->assertSame(2, (int) $this->stand->refresh()->stock);
        $this->assertSame(1136, (int) $this->cam->refresh()->stock);

        $customer = Customer::query()->where('name', 'Madina Knitwear')->first();
        $this->assertSame(600000.0, (float) $customer->current_balance);
    }

    public function test_later_money_is_added_against_the_same_challan(): void
    {
        $this->stock();
        $service = app(DeliveryChallanService::class);
        $challan = $service->create($this->paperChallan());

        $service->receivePayment($challan, 250000, '2026-09-01');
        $this->assertSame(350000.0, $challan->refresh()->dueNow());

        try {
            $service->receivePayment($challan, 400000);
            $this->fail('More than the due must be refused.');
        } catch (ValidationException) {
        }

        $service->receivePayment($challan, 350000);
        $this->assertSame(0.0, $challan->refresh()->dueNow());
        $this->assertSame(0.0, (float) $challan->customer->refresh()->current_balance);
    }

    public function test_cancelling_puts_the_goods_back_and_removes_the_due(): void
    {
        $this->stock();
        $service = app(DeliveryChallanService::class);
        $challan = $service->create($this->paperChallan());

        $service->cancel($challan, 'Written by mistake');

        $this->assertTrue($challan->refresh()->isCancelled());
        $this->assertSame(5, (int) $this->machine->refresh()->stock);
        $this->assertSame(2000, (int) $this->cam->refresh()->stock);
        $this->assertSame(0.0, $challan->dueNow());
        $this->assertLessThanOrEqual(0.0, (float) $challan->customer->refresh()->current_balance);
    }

    public function test_short_stock_is_refused_by_name_and_nothing_is_written(): void
    {
        $this->stock();
        $data = $this->paperChallan();
        $data['items'][0]['quantity'] = 6;

        try {
            app(DeliveryChallanService::class)->create($data);
            $this->fail('Short stock must be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Circular knitting machine 34x72F', implode(' ', $exception->errors()['items']));
        }

        $this->assertSame(0, DeliveryChallan::query()->count());
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(5, (int) $this->machine->refresh()->stock);
    }

    public function test_a_price_needs_an_item_from_stock_and_payments_cannot_pass_the_total(): void
    {
        $this->stock();
        $service = app(DeliveryChallanService::class);

        $data = $this->paperChallan();
        $data['items'][3]['unit_price'] = 500;
        $this->assertThrows(fn () => $service->create($data), ValidationException::class);

        $data = $this->paperChallan();
        $data['cash_amount'] = 1500000;
        $this->assertThrows(fn () => $service->create($data), ValidationException::class);
    }

    public function test_numbers_follow_the_pad_and_cannot_repeat(): void
    {
        $this->stock();
        $service = app(DeliveryChallanService::class);
        $service->create($this->paperChallan());

        $this->assertSame('027', $service->nextNumber());

        $data = $this->paperChallan();
        $data['items'] = [['description' => 'Needles sample']];
        $data['advance_amount'] = 0;
        $data['cash_amount'] = 0;
        $this->assertThrows(fn () => $service->create($data), ValidationException::class);

        $data['challan_number'] = '';
        $this->assertSame('027', $service->create($data)->challan_number);
    }

    public function test_an_old_due_is_received_on_the_customer(): void
    {
        $customer = Customer::query()->create(['name' => 'Old Party', 'opening_balance' => 50000]);
        $customer->syncCurrentBalance();
        $cash = Account::query()->create(['name' => 'Cash in hand', 'type' => 'cash']);

        $this->assertThrows(fn () => app(DeliveryChallanService::class)->receiveOldDue($customer, 20000), ValidationException::class);

        app(DeliveryChallanService::class)->receiveOldDue($customer, 20000, '2026-10-01', $cash->getKey());

        $this->assertSame(30000.0, (float) $customer->refresh()->current_balance);
    }
}
