<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Customers\Widgets\CustomerPurchaseSummaryWidget;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CustomerOrderSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner request 2026-11-01: the customer's name on the order page opens the customer's page, which lists their
 * orders by date and shows cards for what they bought and what they paid.
 */
class CustomerOrderHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'History Co', 'slug' => 'history-co', 'invoice_prefix' => 'HIS', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $this->company->id]);

        $this->product = Product::query()->create(['name' => 'History Lamp', 'sku' => 'HIS-001', 'price' => 100, 'sale_price' => 100, 'stock' => 0]);
        StockMovement::query()->create(['product_id' => $this->product->id, 'type' => 'opening', 'quantity' => 1000]);
    }

    private function customer(string $name, string $phone): Customer
    {
        return Customer::query()->create(['name' => $name, 'phone' => $phone, 'opening_balance' => 0, 'is_active' => true]);
    }

    /** An order of $quantity lamps at 100 each, in the given status and on the given day. */
    private function order(Customer $customer, int $quantity, string $status, string $date): Order
    {
        $order = Order::query()->create([
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'status' => Order::STATUS_DRAFT,
            'order_date' => $date,
            'source' => Order::SOURCE_ADMIN,
        ]);
        OrderItem::query()->create(['order_id' => $order->id, 'product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => 100]);

        if ($status !== Order::STATUS_DRAFT) {
            $order->forceFill(['status' => $status])->save();
        }

        return $order->refresh();
    }

    public function test_the_summary_counts_the_goods_taken_and_everything_paid(): void
    {
        $buyer = $this->customer('Karim', '01711111111');
        $first = $this->order($buyer, 2, Order::STATUS_COMPLETED, '2026-09-01');
        $second = $this->order($buyer, 3, Order::STATUS_PROCESSING, '2026-10-05');
        // Cancelled and returned orders never became a sale.
        $this->order($buyer, 5, Order::STATUS_CANCELLED, '2026-10-06');
        $this->order($buyer, 7, Order::STATUS_RETURNED, '2026-10-07');
        // Somebody else's order is not Karim's.
        $this->order($this->customer('Salma', '01722222222'), 9, Order::STATUS_COMPLETED, '2026-10-08');

        OrderPayment::query()->create(['order_id' => $first->id, 'type' => OrderPayment::TYPE_ADVANCE, 'method' => 'cash', 'amount' => 50, 'paid_at' => '2026-09-01']);
        $buyer->refresh();
        CustomerPayment::query()->create(['customer_id' => $buyer->id, 'amount' => 100, 'method' => 'cash', 'payment_date' => '2026-10-10']);

        $summary = app(CustomerOrderSummaryService::class)->for($buyer->refresh());

        $this->assertSame(2, $summary['orders']);
        $this->assertEqualsWithDelta((float) $first->total_amount + (float) $second->total_amount, $summary['purchased'], 0.001);
        $this->assertEqualsWithDelta(500.0, $summary['purchased'], 0.001);
        $this->assertEqualsWithDelta(50.0, $summary['paid_on_orders'], 0.001);
        $this->assertEqualsWithDelta(100.0, $summary['paid_later'], 0.001);
        $this->assertEqualsWithDelta(150.0, $summary['paid'], 0.001);
        $this->assertEqualsWithDelta(350.0, $summary['due'], 0.001);
        $this->assertSame('2026-09-01', substr((string) $summary['first_order_date'], 0, 10));
        $this->assertSame('2026-10-05', substr((string) $summary['last_order_date'], 0, 10));
    }

    public function test_a_customer_with_no_orders_has_empty_totals(): void
    {
        $summary = app(CustomerOrderSummaryService::class)->for($this->customer('New Buyer', '01733333333'));

        $this->assertSame(0, $summary['orders']);
        $this->assertSame(0.0, $summary['purchased']);
        $this->assertSame(0.0, $summary['paid']);
        $this->assertNull($summary['first_order_date']);
    }

    public function test_the_cards_show_on_the_customer_page(): void
    {
        $buyer = $this->customer('Karim', '01711111111');
        $this->order($buyer, 2, Order::STATUS_COMPLETED, '2026-09-01');

        Livewire::test(CustomerPurchaseSummaryWidget::class, ['record' => $buyer])
            ->assertSee('Orders')
            ->assertSee('Total purchased')
            ->assertSee('Total paid')
            ->assertSee('Current due');

        $this->get(CustomerResource::getUrl('view', ['record' => $buyer]))
            ->assertOk()
            ->assertSee('Karim');
    }

    public function test_the_customer_page_lists_only_this_customers_orders_newest_first(): void
    {
        $buyer = $this->customer('Karim', '01711111111');
        $older = $this->order($buyer, 2, Order::STATUS_COMPLETED, '2026-09-01');
        $newer = $this->order($buyer, 1, Order::STATUS_CONFIRMED, '2026-10-05');
        $draft = $this->order($buyer, 4, Order::STATUS_DRAFT, '2026-10-06');
        $stranger = $this->order($this->customer('Salma', '01722222222'), 9, Order::STATUS_COMPLETED, '2026-10-08');

        Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $buyer, 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords([$newer, $older, $draft], inOrder: false)
            ->assertCanNotSeeTableRecords([$stranger])
            ->assertSee('Order History')
            ->assertSee('History Lamp');

        // Newest sale first.
        Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $buyer, 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords([$draft, $newer, $older], inOrder: true);
    }

    public function test_the_customer_name_on_the_order_page_links_to_the_customer_page(): void
    {
        $buyer = $this->customer('Karim', '01711111111');
        $order = $this->order($buyer, 2, Order::STATUS_COMPLETED, '2026-09-01');
        $customerUrl = CustomerResource::getUrl('view', ['record' => $buyer]);

        $this->get("/admin/sales/orders/{$order->getKey()}")
            ->assertOk()
            ->assertSee($customerUrl, false);

        $this->assertSame($customerUrl, OrderInfolist::customerUrl($order));
    }

    public function test_an_order_with_no_customer_has_no_link(): void
    {
        $order = new Order;

        $this->assertNull(OrderInfolist::customerUrl($order));
    }

    public function test_a_reseller_is_not_sent_to_the_customers_list_they_are_hidden_from(): void
    {
        $reseller = $this->customer('Rafi Reseller', '01744444444');
        $reseller->forceFill(['reseller_status' => 'approved'])->save();
        $order = $this->order($reseller, 1, Order::STATUS_COMPLETED, '2026-09-01');

        $url = OrderInfolist::customerUrl($order->refresh());

        $this->assertNotSame(CustomerResource::getUrl('view', ['record' => $reseller]), $url);
    }
}
