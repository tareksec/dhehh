<?php

namespace Tests\Feature;

use App\Filament\Reseller\Pages\Auth\Apply;
use App\Models\Company;
use App\Models\Customer;
use App\Services\CompanyContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 2 of 13_RESELLER_PORTAL_PLAN.md: the reseller portal (a second
 * Filament panel, guard "customer") lives at reseller.<domain> or a custom
 * portal domain, path "/portal" -- and only there. Every test here is about
 * the boundary in §6: nothing from the portal reachable elsewhere, nothing
 * from elsewhere reachable on the portal host, and one reseller never sees
 * another company's portal.
 */
class ResellerPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_portal_path_404s_on_the_main_shop_host(): void
    {
        [$company] = $this->setUpCompany('shop-only.example.test');

        $this->get('http://shop-only.example.test/portal')->assertNotFound();
    }

    public function test_the_admin_panel_404s_or_redirects_away_on_the_portal_host(): void
    {
        [$company] = $this->setUpCompany('admin-block.example.test');

        $this->get('http://reseller.admin-block.example.test/admin')
            ->assertRedirect('/portal');
    }

    public function test_visiting_the_portal_host_root_redirects_to_the_portal(): void
    {
        [$company] = $this->setUpCompany('root-redirect.example.test');

        $this->get('http://reseller.root-redirect.example.test/')
            ->assertRedirect('/portal');
    }

    public function test_the_portal_host_404s_when_the_reseller_module_is_disabled(): void
    {
        $company = Company::query()->create([
            'name' => 'Portal Disabled',
            'slug' => Str::random(8),
            'domain' => 'module-off.example.test',
            'domain_verified' => true,
            'invoice_prefix' => strtoupper(Str::random(4)),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
            'reseller_module_enabled' => false,
        ]);

        $this->get('http://reseller.module-off.example.test/portal')->assertNotFound();
    }

    public function test_a_custom_portal_domain_is_used_instead_of_the_reseller_prefix(): void
    {
        [$company] = $this->setUpCompany('custom-domain.example.test');
        $company->update(['reseller_portal_domain' => 'partners.example.test']);

        $this->followingRedirects()->get('http://partners.example.test/portal')->assertOk();
        // The default reseller.<domain> stops working once a custom one is set.
        $this->get('http://reseller.custom-domain.example.test/portal')->assertNotFound();
    }

    public function test_an_approved_reseller_can_open_their_own_companys_portal_but_not_another_companys(): void
    {
        [$companyA, $resellerA] = $this->setUpCompany('portal-a.example.test', withReseller: true);
        [$companyB] = $this->setUpCompany('portal-b.example.test');

        $this->actingAs($resellerA, 'customer');

        $this->followingRedirects()->get('http://reseller.portal-a.example.test/portal')->assertOk();
        $this->followingRedirects()->get('http://reseller.portal-b.example.test/portal')->assertForbidden();
    }

    public function test_a_pending_applicant_can_log_in_and_sees_a_status_page_not_the_dashboard(): void
    {
        [$company, $pending] = $this->setUpCompany('pending-status.example.test', withReseller: true, status: 'pending');

        $this->actingAs($pending, 'customer');

        $this->followingRedirects()->get('http://reseller.pending-status.example.test/portal')
            ->assertOk()
            ->assertSee('reviewed');
    }

    public function test_a_rejected_applicant_cannot_open_the_portal(): void
    {
        [$company, $rejected] = $this->setUpCompany('rejected.example.test', withReseller: true, status: 'rejected');

        $this->actingAs($rejected, 'customer');

        $this->followingRedirects()->get('http://reseller.rejected.example.test/portal')->assertForbidden();
    }

    public function test_applying_in_the_portal_creates_a_pending_reseller_with_a_login(): void
    {
        [$company] = $this->setUpCompany('apply.example.test');

        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('reseller'));

        Livewire::test(Apply::class)
            ->fillForm([
                'name' => 'Karim Uddin',
                'phone' => '01911112222',
                'business_name' => 'Karim Traders',
                'note' => 'Sells in Mirpur',
                'password' => 'a-strong-password',
                'passwordConfirmation' => 'a-strong-password',
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $customer = Customer::query()->where('phone', '01911112222')->firstOrFail();
        $this->assertSame('pending', $customer->reseller_status);
        $this->assertSame('Karim Traders', $customer->business_name);
        $this->assertTrue(Hash::check('a-strong-password', $customer->password));
    }

    /**
     * @return array{0: Company, 1: ?Customer}
     */
    protected function setUpCompany(string $domain, bool $withReseller = false, string $status = 'approved'): array
    {
        $company = Company::query()->create([
            'name' => 'Portal '.$domain,
            'slug' => Str::slug($domain),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => strtoupper(Str::random(4)),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
            'reseller_module_enabled' => true,
        ])->fresh();

        if (! $withReseller) {
            return [$company, null];
        }

        $reseller = Customer::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Test Reseller',
            'phone' => '01900000'.random_int(100, 999),
            'reseller_status' => $status,
            'business_name' => 'Test Shop',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);

        return [$company, $reseller];
    }
}
