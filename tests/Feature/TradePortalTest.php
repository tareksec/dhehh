<?php

namespace Tests\Feature;

use App\Filament\Trade\Pages\BusinessProfile;
use App\Http\Middleware\SetTradePortalLocale;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\TradePortalHome;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Trade Portal, phase 1 (docs/tasneem-samples/BUILD-BRIEF.md): who may open
 * /trade and /admin, the host rule, the home cards and the business profile.
 */
class TradePortalTest extends TestCase
{
    use RefreshDatabase;

    protected function company(array $attributes = []): Company
    {
        return Company::query()->create([
            'name' => 'Trade Co '.uniqid(),
            'slug' => 'trade-'.uniqid(),
            'invoice_prefix' => 'TR'.random_int(1000, 9999),
            'is_active' => true,
            'trade_portal_enabled' => true,
            ...$attributes,
        ]);
    }

    protected function staff(Company $company, string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $user->companies()->attach($company->getKey(), ['role' => $role, 'is_default' => true]);

        return $user;
    }

    protected function english(): array
    {
        return [SetTradePortalLocale::SESSION_KEY => 'en'];
    }

    public function test_the_migration_creates_an_editable_trade_owner_role_that_only_opens_the_portal(): void
    {
        $role = UserRole::query()->where('slug', 'trade_owner')->first();

        $this->assertNotNull($role);
        $this->assertTrue($role->is_active);
        $this->assertContains('trade.access', $role->permissions);
        $this->assertContains('trade.profile.manage', $role->permissions);
        $this->assertSame([], array_values(array_filter($role->permissions, fn (string $permission): bool => ! str_starts_with($permission, 'trade.'))));
        $this->assertArrayNotHasKey('trade_owner', User::ROLE_PERMISSIONS, 'A built-in role could not be edited in Users → Roles.');
        $this->assertArrayHasKey('trade.access', User::CUSTOM_PERMISSION_OPTIONS);
        $this->assertArrayHasKey('trade.profile.manage', User::CUSTOM_PERMISSION_OPTIONS);
    }

    public function test_a_trade_owner_opens_the_trade_portal_but_not_the_admin_panel(): void
    {
        $company = $this->company();
        $owner = $this->staff($company, 'trade_owner');

        $this->assertTrue($owner->isTradePortalOnly());
        $this->assertFalse($owner->canAccessPanel(Filament::getPanel('admin')));

        $this->actingAs($owner)->withSession($this->english())->get('/trade')
            ->assertOk()
            ->assertSee("Today's sales")
            ->assertSee('Dues (to collect)')
            ->assertSee('Low stock')
            ->assertSee('LC files');

        $this->actingAs($owner)->get('/admin')->assertForbidden();
    }

    public function test_existing_admin_users_keep_the_admin_panel_and_need_the_permission_for_the_trade_portal(): void
    {
        $company = $this->company();
        UserRole::query()->create(['name' => 'Packer', 'slug' => 'packer', 'permissions' => ['inventory.view'], 'is_active' => true]);
        UserRole::query()->create(['name' => 'Empty', 'slug' => 'empty_role', 'permissions' => [], 'is_active' => true]);

        foreach (['super_admin', 'manager', 'sales_staff', 'inventory_staff', 'accountant', 'packer', 'empty_role'] as $role) {
            $user = $this->staff($company, $role);
            app(CompanyContext::class)->clear();

            $this->assertFalse($user->isTradePortalOnly(), $role);
            $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')), "{$role} must keep /admin");
            $this->assertSame($role === 'super_admin', $user->canAccessPanel(Filament::getPanel('trade')), "{$role} on /trade");
        }

        $manager = $this->staff($company, 'manager');
        $this->actingAs($manager)->get('/trade')->assertForbidden();
    }

