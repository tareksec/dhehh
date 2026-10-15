<?php

namespace App\Services;

use App\Models\Investment;
use App\Models\InvestmentDocument;
use App\Models\InvestmentProject;
use App\Models\InvestorGroup;
use App\Models\InvestorGroupMember;
use App\Models\InvestorPortalAccount;
use App\Models\SettlementPayout;
use App\Services\Investment\GroupCycleCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the investor's dashboard shows, one card per project the person is in
 * (16_INVESTOR_GROUPS_PLAN.md §3.7, owner 2026-10-12):
 *
 *  - while the project runs: a progress bar towards its end date;
 *  - when its cycle is over: the bar at 100% and "Project N completed";
 *  - once the profit is confirmed: the figures and "payment on the way";
 *  - once the payout is paid but its screenshot is not uploaded yet: "paid";
 *  - once the payout is PAID AND its screenshot is uploaded: the card leaves
 *    the dashboard. The full result stays on the Payouts page.
 *
 * Cards are built from the person's own records only, through
 * InvestorPortalService, and are grouped under the group they belong to.
 */
class InvestorPortalCards
{
    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const PAYMENT_PENDING = 'payment_pending';

    public const PAID = 'paid';

    public const WITHDRAWN = 'withdrawn';

    public const DONE = 'done';

    public function __construct(private InvestorPortalService $portal) {}

    /**
     * @return array{groups: Collection<int, array<string, mixed>>, legacy: Collection<int, array<string, mixed>>}
     */
    public function dashboard(InvestorPortalAccount $account, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now()->startOfDay();
        $investorId = $account->investor_id ?? 0;

        $members = InvestorGroupMember::query()->where('investor_id', $investorId)->with('group')->orderBy('id')->get();
        $rows = $this->portal->investments($account)->with(['project.settlement', 'project.group', 'groupMember'])->orderBy('id')->get();
        $payouts = $this->payoutsBySettlement($account);
        $withProof = $this->payoutsWithProof($payouts);

        // One card per project, even when the person has more than one deposit in the group.
        $cards = $rows->groupBy('project_id')
            ->map(function (Collection $projectRows) use ($payouts, $withProof, $today): array {
                $active = $projectRows->reject(fn (Investment $row): bool => $row->isWithdrawn());
                $first = $active->first() ?? $projectRows->first();

                return $this->card(
                    $first,
                    $payouts->get($first->project?->settlement?->getKey()),
                    $withProof,
                    $today,
                    $active->isEmpty() ? (float) $first->amount : round((float) $active->sum('amount'), 2),
                    $active->isEmpty(),
                );
            })
            ->filter(fn (array $card): bool => ! in_array($card['state'], [self::DONE, self::WITHDRAWN], true))
            ->sortByDesc(fn (array $card): string => ($card['project']?->start_date?->format('Y-m-d') ?? '').'|'.str_pad((string) $card['investment']->getKey(), 10, '0', STR_PAD_LEFT))
            ->values();

        $groups = $members->groupBy('group_id')->map(function (Collection $groupMembers) use ($cards): ?array {
            /** @var InvestorGroup|null $group */
            $group = $groupMembers->first()->group;
            $groupCards = $cards->filter(fn (array $card): bool => (int) $card['project']?->group_id === (int) $group?->getKey())->values();
            $active = $groupMembers->where('status', InvestorGroupMember::STATUS_ACTIVE);

            // A group the person has left shows only while something of it is still on the way.
            if ($group === null || ($active->isEmpty() && $groupCards->isEmpty())) {
                return null;
            }

            return [
                'group' => $group,
                'amount' => round((float) $active->sum('amount'), 2),
                'since' => $groupMembers->min('invested_at'),
                'leaving' => $active->isEmpty(),
                'cards' => $groupCards,
            ];
        })->filter()->values();

        $legacy = $cards->filter(fn (array $card): bool => $card['investment']->group_member_id === null)->values();

        return ['groups' => $groups, 'legacy' => $legacy];
    }

