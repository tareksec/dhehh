<?php

namespace Tests\Feature;

use App\Filament\Pages\CourierHandoverSheet;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CourierHandoverSheetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** AI inbox control plane, phase 7 (owner answer O8): one courier, one day, count and cash, per-parcel list. */
class CourierHandoverSheetTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private CourierProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::query()->create(['name' => 'Sheet Co', 'slug' => 'sheet-co', 'invoice_prefix' => 'SH', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($this->company);
        $this->provider = $this->provider('steadfast');
    }

    private function provider(string $slug): CourierProvider
    {
        return CourierProvider::query()->create(['name' => ucfirst($slug), 'slug' => $slug, 'driver' => CourierProvider::DRIVER_MANUAL, 'settings' => [], 'is_active' => true]);
    }

    private function booking(CourierProvider $provider, string $when, float $cod, string $status = CourierBooking::STATUS_BOOKED, string $name = 'Rina'): CourierBooking
    {
        $customer = Customer::query()->create(['name' => $name, 'phone' => '01711000000', 'opening_balance' => 0, 'is_active' => true]);
        $order = Order::query()->create(['customer_id' => $customer->id, 'customer_name' => $name, 'status' => Order::STATUS_CONFIRMED, 'source' => Order::SOURCE_ADMIN]);

        return CourierBooking::query()->create([
            'courier_provider_id' => $provider->id, 'order_id' => $order->id, 'tracking_id' => 'TRK'.$order->id, 'provider_reference' => 'CON'.$order->id,
            'recipient_name' => $name, 'recipient_phone' => '01711000000', 'recipient_address' => 'Mirpur, Dhaka', 'cod_amount' => $cod,
            'status' => $status, 'booked_at' => Carbon::parse($when, 'Asia/Dhaka')->setTimezone(config('app.timezone')),
        ]);
    }

    public function test_it_lists_one_couriers_parcels_of_one_dhaka_day_with_count_and_cash(): void
    {
        $this->booking($this->provider, '2026-10-19 09:30:00', 1200);
        $this->booking($this->provider, '2026-10-19 23:50:00', 800.50, CourierBooking::STATUS_PICKED_UP);
        $this->booking($this->provider, '2026-10-18 23:59:00', 999);   // day before
        $this->booking($this->provider, '2026-10-20 00:01:00', 999);   // day after
        $this->booking($this->provider, '2026-10-19 10:00:00', 999, CourierBooking::STATUS_CANCELLED);
        $this->booking($this->provider, '2026-10-19 10:00:00', 999, CourierBooking::STATUS_FAILED);
        $this->booking($this->provider, '2026-10-19 10:00:00', 999, CourierBooking::STATUS_BOOKING_PENDING);
        $this->booking($this->provider('pathao'), '2026-10-19 11:00:00', 999);

        $sheet = app(CourierHandoverSheetService::class)->build($this->provider, '2026-10-19');

        $this->assertSame(2, $sheet['parcels']);
        $this->assertSame(2000.50, $sheet['cod_total']);
        $first = $sheet['rows']->first();
        $this->assertSame(['Rina', '01711000000', 'Mirpur, Dhaka', 1200.0], [$first['name'], $first['phone'], $first['address'], $first['cod']]);
        $this->assertNotNull($first['invoice']);
        $this->assertStringStartsWith('CON', $first['consignment']);
        $this->assertStringStartsWith('TRK', $first['tracking']);
    }

    public function test_another_companys_bookings_never_appear(): void
    {
        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        $theirs = $this->provider('steadfast-other');
        $this->booking($theirs, '2026-10-19 09:00:00', 500);
        app(CompanyContext::class)->set($this->company);

        $this->assertSame(0, app(CourierHandoverSheetService::class)->build($this->provider, '2026-10-19')['parcels']);
    }

    public function test_the_page_shows_the_totals_and_the_print_sheet_opens(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))->withSession(['current_company_id' => $this->company->id]);
        $booking = $this->booking($this->provider, now('Asia/Dhaka')->setTime(10, 0)->toDateTimeString(), 1500);

        Livewire::test(CourierHandoverSheet::class)->assertOk()
            ->assertSet('providerId', $this->provider->id)
            ->assertSee($booking->order->order_number)
            ->assertSee('Mirpur, Dhaka');

        $this->get(route('courier.handover-sheet', ['provider' => $this->provider->id, 'date' => now('Asia/Dhaka')->toDateString()]))
            ->assertOk()
            ->assertSee($booking->order->order_number)
            ->assertSee('CON'.$booking->order_id)
            ->assertDontSee('Rider');
    }

    public function test_the_print_sheet_needs_a_signed_in_user_and_the_right_company(): void
    {
        $this->get(route('courier.handover-sheet', ['provider' => $this->provider->id]))->assertRedirect();

        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        $theirs = $this->provider('theirs');
        app(CompanyContext::class)->set($this->company);

        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))->withSession(['current_company_id' => $this->company->id]);
        $this->get(route('courier.handover-sheet', ['provider' => $theirs->id]))->assertNotFound();
    }
}