    public function test_a_role_mixing_trade_and_other_permissions_keeps_both_panels(): void
    {
        $company = $this->company();
        UserRole::query()->create(['name' => 'Shop manager', 'slug' => 'shop_manager', 'permissions' => ['trade.access', 'sales.view'], 'is_active' => true]);
        $user = $this->staff($company, 'shop_manager');

        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('trade')));
    }

    public function test_the_portal_stays_closed_while_the_company_has_it_switched_off(): void
    {
        $company = $this->company(['trade_portal_enabled' => false]);
        $owner = $this->staff($company, 'trade_owner');

        $this->assertFalse($owner->canAccessPanel(Filament::getPanel('trade')));
        $this->actingAs($owner)->get('/trade')->assertForbidden();
    }

    public function test_an_inactive_user_opens_no_panel(): void
    {
        $company = $this->company();
        $owner = $this->staff($company, 'trade_owner');
        $owner->forceFill(['is_active' => false])->saveQuietly();

        $this->assertFalse($owner->canAccessPanel(Filament::getPanel('trade')));
        $this->assertFalse($owner->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_staff_accounts_open_no_other_panel(): void
    {
        $user = $this->staff($this->company(), 'super_admin');

        $this->assertFalse($user->canAccessPanel(Filament::getPanel('reseller')));
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('investor')));
    }

    public function test_the_portal_lives_on_the_main_domain_and_not_on_a_portal_host(): void
    {
        $this->get('/trade')->assertRedirect('/trade/login');
        $this->get('/trade/login')->assertOk();

        $ims = $this->company(['ims_enabled' => true, 'ims_domain' => 'ims-'.uniqid().'.test']);
        $this->get('http://'.$ims->ims_domain.'/trade')->assertRedirect('/investor');
    }

    public function test_the_trade_sign_in_never_lands_on_another_panels_page(): void
    {
        $this->withSession(['url.intended' => 'http://localhost/admin/orders'])->get('/trade/login')->assertOk();
        $this->assertNull(session('url.intended'));

        $this->withSession(['url.intended' => 'http://localhost/trade/business-profile'])->get('/trade/login')->assertOk();
        $this->assertSame('http://localhost/trade/business-profile', session('url.intended'));
    }

    public function test_a_person_only_opens_their_own_companies(): void
    {
        $mine = $this->company();
        $other = $this->company();
        $owner = $this->staff($mine, 'trade_owner');

        Customer::withoutGlobalScopes()->create(['company_id' => $other->getKey(), 'name' => 'Other Party', 'opening_balance' => 5000, 'current_balance' => 5000]);

        $this->actingAs($owner)->withSession($this->english())->get('/trade?trade_company='.$other->getKey())
            ->assertOk()
            ->assertSee('No customer owes you money.')
            ->assertDontSee('Other Party');

        $this->assertSame((int) $mine->getKey(), app(CompanyContext::class)->id());
    }

    public function test_the_home_page_is_bangla_first_and_friendly_when_empty(): void
    {
        $owner = $this->staff($this->company(), 'trade_owner');

        $this->actingAs($owner)->get('/trade')
            ->assertOk()
            ->assertSee('আজকের বিক্রি')
            ->assertSee('আজ এখনো কোনো বিক্রি হয়নি।')
            ->assertSee('স্টক ঠিক আছে। কিছুই কম নেই।');
    }

    public function test_the_home_cards_show_the_open_companys_dues_and_open_lcs(): void
    {
        $company = $this->company();
        $owner = $this->staff($company, 'trade_owner');
        app(CompanyContext::class)->set($company);

        Customer::query()->create(['name' => 'Rahim Knit', 'opening_balance' => 600000, 'current_balance' => 600000]);
        $supplier = Supplier::query()->create(['name' => 'Hengli Machinery']);
        Purchase::query()->create(['supplier_id' => $supplier->getKey(), 'purchase_date' => now()->toDateString(), 'lc_number' => 'LC-167', 'lc_date' => now()->toDateString(), 'status' => 'draft']);
        Purchase::query()->create(['supplier_id' => $supplier->getKey(), 'purchase_date' => now()->toDateString(), 'lc_number' => 'LC-163', 'ci_number' => 'CI-1015', 'status' => 'draft']);
        Purchase::query()->create(['supplier_id' => $supplier->getKey(), 'purchase_date' => now()->toDateString(), 'lc_number' => 'LC-100', 'status' => 'cancelled']);

        $lcs = app(TradePortalHome::class)->openLcs();
        $this->assertSame(2, $lcs['count']);
        $this->assertEqualsCanonicalizing(['LC-167', 'LC-163'], array_column($lcs['files'], 'lc'));

        $this->actingAs($owner)->withSession($this->english())->get('/trade')
            ->assertOk()
            ->assertSee('Rahim Knit')
            ->assertSee('LC LC-167')
            ->assertSee('Shipped (invoice received)')
            ->assertDontSee('LC-100');
    }

    public function test_the_owner_edits_the_business_profile_in_the_portal(): void
    {
        $company = $this->company(['bin_number' => '006673859-0112']);
        $owner = $this->staff($company, 'trade_owner');

        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('trade'));
        app(CompanyContext::class)->set($company);

        Livewire::test(BusinessProfile::class)
            ->fillForm([
                'registered_address' => 'Registered: Narayanganj',
                'factory_address' => 'Factory: Gazipur',
                'tin_number' => '123456789012',
                'challan_bin_number' => '006673859-0403',
                'irc_number' => 'IRC-1',
                'signatory_name' => 'Owner Name',
                'bank_accounts' => [['bank_name' => 'Islami Bank', 'branch' => 'Tongi', 'account_name' => 'Tasneem', 'account_number' => '2050', 'routing_number' => '', 'swift_code' => '']],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $company->refresh();
        $this->assertSame('Factory: Gazipur', $company->factory_address);
        $this->assertSame('006673859-0403', $company->challanBin());
        $this->assertSame('006673859-0112', $company->bin_number);
        $this->assertSame('Islami Bank', $company->bank_accounts[0]['bank_name']);

        $company->forceFill(['challan_bin_number' => null])->save();
        $this->assertSame('006673859-0112', $company->challanBin(), 'The challan falls back to the import BIN.');
    }

    public function test_the_business_profile_needs_its_own_permission(): void
    {
        $company = $this->company();
        UserRole::query()->create(['name' => 'Shop clerk', 'slug' => 'shop_clerk', 'permissions' => ['trade.access'], 'is_active' => true]);
        $clerk = $this->staff($company, 'shop_clerk');

        $this->actingAs($clerk)->get('/trade')->assertOk();
        $this->actingAs($clerk)->get('/trade/business-profile')->assertForbidden();
    }
}
