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
 * 18_IMS_ALL_COMPANIES_PLAN.md phases A-D: every active company runs on the
 * one IMS domain; links of a company that does not own the domain say which
 * company they are for; the office opens the company the signed-in person
 * chose, and only one they may open; the All companies page; an investor's
 * projects and totals from every company.
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

    // ---- Phase C: the "All companies" home --------------------------------

    public function test_the_all_companies_home_reads_every_company_on_its_own_and_adds_up_per_currency(): void
    {
        $this->other->forceFill(['currency' => 'USD'])->save();
        $this->owner->forceFill(['currency' => 'BDT'])->save();
        $ownerInvestor = $this->verified($this->investor($this->owner, 'Rahim Owner', '01711000401'));
        $otherInvestor = $this->verified($this->investor($this->other, 'Karim Other', '01711000402'));
        $this->investor($this->other, 'Waiting Person', '01711000404')->forceFill(['kyc_status' => Investor::KYC_PENDING])->save();
        $this->project($this->owner, 'Owner Rice Import', $ownerInvestor, 300000);
        $this->project($this->other, 'Other Solar Lot', $otherInvestor, 50000);

        $figures = app(\App\Ims\Office\AllCompaniesFigures::class);
        app(CompanyContext::class)->set($this->owner);
        $rows = $figures->perCompany(collect([$this->owner, $this->other]));

        $this->assertSame($this->owner->id, app(CompanyContext::class)->id(), 'the page\'s company is put back');
        $this->assertSame(300000.0, $rows[0]['invested']);
        $this->assertSame(50000.0, $rows[1]['invested']);
        $this->assertSame(['Owner Rice Import'], array_map(fn (array $entry): string => $entry['project']->name, $rows[0]['projects']));
        $this->assertSame(['kyc'], array_column($rows[1]['waiting'], 'key'));
        $this->assertSame([], $rows[0]['waiting']);

        $totals = $figures->totals($rows);
        $this->assertSame(['BDT', 'USD'], array_keys($totals), 'never added across currencies');
        $this->assertSame(300000.0, $totals['BDT']['invested']);
        $this->assertSame(50000.0, $totals['USD']['invested']);
    }

    public function test_the_all_companies_page_names_each_company_and_opens_its_own_pages(): void
    {
        $otherInvestor = $this->investor($this->other, 'Karim Other', '01711000403');
        $otherInvestor->forceFill(['kyc_status' => Investor::KYC_PENDING])->save();
        $this->project($this->other, 'Other Solar Lot', $otherInvestor, 50000);
        $admin = $this->staffOf($this->owner, 'super_admin');

        $this->actingAs($admin)->get('http://group-ims.test/office/all-companies')
            ->assertOk()
            ->assertSee(__('Waiting for a decision'))
            ->assertSee(__('KYC waiting for review'))
            ->assertSee('Owner Co')
            ->assertSee('Other Co')
            ->assertSee('Other Solar Lot')
            ->assertSee('office_company='.$this->other->id, false);

        // Rendering it never changes the company open in the office.
        $this->assertSame($this->owner->id, app(CompanyContext::class)->id());
    }

    public function test_someone_with_one_company_has_no_all_companies_page(): void
    {
        $this->actingAs($this->staffOf($this->other))->get('http://group-ims.test/office/all-companies')->assertForbidden();
    }

    private function verified(Investor $investor): Investor
    {
        $investor->forceFill(['kyc_status' => Investor::KYC_VERIFIED, 'kyc_reviewed_at' => now()])->save();

        return $investor;
    }

    // ---- Phase D: an investor's projects from every company -----------------

    /**
     * One person (one phone) investing in Owner Co and Other Co, with a running
     * project in each.
     *
     * @return array{owner: InvestorPortalAccount, other: InvestorPortalAccount, ownerProject: \App\Models\InvestmentProject, otherProject: \App\Models\InvestmentProject}
     */
    private function personInBothCompanies(): array
    {
        $phone = '01711000600';
        $inOwner = $this->verified($this->investor($this->owner, 'Rahim Both', $phone));
        $inOther = $this->verified($this->investor($this->other, 'Rahim Both', $phone));

        return [
            'owner' => $this->portalAccountFor($inOwner),
            'other' => $this->portalAccountFor($inOther),
            'ownerProject' => $this->project($this->owner, 'Owner Rice Import', $inOwner, 300000),
            'otherProject' => $this->project($this->other, 'Other Solar Lot', $inOther, 50000),
        ];
    }

    private function portalAccountFor(Investor $investor): InvestorPortalAccount
    {
        $account = InvestorPortalAccount::query()->withoutGlobalScopes()->create([
            'company_id' => $investor->company_id,
            'investor_id' => $investor->id,
            'name' => $investor->name,
            'phone' => $investor->phone,
            'status' => InvestorPortalAccount::STATUS_ACTIVE,
        ]);
        $account->forceFill(['password' => 'a-strong-password-1'])->save();

        return $account;
    }

    private function signedInAs(InvestorPortalAccount $account): void
    {
        $this->actingAs($account, 'investor')->withSession([auth('investor')->getName() => $account->id]);
    }

    public function test_my_projects_lists_every_company_with_its_name(): void
    {
        $person = $this->personInBothCompanies();
        $this->signedInAs($person['owner']);

        $this->get('http://group-ims.test/investor/projects')
            ->assertOk()
            ->assertSee('Owner Rice Import')
            ->assertSee('Other Solar Lot')
            ->assertSee('Other Co')
            ->assertSee('/investor/projects/'.$person['otherProject']->id.'?business='.$this->other->id, false);
    }

    public function test_a_project_of_another_company_opens_after_checking_the_persons_account_there(): void
    {
        $person = $this->personInBothCompanies();
        $this->signedInAs($person['owner']);
        $url = 'http://group-ims.test/investor/projects/'.$person['otherProject']->id;

        $this->get($url.'?business='.$this->other->id)->assertRedirect($url);
        $this->assertSame($person['other']->id, auth('investor')->id(), 'signed in to the person\'s own account in that company');

        $this->get($url)->assertOk()->assertSee('Other Solar Lot');
        $this->assertSame($this->other->id, app(CompanyContext::class)->id());
    }

    public function test_a_link_to_a_company_the_person_does_not_invest_in_is_ignored(): void
    {
        $person = $this->personInBothCompanies();
        $third = $this->company('Third Co');
        $this->signedInAs($person['owner']);

        $this->get('http://group-ims.test/investor/dashboard?business='.$third->id)->assertRedirect('http://group-ims.test/investor/dashboard');
        $this->assertSame($person['owner']->id, auth('investor')->id());

        // And a project of another company cannot be opened without an account there.
        $this->get('http://group-ims.test/investor/projects/'.$person['otherProject']->id)->assertNotFound();
    }

    public function test_the_home_adds_up_every_company_per_currency(): void
    {
        $person = $this->personInBothCompanies();
        $this->signedInAs($person['owner']);

        $totals = app(\App\Services\InvestorPortfolio::class)->summary($person['owner']);
        $this->assertSame(350000.0, $totals['BDT']['total_invested']);

        $this->get('http://group-ims.test/investor/dashboard')
            ->assertOk()
            ->assertSee(__('All your companies together'));

        Company::query()->whereKey($this->other->id)->update(['currency' => 'USD']);
        $totals = app(\App\Services\InvestorPortfolio::class)->summary($person['owner']);
        $this->assertSame(['BDT', 'USD'], array_keys($totals), 'never added across currencies');
        $this->assertSame(300000.0, $totals['BDT']['total_invested']);
    }

    public function test_a_reset_or_sms_code_on_the_shared_domain_finds_an_account_in_another_company(): void
    {
        $inOther = $this->investor($this->other, 'Only Other', '01711000700');
        $account = $this->portalAccountFor($inOther);
        $switches = app(\App\Services\InvestorBusinessSwitchService::class);
        $finder = fn () => app(\App\Services\InvestorPortalAccountService::class)->findByPhone('01711000700');

        app(CompanyContext::class)->set($this->owner);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('investor'));

        $found = $switches->locateOnImsDomain($finder, \Illuminate\Http\Request::create('http://group-ims.test/investor/password-reset'));
        $this->assertSame($account->id, $found?->id);
        $this->assertSame($this->owner->id, app(CompanyContext::class)->id(), 'the open company is put back');

        // A company with an IMS domain of its own is not searched from here.
        Company::query()->whereKey($this->other->id)->update(['ims_enabled' => true, 'ims_domain' => 'other-ims.test']);
        $this->assertNull($switches->locateOnImsDomain($finder, \Illuminate\Http\Request::create('http://group-ims.test/investor/password-reset')));
    }

    // ---- Phase E: combined lists with a Project filter -----------------------

    public function test_the_statement_lists_every_company_and_the_project_filter_keeps_one(): void
    {
        $person = $this->personInBothCompanies();
        $this->signedInAs($person['owner']);

        $this->get('http://group-ims.test/investor/statement')
            ->assertOk()
            ->assertSee('Owner Rice Import')
            ->assertSee('Other Solar Lot')
            ->assertSee('Other Co')
            ->assertSee(__('All projects'));

        $portfolio = app(\App\Services\InvestorPortfolio::class);
        $only = $portfolio->statement($person['owner'], project: $this->other->id.':'.$person['otherProject']->id);
        $this->assertNotEmpty($only);
        $this->assertSame(['Other Co'], $only->pluck('company_name')->unique()->values()->all());
        $this->assertSame(['USD' => 0, 'BDT' => 2], [
            'USD' => $portfolio->statement($person['owner'])->where('currency', 'USD')->count(),
            'BDT' => $portfolio->statement($person['owner'])->where('currency', 'BDT')->count(),
        ]);
    }

    public function test_requests_from_every_company_are_listed_and_filtered_by_project(): void
    {
        $person = $this->personInBothCompanies();
        $this->request($person['owner'], $person['ownerProject'], 'Owner question');
        $this->request($person['other'], $person['otherProject'], 'Other question');
        $this->signedInAs($person['owner']);

        $this->get('http://group-ims.test/investor/requests')->assertOk()->assertSee('Other Co');

        $portfolio = app(\App\Services\InvestorPortfolio::class);
        $this->assertCount(2, $portfolio->requests($person['owner']));
        $only = $portfolio->requests($person['owner'], $this->other->id.':'.$person['otherProject']->id);
        $this->assertSame([$this->other->id], array_column($only, 'company_id'));
    }

    public function test_a_new_request_lands_in_the_company_of_the_chosen_project(): void
    {
        $person = $this->personInBothCompanies();
        $this->signedInAs($person['owner']);
        app(CompanyContext::class)->set($this->owner);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('investor'));

        \Livewire\Livewire::test(\App\Filament\Investor\Pages\Requests::class)
            ->callAction('new', data: [
                'type' => \App\Models\InvestorRequest::TYPE_TOP_UP,
                'target' => $this->other->id.':p'.$person['otherProject']->id,
                'amount' => 25000,
                'payment_method' => 'bank',
            ])
            ->assertHasNoActionErrors();

        $request = \App\Models\InvestorRequest::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame($this->other->id, (int) $request->company_id);
        $this->assertSame($person['other']->id, (int) $request->portal_account_id, 'sent as the person\'s own account there');
        $this->assertSame($person['otherProject']->id, (int) $request->project_id);
        $this->assertSame($this->owner->id, app(CompanyContext::class)->id(), 'the open company is put back');
    }

    public function test_a_request_of_another_company_is_cancelled_in_that_company(): void
    {
        $person = $this->personInBothCompanies();
        $other = $this->request($person['other'], $person['otherProject'], 'Other question');
        $this->signedInAs($person['owner']);
        app(CompanyContext::class)->set($this->owner);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('investor'));

        \Livewire\Livewire::test(\App\Filament\Investor\Pages\Requests::class)
            ->callTableAction('cancel', $this->other->id.'-'.$other->id);

        $this->assertSame(\App\Models\InvestorRequest::STATUS_CANCELLED, $other->fresh()->status);
    }

    public function test_payouts_and_documents_pages_open_with_every_company(): void
    {
        $person = $this->personInBothCompanies();
        $this->signedInAs($person['owner']);

        $this->get('http://group-ims.test/investor/payouts')->assertOk()->assertSee(__('No payouts yet.'));
        $this->get('http://group-ims.test/investor/documents')->assertOk();
    }

    private function request(InvestorPortalAccount $account, \App\Models\InvestmentProject $project, string $message): \App\Models\InvestorRequest
    {
        return \App\Models\InvestorRequest::query()->withoutGlobalScopes()->create([
            'company_id' => $account->company_id,
            'portal_account_id' => $account->id,
            'investor_id' => $account->investor_id,
            'project_id' => $project->id,
            'type' => \App\Models\InvestorRequest::TYPE_WITHDRAWAL,
            'status' => \App\Models\InvestorRequest::STATUS_PENDING,
            'message' => $message,
        ]);
    }

    private function project(Company $company, string $name, Investor $investor, float $amount): \App\Models\InvestmentProject
    {
        $project = \App\Models\InvestmentProject::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'name' => $name,
            'duration_type' => 'custom_days',
            'trade_cycle_days' => 60,
            'start_date' => now()->toDateString(),
            'investor_share_percent' => 35,
            'channel_partner_share_percent' => 0,
            'company_share_percent' => 65,
            'status' => 'running',
        ]);

        \App\Models\Investment::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'project_id' => $project->id,
            'investor_id' => $investor->id,
            'amount' => $amount,
            'payment_method' => 'bank',
            'invested_at' => now()->toDateString(),
            'status' => \App\Models\Investment::STATUS_ACTIVE,
        ]);

        return $project;
    }

    public function test_the_erp_investments_address_opens_the_same_company_in_the_office(): void
    {
        $admin = $this->staffOf($this->other, 'super_admin');

        $this->actingAs($admin)->withSession(['current_company_id' => $this->other->id])
            ->get('http://localhost/admin/investments/investors')
            ->assertRedirect('http://group-ims.test/office/investments/investors?office_company='.$this->other->id);
    }
}
