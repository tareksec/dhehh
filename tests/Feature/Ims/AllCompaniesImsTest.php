<?php

namespace Tests\Feature\Ims;

use App\Models\Company;
use App\Models\Investor;
use App\Models\InvestorPortalAccount;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\ImsOfficeCompanies;
use App\Services\InvestorPortalStaffEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 18_IMS_ALL_COMPANIES_PLAN.md phases A and B: every active company runs on
 * the one IMS domain; links of a company that does not own the domain say
 * which company they are for; the office opens the company the signed-in
 * person chose, and only one they may open.
 */
class AllCompaniesImsTest extends TestCase
{
    use RefreshDatabase;

    private Company $owner;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->owner = $this->company('Owner Co', ['ims_enabled' => true, 'ims_domain' => 'group-ims.test', 'domain' => 'owner-shop.test']);
        $this->other = $this->company('Other Co', ['domain' => 'other-shop.test']);
    }

    private function company(string $name, array $attributes = []): Company
    {
        return Company::query()->create([
            'name' => $name,
            'slug' => 'co-'.uniqid(),
            'invoice_prefix' => 'C'.random_int(1000, 9999),
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function investor(Company $company, string $name, string $phone): Investor
    {
        return Investor::query()->withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => $name, 'phone' => $phone]);
    }

    private function staffOf(Company $company, string $role = 'manager'): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        // The factory also attaches the default company; this person works for $company only.
        $user->companies()->sync([$company->getKey() => ['role' => $role, 'is_default' => true]]);

        return $user;
    }

    // ---- Phase A: one domain for all ------------------------------------

    public function test_every_active_company_uses_the_group_ims_domain(): void
    {
        $this->assertSame('group-ims.test', $this->owner->imsHost());
        $this->assertSame('group-ims.test', $this->other->imsHost());
        $this->assertTrue($this->other->imsIsLive());
        $this->assertSame('http://group-ims.test/investor', $this->other->investorPortalUrl());

        $inactive = $this->company('Closed Co', ['is_active' => false]);
        $this->assertNull($inactive->imsHost());

        $this->owner->forceFill(['ims_enabled' => false])->save();
        $this->assertFalse($this->other->fresh()->imsIsLive(), 'no company runs IMS: nobody is on it');
    }

    public function test_links_of_a_company_on_the_group_domain_say_which_company_they_are_for(): void
    {
        $this->assertSame('http://group-ims.test/investor/join/abc?business='.$this->other->id, $this->other->investorLinkUrl('join/abc'));
        $this->assertSame('http://group-ims.test/investor/register?code=X1&business='.$this->other->id, $this->other->investorLinkUrl('register?code=X1'));
        $this->assertSame('http://group-ims.test/investor/join/abc', $this->owner->investorLinkUrl('join/abc'), 'the domain\'s own company needs no marker');
        $this->assertSame('http://group-ims.test/office?office_company='.$this->other->id, $this->other->imsOfficeUrl());
    }

    public function test_the_old_portal_host_of_any_company_goes_to_the_group_domain(): void
    {
        $this->other->forceFill(['investor_portal_enabled' => true, 'investor_portal_domain' => 'old-other.test'])->save();

        $this->get('http://old-other.test/investor/login')->assertRedirect('http://group-ims.test/investor/login');
    }

    public function test_a_staff_link_of_another_company_opens_that_company_on_the_group_domain(): void
    {
        $investor = $this->investor($this->other, 'Karim Other', '01711000201');
        $this->investor($this->owner, 'Rahim Owner', '01711000202');
        $staff = User::factory()->create(['role' => 'super_admin']);

        app(CompanyContext::class)->set($this->other);
        $url = app(InvestorPortalStaffEntryService::class)->issue($investor, $staff);
        $this->assertStringStartsWith('http://group-ims.test/investor/staff-entry/', $url);
        $this->assertStringEndsWith('?business='.$this->other->id, $url);

        app(CompanyContext::class)->clear();
        $this->get($url)->assertRedirect('http://group-ims.test/investor');

        $account = InvestorPortalAccount::query()->withoutGlobalScopes()->where('investor_id', $investor->id)->firstOrFail();
        $this->assertSame($account->id, auth('investor')->id());

        // The next page opens the account's own company, not the domain's.
        $this->get('http://group-ims.test/investor/dashboard')->assertOk();
        $this->assertSame($this->other->id, app(CompanyContext::class)->id());
    }

    public function test_a_link_marker_for_a_company_not_on_this_domain_is_ignored(): void
    {
        $elsewhere = $this->company('Elsewhere Co', ['ims_enabled' => true, 'ims_domain' => 'elsewhere-ims.test']);
        $investor = $this->investor($elsewhere, 'Far Away', '01711000203');
        $staff = User::factory()->create(['role' => 'super_admin']);

        app(CompanyContext::class)->set($elsewhere);
        $url = app(InvestorPortalStaffEntryService::class)->issue($investor, $staff);
        $token = basename((string) parse_url($url, PHP_URL_PATH));

        app(CompanyContext::class)->clear();
        // Elsewhere's token on the group domain with Elsewhere's marker: refused, the domain's company stays open.
        $this->get('http://group-ims.test/investor/staff-entry/'.$token.'?business='.$elsewhere->id)->assertRedirectContains('/investor/login');
        $this->assertFalse(auth('investor')->check());
    }

    public function test_the_daily_ims_jobs_run_for_every_company_on_the_domain(): void
    {
        $this->artisan('ims:owner-digest')
            ->expectsOutputToContain('Owner Co')
            ->expectsOutputToContain('Other Co')
            ->assertSuccessful();
    }

    // ---- Phase B: the office picker -------------------------------------

    public function test_staff_of_another_company_open_their_own_company_in_the_office(): void
    {
        $this->investor($this->owner, 'Rahim Owner', '01711000301');
        $this->investor($this->other, 'Karim Other', '01711000302');
        $manager = $this->staffOf($this->other);

        $this->actingAs($manager)->get('http://group-ims.test/office/investments/investors')
            ->assertOk()
            ->assertSee('Karim Other')
            ->assertDontSee('Rahim Owner');
        $this->assertSame($this->other->id, app(CompanyContext::class)->id());
    }

    public function test_a_super_admin_switches_company_with_the_picker_and_it_stays_chosen(): void
    {
        $this->investor($this->owner, 'Rahim Owner', '01711000303');
        $this->investor($this->other, 'Karim Other', '01711000304');
        $admin = $this->staffOf($this->owner, 'super_admin');

        $this->actingAs($admin)->get('http://group-ims.test/office')
            ->assertOk()
            ->assertSee(__('Choose company'))
            ->assertSee('?office_company='.$this->other->id, false);

        $this->get('http://group-ims.test/office?office_company='.$this->other->id)->assertOk();
        $this->get('http://group-ims.test/office/investments/investors')
            ->assertOk()
            ->assertSee('Karim Other')
            ->assertDontSee('Rahim Owner');
    }

    public function test_a_manager_cannot_open_a_company_they_do_not_work_for(): void
    {
        $this->investor($this->other, 'Karim Other', '01711000305');
        $manager = $this->staffOf($this->owner);

        $this->actingAs($manager)->withSession([ImsOfficeCompanies::SESSION_KEY => $this->other->id])
            ->get('http://group-ims.test/office/investments/investors?office_company='.$this->other->id)
            ->assertOk()
            ->assertDontSee('Karim Other');
        $this->assertSame($this->owner->id, app(CompanyContext::class)->id());
        $this->assertNotContains($this->other->id, app(ImsOfficeCompanies::class)->for($manager)->map->getKey()->all());
    }

    public function test_office_admins_see_every_company_and_others_only_their_own(): void
    {
        $companies = app(ImsOfficeCompanies::class);
        $third = $this->company('Third Co');

        $all = $companies->for($this->staffOf($this->owner, 'super_admin'))->map->getKey()->all();
        foreach ([$this->owner->id, $this->other->id, $third->id] as $id) {
            $this->assertContains($id, $all);
        }
        $this->assertSame([$this->other->id], $companies->for($this->staffOf($this->other))->map->getKey()->all());
        $this->assertSame([], $companies->for($this->staffOf($this->other, 'sales_staff'))->map->getKey()->all(), 'no office permission: nothing');
    }

    public function test_the_erp_investments_address_opens_the_same_company_in_the_office(): void
    {
        $admin = $this->staffOf($this->other, 'super_admin');

        $this->actingAs($admin)->withSession(['current_company_id' => $this->other->id])
            ->get('http://localhost/admin/investments/investors')
            ->assertRedirect('http://group-ims.test/office/investments/investors?office_company='.$this->other->id);
    }
}
