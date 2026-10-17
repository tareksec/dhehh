<?php

namespace Tests\Feature;

use App\Filament\Pages\ResellerSettings;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\StorefrontSettings\Pages\EditStorefrontSetting;
use App\Models\Company;
use App\Models\StorefrontPaymentMethod;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner rule (2026-10-06): every reseller setting lives on Resellers →
 * Reseller Settings and is controlled from there only.
 */
class ResellerSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('settings-co.example.test', 'SET');
        StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(), 'theme_color' => '#0F766E', 'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_a_super_admin_controls_every_reseller_setting_from_one_page(): void
    {
        $this->asUser(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        StorefrontPaymentMethod::query()->create([
            'company_id' => $this->company->getKey(), 'type' => StorefrontPaymentMethod::TYPE_MANUAL,
            'name' => 'bKash', 'account_number' => '01800000000', 'is_active' => true,
        ]);

        $this->get(ResellerSettings::getUrl())->assertOk()->assertSee('Reseller Settings');

        Livewire::test(ResellerSettings::class)
            ->assertFormSet(['reseller_module_enabled' => false, 'reseller_program_enabled' => true])
            ->assertSee('bKash — 01800000000')
            ->assertSee('Manage payment methods')
            ->fillForm([
                'reseller_module_enabled' => true,
                'reseller_subdomains_enabled' => true,
                'reseller_custom_domains_enabled' => true,
                'reseller_portal_domain' => 'HTTPS://Partners.Example.test/',
                'reseller_subdomain_setup_minutes' => 20,
                'reseller_cost_per_unit' => 15,
                'reseller_packaging_cost_per_unit' => 5,
                'reseller_min_withdrawal' => 300,
                'reseller_program_enabled' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Reseller settings saved');

        $company = $this->company->fresh();
        $this->assertTrue($company->reseller_module_enabled);
        $this->assertTrue($company->reseller_subdomains_enabled);
        $this->assertTrue($company->reseller_custom_domains_enabled);
        $this->assertSame('partners.example.test', $company->reseller_portal_domain);
        $setting = $company->storefrontSetting;
        $this->assertSame('15.00', $setting->reseller_cost_per_unit);
        $this->assertSame('5.00', $setting->reseller_packaging_cost_per_unit);
        $this->assertSame('300.00', $setting->reseller_min_withdrawal);
        $this->assertFalse($setting->reseller_program_enabled);
        $this->assertSame(20, $setting->reseller_subdomain_setup_minutes);
    }

    public function test_the_portal_domain_cannot_be_used_by_two_companies(): void
    {
        $other = $this->makeCompany('other-co.example.test', 'OTH');
        $other->forceFill(['reseller_portal_domain' => 'partners.example.test'])->save();
        $this->asUser(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        Livewire::test(ResellerSettings::class)
            ->fillForm(['reseller_portal_domain' => 'partners.example.test'])
            ->call('save')
            ->assertHasErrors(['data.reseller_portal_domain']);

        $this->assertNull($this->company->fresh()->reseller_portal_domain);
    }

    public function test_a_settings_manager_changes_the_money_rules_but_not_the_module(): void
    {
        UserRole::query()->create(['name' => 'Settings', 'slug' => 'settings-only', 'permissions' => ['settings.manage'], 'is_active' => true]);
        $this->asUser(User::factory()->create(['role' => 'settings-only', 'is_active' => true]));

        Livewire::test(ResellerSettings::class)
            ->assertFormFieldIsDisabled('reseller_module_enabled')
            ->assertFormFieldIsEnabled('reseller_cost_per_unit')
            ->set('data.reseller_module_enabled', true)
            ->set('data.reseller_portal_domain', 'forged.example.test')
            ->set('data.reseller_subdomain_setup_minutes', 99)
            ->fillForm(['reseller_cost_per_unit' => 12])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($this->company->fresh()->reseller_module_enabled);
        $this->assertNull($this->company->fresh()->reseller_portal_domain);
        $this->assertSame('12.00', $this->company->fresh()->storefrontSetting->reseller_cost_per_unit);
        $this->assertSame(10, $this->company->fresh()->storefrontSetting->reseller_subdomain_setup_minutes);
    }

    public function test_staff_without_settings_permission_cannot_open_the_page(): void
    {
        $this->asUser(User::factory()->create(['role' => 'manager', 'is_active' => true]));

        $this->get(ResellerSettings::getUrl())->assertForbidden();
    }

    public function test_the_old_places_no_longer_carry_reseller_settings(): void
    {
        $this->asUser(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        Livewire::test(EditCompany::class, ['record' => $this->company->getKey()])
            ->assertDontSee('Reseller module enabled')
            ->assertDontSee('Reseller portal domain');

        Livewire::test(EditStorefrontSetting::class, ['record' => $this->company->storefrontSetting->getKey()])
            ->call('selectSection', 'reseller')
            ->assertDontSee('Reseller cost per unit')
            ->call('selectSection', 'navigation_seo')
            ->assertDontSee('Show reseller program');
    }

    protected function asUser(User $user): void
    {
        $this->actingAs($user)->withSession(['current_company_id' => $this->company->getKey()]);
        app(CompanyContext::class)->set($this->company);
    }

    protected function makeCompany(string $domain, string $prefix): Company
    {
        return Company::query()->create([
            'name' => 'Company '.$prefix, 'slug' => str($domain)->slug()->toString(), 'domain' => $domain,
            'domain_verified' => true, 'invoice_prefix' => $prefix, 'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ])->fresh();
    }
}
