<?php

namespace App\Ims\Office;

use App\Ims\Capital\CapitalCalculator;
use App\Models\Investment;
use App\Models\InvestorGroup;
use App\Models\InvestorGroupMember;
use App\Models\SettlementPayout;
use Carbon\CarbonImmutable;

/**
 * The four big numbers on the office home (IMS-V2/PRD.md FR-80), for the
 * company in CompanyContext. Every capital figure comes from
 * CapitalCalculator (TRD §4.3), so home, portal and statements agree.
 */
class OfficeFigures
{
    public function __construct(private CapitalCalculator $capital) {}

    /** Capital that is invested right now: active group deposits and active ungrouped investments, after losses and returns. */
    public function capitalAtWork(): float
    {
        $total = 0.0;

        foreach (InvestorGroupMember::query()->active()->whereHas('group', fn ($query) => $query->where('status', '!=', InvestorGroup::STATUS_CLOSED))->get() as $member) {
            $total += $this->capital->forMember($member)['outstanding'];
        }

        foreach (Investment::query()->active()->whereNull('group_member_id')->whereHas('project', fn ($query) => $query->where('status', '!=', 'settled'))->get() as $investment) {
            $total += $this->capital->forInvestment($investment)['outstanding'];
        }

        return round($total, 2);
    }

    /** Money that came in this month (confirmed deposits only). */
    public function receivedThisMonth(?CarbonImmutable $now = null): float
    {
        [$from, $to] = $this->month($now);

        $members = (float) InvestorGroupMember::query()->whereIn('status', [InvestorGroupMember::STATUS_ACTIVE, InvestorGroupMember::STATUS_WITHDRAWN])
            ->whereBetween('invested_at', [$from, $to])->sum('amount');
        $loose = (float) Investment::query()->whereNull('group_member_id')->whereIn('status', [Investment::STATUS_ACTIVE, Investment::STATUS_WITHDRAWN])
            ->whereBetween('invested_at', [$from, $to])->sum('amount');

        return round($members + $loose, 2);
    }

    /** Profit paid to investors this month. */
    public function profitPaidThisMonth(?CarbonImmutable $now = null): float
    {
        [$from, $to] = $this->month($now);

        return round((float) SettlementPayout::query()->where('payment_status', 'paid')
            ->whereBetween('paid_at', [$from, $to])->where('profit_share_amount', '>', 0)->sum('profit_share_amount'), 2);
    }

    /** Payouts confirmed and still to be paid. */
    public function payoutsDue(): float
    {
        return round((float) SettlementPayout::query()->where('payment_status', 'pending')
            ->whereHas('settlement', fn ($query) => $query->where('status', 'confirmed'))->sum('total_payout'), 2);
    }

    /**
     * Profit paid to investors per month, oldest first, for the bar chart on the
     * office home: "Y-m" => amount, a month with nothing paid is 0.
     *
     * @return array<string, float>
     */
    public function profitPaidByMonth(int $months = 12, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $first = $now->startOfMonth()->subMonthsNoOverflow($months - 1);
        $series = [];

        for ($month = $first; $month <= $now; $month = $month->addMonthNoOverflow()) {
            $series[$month->format('Y-m')] = 0.0;
        }

        SettlementPayout::query()->where('payment_status', 'paid')->where('profit_share_amount', '>', 0)
            ->whereBetween('paid_at', [$first->toDateString(), $now->endOfMonth()->toDateString()])
            ->get(['paid_at', 'profit_share_amount'])
            ->each(function (SettlementPayout $payout) use (&$series): void {
                $key = $payout->paid_at?->format('Y-m');

                if ($key !== null && array_key_exists($key, $series)) {
                    $series[$key] = round($series[$key] + (float) $payout->profit_share_amount, 2);
                }
            });

        return $series;
    }

    /**
     * The latest money recorded from investors, newest first: group deposits
     * and investments outside a group, confirmed or still waiting.
     *
     * @return list<array{name: string, date: \Carbon\CarbonInterface|null, where: string, waiting: bool, amount: float}>
     */
    public function latestMoneyIn(int $limit = 5): array
    {
        $members = InvestorGroupMember::query()->with(['investor:id,name', 'group:id,group_code,name'])
            ->whereIn('status', [InvestorGroupMember::STATUS_PENDING, InvestorGroupMember::STATUS_ACTIVE])
            ->latest('invested_at')->latest('id')->limit($limit)->get()
            ->map(fn (InvestorGroupMember $member): array => [
                'name' => (string) $member->investor?->name,
                'date' => $member->invested_at,
                'where' => trim($member->group?->group_code.' '.$member->group?->name),
                'waiting' => $member->status === InvestorGroupMember::STATUS_PENDING,
                'amount' => (float) $member->amount,
                'sort' => $member->invested_at?->format('Y-m-d').sprintf('-%010d', $member->id),
            ]);

        $investments = Investment::query()->with(['investor:id,name', 'project:id,project_code,name'])
            ->whereNull('group_member_id')->whereIn('status', [Investment::STATUS_PENDING, Investment::STATUS_ACTIVE])
            ->latest('invested_at')->latest('id')->limit($limit)->get()
            ->map(fn (Investment $investment): array => [
                'name' => (string) $investment->investor?->name,
                'date' => $investment->invested_at,
                'where' => trim($investment->project?->project_code.' '.$investment->project?->name),
                'waiting' => $investment->status === Investment::STATUS_PENDING,
                'amount' => (float) $investment->amount,
                'sort' => $investment->invested_at?->format('Y-m-d').sprintf('-%010d', $investment->id),
            ]);

        return $members->concat($investments)->sortByDesc('sort')->take($limit)
            ->map(fn (array $row): array => collect($row)->except('sort')->all())->values()->all();
    }

    /** @return array{0: string, 1: string} */
    private function month(?CarbonImmutable $now): array
    {
        $now ??= CarbonImmutable::now();

        return [$now->startOfMonth()->toDateString(), $now->endOfMonth()->toDateString()];
    }
}