    /**
     * One investment row (the person's share of one project) as a dashboard card.
     *
     * @param  Collection<int, mixed>|null  $withProof  ids of payouts that have a screenshot
     * @return array<string, mixed>
     */
    public function card(Investment $row, ?SettlementPayout $payout, ?Collection $withProof, CarbonImmutable $today, ?float $amount = null, ?bool $withdrawn = null): array
    {
        $project = $row->project;
        $settlement = $project?->settlement;
        $final = $settlement && in_array($settlement->status, InvestorPortalService::FINAL_SETTLEMENT_STATUSES, true) ? $settlement : null;
        $hasProof = $payout !== null && $withProof !== null && $withProof->contains($payout->getKey());

        $state = match (true) {
            ($withdrawn ?? $row->isWithdrawn()) => self::WITHDRAWN,
            $final !== null && $payout !== null && $payout->payment_status === 'paid' && $hasProof => self::DONE,
            $final !== null && $payout !== null && $payout->payment_status === 'paid' => self::PAID,
            $final !== null => self::PAYMENT_PENDING,
            $project !== null && in_array($project->status, ['closed', 'settled'], true) => self::COMPLETED,
            default => self::RUNNING,
        };

        return [
            'investment' => $row,
            'project' => $project,
            'group' => $project?->group,
            'state' => $state,
            'progress' => $this->progress($project, $state, $today),
            'title' => $this->title($project),
            'number' => $project?->sequence_in_group,
            'amount' => $amount ?? (float) $row->amount,
            'ends_on' => $project?->end_date,
            'settlement' => $final,
            'payout' => $payout,
            'profit' => $payout ? (float) $payout->profit_share_amount : null,
            'principal_returned' => $payout?->principal_returned_amount !== null ? (float) $payout->principal_returned_amount : null,
            'total_payout' => $payout ? (float) $payout->total_payout : null,
        ];
    }

    /** 0–100: how far through its dates the project is; 100 once its cycle is over. */
    public function progress(?InvestmentProject $project, string $state, CarbonImmutable $today): int
    {
        if ($project === null) {
            return 0;
        }

        if ($state !== self::RUNNING) {
            return 100;
        }

        $start = CarbonImmutable::parse($project->start_date);
        $end = $project->end_date
            ? CarbonImmutable::parse($project->end_date)
            : ($project->trade_cycle_days ? $start->addDays((int) $project->trade_cycle_days) : null);

        // An open project has no end to measure against.
        if ($end === null || $project->status === 'open') {
            return 0;
        }

        return GroupCycleCalendar::progressPercent($start, $end, $today);
    }

    public function title(?InvestmentProject $project): string
    {
        if ($project === null) {
            return '-';
        }

        return $project->group
            ? trim($project->group->name.' · '.__('Project :number', ['number' => $project->sequence_in_group]))
            : $this->portal->projectLabel($project);
    }

