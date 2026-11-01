<?php

namespace Tests\Feature;

use App\Filament\Pages\ScanParcels;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusTransition;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\OrderStatusSmsService;
use App\Services\OrderStatusWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner request 2026-11-01: an order in Confirmed, Processing or Shipping (in transit) may go back to an earlier
 * stage, as well as forward. Nothing after Shipping goes back, and going back is a correction: the customer is
 * not texted about it and the courier's own booking is left as it is.
 */
class OrderStepBackTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Step Back Co', 'slug' => 'step-back-co', 'invoice_prefix' => 'SBC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $this->company->id]);
        $this->customer = Customer::query()->create(['name' => 'Back Buyer', 'phone' => '01755555555', 'opening_balance' => 0, 'is_active' => true]);
    }

    private function order(string $status, ?string $deliveryStatus = null): Order
    {
        return Order::query()->create([
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->name,
            'status' => $status,
            'delivery_status' => $deliveryStatus ?? CourierBooking::STATUS_NOT_BOOKED,
            'source' => Order::SOURCE_ADMIN,
        ]);
    }

    private function booking(Order $order, string $status): CourierBooking
    {
        $provider = CourierProvider::query()->create([
            'company_id' => $order->company_id, 'name' => 'Back Courier', 'slug' => 'back-courier',
            'driver' => CourierProvider::DRIVER_MANUAL, 'credentials' => [], 'settings' => [], 'is_active' => true,
        ]);

        return CourierBooking::query()->create([
            'company_id' => $order->company_id, 'courier_provider_id' => $provider->id, 'order_id' => $order->id,
            'tracking_id' => 'BACK-1', 'recipient_name' => 'Back Buyer', 'status' => $status,
        ]);
    }

    public function test_which_stages_an_order_may_go_back_to(): void
    {
        $this->assertSame([], $this->order(Order::STATUS_CONFIRMED)->stepBackWorkflowStages());
        $this->assertSame([Order::STAGE_CONFIRMED], $this->order(Order::STATUS_PROCESSING)->stepBackWorkflowStages());
        $this->assertSame(
            [Order::STAGE_CONFIRMED, Order::STAGE_PROCESSING],
            $this->order(Order::STATUS_PROCESSING, CourierBooking::STATUS_IN_TRANSIT)->stepBackWorkflowStages(),
        );

        // Nothing finished or not yet confirmed goes back.
        foreach ([Order::STATUS_DRAFT, Order::STATUS_COMPLETED, Order::STATUS_CANCELLED, Order::STATUS_RETURNED, Order::STATUS_REFUNDED] as $status) {
            $this->assertSame([], $this->order($status)->stepBackWorkflowStages(), $status);
        }
    }

    public function test_a_processing_order_goes_back_to_confirmed_and_can_move_on_again(): void
    {
        $workflow = app(OrderStatusWorkflowService::class);
        $order = $this->order(Order::STATUS_PROCESSING);

        $order = $workflow->transition($order, Order::STAGE_CONFIRMED);

        $this->assertSame(Order::STATUS_CONFIRMED, $order->status);
        $this->assertSame(Order::STAGE_CONFIRMED, $order->workflowStage());
        $this->assertDatabaseHas('order_status_transitions', [
            'order_id' => $order->id,
            'stage' => Order::STAGE_CONFIRMED,
            'from_status' => Order::STATUS_PROCESSING,
            'to_status' => Order::STATUS_CONFIRMED,
        ]);

        $order = $workflow->transition($order, Order::STAGE_PROCESSING);
        $this->assertSame(Order::STAGE_PROCESSING, $order->workflowStage());
    }

    public function test_a_shipping_order_without_a_courier_booking_goes_back_to_processing_and_to_confirmed(): void
    {
        $workflow = app(OrderStatusWorkflowService::class);
        $order = $this->order(Order::STATUS_CONFIRMED);
        $order = $workflow->transition($order, Order::STAGE_SHIPPING);
        $this->assertSame(Order::STAGE_SHIPPING, $order->workflowStage());
        $this->assertSame(CourierBooking::STATUS_IN_TRANSIT, $order->delivery_status);

        $order = $workflow->transition($order, Order::STAGE_PROCESSING);

        $this->assertSame(Order::STAGE_PROCESSING, $order->workflowStage());
        $this->assertSame(Order::STATUS_PROCESSING, $order->status);
        $this->assertSame(CourierBooking::STATUS_NOT_BOOKED, $order->delivery_status);

        $order = $workflow->transition($order, Order::STAGE_SHIPPING);
        $order = $workflow->transition($order, Order::STAGE_CONFIRMED);

        $this->assertSame(Order::STAGE_CONFIRMED, $order->workflowStage());
        $this->assertSame(Order::STATUS_CONFIRMED, $order->status);
        $this->assertSame(CourierBooking::STATUS_NOT_BOOKED, $order->delivery_status);
    }

    public function test_going_back_from_shipping_leaves_the_couriers_own_booking_alone(): void
    {
        $order = $this->order(Order::STATUS_PROCESSING, CourierBooking::STATUS_IN_TRANSIT);
        $booking = $this->booking($order, CourierBooking::STATUS_IN_TRANSIT);

        $order = app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_PROCESSING);

        // The order is no longer counted as on its way, but the parcel stays booked with the courier.
        $this->assertSame(Order::STAGE_PROCESSING, $order->workflowStage());
        $this->assertSame(CourierBooking::STATUS_BOOKED, $order->delivery_status);
        $this->assertSame(CourierBooking::STATUS_IN_TRANSIT, $booking->refresh()->status);
        $this->assertSame(0, $booking->statusLogs()->count());
    }

    public function test_a_delivered_completed_cancelled_or_returned_order_cannot_go_back(): void
    {
        foreach ([Order::STATUS_COMPLETED, Order::STATUS_CANCELLED, Order::STATUS_RETURNED] as $status) {
            $order = $this->order($status);

            foreach ([Order::STAGE_CONFIRMED, Order::STAGE_PROCESSING] as $stage) {
                try {
                    app(OrderStatusWorkflowService::class)->transition($order, $stage);
                    $this->fail("{$status} order moved back to {$stage}.");
                } catch (ValidationException) {
                    $this->assertSame($status, $order->fresh()->status);
                }
            }
        }
    }

    public function test_moving_back_is_marked_as_a_step_back_and_the_customer_is_not_texted(): void
    {
        $workflow = app(OrderStatusWorkflowService::class);
        $order = $this->order(Order::STATUS_PROCESSING);

        $workflow->transition($order, Order::STAGE_SHIPPING);
        $forward = OrderStatusTransition::query()->where('order_id', $order->id)->latest('id')->firstOrFail();
        $this->assertFalse($forward->isStepBack());
        $this->assertSame(Order::STAGE_SHIPPING, app(OrderStatusSmsService::class)->stageFor($forward));

        $workflow->transition($order->fresh(), Order::STAGE_PROCESSING);
        $back = OrderStatusTransition::query()->where('order_id', $order->id)->latest('id')->firstOrFail();
        $this->assertTrue($back->isStepBack());
        $this->assertNull(app(OrderStatusSmsService::class)->stageFor($back));
    }

    public function test_a_closing_or_first_move_is_never_a_step_back(): void
    {
        $workflow = app(OrderStatusWorkflowService::class);
        $order = $this->order(Order::STATUS_DRAFT);

        $workflow->transition($order, Order::STAGE_CONFIRMED);
        $workflow->transition($order->fresh(), Order::STAGE_CANCELLED, 'Customer cancelled');

        $this->assertSame(
            [false, false],
            OrderStatusTransition::query()->where('order_id', $order->id)->orderBy('id')->get()
                ->map(fn (OrderStatusTransition $transition): bool => $transition->isStepBack())->all(),
        );
    }

    public function test_the_change_status_choices_are_grouped_into_go_back_and_move_forward(): void
    {
        $processing = $this->order(Order::STATUS_PROCESSING);
        $options = $processing->groupedWorkflowStageOptions();

        $this->assertSame([__('Go back to'), __('Move forward to')], array_keys($options));
        $this->assertSame([Order::STAGE_CONFIRMED], array_keys($options[__('Go back to')]));
        $this->assertNotContains(Order::STAGE_CONFIRMED, array_keys($options[__('Move forward to')]));
        $this->assertContains(Order::STAGE_SHIPPING, array_keys($options[__('Move forward to')]));

        // An order that cannot go back keeps the plain list.
        $confirmed = $this->order(Order::STATUS_CONFIRMED)->groupedWorkflowStageOptions();
        $this->assertArrayHasKey(Order::STAGE_PROCESSING, $confirmed);
        $this->assertIsString($confirmed[Order::STAGE_PROCESSING]);
    }

    public function test_the_change_status_action_on_the_order_page_goes_back(): void
    {
        $order = $this->order(Order::STATUS_PROCESSING, CourierBooking::STATUS_IN_TRANSIT);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('changeWorkflowStatus', data: ['stage' => Order::STAGE_PROCESSING])
            ->assertHasNoActionErrors()
            ->assertNotified('Order status updated');

        $this->assertSame(Order::STAGE_PROCESSING, $order->fresh()->workflowStage());

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('changeWorkflowStatus', data: ['stage' => Order::STAGE_CONFIRMED])
            ->assertHasNoActionErrors();

        $this->assertSame(Order::STAGE_CONFIRMED, $order->fresh()->workflowStage());
    }

    public function test_a_parcel_scan_never_moves_an_order_back(): void
    {
        $shipping = $this->order(Order::STATUS_PROCESSING, CourierBooking::STATUS_IN_TRANSIT);

        Livewire::test(ScanParcels::class)
            ->set('code', $shipping->order_number)->call('scan')
            ->set('stage', Order::STAGE_PROCESSING)
            ->call('move')
            ->assertSet('ids', [$shipping->id])
            ->assertCount('skipped', 1);

        $this->assertSame(Order::STAGE_SHIPPING, $shipping->fresh()->workflowStage());
        $this->assertSame(0, $shipping->fresh()->statusTransitions()->count());
    }
}
