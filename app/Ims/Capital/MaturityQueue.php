<?php

namespace App\Ims\Capital;

use App\Models\Investment;
use App\Models\InvestorGroupMember;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Money with a tenor whose maturity has come or is near
 * (IMS-V2/PRD.md FR-53): capital still to be paid back, for the office's
 * "Capital returns" page, the to-do list and the 7-day reminder.
 */
class MaturityQueue
{
    public function __construct(private CapitalCalculator $capital) {}

    /**
     * Everything maturing on or before today + $days that still has capital
     * to pay back, earliest first.
     *
     * @return Collection<int, array{record: Investment|InvestorGroupMember, investor_id: int, maturity_date: CarbonImmutable, outstanding: float, overdue: bool}>
     */
    public function dueWithin(int $days, ?CarbonImmutable $today = null): Collection
    {
        $today ??= CarbonImmutable::today();
        $until = $today->addDays($days)->toDateString();

        $rows = collect();

        foreach (Investment::query()->active()->whereNull('group_member_id')->whereNotNull('maturity_date')->where('maturity_date', '<=', $until)->with('investor')->get() as $investment) {
            $rows->push($this->row($investment, $this->capital->forInvestment($investment)['outstanding'], $today));
        }

        foreach (InvestorGroupMember::query()->active()->whereNotNull('maturity_date')->where('maturity_date', '<=', $until)->with('investor')->get() as $member) {
            $rows->push($this->row($member, $this->capital->forMember($member)['outstanding'], $today));
        }

        return $rows->filter(fn (array $row): bool => $row['outstanding'] > 0)
            ->sortBy(fn (array $row): string => $row['maturity_date']->toDateString())
            ->values();
    }

    /** @return array{record: Investment|InvestorGroupMember, investor_id: int, maturity_date: CarbonImmutable, outstanding: float, overdue: bool} */
    private function row(Investment|InvestorGroupMember $record, float $outstanding, CarbonImmutable $today): array
    {
        $date = CarbonImmutable::parse($record->maturity_date);

        return [
            'record' => $record,
            'investor_id' => (int) $record->investor_id,
            'maturity_date' => $date,
            'outstanding' => $outstanding,
            'overdue' => $date->lt($today),
        ];
    }
}
