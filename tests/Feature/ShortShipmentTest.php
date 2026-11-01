<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CourierService;
use App\Services\OrderActivityFeedService;
use App\Services\ShortShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner request 2026-11-01: fewer pieces went out than were ordered and the customer confirmed what arrived on a
 * video call; the order is cut down to the pieces sent and the courier's cash on delivery comes down by the price of
 * the pieces left out.
 */
class ShortShipmentTest extends TestCase
{
    use RefreshDatabase;

    private Product $lamp;

    private Product $fan;

    private Order $order;

    private OrderItem $lampLine;

    private OrderItem $fanLine;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::query()->create([
            'name' => 'Short Co', 'slug' => 'short-co', 'invoice_prefix' => 'SHO', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $company->id]);

        $this->lamp = Product::query()->create(['name' => 'Short Lamp', 'sku' => 'SHO-001', 'price' => 100, 'sale_price' => 100, 'stock' => 0]);
        $this->fan = Product::query()->create(['name' => 'Short Fan', 'sku' => 'SHO-002', 'price' => 250, 'sale_price' => 250, 'stock' => 0]);
        StockMovement::query()->create(['product_id' => $this->lamp->id, 'type' => 'opening', 'quantity' => 10]);
        StockMovement::query()->create(['product_id' => $this->fan->id, 'type' => 'opening', 'quantity' => 10]);

        $customer = Customer::query()->create(['name' => 'Short Buyer', 'phone' => '01712345678', 'address' => 'Dhaka']);
        $this->order = Order::query()->create(['customer_id' => $customer->id, 'status' => Order::STATUS_DRAFT, 'source' => Order::SOURCE_ADMIN]);
        $this->lampLine = OrderItem::query()->create(['order_id' => $this->order->id, 'product_id' => $this->lamp->id, 'quantity' => 3, 'unit_price' => 100]);
        $this->fanLine = OrderItem::query()->create(['order_id' => $this->order->id, 'product_id' => $this->fan->id, 'quantity' => 1, 'unit_price' => 250]);
        $this->order->forceFill(['status' => Order::STATUS_PROCESSING])->save();
        $this->order->refresh();

