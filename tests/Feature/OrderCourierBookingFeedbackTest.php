<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
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
use App\Services\SteadfastCourierClient;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner report (2026-10-05): clicking Book courier did not book and showed
 * nothing. A refused booking (courier API error, customer risk review, ...)
 * raised an error no popup field shows, and a courier's HTTP error was not
 * caught at all. Booking now always says what happened.
 */
class OrderCourierBookingFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private CourierProvider $steadfast;

    private CourierProvider $manual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::defaultCompany();
        app(CompanyContext::class)->set($this->company);

        $user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $user->companies()->sync([$this->company->getKey() => ['role' => 'sales_staff', 'is_default' => true]]);
        $this->actingAs($user);

        $this->steadfast = CourierProvider::query()->create([
            'company_id' => $this->company->getKey(),
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'driver' => CourierProvider::DRIVER_STEADFAST,
            'credentials' => ['api_key' => 'test-api-key', 'secret_key' => 'test-secret-key'],
            'settings' => ['base_url' => SteadfastCourierClient::DEFAULT_BASE_URL],
            'is_active' => true,
            'is_default' => true,
        ]);
        $this->manual = CourierProvider::query()->create([
            'company_id' => $this->company->getKey(),
            'name' => 'In-house Rider',
            'slug' => 'in-house-rider',
            'driver' => CourierProvider::DRIVER_MANUAL,
            'is_active' => true,
        ]);
    }

    private function order(string $phone = '+8801700000000'): Order
    {
        $customer = Customer::query()->create([
            'name' => 'Courier Customer',
            'phone' => $phone,
            'address' => 'Dhaka',
            'opening_balance' => 0,
            'is_active' => true,
        ]);
        $product = Product::query()->firstOrCreate(['sku' => 'BOOK-SKU'], [
            'name' => 'Booking Product',
            'price' => 500,
            'sale_price' => 500,
            'cost_price' => 300,
            'stock' => 0,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);
        StockMovement::query()->create(['product_id' => $product->getKey(), 'type' => 'purchase', 'quantity' => 5]);

        $order = Order::query()->create([
            'customer_id' => $customer->getKey(),
            'order_date' => now()->toDateString(),
            'discount' => 0,
            'vat' => 0,
            'paid_amount' => 0,
            'status' => 'draft',
        ]);
        OrderItem::query()->create(['order_id' => $order->getKey(), 'product_id' => $product->getKey(), 'quantity' => 1, 'unit_price' => 500]);
        $order->update(['status' => 'confirmed']);

        return $order->refresh();
    }

    public function test_a_courier_refusal_is_shown_and_the_popup_stays_open(): void
    {
        $order = $this->order();
        Http::fake([
            SteadfastCourierClient::DEFAULT_BASE_URL.'/create_order' => Http::response([
                'status' => 400,
                'message' => 'Validation failed',
                'errors' => ['recipient_phone' => ['The recipient phone must be 11 characters.']],
            ], 422),
        ]);

        Livewire::test(ListOrders::class)
            ->mountTableAction('bookCourier', $order)
            ->callMountedTableAction()
            ->assertActionMounted(TestAction::make('bookCourier')->table($order));

        Notification::assertNotified(
            Notification::make()
                ->title(__('Courier booking failed'))
                ->body(__(':courier refused the booking: :reason', [
                    'courier' => 'Steadfast',
                    'reason' => 'Validation failed The recipient phone must be 11 characters.',
                ]))
                ->danger()
                ->persistent()
        );
        $this->assertSame(0, CourierBooking::query()->count());
        $this->assertSame(CourierBooking::STATUS_NOT_BOOKED, $order->refresh()->delivery_status);
    }

    public function test_an_unreachable_courier_is_shown(): void
    {
        $order = $this->order();
        Http::fake(fn () => throw new ConnectionException('timed out'));

        Livewire::test(ListOrders::class)
            ->mountTableAction('bookCourier', $order)
            ->callMountedTableAction()
            ->assertActionMounted(TestAction::make('bookCourier')->table($order));

        Notification::assertNotified(__('Courier booking failed'));
        $this->assertSame(0, CourierBooking::query()->count());
    }

    public function test_a_successful_booking_says_so_with_the_tracking_id(): void
    {
        $order = $this->order();
        Http::fake([
            SteadfastCourierClient::DEFAULT_BASE_URL.'/create_order' => Http::response([
                'status' => 200,
                'consignment' => ['consignment_id' => 77, 'tracking_code' => 'SF-TRACK-77', 'status' => 'in_review'],
            ]),
        ]);

        Livewire::test(ListOrders::class)
            ->mountTableAction('bookCourier', $order)
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        Notification::assertNotified(
            Notification::make()
                ->title(__('Courier booked'))
                ->body(__('Tracking ID: :tracking', ['tracking' => 'SF-TRACK-77']))
                ->success()
        );
        $this->assertSame('SF-TRACK-77', CourierBooking::query()->sole()->tracking_id);
    }

    public function test_the_order_page_book_courier_shows_a_refusal_too(): void
    {
        $order = $this->order();
        Http::fake([
            SteadfastCourierClient::DEFAULT_BASE_URL.'/create_order' => Http::response(['status' => 401, 'message' => 'Unauthorized'], 401),
        ]);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->mountAction('bookSteadfast')
            ->setActionData(['courier_provider_id' => $this->steadfast->getKey()])
            ->callMountedAction()
            ->assertActionMounted('bookSteadfast');

        Notification::assertNotified(__('Courier booking failed'));
        $this->assertSame(0, CourierBooking::query()->count());
    }

    public function test_a_bulk_booking_keeps_going_past_a_refused_order_and_names_it(): void
    {
        $refused = $this->order('+8801700000001');
        $booked = $this->order('+8801700000002');
        Http::fake(fn ($request) => $request['invoice'] === $refused->order_number
            ? Http::response(['status' => 400, 'message' => 'Duplicate invoice'], 422)
            : Http::response(['status' => 200, 'consignment' => ['consignment_id' => 78, 'tracking_code' => 'SF-TRACK-78', 'status' => 'in_review']]));

        Livewire::test(ListOrders::class)
            ->callTableBulkAction('bookCourierBulk', [$refused, $booked], ['courier_provider_id' => $this->steadfast->getKey()]);

        $this->assertSame(CourierBooking::STATUS_NOT_BOOKED, $refused->refresh()->delivery_status);
        $this->assertSame($booked->getKey(), CourierBooking::query()->sole()->order_id);
        Notification::assertNotified('1 order(s) booked');
    }

    public function test_the_orders_list_filters_by_upsell_items(): void
    {
        $withUpsell = $this->order('+8801700000003');
        $withoutUpsell = $this->order('+8801700000004');
        $withUpsell->items()->first()->update(['is_upsell' => true]);

        Livewire::test(ListOrders::class)
            ->filterTable('has_upsell', true)
            ->assertCanSeeTableRecords([$withUpsell])
            ->assertCanNotSeeTableRecords([$withoutUpsell])
            ->filterTable('has_upsell', false)
            ->assertCanSeeTableRecords([$withoutUpsell])
            ->assertCanNotSeeTableRecords([$withUpsell]);
    }
}
