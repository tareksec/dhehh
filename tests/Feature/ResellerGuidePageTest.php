<?php

namespace Tests\Feature;

use App\Filament\Pages\ResellerSettings;
use App\Models\Company;
use App\Models\StorefrontPaymentMethod;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner request (2026-10-07): a public reseller guide on the portal host that
 * a new reseller reads before joining, with apply / sign-in buttons. Its
 * intro, own rules and money figures are managed on Reseller Settings.
 */
class ResellerGuidePageTest extends TestCase
{
    use RefreshDatabase;

    protected string $portal = 'https://reseller.guide-co.example.test';

    protected Company $company;

    protected StorefrontSetting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Guide Co', 'slug' => 'guide-co', 'domain' => 'guide-co.example.test',
            'domain_verified' => true, 'invoice_prefix' => 'GDE', 'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka', 'is_active' => true, 'reseller_module_enabled' => true,
        ]);
        $this->setting = StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(), 'theme_color' => '#0F766E', 'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
            'reseller_min_withdrawal' => 500, 'reseller_packaging_cost_per_unit' => 7, 'reseller_cost_per_unit' => 33,
        ]);
    }

    public function test_the_guide_explains_the_program_and_links_to_apply_and_sign_in(): void
    {
        $this->setting->forceFill([
            'reseller_guide_intro' => 'Join the Guide Co family.',
            'reseller_guide_rules' => [
                ['title' => 'No fake orders', 'body' => 'Accounts placing fake orders are closed.'],
                ['title' => 'Keep prices fair', 'body' => null],
            ],
        ])->save();

        $this->get($this->portal.'/portal/guide')
            ->assertOk()
            ->assertSee('data-reseller-guide', false)
            ->assertSee('Join the Guide Co family.')
            ->assertSeeInOrder(['No fake orders', 'Accounts placing fake orders are closed.', 'Keep prices fair'])
            ->assertSee($this->portal.'/portal/register', false)
            ->assertSee($this->portal.'/portal/login', false)
            // Figures stay hidden until the admin turns them on; costs never show.
            ->assertDontSee('The smallest withdrawal is')
            ->assertDontSee('33.00');
    }

    public function test_money_figures_and_own_courier_rules_follow_the_settings(): void
    {
        $this->get($this->portal.'/portal/guide')->assertOk()->assertDontSee('Your own courier');

        StorefrontPaymentMethod::query()->create([
            'company_id' => $this->company->getKey(), 'type' => StorefrontPaymentMethod::TYPE_MANUAL,
            'name' => 'bKash', 'account_number' => '01800000000', 'is_active' => true,
        ]);
        $this->setting->forceFill(['reseller_guide_show_figures' => true])->save();

        $this->get($this->portal.'/portal/guide')
            ->assertOk()
            ->assertSee('Your own courier')
            ->assertSee('Pay by: bKash.')
            ->assertDontSee('01800000000')
            ->assertSee('The smallest withdrawal is')
            ->assertSee('500')
            ->assertSee('The packaging charge is')
            ->assertDontSee('33.00');
    }

    public function test_the_guide_is_only_on_the_portal_host_and_can_be_turned_off(): void
    {
        $this->get('https://guide-co.example.test/portal/guide')->assertNotFound();

        $this->setting->forceFill(['reseller_guide_enabled' => false])->save();

        $this->get($this->portal.'/portal/guide')->assertNotFound();
    }

    public function test_storefront_and_apply_page_link_to_the_guide_while_it_is_on(): void
    {
        $this->assertStringEndsWith('://reseller.guide-co.example.test/portal/guide', (string) $this->company->fresh()->resellerJoinUrl());
        $this->get($this->portal.'/portal/register')->assertOk()->assertSee('Read the reseller guide');
        $this->get('https://guide-co.example.test/')->assertOk()->assertSee('reseller.guide-co.example.test/portal/guide', false);

        $this->setting->forceFill(['reseller_guide_enabled' => false])->save();

        $this->assertStringEndsWith('://reseller.guide-co.example.test/portal/register', (string) $this->company->fresh()->resellerJoinUrl());
        $this->get($this->portal.'/portal/register')->assertOk()->assertDontSee('Read the reseller guide');
    }

    public function test_admin_manages_the_guide_on_reseller_settings(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $this->company->getKey()]);
        app(CompanyContext::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ResellerSettings::class)
            ->assertSee('/portal/guide')
            ->fillForm([
                'reseller_guide_enabled' => true,
                'reseller_guide_show_figures' => true,
                'reseller_guide_intro' => '  Welcome resellers.  ',
                'reseller_guide_rules' => [
                    ['title' => ' Pay on time ', 'body' => 'Advances are sent before packing.'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $setting = $this->setting->fresh();
        $this->assertTrue($setting->reseller_guide_show_figures);
        $this->assertSame('Welcome resellers.', $setting->reseller_guide_intro);
        $this->assertSame([['title' => 'Pay on time', 'body' => 'Advances are sent before packing.']], $setting->reseller_guide_rules);
    }
}
