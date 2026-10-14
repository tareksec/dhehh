<?php

namespace Tests\Unit;

use App\Services\Investment\GroupCycleCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The dates of a group's projects (owner, 2026-10-12: every calendar 2 months
 * from the day the group went Running). A wrong date here starts or closes a
 * project on the wrong day, so the boundaries are pinned down one by one.
 */
class GroupCycleCalendarTest extends TestCase
{
    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    public function test_projects_follow_each_other_every_two_calendar_months(): void
    {
        $anchor = $this->day('2026-01-01');

        $this->assertSame('2026-01-01', GroupCycleCalendar::start($anchor, 2, 1)->toDateString());
        $this->assertSame('2026-03-01', GroupCycleCalendar::end($anchor, 2, 1)->toDateString());
        $this->assertSame('2026-03-01', GroupCycleCalendar::start($anchor, 2, 2)->toDateString());
        $this->assertSame('2026-05-01', GroupCycleCalendar::end($anchor, 2, 2)->toDateString());
        $this->assertSame('2026-05-01', GroupCycleCalendar::start($anchor, 2, 3)->toDateString());
        $this->assertSame('2026-07-01', GroupCycleCalendar::end($anchor, 2, 3)->toDateString());
    }

    public function test_a_project_ends_the_day_the_next_one_starts(): void
    {
        $anchor = $this->day('2026-08-31');

        foreach (range(1, 12) as $sequence) {
            $this->assertSame(
                GroupCycleCalendar::end($anchor, 2, $sequence)->toDateString(),
                GroupCycleCalendar::start($anchor, 2, $sequence + 1)->toDateString(),
                "Project {$sequence} must end on the day project ".($sequence + 1).' starts.',
            );
        }
    }

    /** @return array<string, array{string, list<string>}> */
    public static function anchorsAndTheirSixEndDates(): array
    {
        return [
            // 31 Aug: Oct 31, Dec 31, then February is clamped to the 28th, April to the 30th …
            'anchor on the 31st' => ['2026-08-31', ['2026-10-31', '2026-12-31', '2027-02-28', '2027-04-30', '2027-06-30', '2027-08-31']],
            // The end dates come from the anchor, so they climb back to the 31st: no drift.
            'anchor on the 30th' => ['2026-12-30', ['2027-02-28', '2027-04-30', '2027-06-30', '2027-08-30', '2027-10-30', '2027-12-30']],
            'anchor on the 29th' => ['2027-12-29', ['2028-02-29', '2028-04-29', '2028-06-29', '2028-08-29', '2028-10-29', '2028-12-29']],
            'leap february' => ['2027-12-31', ['2028-02-29', '2028-04-30', '2028-06-30', '2028-08-31', '2028-10-31', '2028-12-31']],
            'anchor on the 28th' => ['2026-12-28', ['2027-02-28', '2027-04-28', '2027-06-28', '2027-08-28', '2027-10-28', '2027-12-28']],
        ];
    }

    /** @param  list<string>  $expectedEnds */
    #[DataProvider('anchorsAndTheirSixEndDates')]
    public function test_month_ends_are_clamped_without_drifting(string $anchor, array $expectedEnds): void
    {
        $ends = array_map(
            fn (int $sequence): string => GroupCycleCalendar::end($this->day($anchor), 2, $sequence)->toDateString(),
            range(1, 6),
        );

        $this->assertSame($expectedEnds, $ends);
    }

    public function test_completed_cycles_count_a_project_on_the_day_it_ends(): void
    {
        $anchor = $this->day('2026-08-31');

        $this->assertSame(0, GroupCycleCalendar::completedCycles($anchor, 2, $this->day('2026-08-30')), 'before the anchor');
        $this->assertSame(0, GroupCycleCalendar::completedCycles($anchor, 2, $this->day('2026-08-31')), 'on the anchor');
        $this->assertSame(0, GroupCycleCalendar::completedCycles($anchor, 2, $this->day('2026-10-30')), 'the day before project 1 ends');
        $this->assertSame(1, GroupCycleCalendar::completedCycles($anchor, 2, $this->day('2026-10-31')), 'the day project 1 ends');
        $this->assertSame(1, GroupCycleCalendar::completedCycles($anchor, 2, $this->day('2026-12-30')));
        $this->assertSame(2, GroupCycleCalendar::completedCycles($anchor, 2, $this->day('2026-12-31')));
        $this->assertSame(2, GroupCycleCalendar::completedCycles($anchor, 2, $this->day('2027-02-27')), 'one day before the clamped 28 Feb');
        $this->assertSame(3, GroupCycleCalendar::completedCycles($anchor, 2, $this->day('2027-02-28')), 'the clamped 28 Feb');
        $this->assertSame(4, GroupCycleCalendar::completedCycles($anchor, 2, $this->day('2027-04-30')));
    }

    public function test_the_running_project_number_is_one_more_than_the_finished_ones(): void
    {
        $anchor = $this->day('2026-01-15');

        $this->assertNull(GroupCycleCalendar::runningSequence($anchor, 2, $this->day('2026-01-14')));
        $this->assertSame(1, GroupCycleCalendar::runningSequence($anchor, 2, $this->day('2026-01-15')));
        $this->assertSame(1, GroupCycleCalendar::runningSequence($anchor, 2, $this->day('2026-03-14')));
        $this->assertSame(2, GroupCycleCalendar::runningSequence($anchor, 2, $this->day('2026-03-15')));
        $this->assertSame(5, GroupCycleCalendar::runningSequence($anchor, 2, $this->day('2026-09-20')));
    }

    public function test_the_time_of_day_never_moves_a_boundary(): void
    {
        $anchor = CarbonImmutable::parse('2026-01-01 23:59:59');
        $evening = CarbonImmutable::parse('2026-02-28 23:59:59');
        $morning = CarbonImmutable::parse('2026-03-01 00:00:01');

        $this->assertSame(0, GroupCycleCalendar::completedCycles($anchor, 2, $evening));
        $this->assertSame(1, GroupCycleCalendar::completedCycles($anchor, 2, $morning));
    }

    public function test_other_cycle_lengths_use_the_same_rule(): void
    {
        $anchor = $this->day('2026-01-31');

        $this->assertSame('2026-02-28', GroupCycleCalendar::end($anchor, 1, 1)->toDateString());
        $this->assertSame('2026-03-31', GroupCycleCalendar::end($anchor, 1, 2)->toDateString());
        $this->assertSame('2026-07-31', GroupCycleCalendar::end($anchor, 6, 1)->toDateString());
        $this->assertSame('2027-01-31', GroupCycleCalendar::end($anchor, 6, 2)->toDateString());
    }

    public function test_progress_runs_from_zero_to_one_hundred_and_stays_there(): void
    {
        $start = $this->day('2026-01-01');
        $end = $this->day('2026-03-01'); // 59 days

        $this->assertSame(0, GroupCycleCalendar::progressPercent($start, $end, $this->day('2025-12-31')));
        $this->assertSame(0, GroupCycleCalendar::progressPercent($start, $end, $this->day('2026-01-01')));
        $this->assertSame(49, GroupCycleCalendar::progressPercent($start, $end, $this->day('2026-01-30'))); // 29 of 59 days
        $this->assertSame(98, GroupCycleCalendar::progressPercent($start, $end, $this->day('2026-02-28'))); // 58 of 59 days
        $this->assertSame(100, GroupCycleCalendar::progressPercent($start, $end, $this->day('2026-03-01')));
        $this->assertSame(100, GroupCycleCalendar::progressPercent($start, $end, $this->day('2026-06-01')));
    }
}
