<?php

namespace App\Services\Investment;

use App\Events\InvestorGroupProjectCompleted;
use App\Models\InvestmentProject;
use App\Models\InvestmentWithdrawalNotice;
use App\Models\InvestorGroup;
use App\Models\InvestorGroupMember;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The 2-month cycle of a running group (16_INVESTOR_GROUPS_PLAN.md §3.4).
 *
 * When project N reaches its end date (counted in calendar months from the
 * group's anchor day) it is closed, ready for Calculate & Settle, and project
 * N+1 starts the same day with the group's active members copied into it.
 *
 * Safe to run any number of times a day: it looks at what exists and only
 * moves forward. It creates at most ONE project per call, and only when today
 * is still inside that project's own window. If whole cycles were missed (the
 * scheduler was off, or automatic cycles were switched on late) it does not
 * quietly catch up: it reports BEHIND and waits for staff to confirm.
 *
 * Leaving the group (owner's rule, 2026-10-12): a member whose 60-day written
 * notice has run out by a project's end date leaves at that boundary. They are
 * not copied into the next project, and the principal goes back with that
 * project's payout (`SettlementService`).
 */
class InvestorGroupCycleService
{
    public const NOT_DUE = 'not_due';

    public const ADVANCE = 'advance';

    public const BEHIND = 'behind';

    public const NO_MEMBERS = 'no_members';

    public const SKIPPED = 'skipped';

    public function __construct(private InvestorGroupService $groups) {}

    /** Today's date in the company's own timezone, as a day (what the daily run uses). */
    public static function today(?string $timezone = null): CarbonImmutable
    {
        return CarbonImmutable::now($timezone ?: config('app.timezone'))->startOfDay();
    }

    /**
     * What a run on `$today` would do for this group. Changes nothing.
     *
     * @return array{action: string, from_sequence: int|null, next_sequence: int|null, close: bool, behind_by: int}
     */
    public function decide(InvestorGroup $group, CarbonInterface $today): array
    {
        $none = ['action' => self::SKIPPED, 'from_sequence' => null, 'next_sequence' => null, 'close' => false, 'behind_by' => 0];

        $latest = $group->latestProject()->first();

        if (! $group->isRunning() || ! $group->running_since || ! $latest) {
            return $none;
        }

        $today = CarbonImmutable::parse($today->toDateString());
        $months = (int) $group->cycle_months;
        $sequence = (int) $latest->sequence_in_group;
        $result = [...$none, 'from_sequence' => $sequence, 'close' => in_array($latest->status, ['open', 'running'], true)];

        if ($today->lt(GroupCycleCalendar::end($group->running_since, $months, $sequence))) {
            return [...$result, 'action' => self::NOT_DUE, 'close' => false];
        }

        // Cycles after this one that have also ended already.
        $behindBy = max(GroupCycleCalendar::completedCycles($group->running_since, $months, $today) - $sequence, 0);

        if ($behindBy > 0) {
            return [...$result, 'action' => self::BEHIND, 'behind_by' => $behindBy, 'next_sequence' => $sequence + 1];
        }

        $boundary = GroupCycleCalendar::end($group->running_since, $months, $sequence);
        $leavingInvestors = $this->maturedNotices($group, $boundary)->pluck('investor_id')->all();

        if (! $group->activeMembers()->whereNotIn('investor_id', $leavingInvestors)->exists()) {
            return [...$result, 'action' => self::NO_MEMBERS];
        }

        return [...$result, 'action' => self::ADVANCE, 'next_sequence' => $sequence + 1];
    }

    /** Pending notices of the group whose 60 days are over by the end of the project. */
    public function maturedNotices(InvestorGroup $group, CarbonInterface $boundary): Collection
    {
        return InvestmentWithdrawalNotice::query()
            ->where('group_id', $group->getKey())
            ->where('status', 'pending')
            ->whereDate('effective_at', '<=', $boundary->toDateString())
            ->get();
    }

    /**
     * Members with a matured notice leave at the end of project `$sequence`:
     * marked withdrawn from that day and tagged with the project they leave
     * after, so its settlement returns their principal.
     *
     * @return int members who left
     */
    private function applyMaturedNotices(InvestorGroup $group, int $sequence): int
    {
        $boundary = GroupCycleCalendar::end($group->running_since, (int) $group->cycle_months, $sequence);
        $left = 0;

        foreach ($this->maturedNotices($group, $boundary) as $notice) {
            $group->activeMembers()->where('investor_id', $notice->investor_id)->get()
                ->each(function (InvestorGroupMember $member) use ($boundary, $sequence, &$left): void {
                    $member->update([
                        'status' => InvestorGroupMember::STATUS_WITHDRAWN,
                        'withdrawn_at' => $boundary->toDateString(),
                        'leaves_after_sequence' => $sequence,
                        'withdrawal_note' => "Left at the end of project {$sequence} after a 60-day notice.",
                    ]);
                    $left++;
                });

            $notice->update(['status' => 'honored']);
        }

        return $left;
    }

    /**
     * Does what `decide()` says: closes the finished project and starts the
     * next one. `$confirmCatchUp` is staff saying "start the next project now"
     * for a group that is behind; it still creates only one project.
     *
     * @return array{action: string, from_sequence: int|null, next_sequence: int|null, close: bool, behind_by: int, closed: InvestmentProject|null, created: InvestmentProject|null, left: int}
     */
    public function advance(InvestorGroup $group, CarbonInterface $today, bool $confirmCatchUp = false): array
    {
        $events = [];

        $result = DB::transaction(function () use ($group, $today, $confirmCatchUp, &$events): array {
            $locked = InvestorGroup::query()->lockForUpdate()->findOrFail($group->getKey());
            $decision = $this->decide($locked, $today);
            $closed = null;
            $created = null;

            $due = in_array($decision['action'], [self::ADVANCE, self::BEHIND, self::NO_MEMBERS], true);

            if ($due && $decision['close']) {
                $closed = $locked->latestProject()->firstOrFail();
                $closed->update(['status' => 'closed']);
            }

            // Notices that have run out leave now, even when nobody stays behind
            // (their principal then comes back with the project's payout).
            $left = 0;

            if (in_array($decision['action'], [self::ADVANCE, self::NO_MEMBERS], true) || ($decision['action'] === self::BEHIND && $confirmCatchUp)) {
                $left = $this->applyMaturedNotices($locked, (int) $decision['from_sequence']);
            }

            $mayCreate = in_array($decision['action'], [self::ADVANCE, self::BEHIND], true)
                && ($decision['action'] === self::ADVANCE || $confirmCatchUp)
                && $locked->activeMembers()->exists();

            if ($mayCreate) {
                $created = $this->groups->createProject($locked, (int) $decision['next_sequence']);
                $this->groups->snapshotMembers($locked, $created);
            }

            if ($closed) {
                $events[] = new InvestorGroupProjectCompleted($closed, $locked, $created);
            }

            return [...$decision, 'closed' => $closed, 'created' => $created, 'left' => $left];
        });

        // After the commit: a listener that fails must never undo the cycle.
        foreach ($events as $event) {
            try {
                event($event);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $result;
    }
}
