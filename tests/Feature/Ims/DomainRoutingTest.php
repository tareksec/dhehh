<?php

namespace Tests\Feature\Ims;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IMS v2 on its own root domain (IMS-V2/ARCHITECTURE.md §2–3, PRD FR-90–92):
 * the portal at the domain itself, the office at /office, with or without
 * "www.", nothing of IMS on any other host, and the old portal host sent on.
 */
class DomainRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function company(array $attributes = []): Company
    {
        return Company::query()->create([
            'name' => 'IMS Co',
            'slug' => 'ims-co-'.uniqid(),
            'invoice_prefix' => 'IMS'.random_int(100, 999),
            'domain' => 'erp-shop.test',
            'is_active' => true,
            'ims_enabled' => true,
            'ims_domain' => 'ims-root.test',
            ...$attributes,
        ]);
    }

    public function test_the_ims_domain_opens_the_portal_at_its_root(): void
    {
        $this->company();

        $this->get('http://ims-root.test/')->assertRedirect('/investor');
        $this->get('http://ims-root.test/admin')->assertRedirect('/investor');
        $this->followingRedirects()->get('http://ims-root.test/investor')->assertOk();
    }

    public function test_www_opens_the_same_ims(): void
    {
        $this->company();

        $this->followingRedirects()->get('http://www.ims-root.test/investor')->assertOk();
        $this->followingRedirects()->get('http://www.ims-root.test/office')->assertOk();
    }

    public function test_the_office_opens_only_on_the_ims_domain(): void
    {
        $this->company();

        $this->get('http://ims-root.test/office/login')->assertOk();
        $this->get('http://erp-shop.test/office')->assertNotFound();
        $this->get('http://localhost/office/login')->assertNotFound();
    }

    public function test_nothing_of_ims_opens_while_it_is_off(): void
    {
        $this->company(['ims_enabled' => false]);

        $this->get('http://ims-root.test/office/login')->assertNotFound();
        $this->get('http://ims-root.test/investor')->assertNotFound();
    }

    public function test_the_old_portal_host_goes_to_the_ims_domain(): void
    {
        $this->company(['investor_portal_enabled' => true, 'investor_portal_domain' => 'old-invest.test']);

        $this->get('http://old-invest.test/investor/login')->assertRedirect('http://ims-root.test/investor/login');
        $this->get('http://old-invest.test/')->assertRedirect('http://ims-root.test/investor');
    }

    public function test_the_portal_links_point_at_the_ims_domain(): void
    {
        $company = $this->company(['investor_portal_enabled' => true, 'investor_portal_domain' => 'old-invest.test']);

        $this->assertSame('ims-root.test', $company->investorPortalHost());
        $this->assertStringStartsWith('http://ims-root.test/investor', (string) $company->investorPortalUrl());
    }

    public function test_staff_of_another_company_cannot_enter_the_office(): void
    {
        $this->company();
        $other = Company::query()->create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'invoice_prefix' => 'OTH'.random_int(100, 999), 'is_active' => true]);
        $manager = User::factory()->create(['role' => 'manager', 'is_active' => true]);
        $manager->companies()->attach($other->getKey(), ['role' => 'manager', 'is_default' => true]);

        $this->actingAs($manager)->get('http://ims-root.test/office')->assertForbidden();
    }

    public function test_staff_of_the_company_with_office_access_see_the_home(): void
    {
        $company = $this->company();
        $manager = User::factory()->create(['role' => 'manager', 'is_active' => true]);
        $manager->companies()->attach($company->getKey(), ['role' => 'manager', 'is_default' => true]);

        $this->actingAs($manager)->get('http://ims-root.test/office')
            ->assertOk()
            ->assertSee(__('What to do today'))
            ->assertSee(__('Capital at work now'));
    }

    public function test_a_sales_user_without_office_access_is_refused(): void
    {
        $company = $this->company();
        $sales = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $sales->companies()->attach($company->getKey(), ['role' => 'sales_staff', 'is_default' => true]);

        $this->actingAs($sales)->get('http://ims-root.test/office')->assertForbidden();
    }

    public function test_an_ims_domain_cannot_be_another_companys_domain(): void
    {
        $this->company();

        $this->assertTrue(Company::hostIsTakenByThePlatform('ims-root.test'));
        $this->assertTrue(Company::hostIsTakenByThePlatform('www.ims-root.test'));
    }
}
