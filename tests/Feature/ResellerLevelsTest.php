<?php

namespace Tests\Feature;

use App\Filament\Reseller\Pages\Dashboard;
use App\Filament\Reseller\Pages\Withdrawals;
use App\Filament\Resources\ResellerLevels\Pages\ManageResellerLevels;
use App\Filament\Resources\ResellerLevels\Pages\TrashedResellerLevels;
use App\Filament\Resources\ResellerLevels\ResellerLevelResource;
use App\Filament\Resources\Resellers\Pages\EditReseller;
use App\Filament\Resources\Resellers\Pages\ListResellers;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ResellerDepositEntry;
use App\Models\ResellerLevel;
use App\Models\ResellerProduct;
use App\Models\StockMovement;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\OrderStatusWorkflowService;
use App\Services\ResellerOrderPlacementService;
use App\Services\ResellerOwnCourierService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reseller levels (owner decisions 2026-10-16): the admin makes up the levels,
 * gives each reseller a level by hand, the reseller sees the level name, and a
 * level may carry a lower return charge and a lower minimum withdrawal. An amount
 * a level leaves empty falls back to the company's amount from Reseller Settings.
 */
class ResellerLevelsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Company $other;

    protected Customer $reseller;

    protected Product $ring;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('Level Co', 'level-co');
        $this->other = $this->makeCompany('Other Level Co', 'other-level-co');
        app(CompanyContext::class)->set($this->company);
        $this->reseller = $this->makeReseller($this->company, '01711000001');
        $this->ring = $this->pickProduct($this->company, $this->reseller);
        Queue::fake();
    }

    // ---------------------------------------------------------- the amounts

    public function test_a_level_with_its_own_return_charge_lowers_the_charge_of_a_returned_order(): void
    {
        $silver = $this->level($this->company, 'Silver', returnCharge: 20);
        $this->reseller->update(['reseller_level_id' => $silver->getKey()]);

        $order = $this->placeStoreOrder();
        $this->returnOrder($order);

        $this->assertSame(20.0, app(ResellerOwnCourierService::class)->returnChargeOf($order->fresh()));
    }

    public function test_a_level_that_sets_nothing_or_no_level_uses_the_company_charge_and_zero_means_free(): void
    {
        $plain = $this->level($this->company, 'Plain');
        $free = $this->level($this->company, 'Gold', returnCharge: 0);
        $service = app(ResellerOwnCourierService::class);

        // No level at all: the company's 50.
        $first = $this->placeStoreOrder('01722000001');
        $this->returnOrder($first);
        $this->assertSame(50.0, $service->returnChargeOf($first->fresh()));

        // A level that leaves the charge empty: still the company's 50.
        $this->reseller->update(['reseller_level_id' => $plain->getKey()]);
        $second = $this->placeStoreOrder('01722000002');
        $this->returnOrder($second);
        $this->assertSame(50.0, $service->returnChargeOf($second->fresh()));

        // A level that sets 0: returns cost nothing, and no charge row is written.
        $this->reseller->update(['reseller_level_id' => $free->getKey()]);
        $third = $this->placeStoreOrder('01722000003');
        $this->returnOrder($third);
        $this->assertSame(0.0, $service->returnChargeOf($third->fresh()));
        $this->assertSame(2, ResellerDepositEntry::query()->where('type', ResellerDepositEntry::TYPE_RETURN_CHARGE)->count());
    }

    public function test_the_minimum_withdrawal_follows_the_level_then_the_company(): void
    {
        $company = $this->company->fresh();
        StorefrontSetting::query()->where('company_id', $company->getKey())->update(['reseller_min_withdrawal' => 500]);
        $this->assertSame(500.0, $this->reseller->fresh()->resellerMinWithdrawal());

        $gold = $this->level($this->company, 'Gold', minWithdrawal: 100);
        $this->reseller->update(['reseller_level_id' => $gold->getKey()]);
        $this->assertSame(100.0, $this->reseller->fresh()->resellerMinWithdrawal());

        // The portal's Withdrawals page shows and enforces the reseller's own minimum.
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        app(CompanyContext::class)->set($this->company->fresh());
        $this->actingAs($this->reseller->fresh(), 'customer');
        $this->assertSame(100.0, Livewire::test(Withdrawals::class)->instance()->minimumWithdrawal());

        $empty = $this->level($this->company, 'Bronze');
        $this->reseller->update(['reseller_level_id' => $empty->getKey()]);
        $this->assertSame(500.0, $this->reseller->fresh()->resellerMinWithdrawal());
    }

    // ------------------------------------------------------------- the admin

    public function test_staff_make_levels_in_one_list_and_each_company_has_its_own(): void
    {
        $this->signInAdmin();
        $this->level($this->other, 'Theirs', returnCharge: 5);

        Livewire::test(ManageResellerLevels::class)
            ->assertSee('No reseller levels yet')
            ->callAction('create', ['name' => 'Silver', 'sort_order' => 2, 'return_charge' => '20', 'min_withdrawal' => null])
            ->assertHasNoActionErrors()
            ->callAction('create', ['name' => 'Bronze', 'sort_order' => 1])
            ->assertHasNoActionErrors()
            ->assertSee('Silver')
            ->assertSee('Bronze')
            ->assertDontSee('Theirs');

        $silver = ResellerLevel::query()->where('name', 'Silver')->sole();
        $this->assertSame($this->company->getKey(), $silver->company_id);
        $this->assertSame('20.00', $silver->return_charge);
        $this->assertNull($silver->min_withdrawal);
        $this->assertSame(['Bronze', 'Silver'], ResellerLevel::query()->orderBy('sort_order')->pluck('name')->all());
    }

    public function test_a_level_name_is_unique_inside_one_company_only(): void
    {
        $this->signInAdmin();
        $this->level($this->other, 'Gold');
        $this->level($this->company, 'Silver');

        Livewire::test(ManageResellerLevels::class)
            ->callAction('create', ['name' => 'Silver', 'sort_order' => 0])
            ->assertHasActionErrors(['name' => 'unique']);

        // The same name in another company is fine.
        Livewire::test(ManageResellerLevels::class)
            ->callAction('create', ['name' => 'Gold', 'sort_order' => 0])
            ->assertHasNoActionErrors();
        $this->assertSame(2, ResellerLevel::withoutGlobalScopes()->where('name', 'Gold')->count());
    }

    public function test_deleting_a_level_puts_its_resellers_back_on_the_company_amounts_and_changes_no_money(): void
    {
        $this->signInAdmin();
        $silver = $this->level($this->company, 'Silver', returnCharge: 20);
        $this->reseller->update(['reseller_level_id' => $silver->getKey()]);

        Livewire::test(ManageResellerLevels::class)
            ->callTableAction('delete', $silver, data: ['confirmation' => 'Silver'])
            ->assertHasNoTableActionErrors();

        // Delete = Trash (plan T4b): the level is kept, but it stops applying.
        $this->assertTrue(ResellerLevel::withoutGlobalScopes()->withTrashed()->findOrFail($silver->getKey())->trashed());
        $this->assertNull(ResellerLevel::query()->find($silver->getKey()));
        $this->assertSame($silver->getKey(), $this->reseller->fresh()->reseller_level_id);
        $this->assertNull($this->reseller->fresh()->resellerLevel);
        $this->assertSame(50.0, $this->reseller->fresh()->resellerReturnCharge());
        $this->assertSame('approved', $this->reseller->fresh()->reseller_status);

        // Deleting it permanently clears the link, as a hard delete always did.
        ResellerLevel::withoutGlobalScopes()->withTrashed()->findOrFail($silver->getKey())->forceDelete();
        $this->assertNull($this->reseller->fresh()->reseller_level_id);
        $this->assertSame(50.0, $this->reseller->fresh()->resellerReturnCharge());
    }

    public function test_a_level_nobody_has_goes_to_trash_too(): void
    {
        $this->signInAdmin();
        $bronze = $this->level($this->company, 'Bronze');

        // Its delete modal has no "resellers go back to the company amounts" line, which used to fail the whole request.
        $component = Livewire::test(ManageResellerLevels::class)->mountTableAction('delete', $bronze);
        $description = (string) $component->instance()->getMountedAction()->getModalDescription();
        $this->assertStringContainsString('moved to Trash', $description);
        $this->assertStringNotContainsString('go back to the company amounts', $description);

        $component
            ->setTableActionData(['confirmation' => 'Bronze'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSoftDeleted($bronze);
    }

    public function test_a_level_in_trash_stops_applying_keeps_its_name_and_applies_again_when_restored(): void
    {
        $this->signInAdmin();
        StorefrontSetting::query()->where('company_id', $this->company->getKey())->update(['reseller_min_withdrawal' => 500]);
        $silver = $this->level($this->company, 'Silver', returnCharge: 20, minWithdrawal: 100);
        $this->reseller->update(['reseller_level_id' => $silver->getKey()]);
        $this->assertSame(20.0, $this->reseller->fresh()->resellerReturnCharge());
        $this->assertSame(100.0, $this->reseller->fresh()->resellerMinWithdrawal());

        $silver->delete();

        // While it is in Trash the reseller uses the company amounts, but keeps the link...
        $reseller = $this->reseller->fresh();
        $this->assertSame($silver->getKey(), $reseller->reseller_level_id);
        $this->assertSame(50.0, $reseller->resellerReturnCharge());
        $this->assertSame(500.0, $reseller->resellerMinWithdrawal());

        // ...the level is not offered for another reseller, and its name stays reserved.
        $names = collect(ResellerLevel::query()->pluck('name'));
        $this->assertFalse($names->contains('Silver'));
        Livewire::test(ManageResellerLevels::class)
            ->callAction('create', ['name' => 'Silver', 'sort_order' => 0])
            ->assertHasActionErrors(['name' => 'unique']);

        // Restore makes it apply again, for the same reseller, with nothing else changed.
        Livewire::test(TrashedResellerLevels::class)
            ->assertCanSeeTableRecords([$silver])
            ->callTableAction('restore', $silver)
            ->assertHasNoTableActionErrors();

        $this->assertSame(20.0, $this->reseller->fresh()->resellerReturnCharge());
        $this->assertSame(100.0, $this->reseller->fresh()->resellerMinWithdrawal());
    }

    public function test_a_reseller_is_given_a_level_on_their_edit_page_and_only_one_of_their_own_company(): void
    {
        $this->signInAdmin();
        $silver = $this->level($this->company, 'Silver');
        $theirs = $this->level($this->other, 'Theirs');

        Livewire::test(EditReseller::class, ['record' => $this->reseller->getKey()])
            ->fillForm(['reseller_level_id' => $silver->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($silver->getKey(), $this->reseller->fresh()->reseller_level_id);

        Livewire::test(EditReseller::class, ['record' => $this->reseller->getKey()])
            ->fillForm(['reseller_level_id' => $theirs->getKey()])
            ->call('save')
            ->assertHasFormErrors(['reseller_level_id']);
        $this->assertSame($silver->getKey(), $this->reseller->fresh()->reseller_level_id);

        // "No level" takes it away again.
        Livewire::test(EditReseller::class, ['record' => $this->reseller->getKey()])
            ->fillForm(['reseller_level_id' => null])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertNull($this->reseller->fresh()->reseller_level_id);
    }

    public function test_several_resellers_get_a_level_at_once_and_it_can_be_filtered_on(): void
    {
        $this->signInAdmin();
        $silver = $this->level($this->company, 'Silver');
        $second = $this->makeReseller($this->company, '01711000002');
        $third = $this->makeReseller($this->company, '01711000003');

        Livewire::test(ListResellers::class)
            ->callTableBulkAction('setLevel', [$this->reseller, $second], ['reseller_level_id' => $silver->getKey()]);

        $this->assertSame($silver->getKey(), $this->reseller->fresh()->reseller_level_id);
        $this->assertSame($silver->getKey(), $second->fresh()->reseller_level_id);
        $this->assertNull($third->fresh()->reseller_level_id);

        Livewire::test(ListResellers::class)
            ->filterTable('reseller_level_id', $silver->getKey())
            ->assertCanSeeTableRecords([$this->reseller, $second])
            ->assertCanNotSeeTableRecords([$third]);

        // Choosing "No level" clears it for the chosen resellers.
        Livewire::test(ListResellers::class)
            ->callTableBulkAction('setLevel', [$second], ['reseller_level_id' => null]);
        $this->assertNull($second->fresh()->reseller_level_id);
    }

    public function test_the_levels_page_is_closed_without_the_reseller_module_or_the_right(): void
    {
        $this->signInAdmin();
        $this->assertTrue(ResellerLevelResource::canViewAny());

        $this->company->forceFill(['reseller_module_enabled' => false])->save();
        app(CompanyContext::class)->set($this->company->fresh());
        $this->assertFalse(ResellerLevelResource::canViewAny());

        $this->company->forceFill(['reseller_module_enabled' => true])->save();
        app(CompanyContext::class)->set($this->company->fresh());
        $this->actingAs(User::factory()->create(['role' => 'viewer', 'is_active' => true]));
        $this->assertFalse(ResellerLevelResource::canViewAny());
    }

    // ---------------------------------------------------------- the portal

    public function test_the_reseller_sees_their_level_name_on_the_dashboard_and_nothing_without_one(): void
    {
        $silver = $this->level($this->company, 'Silver Star');

        $this->assertStringNotContainsString('data-reseller-level', $this->dashboardHtml());

        $this->reseller->update(['reseller_level_id' => $silver->getKey()]);
        $html = $this->dashboardHtml();

        $this->assertStringContainsString('data-reseller-level', $html);
        $this->assertStringContainsString('Silver Star', $html);
    }

    // -------------------------------------------------------------- helpers

    protected function dashboardHtml(): string
    {
        app(CompanyContext::class)->set($this->company->fresh());
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $this->actingAs($this->reseller->fresh(), 'customer');

        return Livewire::test(Dashboard::class)->html();
    }

    protected function signInAdmin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $this->company->getKey()]);
        app(CompanyContext::class)->set($this->company->fresh());
    }

    protected function level(Company $company, string $name, ?float $returnCharge = null, ?float $minWithdrawal = null): ResellerLevel
    {
        return ResellerLevel::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(), 'name' => $name, 'sort_order' => 0,
            'return_charge' => $returnCharge, 'min_withdrawal' => $minWithdrawal,
        ]);
    }

    protected function placeStoreOrder(string $buyerPhone = '01722000009'): Order
    {
        $order = app(ResellerOrderPlacementService::class)->place($this->reseller->fresh(), [
            'name' => 'Buyer', 'phone' => $buyerPhone, 'address' => 'Dhaka, Mirpur', 'note' => null,
        ], [['product_id' => $this->ring->getKey(), 'quantity' => 1, 'price' => 500]]);
        app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_CONFIRMED, null, 'test');

        return $order->fresh();
    }

    protected function returnOrder(Order $order): void
    {
        app(OrderStatusWorkflowService::class)->transition($order->fresh(), Order::STAGE_RETURNED, 'Customer refused the parcel', 'test');
    }

    protected function makeCompany(string $name, string $slug): Company
    {
        $company = Company::query()->create([
            'name' => $name, 'slug' => $slug, 'domain' => $slug.'.example.test', 'domain_verified' => true,
            'invoice_prefix' => strtoupper(substr($slug, 0, 3)), 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka',
            'is_active' => true, 'reseller_module_enabled' => true,
        ])->fresh();
        StorefrontSetting::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(), 'theme_color' => '#0F766E', 'is_published' => true,
            'new_customer_delivery_advance_enabled' => false, 'reseller_cost_per_unit' => 10,
            'delivery_first_kg_inside' => 70, 'delivery_first_kg_outside' => 110, 'delivery_additional_per_kg' => 20,
        ]);

        return $company;
    }

    protected function makeReseller(Company $company, string $phone): Customer
    {
        app(CompanyContext::class)->set($company);
        $customer = new Customer([
            'name' => 'Reseller '.$phone, 'phone' => $phone, 'reseller_status' => 'approved',
            'reseller_slug' => 'r-'.Str::random(6), 'business_name' => 'Shop '.$phone, 'is_active' => true,
        ]);
        $customer->company_id = $company->getKey();
        $customer->forceFill(['password' => Hash::make('secret-password')])->save();

        return $customer;
    }

    protected function pickProduct(Company $company, Customer $reseller): Product
    {
        app(CompanyContext::class)->set($company);
        $product = Product::query()->create([
            'company_id' => $company->getKey(), 'name' => 'Ring', 'sku' => 'LV-'.Str::random(6),
            'price' => 450, 'sale_price' => 450, 'cost_price' => 200, 'stock' => 0, 'unit' => 'pcs',
            'reorder_level' => 1, 'vat_rate' => 0, 'tier_prices' => [['min_qty' => 1, 'price' => 300]],
            'is_active' => true, 'status' => Product::STATUS_AVAILABLE,
        ])->fresh();
        StockMovement::query()->create(['product_id' => $product->getKey(), 'type' => 'opening', 'quantity' => 50]);
        ResellerProduct::query()->create(['customer_id' => $reseller->getKey(), 'product_id' => $product->getKey(), 'is_active' => true]);

        return $product->fresh();
    }
}
