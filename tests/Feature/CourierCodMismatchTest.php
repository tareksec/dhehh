<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Models\CourierStatusLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CourierService;
use App\Services\OrderActivityFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner request 2026-11-01: a customer adds pieces after the parcel was booked, but the courier only collects the
 * amount it was booked with. No courier API we use can change the amount afterwards, so the app spots the difference,
 * says it on the order, and records the new amount once the courier has it (our own manual delivery is changed by
 * the app itself).
 */
class CourierCodMismatchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Cod Co', 'slug' => 'cod-co', 'invoice_prefix' => 'COD', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $this->company->id]);

        $this->product = Product::query()->create(['name' => 'Cod Lamp', 'sku' => 'COD-001', 'price' => 100, 'sale_price' => 100, 'stock' => 0]);
        StockMovement::query()->create(['product_id' => $this->product->id, 'type' => 'opening', 'quantity' => 100]);
    }

    /** A confirmed order of one lamp, booked with a courier for exactly what it needs. */
    private function bookedOrder(string $driver = 'steadfast'): Order
    {
        $customer = Customer::query()->create(['name' => 'Cod Buyer', 'phone' => '01712345678', 'address' => 'Dhaka']);
        $order = Order::query()->create(['customer_id' => $customer->id, 'status' => Order::STATUS_DRAFT, 'source' => Order::SOURCE_ADMIN]);
        OrderItem::query()->create(['order_id' => $order->id, 'product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100]);
        $order->forceFill(['status' => Order::STATUS_CONFIRMED])->save();
        $order->refresh();

        $provider = CourierProvider::query()->create(['name' => 'Cod Courier', 'driver' => $driver, 'is_active' => true]);
        CourierBooking::query()->create([
            'order_id' => $order->id, 'courier_provider_id' => $provider->id, 'status' => CourierBooking::STATUS_BOOKED,
            'tracking_id' => 'COD-TRACK-1', 'recipient_name' => 'Cod Buyer', 'cod_amount' => $order->courierCodAmount(),
        ]);

        return $order->refresh();
    }

    private function addPieces(Order $order, int $quantity): void
    {
        OrderItem::query()->create(['order_id' => $order->id, 'product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => 100]);
    }

    public function test_a_booking_that_agrees_with_the_order_raises_nothing(): void
    {
        $this->assertNull(app(CourierService::class)->codMismatch($this->bookedOrder()));
    }

    public function test_pieces_added_after_the_booking_are_spotted_with_the_difference(): void
    {
        $order = $this->bookedOrder();
        $booked = (float) $order->latestCourierBooking->cod_amount;

        $this->addPieces($order, 2);
        $mismatch = app(CourierService::class)->codMismatch($order->refresh());

        $this->assertNotNull($mismatch);
        $this->assertEqualsWithDelta($booked, $mismatch['booked'], 0.001);
        $this->assertEqualsWithDelta($booked + 200, $mismatch['needed'], 0.001);
        $this->assertEqualsWithDelta(200.0, $mismatch['difference'], 0.001);
        // Steadfast cannot be told by the app.
        $this->assertFalse($mismatch['automatic']);
    }

    public function test_a_payment_after_the_booking_is_spotted_too(): void
    {
        $order = $this->bookedOrder();

        OrderPayment::query()->create(['order_id' => $order->id, 'type' => OrderPayment::TYPE_ADVANCE, 'method' => 'cash', 'amount' => 40, 'paid_at' => now()]);
        $mismatch = app(CourierService::class)->codMismatch($order->refresh());

        $this->assertEqualsWithDelta(-40.0, $mismatch['difference'], 0.001);
    }

    public function test_a_booking_the_courier_no_longer_acts_on_raises_nothing(): void
    {
        $order = $this->bookedOrder();
        $this->addPieces($order, 2);

        foreach ([CourierBooking::STATUS_DELIVERED, CourierBooking::STATUS_RETURNED, CourierBooking::STATUS_CANCELLED, CourierBooking::STATUS_FAILED] as $status) {
            $order->latestCourierBooking->forceFill(['status' => $status])->save();
            $this->assertNull(app(CourierService::class)->codMismatch($order->refresh()), $status);
        }
    }

    public function test_our_own_manual_delivery_is_marked_as_changed_by_the_app(): void
    {
        $order = $this->bookedOrder('manual');
        $this->addPieces($order, 1);

        $this->assertTrue(app(CourierService::class)->codMismatch($order->refresh())['automatic']);
    }

    public function test_recording_the_new_amount_updates_the_booking_and_leaves_a_trail(): void
    {
        $order = $this->bookedOrder();
        $this->addPieces($order, 2);
        $order->refresh();
        $mismatch = app(CourierService::class)->codMismatch($order);

        app(CourierService::class)->recordCodChange($mismatch['booking'], $mismatch['needed'], 'COD changed with the courier: Rafi at the hub');

        $this->assertEqualsWithDelta($mismatch['needed'], (float) $order->latestCourierBooking->refresh()->cod_amount, 0.001);
        $this->assertNull(app(CourierService::class)->codMismatch($order->refresh()));
        $this->assertTrue(CourierStatusLog::query()->where('courier_booking_id', $mismatch['booking']->id)->where('note', 'like', '%Rafi at the hub%')->exists());

        $log = AuditLog::query()->where('action', 'courier_cod_changed')->where('auditable_id', $order->id)->firstOrFail();
        $this->assertEqualsWithDelta($mismatch['booked'], (float) $log->old_values['cod_amount'], 0.001);
        $this->assertEqualsWithDelta($mismatch['needed'], (float) $log->new_values['cod_amount'], 0.001);
        $this->assertStringContainsString(
            'Amount for the courier to collect changed from',
            app(OrderActivityFeedService::class)->narrate($order)->pluck('text')->implode(' | '),
        );
    }

    public function test_the_order_page_warns_and_the_action_saves_the_new_amount(): void
    {
        $order = $this->bookedOrder();
        $this->addPieces($order, 2);
        $order->refresh();
        $needed = $order->courierCodAmount();

        $this->assertStringContainsString('COD-TRACK-1', OrderInfolist::codMismatchMessage($order));

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSee('The courier has the old amount')
            ->assertActionVisible('courierAmountUpdated')
            ->callAction('courierAmountUpdated', data: ['note' => 'Told the hub'])
            ->assertNotified('Courier amount saved')
            ->assertDontSee('The courier has the old amount');

        $this->assertEqualsWithDelta($needed, (float) $order->latestCourierBooking->refresh()->cod_amount, 0.001);
    }

    public function test_the_action_is_hidden_when_nothing_differs(): void
    {
        $order = $this->bookedOrder();

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertDontSee('The courier has the old amount')
            ->assertActionHidden('courierAmountUpdated');
    }
}