        $provider = CourierProvider::query()->create(['name' => 'Short Courier', 'driver' => 'steadfast', 'is_active' => true]);
        CourierBooking::query()->create([
            'order_id' => $this->order->id, 'courier_provider_id' => $provider->id, 'status' => CourierBooking::STATUS_BOOKED,
            'tracking_id' => 'SHORT-1', 'recipient_name' => 'Short Buyer', 'cod_amount' => $this->order->courierCodAmount(),
        ]);
        $this->order->refresh();
    }

    public function test_the_new_amount_is_the_current_one_minus_the_price_of_the_pieces_left_out(): void
    {
        $current = $this->order->courierCodAmount();

        $preview = app(ShortShipmentService::class)->preview($this->order, [$this->lampLine->id => 2, $this->fanLine->id => 1]);

        $this->assertSame(1, $preview['missing_pieces']);
        $this->assertEqualsWithDelta(100.0, $preview['missing_value'], 0.001);
        $this->assertEqualsWithDelta($current, $preview['current_cod'], 0.001);
        $this->assertEqualsWithDelta($current - 100, $preview['new_cod'], 0.001);
        $this->assertEqualsWithDelta((float) $this->order->total_amount - 100, $preview['new_total'], 0.001);
    }

    public function test_pieces_are_kept_between_nothing_and_what_was_ordered(): void
    {
        $preview = app(ShortShipmentService::class)->preview($this->order, [$this->lampLine->id => 9, $this->fanLine->id => -4]);

        $this->assertSame([3, 0], array_column($preview['lines'], 'sent'));
        $this->assertEqualsWithDelta(250.0, $preview['missing_value'], 0.001);
    }

    public function test_applying_cuts_the_order_and_puts_the_pieces_back_in_stock(): void
    {
        $service = app(ShortShipmentService::class);
        $current = $this->order->courierCodAmount();
        $this->assertSame(7, $this->lamp->refresh()->stock);

        $applied = $service->apply($this->order, [$this->lampLine->id => 2, $this->fanLine->id => 1], 'Video call, the customer showed 2 lamps');

        $this->assertEqualsWithDelta($current - 100, $applied['new_cod'], 0.001);
        $this->assertSame(2, $this->lampLine->refresh()->quantity);
        $this->assertSame(1, $this->fanLine->refresh()->quantity);
        $this->assertEqualsWithDelta(450.0, (float) $this->order->refresh()->total_amount, 0.001);
        // The lamp that did not leave is back on the shelf.
        $this->assertSame(8, $this->lamp->refresh()->stock);
        $this->assertEqualsWithDelta($current - 100, $this->order->courierCodAmount(), 0.001);

        $log = AuditLog::query()->where('action', 'short_shipment')->where('auditable_id', $this->order->id)->firstOrFail();
        $this->assertSame('Video call, the customer showed 2 lamps', $log->new_values['note']);
        $this->assertSame(3, $log->old_values['pieces']['Short Lamp']);
        $this->assertSame(2, $log->new_values['pieces']['Short Lamp']);
        $this->assertStringContainsString('Order cut down to the pieces sent', app(OrderActivityFeedService::class)->narrate($this->order)->pluck('text')->implode(' | '));
    }

    public function test_the_courier_booking_is_then_flagged_until_the_courier_has_the_new_amount(): void
    {
        $booked = (float) $this->order->latestCourierBooking->cod_amount;

        app(ShortShipmentService::class)->apply($this->order, [$this->lampLine->id => 0, $this->fanLine->id => 1], 'Video call');

        $mismatch = app(CourierService::class)->codMismatch($this->order->refresh());
        $this->assertEqualsWithDelta($booked, $mismatch['booked'], 0.001);
        $this->assertEqualsWithDelta($booked - 300, $mismatch['needed'], 0.001);
        $this->assertEqualsWithDelta(-300.0, $mismatch['difference'], 0.001);
    }

    public function test_a_line_with_nothing_sent_is_removed(): void
    {
        app(ShortShipmentService::class)->apply($this->order, [$this->lampLine->id => 0, $this->fanLine->id => 1], 'Video call');

        $this->assertDatabaseMissing('order_items', ['id' => $this->lampLine->id]);
        $this->assertSame(10, $this->lamp->refresh()->stock);
    }

    public function test_nothing_sent_at_all_or_nothing_missing_is_refused(): void
    {
        $service = app(ShortShipmentService::class);

        foreach ([[0, 0], [3, 1]] as [$lamps, $fans]) {
            try {
                $service->apply($this->order, [$this->lampLine->id => $lamps, $this->fanLine->id => $fans], 'x');
                $this->fail('The change should have been refused.');
            } catch (ValidationException) {
                $this->assertSame(3, $this->lampLine->refresh()->quantity);
                $this->assertSame(1, $this->fanLine->refresh()->quantity);
            }
        }
    }

    public function test_only_an_open_order_of_our_own_can_be_cut_down(): void
    {
        $service = app(ShortShipmentService::class);
        $this->assertTrue($service->allowedFor($this->order));

        foreach ([Order::STATUS_COMPLETED, Order::STATUS_CANCELLED, Order::STATUS_RETURNED, Order::STATUS_DRAFT] as $status) {
            $this->order->forceFill(['status' => $status])->saveQuietly();
            $this->assertFalse($service->allowedFor($this->order->refresh()), $status);
        }
    }

    public function test_the_order_page_action_cuts_the_order_and_tells_staff_the_new_amount(): void
    {
        $current = $this->order->courierCodAmount();

        Livewire::test(ViewOrder::class, ['record' => $this->order->getKey()])
            ->assertActionVisible('shortShipment')
            ->callAction('shortShipment', data: [
                'sent' => [$this->lampLine->id => 2, $this->fanLine->id => 1],
                'note' => 'Video call',
            ])
            ->assertNotified('The order now matches the pieces sent');

        $this->assertSame(2, $this->lampLine->refresh()->quantity);
        $this->assertEqualsWithDelta($current - 100, $this->order->refresh()->courierCodAmount(), 0.001);
    }

    public function test_a_note_on_how_the_customer_confirmed_is_required(): void
    {
        Livewire::test(ViewOrder::class, ['record' => $this->order->getKey()])
            ->callAction('shortShipment', data: ['sent' => [$this->lampLine->id => 2, $this->fanLine->id => 1], 'note' => ''])
            ->assertHasActionErrors(['note']);

        $this->assertSame(3, $this->lampLine->refresh()->quantity);
    }
}
