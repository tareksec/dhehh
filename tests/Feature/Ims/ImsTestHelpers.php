<?php

namespace Tests\Feature\Ims;

use App\Models\Company;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\InvestorGroup;
use App\Models\InvestorPortalAccount;
use App\Models\User;
use App\Services\CompanyContext;

/** Shared set-up for the IMS v2 tests: a company with IMS on its own domain. */
trait ImsTestHelpers
{
    protected Company $company;

    protected function imsCompany(array $attributes = []): Company
    {
        $this->company = Company::query()->create([
            'name' => 'IMS Test Co',
            'slug' => 'ims-test-'.uniqid(),
            'invoice_prefix' => 'IT'.random_int(1000, 9999),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'domain' => 'shop-'.uniqid().'.test',
            'is_active' => true,
            'ims_enabled' => true,
            'ims_domain' => 'ims-'.uniqid().'.test',
            ...$attributes,
        ]);

        app(CompanyContext::class)->set($this->company);

        return $this->company;
    }

    protected function staff(string $role = 'super_admin'): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $user->companies()->attach($this->company->getKey(), ['role' => $role, 'is_default' => true]);

        return $user;
    }

    protected function newInvestor(array $attributes = []): Investor
    {
        return Investor::query()->create([
            'company_id' => $this->company->getKey(),
            'name' => 'Investor '.uniqid(),
            'phone' => '017'.random_int(10000000, 99999999),
            'date_of_birth' => '1990-05-01',
            'nid_number' => (string) random_int(1000000000, 9999999999),
            ...$attributes,
        ]);
    }

    protected function verified(Investor $investor): Investor
    {
        $investor->forceFill(['kyc_status' => Investor::KYC_VERIFIED, 'kyc_reviewed_at' => now()])->save();

        return $investor;
    }

    protected function portalAccount(Investor $investor): InvestorPortalAccount
    {
        $account = InvestorPortalAccount::query()->create([
            'company_id' => $this->company->getKey(),
            'investor_id' => $investor->getKey(),
            'name' => $investor->name,
            'phone' => $investor->phone,
            'status' => InvestorPortalAccount::STATUS_ACTIVE,
        ]);
        $account->forceFill(['password' => 'a-strong-password-1'])->save();

        return $account;
    }

    protected function openGroup(array $attributes = []): InvestorGroup
    {
        return InvestorGroup::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Group '.uniqid(), ...$attributes]);
    }

    protected function closedProject(array $attributes = []): InvestmentProject
    {
        return InvestmentProject::query()->create([
            'company_id' => $this->company->getKey(),
            'name' => 'Project '.uniqid(),
            'duration_type' => 'custom_days',
            'trade_cycle_days' => 60,
            'start_date' => now()->subDays(60)->toDateString(),
            'investor_share_percent' => 35,
            'channel_partner_share_percent' => 0,
            'company_share_percent' => 65,
            'status' => 'closed',
            ...$attributes,
        ]);
    }

    protected function imsUrl(string $path): string
    {
        return 'http://'.$this->company->ims_domain.'/'.ltrim($path, '/');
    }
}
