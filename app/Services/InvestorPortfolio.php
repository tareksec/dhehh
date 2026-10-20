<?php

namespace App\Services;

use App\Filament\Ims\Portal\Pages\ProjectDetail;
use App\Models\Company;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\InvestorGroup;
use App\Models\InvestorPortalAccount;
use App\Support\ModuleGuard;
use Illuminate\Support\Collection;

/**
 * One person's investments across every company (18_IMS_ALL_COMPANIES_PLAN.md
 * §4): the person is the same phone with an active account in each company
 * (InvestorBusinessSwitchService::businesses()). Every figure is read under
 * its own company's context and only then put together, so no query mixes
 * companies; money is added up per currency, never across currencies.
 *
 * A staff preview or an applicant sees only the account it is on.
 */
class InvestorPortfolio
{
    /** Opens another company's page from a link (OpenLinkedInvestorBusiness). */
    public const QUERY_KEY = 'business';

    public function __construct(
        protected CompanyContext $context,
        protected InvestorBusinessSwitchService $switches,
        protected InvestorPortalService $portal,
        protected CompanySettingsService $settings,
    ) {}

    /**
     * The person's companies with their account in each, the current one first.
     *
     * @return Collection<int, array{company: Company, account: InvestorPortalAccount}>
     */
    public function entries(InvestorPortalAccount $current): Collection
    {
        $currentCompany = Company::query()->find($current->company_id);
        $entries = $this->switches->businesses($current);

        if ($entries->isEmpty()) {
            return $currentCompany ? collect([['company' => $currentCompany, 'account' => $current]]) : collect();
        }

        return $entries
            ->sortBy(fn (array $entry): int => (int) $entry['company']->getKey() === (int) $current->company_id ? 0 : 1)
            ->values();
    }

    /** Whether the person has accounts in more than one company. */
    public function spansCompanies(InvestorPortalAccount $current): bool
    {
        return $this->entries($current)->count() > 1;
    }

    /**
     * The home's totals for every company added up per currency.
     *
     * @return array<string, array{capital_at_work: float, total_invested: float, profit_received: float, profit_pending: float, paid_out: float}>
     */
    public function summary(InvestorPortalAccount $current): array
    {
        $totals = [];

        foreach ($this->investorEntries($current) as $entry) {
            $summary = $this->read($entry, fn (): array => $this->portal->investorSummary($entry['account']));

            if (! is_array($summary)) {
                continue;
            }

            $currency = $this->currency($entry['company']);
            $sum = $totals[$currency] ?? array_fill_keys(array_keys($summary), 0.0);

            foreach ($summary as $key => $value) {
                $sum[$key] = round($sum[$key] + (float) $value, 2);
            }

            $totals[$currency] = $sum;
        }

        return $totals;
    }

    /**
     * Every project the person put money in, from every company, newest first.
     *
     * @return list<array{company: Company, company_name: string, project: InvestmentProject, currency: string, url: string}>
     */
    public function projects(InvestorPortalAccount $current): array
    {
        $rows = [];

        foreach ($this->investorEntries($current) as $entry) {
            $projects = $this->read($entry, fn (): Collection => InvestmentProject::query()
                ->whereIn('id', Investment::query()->where('investor_id', $entry['account']->investor_id)->select('project_id'))
                ->with('group')->get());

            foreach ($projects ?? [] as $project) {
                $rows[] = $this->projectRow($entry['company'], $project, $current);
            }
        }

        usort($rows, fn (array $a, array $b): int => [$b['project']->start_date?->format('Y-m-d') ?? '', $b['project']->getKey()]
            <=> [$a['project']->start_date?->format('Y-m-d') ?? '', $a['project']->getKey()]);

        return $rows;
    }

    /**
     * What is raising money now in every company the person invests in.
     *
     * @return array{groups: list<array{company: Company, company_name: string, group: InvestorGroup, url: string|null}>, projects: list<array{company: Company, company_name: string, project: InvestmentProject, currency: string, url: string}>}
     */
    public function raising(InvestorPortalAccount $current, ?string $requestsUrl = null): array
    {
        $groups = [];
        $projects = [];

        foreach ($this->investorEntries($current) as $entry) {
            $found = $this->read($entry, fn (): array => [
                InvestorGroup::query()->where('status', InvestorGroup::STATUS_OPEN)->orderByDesc('id')->get(),
                InvestmentProject::query()->ungrouped()->where('status', 'open')->orderByDesc('id')->get(),
            ]);

            if (! is_array($found)) {
                continue;
            }

            foreach ($found[0] as $group) {
                $groups[] = [
                    'company' => $entry['company'],
                    'company_name' => $this->settings->name($entry['company']),
                    'group' => $group,
                    'url' => $requestsUrl === null ? null : $this->link($requestsUrl, $entry['company'], $current),
                ];
            }

            foreach ($found[1] as $project) {
                $projects[] = $this->projectRow($entry['company'], $project, $current);
            }
        }

        return ['groups' => $groups, 'projects' => $projects];
    }

    /** $url opening $company for this person: the current company as it is, another with ?business=<id>. */
    public function link(string $url, Company $company, InvestorPortalAccount $current): string
    {
        if ((int) $company->getKey() === (int) $current->company_id) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').self::QUERY_KEY.'='.$company->getKey();
    }

    /** @return array{company: Company, company_name: string, project: InvestmentProject, currency: string, url: string} */
    protected function projectRow(Company $company, InvestmentProject $project, InvestorPortalAccount $current): array
    {
        return [
            'company' => $company,
            'company_name' => $this->settings->name($company),
            'project' => $project,
            'currency' => $this->currency($company),
            'url' => $this->link(ProjectDetail::getUrl(['project' => $project->getKey()], panel: 'investor'), $company, $current),
        ];
    }

    /** @return Collection<int, array{company: Company, account: InvestorPortalAccount}> */
    protected function investorEntries(InvestorPortalAccount $current): Collection
    {
        return $this->entries($current)->filter(fn (array $entry): bool => $entry['account']->investor_id !== null)->values();
    }

    /**
     * Reads under the entry's company; one company's failure leaves that company out.
     *
     * @param  array{company: Company, account: InvestorPortalAccount}  $entry
     */
    protected function read(array $entry, callable $callback): mixed
    {
        return ModuleGuard::run(
            'investor-portfolio.company-'.$entry['company']->getKey(),
            fn (): mixed => $this->context->within($entry['company'], $callback),
        );
    }

    protected function currency(Company $company): string
    {
        return $company->currency ?: 'BDT';
    }
}
