<?php

namespace Tests\Feature;

use App\Filament\Pages\FraudChecker;
use App\Models\Company;
use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\CustomerBlacklist;
use App\Models\Order;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class FraudCheckerPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_staff_can_open_the_fraud_checker_but_inventory_staff_cannot(): void
    {
        $this->actingAsRole('sales_staff');
        $this->assertTrue(FraudChecker::canAccess());

        $this->actingAsRole('inventory_staff');
        $this->assertFalse(FraudChecker::canAccess());
    }

    public function test_a_good_courier_history_is_shown_as_safe_with_per_courier_cards(): void
    {
        $this->actingAsRole('sales_staff');
        $this->steadfastProvider();
        Http::fake($this->fakeSteadfastApi(delivered: 22, cancelled: 0));

        $page = Livewire::test(FraudChecker::class)
            ->set('phone', '+8801712345678')
            ->call('check')
            ->assertHasNoErrors()
            ->assertSet('checkedPhone', '01712345678')
            ->assertSee(__('Customer verification result'))
            ->assertSee(__('Safe'))
            ->assertSee(__('Courier-wise performance'))
            ->assertSee(__('Not configured'));

        $summary = $page->instance()->summary();
        $this->assertSame(22, $summary['total']);
        $this->assertSame(22, $summary['success']);
        $this->assertEquals(100, $summary['ratio']);

        $cards = $page->instance()->courierCards();
        $this->assertTrue($cards[CourierProvider::DRIVER_STEADFAST]['configured']);
        $this->assertEquals(100, $cards[CourierProvider::DRIVER_STEADFAST]['ratio']);
        $this->assertFalse($cards[CourierProvider::DRIVER_PATHAO]['configured']);
        $this->assertFalse($cards[CourierProvider::DRIVER_REDX]['configured']);
    }

    public function test_a_success_rate_below_the_review_threshold_is_shown_as_risky(): void
    {
        $this->actingAsRole('sales_staff');
        $this->steadfastProvider();
        Http::fake($this->fakeSteadfastApi(delivered: 1, cancelled: 9));

        $page = Livewire::test(FraudChecker::class)
            ->set('phone', '01712345678')
            ->call('check')
            ->assertSee(__('Risky'));

        $this->assertSame('risky', $page->instance()->verdict()['key']);
    }

    public function test_no_configured_courier_reports_no_history_instead_of_safe(): void
    {
        $this->actingAsRole('sales_staff');
        Http::fake();

        $page = Livewire::test(FraudChecker::class)
            ->set('phone', '01712345678')
            ->call('check')
            ->assertSee(__('No courier history'));

        $this->assertSame('none', $page->instance()->verdict()['key']);
        Http::assertNothingSent();
    }

    public function test_an_invalid_number_is_rejected_without_calling_any_courier(): void
    {
        $this->actingAsRole('sales_staff');
        $this->steadfastProvider();
        Http::fake();

        Livewire::test(FraudChecker::class)
            ->set('phone', '12345')
            ->call('check')
            ->assertHasErrors(['phone'])
            ->assertSet('result', null);

        Http::assertNothingSent();
    }

    public function test_refresh_bypasses_the_cache(): void
    {
        $this->actingAsRole('sales_staff');
        $this->steadfastProvider();
        Http::fake($this->fakeSteadfastApi(delivered: 3, cancelled: 1));

        Livewire::test(FraudChecker::class)
            ->set('phone', '01712345678')
            ->call('check')
            ->call('check')
            ->call('refreshCheck');

        // First check hits the API, the second is cached, refresh re-hits it.
        Http::assertSentCount(2);
    }

    public function test_store_history_and_blacklist_hits_for_the_number_are_shown(): void
    {
        $company = $this->actingAsRole('sales_staff');
        Http::fake();

        $customer = Customer::query()->create(['name' => 'Known Buyer', 'phone' => '+8801712345678', 'address' => 'Dhaka', 'opening_balance' => 0, 'is_active' => true]);
        foreach (['completed', 'completed', 'returned'] as $index => $status) {
            Order::withoutEvents(fn () => Order::query()->create([
                'company_id' => $company->id, 'order_number' => 'FC-'.$index, 'customer_id' => $customer->id, 'customer_name' => $customer->name,
                'order_date' => now(), 'subtotal' => 500, 'total_amount' => 500, 'paid_amount' => 0, 'due_amount' => 500, 'discount' => 0, 'vat' => 0, 'status' => $status,
            ]));
        }
        CustomerBlacklist::query()->create(['company_id' => $company->id, 'phone' => '01712345678', 'reason' => 'Refused three parcels', 'is_active' => true]);

        $page = Livewire::test(FraudChecker::class)
            ->set('phone', '01712345678')
            ->call('check')
            ->assertSee('Known Buyer')
            ->assertSee(__('This number is on your blacklist'))
            ->assertSee('Refused three parcels');

        $history = $page->instance()->storeHistory();
        $this->assertSame(3, $history['total']);
        $this->assertSame(2, $history['completed']);
        $this->assertSame(1, $history['returned']);
    }

    public function test_another_companys_customers_and_blacklist_entries_are_not_shown(): void
    {
        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OTH', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        Customer::query()->create(['name' => 'Other Company Buyer', 'phone' => '01712345678', 'address' => 'Dhaka', 'opening_balance' => 0, 'is_active' => true]);
        CustomerBlacklist::query()->create(['company_id' => $other->id, 'phone' => '01712345678', 'reason' => 'Other company only', 'is_active' => true]);

        $this->actingAsRole('sales_staff');
        Http::fake();

        $page = Livewire::test(FraudChecker::class)
            ->set('phone', '01712345678')
            ->call('check')
            ->assertDontSee('Other Company Buyer')
            ->assertDontSee('Other company only');

        $this->assertNull($page->instance()->storeHistory());
        $this->assertNull($page->instance()->blacklistEntry());
    }

    protected function actingAsRole(string $role): Company
    {
        $company = Company::defaultCompany();
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $user->companies()->sync([$company->getKey() => ['role' => $role, 'is_default' => true]]);
        app(CompanyContext::class)->set($company);
        $this->actingAs($user);

        return $company;
    }

    protected function steadfastProvider(): CourierProvider
    {
        return CourierProvider::query()->create([
            'company_id' => app(CompanyContext::class)->id(),
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'driver' => CourierProvider::DRIVER_STEADFAST,
            'credentials' => ['api_key' => 'test-api-key', 'secret_key' => 'test-secret-key'],
            'settings' => [],
            'is_active' => true,
        ]);
    }

    protected function fakeSteadfastApi(int $delivered, int $cancelled): \Closure
    {
        return function ($request) use ($delivered, $cancelled) {
            if (str_contains($request->url(), 'portal.packzy.com/api/v1/fraud_check/score/')) {
                return Http::response(['total_delivered' => $delivered, 'total_cancelled' => $cancelled]);
            }

            return Http::response('', 404);
        };
    }
}