    /**
     * The person's group projects that fall in the current calendar year, by
     * group, for the dashboard's project calendar: each with its dates and
     * where it stands (paid, confirmed, completed, running or upcoming).
     * Projects outside any group and withdrawn shares are not on it.
     *
     * @return Collection<int, array{group: InvestorGroup, entries: Collection<int, array{number: ?int, starts_on: ?CarbonImmutable, ends_on: ?CarbonImmutable, state: string}>}>
     */
    public function calendar(InvestorPortalAccount $account, ?CarbonImmutable $today = null): Collection
    {
        $today ??= CarbonImmutable::now()->startOfDay();
        $yearStart = $today->startOfYear();
        $yearEnd = $today->endOfYear();

        $rows = $this->portal->investments($account)->whereNotNull('group_member_id')->with(['project.settlement', 'project.group'])->orderBy('id')->get();
        $payouts = $this->payoutsBySettlement($account);
        $withProof = $this->payoutsWithProof($payouts);

        return $rows->groupBy('project_id')
            ->map(function (Collection $projectRows) use ($payouts, $withProof, $today): array {
                $active = $projectRows->reject(fn (Investment $row): bool => $row->isWithdrawn());
                $first = $active->first() ?? $projectRows->first();

                return $this->card($first, $payouts->get($first->project?->settlement?->getKey()), $withProof, $today, null, $active->isEmpty());
            })
            ->filter(function (array $card) use ($yearStart, $yearEnd): bool {
                $project = $card['project'];

                if ($card['state'] === self::WITHDRAWN || $project?->group === null || $project->start_date === null) {
                    return false;
                }

                $ends = $project->end_date ?? $project->start_date;

                return $project->start_date->lte($yearEnd) && $ends->gte($yearStart);
            })
            ->groupBy(fn (array $card): int => (int) $card['project']->group_id)
            ->map(fn (Collection $cards): array => [
                'group' => $cards->first()['project']->group,
                'entries' => $cards
                    ->sortBy(fn (array $card): int => (int) $card['project']->sequence_in_group)
                    ->map(fn (array $card): array => [
                        'number' => $card['number'],
                        'starts_on' => CarbonImmutable::parse($card['project']->start_date),
                        'ends_on' => $card['project']->end_date ? CarbonImmutable::parse($card['project']->end_date) : null,
                        'state' => $this->calendarState($card, $today),
                    ])
                    ->values(),
            ])
            ->sortBy(fn (array $row): string => (string) $row['group']->group_code)
            ->values();
    }

    /** paid, confirmed, completed, running or upcoming. */
    public function calendarState(array $card, CarbonImmutable $today): string
    {
        return match ($card['state']) {
            self::DONE, self::PAID => 'paid',
            self::PAYMENT_PENDING => 'confirmed',
            self::COMPLETED => 'completed',
            default => $card['project']?->status === 'open' || ($card['project']?->start_date && $card['project']->start_date->gt($today)) ? 'upcoming' : 'running',
        };
    }

    /**
     * The payout to follow on the dashboard: money on its way first, then a
     * paid payout still waiting for its screenshot, then a finished project
     * whose profit is being calculated. Null when none of the cards is there.
     *
     * @param  Collection<int, array<string, mixed>>  $cards
     * @return array{card: array<string, mixed>, steps: list<array{label: string, state: string}>}|null
     */
    public function nextPayout(Collection $cards): ?array
    {
        foreach ([self::PAYMENT_PENDING, self::PAID, self::COMPLETED] as $state) {
            $card = $cards->first(fn (array $card): bool => $card['state'] === $state);

            if ($card === null) {
                continue;
            }

            $steps = [
                ['label' => __('Project completed'), 'state' => 'done'],
                ['label' => __('Profit confirmed'), 'state' => $state === self::COMPLETED ? 'current' : 'done'],
                ['label' => __('Payment sent'), 'state' => match ($state) {
                    self::PAID => 'done',
                    self::PAYMENT_PENDING => 'current',
                    default => 'next',
                }],
                ['label' => __('Screenshot added'), 'state' => $state === self::PAID ? 'current' : 'next'],
            ];

            return ['card' => $card, 'steps' => $steps];
        }

        return null;
    }

    /** Payouts of the person on confirmed settlements, by settlement id. */
    private function payoutsBySettlement(InvestorPortalAccount $account): Collection
    {
        return $this->portal->payouts($account)->get()->keyBy('settlement_id');
    }

    /** @return Collection<int, int> ids of the payouts that have a payment screenshot */
    private function payoutsWithProof(Collection $payouts): Collection
    {
        if ($payouts->isEmpty()) {
            return collect();
        }

        return InvestmentDocument::query()
            ->where('documentable_type', (new SettlementPayout)->getMorphClass())
            ->whereIn('documentable_id', $payouts->pluck('id'))
            ->where('category', InvestmentDocument::CATEGORY_PAYMENT_PROOF)
            ->pluck('documentable_id')
            ->unique()
            ->values();
    }
}
