<?php

namespace App\Services\Investment;

use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\InvestorGroup;
use App\Models\InvestorGroupMember;
use App\Services\CompanyContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves today's projects into groups (16_INVESTOR_GROUPS_PLAN.md §4): one group
 * per project, in project order (project 1 → G1, project 2 → G2 …), each project
 * becoming Project 1 of its group. Nothing is deleted or recomputed: the
 * project's own `investments` rows stay as they are and become the project's
 * snapshot, linked to a new group member that copies the same amount, date,
 * payment details and voucher. No voucher is booked.
 *
 * Works on the current company (`CompanyContext`). Groups start Running with
 * automatic cycles OFF, so the scheduler never creates a pile of catch-up
 * projects from an old start date; staff switch them on after checking dates.
 */
class InvestorGroupBackfillService
{
    /**
     * What `apply()` would do, without changing anything.
     *
     * @return array{rows: list<array<string, mixed>>, skipped: list<array{project: string, reason: string}>}
     */
    public function plan(): array
    {
        $projects = InvestmentProject::query()->ungrouped()->orderBy('id')->get();

        $nextNumber = $this->nextGroupNumber();
        $rows = [];
        $skipped = [];

        foreach ($projects as $project) {
            $label = $project->project_code.' — '.$project->name;

            if ($project->status === 'open') {
                $skipped[] = ['project' => $label, 'reason' => 'The project is still Open (taking money). Decide whether it is a running group first.'];

                continue;
            }

            $investments = Investment::query()->where('project_id', $project->getKey())->orderBy('id')->get();

            if ($investments->isEmpty()) {
                $skipped[] = ['project' => $label, 'reason' => 'The project has no investments, so there is no group to make.'];

                continue;
            }

            $rows[] = [
                'project_id' => $project->getKey(),
                'project' => $label,
                'status' => $project->status,
                'group_code' => 'G'.$nextNumber,
                'group_name' => 'Group-'.$nextNumber,
                'running_since' => $project->start_date?->toDateString(),
                'members' => $investments->count(),
                'active_members' => $investments->where('status', Investment::STATUS_ACTIVE)->count(),
                'total' => round((float) $investments->sum('amount'), 2),
                'active_total' => round((float) $investments->where('status', Investment::STATUS_ACTIVE)->sum('amount'), 2),
            ];
            $nextNumber++;
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /**
     * Does the move in one transaction; any mismatch rolls everything back.
     *
     * @return list<array<string, mixed>> the rows that were created
     */
    public function apply(): array
    {
        $plan = $this->plan();

        DB::transaction(function () use ($plan): void {
            foreach ($plan['rows'] as $row) {
                $this->moveProject(InvestmentProject::query()->ungrouped()->findOrFail($row['project_id']), $row);
            }
        });

        return $plan['rows'];
    }

    /** @param  array<string, mixed>  $row */
    private function moveProject(InvestmentProject $project, array $row): void
    {
        /** @var Collection<int, Investment> $investments */
        $investments = Investment::query()->where('project_id', $project->getKey())->orderBy('id')->get();

        $group = InvestorGroup::query()->create([
            'company_id' => $project->company_id,
            'group_code' => $row['group_code'],
            'name' => $row['group_name'],
            'opened_on' => $investments->min('invested_at') ?? $project->created_at,
            'cycle_months' => 2,
            'auto_cycle' => false,
            'investor_share_percent' => $project->investor_share_percent,
            'channel_partner_share_percent' => $project->channel_partner_share_percent,
            'company_share_percent' => $project->company_share_percent,
            'receiving_fund_source_id' => $project->receiving_fund_source_id,
            'payout_fund_source_id' => $project->payout_fund_source_id,
            'notes' => "Created from {$project->project_code} — {$project->name}.",
        ]);

        foreach ($investments as $investment) {
            $member = InvestorGroupMember::query()->create([
                'group_id' => $group->getKey(),
                'investor_id' => $investment->investor_id,
                'amount' => $investment->amount,
                'invested_at' => $investment->invested_at,
                'payment_method' => $investment->payment_method,
                'payment_reference' => $investment->payment_reference,
                'received_by' => $investment->received_by,
                'voucher_id' => $investment->voucher_id,
            ]);

            if ($investment->isWithdrawn()) {
                $member->forceFill([
                    'status' => InvestorGroupMember::STATUS_WITHDRAWN,
                    'withdrawn_at' => $investment->withdrawn_at,
                    'withdrawal_voucher_id' => $investment->withdrawal_voucher_id,
                    'withdrawal_note' => $investment->withdrawal_note,
                ])->save();
            }

            // A plain update: the investment already exists and is only being linked.
            DB::table('investments')->where('id', $investment->getKey())->update(['group_member_id' => $member->getKey()]);
        }

        $group->forceFill([
            'status' => InvestorGroup::STATUS_RUNNING,
            'running_since' => $project->start_date,
        ])->save();

        $project->forceFill(['group_id' => $group->getKey(), 'sequence_in_group' => 1])->saveQuietly();

        $memberTotal = round((float) $group->members()->sum('amount'), 2);
        $investmentTotal = round((float) Investment::query()->where('project_id', $project->getKey())->sum('amount'), 2);

        if (abs($memberTotal - $investmentTotal) > 0.001 || abs($memberTotal - (float) $row['total']) > 0.001) {
            throw new RuntimeException("Total mismatch for {$project->project_code}: members {$memberTotal}, investments {$investmentTotal}. Nothing was changed.");
        }
    }

    private function nextGroupNumber(): int
    {
        return (int) preg_replace('/\D/', '', InvestorGroup::nextGroupCode((int) (app(CompanyContext::class)->id() ?? 0)));
    }
}
