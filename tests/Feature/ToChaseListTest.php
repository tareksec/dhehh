<?php

namespace Tests\Feature;

use App\Filament\Pages\ToChase;
use App\Models\ChaseMark;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Order;
use App\Models\StorefrontCartRecord;
use App\Models\StorefrontPayment;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Crm\ToChaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** AI inbox control plane, phase 7 (owner answer O9): carts, unpaid checkouts and leads, minus anyone who ordered. */
class ToChaseListTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::query()->create(['name' => 'Chase Co', 'slug' => 'chase-co', 'invoice_prefix' => 'CH', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($this->company);
    }

    private function cart(string $phone, string $status = StorefrontCartRecord::STATUS_ACTIVE, int $daysAgo = 1, string $name = 'Cart Buyer'): StorefrontCartRecord
    {
        return StorefrontCartRecord::query()->create([
            'session_id' => 's-'.uniqid(), 'phone' => $phone, 'customer_name' => $name, 'items' => [['quantity' => 2]], 'subtotal' => 900,
            'status' => $status, 'last_activity_at' => now()->subDays($daysAgo),
        ]);
    }

    private function unpaidCheckout(string $phone, int $daysAgo = 1): StorefrontPayment
    {
        $payment = StorefrontPayment::query()->create([
            'gateway' => 'zinipay', 'purpose' => StorefrontPayment::PURPOSE_CHECKOUT_ADVANCE, 'amount' => 150, 'status' => StorefrontPayment::STATUS_PENDING,
            'checkout_data' => ['phone' => $phone, 'name' => 'Checkout Buyer'],
        ]);
        $payment->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();

        return $payment;
    }

    private function lead(string $phone, string $status = 'new', int $daysAgo = 1): Lead
    {
        $lead = Lead::query()->create(['name' => 'Lead Buyer', 'phone' => $phone, 'status' => $status, 'source' => 'website', 'interest' => 'Wholesale lamps']);
        $lead->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();

        return $lead;
    }

    private function phones(?array $sources = null, int $days = 7, bool $contacted = false): array
    {
        return app(ToChaseService::class)->rows($days, $sources, $contacted)->pluck('phone')->sort()->values()->all();
    }

    public function test_all_three_sources_are_listed_and_a_finished_cart_is_not(): void
    {
        $this->cart('01711000001');
        $this->cart('01711000002', StorefrontCartRecord::STATUS_CONVERTED);
        $this->unpaidCheckout('01711000003');
        $this->lead('01711000004');
        $this->lead('01711000005', 'won');

        $this->assertSame(['8801711000001', '8801711000003', '8801711000004'], $this->phones());
        $this->assertSame(['8801711000003'], $this->phones(['checkout']));
    }

    public function test_the_look_back_days_apply_and_one_phone_is_one_row(): void
    {
        $this->cart('01711000001', daysAgo: 20);
        $this->lead('01711000002', daysAgo: 3);
        $this->cart('01711000002', daysAgo: 2);

        $this->assertSame(['8801711000002'], $this->phones(days: 7));
        $this->assertSame(['8801711000001', '8801711000002'], $this->phones(days: 30));

        $row = app(ToChaseService::class)->rows(7)->sole();
        $this->assertEqualsCanonicalizing(['cart', 'lead'], $row['sources']);
    }

    public function test_someone_who_ordered_after_their_last_sign_of_interest_is_left_out(): void
    {
        $this->cart('01711000001', daysAgo: 3);
        $this->cart('01711000002', daysAgo: 3);
        $buyer = Customer::query()->create(['name' => 'Buyer', 'phone' => '+8801711000001', 'opening_balance' => 0, 'is_active' => true]);
        Order::query()->create(['customer_id' => $buyer->id, 'customer_name' => 'Buyer', 'status' => Order::STATUS_CONFIRMED, 'source' => Order::SOURCE_ADMIN]);
        $cancelled = Customer::query()->create(['name' => 'Cancelled', 'phone' => '01711000002', 'opening_balance' => 0, 'is_active' => true]);
        Order::query()->create(['customer_id' => $cancelled->id, 'customer_name' => 'Cancelled', 'status' => Order::STATUS_CANCELLED, 'source' => Order::SOURCE_ADMIN]);

        $this->assertSame(['8801711000002'], $this->phones());
    }

    public function test_a_contacted_person_is_hidden_until_they_show_interest_again(): void
    {
        $this->cart('01711000001', daysAgo: 2);
        $service = app(ToChaseService::class);

        $service->markContacted('01711000001');
        $this->assertSame([], $this->phones());
        $this->assertSame(['8801711000001'], $this->phones(contacted: true));

        $this->travel(1)->minute();
        $this->cart('01711000001', daysAgo: 0); // a new cart after the call
        $this->assertSame(['8801711000001'], $this->phones());

        $service->unmarkContacted('01711000001');
        $this->assertSame(0, ChaseMark::query()->count());
    }

    public function test_the_page_lists_people_and_marks_them_contacted(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        $this->lead('01711000004');

        Livewire::test(ToChase::class)->assertOk()
            ->assertSee('Lead Buyer')
            ->assertSee('8801711000004')
            ->call('markContacted', '01711000004')
            ->assertDontSee('Lead Buyer');

        $this->assertSame(1, ChaseMark::query()->count());
    }

    public function test_another_companys_people_never_appear(): void
    {
        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        $this->cart('01711000009');
        $this->lead('01711000008');
        app(ToChaseService::class)->markContacted('01711000007');
        app(CompanyContext::class)->set($this->company);

        $this->assertSame([], $this->phones());
        $this->assertSame(0, ChaseMark::query()->count());
    }

    public function test_only_people_who_can_view_the_crm_may_open_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'inventory_staff', 'is_active' => true]));
        $this->assertFalse(ToChase::canAccess());
    }
}
