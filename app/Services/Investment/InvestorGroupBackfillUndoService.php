<?php

namespace App\Services\Investment;

use App\Models\AuditLog;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\InvestorGroup;
use App\Models\InvestorGroupMember;
use App\Services\AuditLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The way back from `InvestorGroupBackfillService` (owner 2026-10-25: "delete
 * all the groups and start again"). It takes a group that the backfill made
 * (its notes start with "Created from") out again: the project becomes an
 * ungrouped project, its investments stay exactly as they are, the group's
 * member copies and the group itself are deleted, and the project gets its
 * old code back (from the audit log) when the backfill had renamed it.
 *
 * It only undoes a group that is still in the state the backfill left it:
 * one project (Project 1), no settlement, every investment linked to exactly
 * one member with the same amount, and nothing that points at the group
 * (withdrawal notices, requests, drafts, reinvestment choices, capital
 * returns). Anything else is refused and listed with the reason, never forced.
 * A group with no members and no project (a test group) is deleted only when
 * the caller asks for it. No voucher is touched and the investments' own
 * vouchers stay on the investments.
 *
 * Works on the current company (`CompanyContext`).
 */
class InvestorGroupBackfillUndoService
{
    /** The marker the backfill writes at the start of a group's notes. */
    public const NOTES_MARKER = 'Created from ';

    /**
     * What `apply()` would do, without changing anything.
     *
     * @param  list<string>  $groupCodes  only these groups (empty = every group of the company)
     * @return array{rows: list<array<string, mixed>>, empty: list<array<string, mixed>>, refused: list<array{group: string, reason: string}>}
     */
    public function plan(array $groupCodes = [], bool $includeEmpty = false): array
    {
        $groups = InvestorGroup::query()
            ->when($groupCodes !== [], fn ($query) => $query->whereIn('group_code', array_map('strtoupper', $groupCodes)))
            ->orderBy('id')
            ->get();

        $rows = [];
        $empty = [];
        $refused = [];

        foreach ($groups as $group) {
            $label = "{$group->group_code} ({$group->name})";
            $members = $group->members()->get();
            $projects = $group->projects()->get();

            if ($members->isEmpty() && $projects->isEmpty()) {
                if (! $includeEmpty) {
                    $refused[] = ['group' => $label, 'reason' => 'The group is empty (no members, no project). It is deleted only with --include-empty.'];
                } elseif ($reason = $this->referenceProblem($group, $members->pluck('id')->all())) {
                    $refused[] = ['group' => $label, 'reason' => $reason];
                } else {
                    $empty[] = ['group_id' => $group->getKey(), 'group' => $label];
                }

                continue;
            }

            $problem = $this->problem($group, $members, $projects);

            if ($problem !== null) {
                $refused[] = ['group' => $label, 'reason' => $problem];

                continue;
            }

            /** @var InvestmentProject $project */
            $project = $projects->first();
            $restoreCode = $this->previousCode($group);

            if ($restoreCode !== null && $restoreCode !== $project->project_code
                && InvestmentProject::query()->where('project_code', $restoreCode)->whereKeyNot($project->getKey())->exists()) {
                $refused[] = ['group' => $label, 'reason' => "The old project code {$restoreCode} is now used by another project."];

                continue;
            }

            $rows[] = [
                'group_id' => $group->getKey(),
                'group' => $label,
                'project_id' => $project->getKey(),
                'project_code' => $project->project_code,
                'restore_code' => $restoreCode !== null && $restoreCode !== $project->project_code ? $restoreCode : null,
                'status' => $project->status,
                'members' => $members->count(),
                'total' => round((float) $members->sum('amount'), 2),
            ];
        }

        return ['rows' => $rows, 'empty' => $empty, 'refused' => $refused];
    }

    /**
     * Does the undo in one transaction; a mismatch rolls everything back.
     *
     * @param  list<string>  $groupCodes
     * @return array{rows: list<array<string, mixed>>, empty: list<array<string, mixed>>, refused: list<array{group: string, reason: string}>}
     */
    public function apply(array $groupCodes = [], bool $includeEmpty = false): array
    {
        $plan = $this->plan($groupCodes, $includeEmpty);

        DB::transaction(function () use ($plan): void {
            foreach ($plan['rows'] as $row) {
                $this->undoGroup($row);
            }

            foreach ($plan['empty'] as $row) {
                InvestorGroup::query()->findOrFail($row['group_id'])->delete();
            }
        });

        return $plan;
    }

    /** @param  array<string, mixed>  $row */
    private function undoGroup(array $row): void
    {
        $group = InvestorGroup::query()->lockForUpdate()->findOrFail($row['group_id']);
        $project = InvestmentProject::query()->findOrFail($row['project_id']);

        $investments = Investment::query()->where('project_id', $project->getKey());
        $countBefore = (clone $investments)->count();
        $sumBefore = round((float) (clone $investments)->sum('amount'), 2);

        $changes = ['group_id' => null, 'sequence_in_group' => null, 'updated_at' => now()];

        if ($row['restore_code'] !== null) {
            $changes['project_code'] = $row['restore_code'];
        }

        // Plain updates: the model refuses to move a project out of its group,
        // and the investments only lose their link to the member copy.
        DB::table('investments')->where('project_id', $project->getKey())->update(['group_member_id' => null]);
        DB::table('investment_projects')->where('id', $project->getKey())->update($changes);
        DB::table('investor_group_members')->where('group_id', $group->getKey())->delete();

        app(AuditLogService::class)->record('investor_group_backfill_undone', $group, [
            'group_code' => $group->group_code,
            'project_id' => $project->getKey(),
            'project_code' => $row['project_code'],
            'members' => $row['members'],
            'total' => $row['total'],
        ]);

        $group->delete();

        if ((clone $investments)->count() !== $countBefore
            || abs(round((float) (clone $investments)->sum('amount'), 2) - $sumBefore) > 0.001
            || InvestmentProject::query()->whereKey($project->getKey())->whereNotNull('group_id')->exists()) {
            throw new RuntimeException("Undo check failed for {$row['group']}. Nothing was changed.");
        }
    }

    /**
     * Why this group may not be undone (null = it may).
     *
     * @param  Collection<int, InvestorGroupMember>  $members
     * @param  Collection<int, InvestmentProject>  $projects
     */
    private function problem(InvestorGroup $group, Collection $members, Collection $projects): ?string
    {
        if (! str_starts_with((string) $group->notes, self::NOTES_MARKER)) {
            return 'It was not made by the backfill (its notes do not start with "'.trim(self::NOTES_MARKER).'").';
        }

        if ($projects->count() !== 1 || (int) $projects->first()->sequence_in_group !== 1) {
            return 'It has more than Project 1 (a cycle already ran), so it is no longer the backfill\'s state.';
        }

        /** @var InvestmentProject $project */
        $project = $projects->first();

        if ($project->settlement()->exists()) {
            return "{$project->project_code} already has a settlement.";
        }

        if ($members->contains(fn (InvestorGroupMember $member): bool => $member->leaves_after_sequence !== null)) {
            return 'A member has a leaving date set.';
        }

        if ($reason = $this->referenceProblem($group, $members->pluck('id')->all())) {
            return $reason;
        }

        // Each investment of the project must be the copy of exactly one member, with the same amount.
        $investments = Investment::query()->where('project_id', $project->getKey())->get();
        $byMember = $investments->groupBy('group_member_id');

        if ($investments->count() !== $members->count() || $byMember->count() !== $members->count() || $byMember->has('')) {
            return 'Its members and the project\'s investments no longer match one to one.';
        }

        foreach ($members as $member) {
            $copy = $byMember->get($member->getKey());

            if ($copy === null || $copy->count() !== 1 || abs((float) $copy->first()->amount - (float) $member->amount) > 0.001
                || (int) $copy->first()->investor_id !== (int) $member->investor_id) {
                return 'A member and its investment no longer match (amount or investor changed).';
            }
        }

        return null;
    }

    /** Anything else in the system that points at the group or its members. */
    private function referenceProblem(InvestorGroup $group, array $memberIds): ?string
    {
        $groupId = $group->getKey();

        $checks = [
            'a withdrawal notice' => DB::table('investment_withdrawal_notices')->where('group_id', $groupId),
            'an investor request' => DB::table('investor_requests')->where('group_id', $groupId),
            'an investor draft' => DB::table('investor_drafts')->where('group_id', $groupId),
            'a reinvestment choice' => DB::table('investor_cycle_elections')->where(fn ($query) => $query
                ->where('next_group_id', $groupId)
                ->when($memberIds !== [], fn ($inner) => $inner->orWhereIn('reinvestment_member_id', $memberIds))),
        ];

        if ($memberIds !== []) {
            $checks['a capital return'] = DB::table('capital_returns')->whereIn('group_member_id', $memberIds);
        }

        foreach ($checks as $what => $query) {
            if ($query->exists()) {
                return "It is used by {$what}.";
            }
        }

        return null;
    }

    /** The project's code before the backfill renamed it, from the audit log (null for a group made before the rename existed). */
    private function previousCode(InvestorGroup $group): ?string
    {
        $log = AuditLog::query()
            ->where('action', 'investor_group_backfilled')
            ->where('auditable_type', InvestorGroup::class)
            ->where('auditable_id', $group->getKey())
            ->latest('id')
            ->first();

        $code = $log?->new_values['previous_project_code'] ?? null;

        return filled($code) ? (string) $code : null;
    }
}
