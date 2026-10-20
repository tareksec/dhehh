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

    // ---- Combined lists (18_IMS_ALL_COMPANIES_PLAN.md §4, phase E) ----------

    /**
     * The Project filter: every project the person has money in, keyed
     * "<company id>:<project id>", named with the company when they invest in
     * more than one.
     *
     * @return array<string, string>
     */
    public function projectOptions(InvestorPortalAccount $current): array
    {
        $named = $this->spansCompanies($current);
        $options = [];

        foreach ($this->projects($current) as $row) {
            $label = $this->portal->projectLabel($row['project']);
            $options[$row['company']->getKey().':'.$row['project']->getKey()] = $named ? $label.' · '.$row['company_name'] : $label;
        }

        return $options;
    }

    /**
     * Every statement line from every company, newest first, each with its
     * company and currency; $project ("<company id>:<project id>") keeps one project.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function statement(InvestorPortalAccount $current, ?\Illuminate\Support\Carbon $from = null, ?\Illuminate\Support\Carbon $until = null, ?string $project = null): Collection
    {
        $rows = collect();

        foreach ($this->investorEntries($current) as $entry) {
            $lines = $this->read($entry, fn (): Collection => $this->portal->statement($entry['account'], $from, $until));

            foreach ($lines ?? [] as $line) {
                $rows->push([
                    ...$line,
                    'company_name' => $this->settings->name($entry['company']),
                    'currency' => $this->currency($entry['company']),
                    'project_key' => $line['project_id'] === null ? null : $entry['company']->getKey().':'.$line['project_id'],
                ]);
            }
        }

        return $rows
            ->when(filled($project), fn (Collection $rows): Collection => $rows->where('project_key', $project))
            ->sortByDesc(fn (array $line): string => $line['date']->format('Y-m-d H:i:s').'|'.$line['order'])
            ->values();
    }

    /**
     * Every payout from every company, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function payouts(InvestorPortalAccount $current, ?string $project = null): array
    {
        $rows = [];

        foreach ($this->investorEntries($current) as $entry) {
            $company = $entry['company'];
            $payouts = $this->read($entry, fn (): array => $this->portal->payouts($entry['account'])
                ->with(['settlement.project', 'cycleElection'])->orderByDesc('id')->get()
                ->map(fn (\App\Models\SettlementPayout $payout): array => [
                    'key' => $company->getKey().'-'.$payout->getKey(),
                    'company_name' => $this->settings->name($company),
                    'currency' => $this->currency($company),
                    'project' => app(InvestorPortalCards::class)->title($payout->settlement?->project),
                    'project_key' => $payout->settlement?->project_id ? $company->getKey().':'.$payout->settlement->project_id : null,
                    'investment_amount' => (float) $payout->investment_amount,
                    'stays_invested' => $payout->principal_returned_amount !== null && (float) $payout->principal_returned_amount <= 0,
                    'principal_returned' => (float) $payout->principalReturned(),
                    'profit_share_amount' => (float) $payout->profit_share_amount,
                    'total_payout' => (float) $payout->total_payout,
                    'payment_status' => (string) $payout->payment_status,
                    'paid_at' => $payout->paid_at,
                    'payment_reference' => $payout->payment_reference,
                    'election' => $payout->cycleElection?->election,
                    'proof_url' => $payout->payment_status === 'paid' && $payout->hasPaymentProof()
                        ? $this->link(route('filament.investor.documents.download', ['document' => $payout->paymentProof()?->getKey()]), $company, $current)
                        : null,
                    'report_url' => $this->link(route('filament.investor.reports.payout', ['payout' => $payout->getKey()]), $company, $current),
                    'sort' => ($payout->paid_at?->format('Y-m-d') ?? '9999').'|'.sprintf('%010d', $payout->getKey()),
                ])->all());

            array_push($rows, ...($payouts ?? []));
        }

        return $this->newestFirst($rows, $project);
    }

    /**
     * The person's papers and their projects' shared papers, from every company.
     *
     * @return list<array<string, mixed>>
     */
    public function documents(InvestorPortalAccount $current, ?string $project = null): array
    {
        $rows = [];

        foreach ($this->entries($current) as $entry) {
            $company = $entry['company'];
            $documents = $this->read($entry, fn (): array => $this->portal->documents($entry['account'])->with('documentable')->orderByDesc('id')->get()
                ->map(function (\App\Models\InvestmentDocument $document) use ($company, $current): array {
                    $about = $document->documentable;
                    $projectModel = match (true) {
                        $about instanceof InvestmentProject => $about,
                        $about instanceof Investment => $about->project,
                        $about instanceof \App\Models\SettlementPayout, $about instanceof \App\Models\ChannelPartnerPayout => $about->settlement?->project,
                        default => null,
                    };

                    return [
                        'key' => $company->getKey().'-'.$document->getKey(),
                        'company_name' => $this->settings->name($company),
                        'label' => filled($document->label) ? (string) $document->label : \App\Models\InvestmentDocument::categoryLabel($document->category),
                        'about' => match (true) {
                            $about instanceof \App\Models\SettlementPayout, $about instanceof \App\Models\ChannelPartnerPayout => __('Payment for :project', ['project' => $this->portal->projectLabel($projectModel)]),
                            $projectModel !== null => $this->portal->projectLabel($projectModel),
                            default => __('You'),
                        },
                        'project_key' => $projectModel ? $company->getKey().':'.$projectModel->getKey() : null,
                        'created_at' => $document->created_at,
                        'download_url' => $this->link(route('filament.investor.documents.download', ['document' => $document->getKey()]), $company, $current),
                        'sort' => ($document->created_at?->format('Y-m-d H:i:s') ?? '').'|'.sprintf('%010d', $document->getKey()),
                    ];
                })->all());

            array_push($rows, ...($documents ?? []));
        }

        return $this->newestFirst($rows, $project);
    }

    /**
     * The person's requests in every company, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function requests(InvestorPortalAccount $current, ?string $project = null): array
    {
        $rows = [];
        $targets = app(\App\Services\Investment\InvestmentTargets::class);

        foreach ($this->entries($current) as $entry) {
            $company = $entry['company'];
            $requests = $this->read($entry, fn (): array => \App\Models\InvestorRequest::query()
                ->where('portal_account_id', $entry['account']->getKey())->with(['project', 'group'])->orderByDesc('id')->get()
                ->map(fn (\App\Models\InvestorRequest $request): array => [
                    'key' => $company->getKey().'-'.$request->getKey(),
                    'company_id' => (int) $company->getKey(),
                    'request_id' => (int) $request->getKey(),
                    'company_name' => $this->settings->name($company),
                    'currency' => $this->currency($company),
                    'type' => (string) $request->type,
                    'about' => collect([
                        $request->group ? $targets->label($request->group) : null,
                        $request->project ? $this->portal->projectLabel($request->project) : null,
                    ])->filter()->implode(' · '),
                    'project_key' => $request->project_id ? $company->getKey().':'.$request->project_id : null,
                    'amount' => $request->amount === null ? null : (float) $request->amount,
                    'created_at' => $request->created_at,
                    'status' => (string) $request->status,
                    'admin_note' => $request->admin_note,
                    'pending' => $request->isPending(),
                    'sort' => ($request->created_at?->format('Y-m-d H:i:s') ?? '').'|'.sprintf('%010d', $request->getKey()),
                ])->all());

            array_push($rows, ...($requests ?? []));
        }

        return $this->newestFirst($rows, $project);
    }

    /**
     * The person's account and company for "<company id>:..." from a combined
     * list or choice, or null when it is not one of their companies.
     *
     * @return array{company: Company, account: InvestorPortalAccount}|null
     */
    public function entryFor(InvestorPortalAccount $current, int $companyId): ?array
    {
        return $this->entries($current)->first(fn (array $entry): bool => (int) $entry['company']->getKey() === $companyId);
    }

    /**
     * Runs $callback under $companyId with the person's own account there.
     *
     * @param  callable(InvestorPortalAccount): mixed  $callback
     */
    public function inCompany(InvestorPortalAccount $current, int $companyId, callable $callback): mixed
    {
        $entry = $this->entryFor($current, $companyId);

        abort_if($entry === null, 404);

        return $this->context->within($entry['company'], fn (): mixed => $callback($entry['account']));
    }

    /**
     * A request form's choices from every company (InvestmentTargets for new
     * money, the person's groups and loose projects for a withdrawal notice,
     * their pending payouts for a payout choice), keyed "<company id>:<key>".
     *
     * @param  callable(InvestorPortalAccount): array<string, string>  $options  read under each company
     * @return array<string, string>
     */
    public function choices(InvestorPortalAccount $current, callable $options, ?int $onlyCompany = null): array
    {
        $named = $this->spansCompanies($current);
        $choices = [];

        foreach ($this->investorEntries($current) as $entry) {
            if ($onlyCompany !== null && (int) $entry['company']->getKey() !== $onlyCompany) {
                continue;
            }

            foreach ($this->read($entry, fn (): array => $options($entry['account'])) ?? [] as $key => $label) {
                $choices[$entry['company']->getKey().':'.$key] = $named ? $label.' · '.$this->settings->name($entry['company']) : $label;
            }
        }

        return $choices;
    }

    /** "<company id>:<key>" → [company id, key], or null. */
    public static function splitChoice(?string $value): ?array
    {
        if (! is_string($value) || ! preg_match('/^(\d+):(.+)$/', $value, $match)) {
            return null;
        }

        return [(int) $match[1], $match[2]];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function newestFirst(array $rows, ?string $project): array
    {
        return collect($rows)
            ->when(filled($project), fn (Collection $rows): Collection => $rows->where('project_key', $project))
            ->sortByDesc('sort')
            ->values()
            ->all();
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
