<?php

namespace Tests\Feature;

use App\Filament\Reseller\Pages\CreateOrder;
use App\Filament\Reseller\Pages\FraudChecker;
use App\Models\Company;
use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\CustomerBlacklist;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The reseller portal's Fraud Checker page and the fraud-check card on its
 * Create order page: courier history and verdict only, run on the store's
 * own courier accounts, never the store's customer data.
 */
class ResellerFraudCheckerTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $reseller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Fraud Co', 'slug' => 'fraud-co', 'domain' => 'fraud-co.example.test',
            'domain_verified' => true, 'invoice_prefix' => 'FRC', 'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka', 'is_active' => true, 'reseller_module_enabled' => true,
        ])->fresh();
        StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(), 'theme_color' => '#0F766E', 'is_published' => true,
            'new_customer_delivery_advance_enabled' => false, 'reseller_cost_per_unit' => 10,
            'delivery_first_kg_inside' => 70, 'delivery_first_kg_outside' => 110, 'delivery_additional_per_kg' => 20,
        ]);
        app(CompanyContext::class)->set($this->company);

        $this->reseller = new Customer([
            'name' => 'Reseller One', 'phone' => '01711000001', 'reseller_status' => 'approved',
            'reseller_slug' => 'r-'.Str::random(6), 'business_name' => 'Shop', 'is_active' => true,
        ]);
        $this->reseller->company_id = $this->company->getKey();
        $this->reseller->forceFill(['password' => Hash::make('secret-password')])->save();
    }

    public function test_approved_resellers_see_the_page_in_the_portal_menu_and_others_cannot_open_it(): void
    {
        $this->actingAs($this->reseller, 'customer');
        $this->get('http://reseller.fraud-co.example.test/portal/orders')->assertOk()->assertSee('href="http://reseller.fraud-co.example.test/portal/fraud-checker"', false);
        $this->get('http://reseller.fraud-co.example.test/portal/fraud-checker')->assertOk()->assertSee(__('Fraud Checker'));

        $this->reseller->update(['reseller_status' => 'pending']);
        $this->get('http://reseller.fraud-co.example.test/portal/fraud-checker')->assertForbidden();
    }

    public function test_a_reseller_sees_the_courier_verdict_but_not_the_stores_customer_data(): void
    {
        $this->steadfastProvider($this->company);
        Http::fake($this->fakeSteadfastApi(delivered: 1, cancelled: 9));
        Customer::query()->create(['name' => 'Store Private Buyer', 'phone' => '01712345678', 'address' => 'Dhaka', 'opening_balance' => 0, 'is_active' => true]);
        CustomerBlacklist::query()->create(['company_id' => $this->company->getKey(), 'phone' => '01712345678', 'reason' => 'Internal blacklist note', 'is_active' => true]);
        $this->asPortal();

        $page = Livewire::test(FraudChecker::class)
            ->set('phone', '01712345678')
            ->call('check')
            ->assertHasNoErrors()
            ->assertSee(__('Risky'))
            ->assertSee('Steadfast')
            ->assertDontSee('Store Private Buyer')
            ->assertDontSee('Internal blacklist note')
            ->assertDontSee(__('Your store history'))
            ->assertDontSee(__('Not configured'))
            ->assertDontSee(__('Refresh live data'));

        $this->assertSame('risky', $page->instance()->report()->verdict()['key']);
    }

    public function test_a_reseller_cannot_skip_the_cache(): void
    {
        $this->steadfastProvider($this->company);
        Http::fake($this->fakeSteadfastApi(delivered: 5, cancelled: 0));
        $this->asPortal();

        Livewire::test(FraudChecker::class)
            ->set('phone', '01712345678')
            ->call('check')
            ->call('refreshCheck')
            ->call('check', true);

        Http::assertSentCount(1);
    }

    public function test_only_the_resellers_own_store_couriers_are_used(): void
    {
        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OTH', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        $this->steadfastProvider($other);
        Http::fake();
        $this->asPortal();

        Livewire::test(FraudChecker::class)
            ->set('phone', '01712345678')
            ->call('check')
            ->assertSee(__('No courier history'));

        Http::assertNothingSent();
    }

    public function test_the_create_order_page_shows_the_fraud_card_for_the_typed_phone(): void
    {
        $this->steadfastProvider($this->company);
        Http::fake($this->fakeSteadfastApi(delivered: 20, cancelled: 0));
        $this->asPortal();

        Livewire::test(CreateOrder::class)
            ->set('data.phone', '01712345678')
            ->assertSet('fraudPhone', '01712345678')
            ->assertSee(__('Safe'))
            ->assertSee('20/20')
            ->assertSee(__('Full report'))
            ->assertSee('fraud-checker?phone=01712345678', false)
            // A half-typed number clears the card instead of checking it.
            ->set('data.phone', '017')
            ->assertSet('fraudResult', null)
            ->assertDontSee(__('Full report'));

        Http::assertSentCount(1);
    }

    public function test_the_full_report_link_opens_the_page_already_checked(): void
    {
        $this->steadfastProvider($this->company);
        Http::fake($this->fakeSteadfastApi(delivered: 3, cancelled: 0));
        $this->asPortal();

        Livewire::withQueryParams(['phone' => '01712345678'])
            ->test(FraudChecker::class)
            ->assertSet('checkedPhone', '01712345678')
            ->assertSee(__('Customer verification result'));
    }

    protected function asPortal(): void
    {
        app(CompanyContext::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $this->actingAs($this->reseller, 'customer');
    }

    protected function steadfastProvider(Company $company): CourierProvider
    {
        return CourierProvider::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Steadfast',
            'slug' => 'steadfast-'.$company->getKey(),
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
