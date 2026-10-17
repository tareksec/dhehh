<?php

namespace Tests\Feature;

use App\Filament\Resources\CourierBookings\Pages\ViewCourierBooking;
use App\Filament\Resources\CourierWebhookLogs\Pages\ListCourierWebhookLogs;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Models\CourierWebhookLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\SteadfastCourierClient;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner audit request (2026-10-06): order status vs courier status after
 * booking, booking / sticker / API calls, and the callback URL ("no courier
 * callback has arrived yet"). Runs the whole Steadfast flow the way the
 * courier drives it.
 */
class CourierStatusFlowAuditTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'audit-token-123';

    private Company $company;

    private CourierProvider $steadfast;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::defaultCompany();
        app(CompanyContext::class)->set($this->company);

        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $user->companies()->sync([$this->company->getKey() => ['role' => 'super_admin', 'is_default' => true]]);
        $this->actingAs($user);

        $this->steadfast = CourierProvider::query()->create([
            'company_id' => $this->company->getKey(),
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'driver' => CourierProvider::DRIVER_STEADFAST,
            'credentials' => ['api_key' => 'k', 'secret_key' => 's', 'webhook_secret' => self::TOKEN],
            'settings' => [],
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function confirmedOrder(): Order
    {
        $customer = Customer::query()->create(['name' => 'Audit Buyer', 'phone' => '01700000000', 'address' => 'Dhaka', 'opening_balance' => 0, 'is_active' => true]);
        $product = Product::query()->create([
            'name' => 'Audit Product', 'sku' => 'AUDIT', 'price' => 500, 'sale_price' => 500, 'cost_price' => 300,
            'stock' => 0, 'unit' => 'pcs', 'reorder_level' => 1, 'vat_rate' => 0, 'is_active' => true, 'status' => Product::STATUS_AVAILABLE,
        ]);
        StockMovement::query()->create(['product_id' => $product->getKey(), 'type' => 'purchase', 'quantity' => 5]);
        $order = Order::query()->create(['customer_id' => $customer->getKey(), 'order_date' => now()->toDateString(), 'status' => 'draft']);
        OrderItem::query()->create(['order_id' => $order->getKey(), 'product_id' => $product->getKey(), 'quantity' => 1, 'unit_price' => 500]);
        $order->update(['status' => Order::STATUS_CONFIRMED]);

        return $order->refresh();
    }

    private function book(Order $order): void
    {
        Http::fake([
            SteadfastCourierClient::DEFAULT_BASE_URL.'/create_order' => Http::response([
                'status' => 200,
                'message' => 'Consignment has been created successfully.',
                'consignment' => ['consignment_id' => 1424107, 'invoice' => $order->order_number, 'tracking_code' => '15BAEB8A', 'status' => 'in_review'],
            ]),
        ]);

        Livewire::test(ListOrders::class)->mountTableAction('bookCourier', $order)->callMountedTableAction();
    }

    /** The JSON Steadfast's webhook page sends for a status change. */
    private function sendCallback(array $payload, ?string $token = self::TOKEN)
    {
        return $this->call('POST', route('couriers.webhook', $this->steadfast), [], [], [], array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => $token ? 'Bearer '.$token : null,
        ]), json_encode($payload));
    }

    private function statusPayload(Order $order, string $status): array
    {
        return [
            'notification_type' => 'delivery_status',
            'consignment_id' => 1424107,
            'invoice' => $order->order_number,
            'cod_amount' => 500,
            'status' => $status,
            'delivery_charge' => 60,
            'tracking_message' => 'Status: '.$status,
            'updated_at' => now()->toDateTimeString(),
        ];
    }

    public function test_booking_never_marks_the_order_delivered_and_callbacks_move_both_statuses(): void
    {
        $order = $this->confirmedOrder();

        $this->book($order);
        $order->refresh();
        $this->assertSame(Order::STATUS_CONFIRMED, $order->status);
        $this->assertSame(CourierBooking::STATUS_BOOKING_PENDING, $order->delivery_status);

        $this->sendCallback($this->statusPayload($order, 'Pending'))
            ->assertOk()
            ->assertExactJson(['status' => 'success', 'message' => 'Webhook received successfully.']);
        $order->refresh();
        $this->assertSame(Order::STATUS_PROCESSING, $order->status);
        $this->assertSame(CourierBooking::STATUS_IN_TRANSIT, $order->delivery_status);

        $this->sendCallback($this->statusPayload($order, 'Delivered'))->assertOk();
        $order->refresh();
        $this->assertSame(Order::STATUS_COMPLETED, $order->status);
        $this->assertSame(CourierBooking::STATUS_DELIVERED, $order->delivery_status);
        $this->assertSame(2, CourierWebhookLog::query()->where('status', 'processed')->count());
    }

    public function test_the_quick_buttons_after_booking_say_mark_delivered_not_delivered(): void
    {
        $order = $this->confirmedOrder();
        $this->book($order);

        Livewire::test(ListOrders::class)
            ->assertTableActionHasLabel('markDelivered', __('Mark delivered'), $order->refresh())
            ->assertTableActionHasLabel('markReturned', __('Mark returned'), $order);
    }

    public function test_a_callback_without_a_token_is_rejected_and_shown_in_the_webhook_logs(): void
    {
        $order = $this->confirmedOrder();
        $this->book($order);

        $this->sendCallback($this->statusPayload($order, 'Delivered'), token: null)->assertUnauthorized();
        $this->sendCallback($this->statusPayload($order, 'Delivered'), token: null)->assertUnauthorized();
        $this->sendCallback($this->statusPayload($order, 'Pending'), token: 'wrong-token')->assertUnauthorized();

        $rejected = CourierWebhookLog::query()->where('status', 'rejected')->orderBy('id')->get();
        $this->assertCount(2, $rejected); // the repeated call is logged once
        $this->assertStringContainsString('had no Authorization token', $rejected[0]->error);
        $this->assertStringContainsString('does not match', $rejected[1]->error);
        $this->assertSame(CourierBooking::STATUS_BOOKING_PENDING, $order->refresh()->delivery_status);
    }

    public function test_a_provider_without_a_saved_token_says_so(): void
    {
        $this->steadfast->update(['credentials' => ['api_key' => 'k', 'secret_key' => 's']]);

        $this->sendCallback(['notification_type' => 'delivery_status', 'consignment_id' => 1, 'status' => 'Delivered'])->assertUnauthorized();

        $this->assertStringContainsString('no Webhook Auth Token is saved', CourierWebhookLog::query()->sole()->error);
    }

    public function test_a_callback_stuck_without_a_queue_worker_is_flagged_and_can_be_processed_now(): void
    {
        $order = $this->confirmedOrder();
        $this->book($order);
        Queue::fake();

        $this->sendCallback($this->statusPayload($order, 'Delivered'))->assertOk();
        $log = CourierWebhookLog::query()->sole();
        $this->assertSame('pending', $log->status);
        $log->forceFill(['created_at' => now()->subMinutes(10)])->save();

        Livewire::test(ListCourierWebhookLogs::class)
            ->assertSee(__('Process now'))
            ->assertSee('queue worker does not seem to be running')
            ->callTableAction('processNow', $log);

        Notification::assertNotified(__('Callback processed'));
        $this->assertSame('processed', $log->refresh()->status);
        $this->assertSame(CourierBooking::STATUS_DELIVERED, $order->refresh()->delivery_status);
    }

    public function test_a_failed_status_sync_is_reported(): void
    {
        $order = $this->confirmedOrder();
        $this->book($order);
        $booking = CourierBooking::query()->sole();
        Http::fake([
            SteadfastCourierClient::DEFAULT_BASE_URL.'/status_by_trackingcode/*' => Http::response(['status' => 401, 'message' => 'Unauthorized'], 401),
        ]);

        Livewire::test(ViewCourierBooking::class, ['record' => $booking->getRouteKey()])
            ->callAction('syncSteadfast');

        Notification::assertNotified(__('Courier action failed'));
        $this->assertSame(CourierBooking::STATUS_BOOKING_PENDING, $booking->refresh()->status);
    }
}
