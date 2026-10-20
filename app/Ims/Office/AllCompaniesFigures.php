<?php

namespace App\Ims\Office;

use App\Ims\Todo\OwnerTodo;
use App\Models\Company;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\InvestorGroupMember;
use App\Models\SettlementPayout;
use App\Services\CompanyContext;
use App\Support\ModuleGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The office's "All companies" home (18_IMS_ALL_COMPANIES_PLAN.md §3.3).
 * Every figure is read company by company, each under its own
 * CompanyContext, and only then added up, so no query ever mixes companies.
 * Totals are kept per currency and never added across currencies. One
 * company's failure leaves that company out (ModuleGuard), not the page.
 */
class AllCompaniesFigures
{
    public function __construct(
        private CompanyContext $context,
        private OfficeFigures $figures,
        private OwnerTodo $todo,
    ) {}

    /**
     * @param  Collection<int, Company>  $companies
     * @return list<array{company: Company, currency: string, invested: float, capital_at_work: float, received_this_month: float, profit_paid_this_month: float, payouts_due: float, payouts_due_count: int, new_investors: int, waiting: list<array{key: string, label: string, count: int, page: class-string|null, tone: string}>, projects: list<array{project: InvestmentProject, invested: float}>}>
     */
    public function perCompany(Collection $companies, ?CarbonImmutable $now = null): array
    {
        $rows = [];

        foreach ($companies as $company) {
            $row = ModuleGuard::run('ims.all-companies.company-'.$company->getKey(), fn (): array => $this->context->within($company, fn (): array => $this->read($company, $now)));

            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The money figures added up per currency.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{invested: float, capital_at_work: float, received_this_month: float, payouts_due: float, payouts_due_count: int}>
     */
    public function totals(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $sum = $totals[$row['currency']] ?? ['invested' => 0.0, 'capital_at_work' => 0.0, 'received_this_month' => 0.0, 'payouts_due' => 0.0, 'payouts_due_count' => 0];

            foreach (['invested', 'capital_at_work', 'received_this_month', 'payouts_due'] as $key) {
                $sum[$key] = round($sum[$key] + $row[$key], 2);
            }

            $sum['payouts_due_count'] += $row['payouts_due_count'];
            $totals[$row['currency']] = $sum;
        }

        return $totals;
    }

    /** @return array<string, mixed> */
    private function read(Company $company, ?CarbonImmutable $now): array
    {
        $now ??= CarbonImmutable::now();
        $confirmed = [InvestorGroupMember::STATUS_ACTIVE, InvestorGroupMember::STATUS_WITHDRAWN];

        $invested = (float) InvestorGroupMember::query()->whereIn('status', $confirmed)->sum('amount')
            + (float) Investment::query()->whereNull('group_member_id')->whereIn('status', [Investment::STATUS_ACTIVE, Investment::STATUS_WITHDRAWN])->sum('amount');

        $due = SettlementPayout::query()->where('payment_status', 'pending')
            ->whereHas('settlement', fn ($query) => $query->where('status', 'confirmed'));

        return [
            'company' => $company,
            'currency' => $company->currency ?: 'BDT',
            'invested' => round($invested, 2),
            'capital_at_work' => $this->figures->capitalAtWork(),
            'received_this_month' => $this->figures->receivedThisMonth($now),
            'profit_paid_this_month' => $this->figures->profitPaidThisMonth($now),
            'payouts_due' => round((float) (clone $due)->sum('total_payout'), 2),
            'payouts_due_count' => (clone $due)->count(),
            'new_investors' => Investor::query()->whereBetween('created_at', [$now->startOfMonth(), $now->endOfMonth()])->count(),
            'waiting' => $this->todo->items(),
            'projects' => InvestmentProject::query()->whereIn('status', ['open', 'running'])->orderBy('name')->get()
                ->map(fn (InvestmentProject $project): array => ['project' => $project, 'invested' => (float) $project->totalInvested()])
                ->all(),
        ];
    }
}
