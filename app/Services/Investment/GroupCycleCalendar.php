<?php

namespace App\Services\Investment;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Dates of a group's projects (16_INVESTOR_GROUPS_PLAN.md §3.4, owner
 * 2026-10-12: "every calendar 2 months" from the day the group went Running).
 *
 * Every date is counted from the ANCHOR (the day the group went Running), never
 * from the previous project's end, and month ends use Carbon's no-overflow rule.
 * So an anchor on the 31st does not drift: 31 Aug → 31 Oct → 31 Dec → 28/29 Feb
 * → 30 Apr. Project N runs from `start(N)` up to, but not including, `end(N)`;
 * project N+1 starts on the day project N ends.
 */
final class GroupCycleCalendar
{
    public static function start(CarbonInterface $anchor, int $cycleMonths, int $sequence): CarbonImmutable
    {
        return CarbonImmutable::parse($anchor->toDateString())
            ->addMonthsNoOverflow(max($sequence - 1, 0) * max($cycleMonths, 1));
    }

    public static function end(CarbonInterface $anchor, int $cycleMonths, int $sequence): CarbonImmutable
    {
        return CarbonImmutable::parse($anchor->toDateString())
            ->addMonthsNoOverflow(max($sequence, 1) * max($cycleMonths, 1));
    }

    /** How many whole project cycles have ended on or before $today (0 before the first end date). */
    public static function completedCycles(CarbonInterface $anchor, int $cycleMonths, CarbonInterface $today): int
    {
        $today = CarbonImmutable::parse($today->toDateString());
        $anchorDay = CarbonImmutable::parse($anchor->toDateString());

        if ($today->lt($anchorDay)) {
            return 0;
        }

        // Start from a close estimate, then correct it either way, so a month
        // end that was clamped (31st → 28th) is still counted on the right day.
        $cycles = intdiv((int) $anchorDay->diffInMonths($today), max($cycleMonths, 1));

        while (self::end($anchor, $cycleMonths, $cycles + 1)->lte($today)) {
            $cycles++;
        }

        while ($cycles > 0 && self::end($anchor, $cycleMonths, $cycles)->gt($today)) {
            $cycles--;
        }

        return $cycles;
    }

    /** Which project (1, 2, 3 …) is running on $today. Null before the anchor. */
    public static function runningSequence(CarbonInterface $anchor, int $cycleMonths, CarbonInterface $today): ?int
    {
        if (CarbonImmutable::parse($today->toDateString())->lt(CarbonImmutable::parse($anchor->toDateString()))) {
            return null;
        }

        return self::completedCycles($anchor, $cycleMonths, $today) + 1;
    }

    /** 0–100: how far through the project's dates $today is. */
    public static function progressPercent(CarbonInterface $start, CarbonInterface $end, CarbonInterface $today): int
    {
        $start = CarbonImmutable::parse($start->toDateString());
        $end = CarbonImmutable::parse($end->toDateString());
        $today = CarbonImmutable::parse($today->toDateString());

        $totalDays = $start->diffInDays($end);

        if ($totalDays <= 0 || $today->gte($end)) {
            return 100;
        }

        if ($today->lte($start)) {
            return 0;
        }

        return (int) min(100, max(0, floor($start->diffInDays($today) / $totalDays * 100)));
    }
}
