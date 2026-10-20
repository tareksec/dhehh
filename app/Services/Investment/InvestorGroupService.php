<?php

namespace App\Services\Investment;

use App\Ims\Capital\CapitalCalculator;
use App\Ims\Kyc\KycService;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\InvestorGroup;
use App\Models\InvestorGroupMember;
use App\Models\InvestorSecurityInstrument;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Group lifecycle (16_INVESTOR_GROUPS_PLAN.md §3.4): add members while the
 * group is Open, start it (Project 1 + the snapshot of its members), close it.
 * The daily cycle command (phase G2) creates Project 2, 3 … with the same
 * `createProject()` and `snapshotMembers()`.
 */
class InvestorGroupService
{
    public function __construct(private InvestmentLedgerService $ledger) {}

    /**
     * @param  array{amount: float|int|string, invested_at?: string|null, payment_method?: string|null, payment_reference?: string|null, notes?: string|null, paperwork?: array<string, mixed>|null}  $data
     */
    public function addMember(InvestorGroup $group, Investor $investor, array $data, ?User $by = null): InvestorGroupMember
    {
        $member = DB::transaction(function () use ($group, $investor, $data, $by): InvestorGroupMember {
            $locked = InvestorGroup::query()->lockForUpdate()->findOrFail($group->getKey());

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['group_id' => __('New members can be added only while the group is Open.')]);
            }

            // IMS v2 (PRD FR-14, FR-22): money waits as "pending" until it is confirmed;
            // while IMS is on, a new investor's money waits until their KYC is verified.
            $pending = (bool) ($data['pending'] ?? false) || KycService::blocksNewMoney($investor);

            return InvestorGroupMember::query()->create([
                'group_id' => $locked->getKey(),
                'investor_id' => $investor->getKey(),
                'amount' => $data['amount'],
                'invested_at' => $data['invested_at'] ?? now()->toDateString(),
                'payment_method' => $data['payment_method'] ?? null,
                'payment_reference' => $data['payment_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'paperwork' => ($data['paperwork'] ?? []) ?: null,
                'received_by' => $by?->getKey(),
                'tenor_months' => filled($data['tenor_months'] ?? null) ? (int) $data['tenor_months'] : null,
                'status' => $pending ? InvestorGroupMember::STATUS_PENDING : InvestorGroupMember::STATUS_ACTIVE,
                'confirmed_at' => $pending ? null : now(),
                'confirmed_by' => $pending ? null : $by?->getKey(),
            ]);
        });

        // After the commit, so a voucher failure can never lose the member.
        $this->ledger->recordGroupDepositReceived($member);

        return $member->refresh();
    }

    /**
     * Open → Running. Freezes the membership and creates Project 1, starting
     * on `$runningSince`, with every active member copied into it.
     */
    public function startGroup(InvestorGroup $group, CarbonInterface $runningSince, ?User $by = null): InvestmentProject
    {
        return DB::transaction(function () use ($group, $runningSince, $by): InvestmentProject {
            $locked = InvestorGroup::query()->lockForUpdate()->findOrFail($group->getKey());

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['status' => __('Only an Open group can be started.')]);
            }

            if ($runningSince->copy()->startOfDay()->gt(now()->startOfDay())) {
                throw ValidationException::withMessages(['running_since' => __('The start date cannot be in the future.')]);
            }

            if ($locked->totalActiveDeposits() <= 0) {
                throw ValidationException::withMessages(['status' => __('Add at least one member before starting the group.')]);
            }

            // Money still waiting could never join once the group runs.
            if (($waiting = $locked->members()->pending()->count()) > 0) {
                throw ValidationException::withMessages(['status' => __('Confirm or remove the :count deposits still waiting for money before starting the group.', ['count' => $waiting])]);
            }

            $locked->forceFill(['status' => InvestorGroup::STATUS_RUNNING, 'running_since' => $runningSince->toDateString()])->save();

            $project = $this->createProject($locked, 1);
            $this->snapshotMembers($locked, $project);

            app(AuditLogService::class)->record('investor_group_started', $locked, null, [
                'running_since' => $runningSince->toDateString(),
                'project_id' => $project->getKey(),
                'members' => $project->investments()->count(),
                'started_by' => $by?->getKey(),
            ]);

            $group->setRawAttributes($locked->getAttributes(), true);

            return $project;
        });
    }

    /**
     * Running (or Open) → Closed. Allowed only when no project is still being
     * funded, running or waiting for its settlement, and no member still has
     * money in the group.
     */
    public function closeGroup(InvestorGroup $group, string $reason, ?User $by = null): InvestorGroup
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['close_reason' => __('A reason is required to close a group.')]);
        }

        return DB::transaction(function () use ($group, $reason, $by): InvestorGroup {
            $locked = InvestorGroup::query()->lockForUpdate()->findOrFail($group->getKey());

            if ($locked->isClosed()) {
                throw ValidationException::withMessages(['status' => __('This group is already closed.')]);
            }

            if ($locked->projects()->where('status', '!=', 'settled')->exists()) {
                throw ValidationException::withMessages(['status' => __('Settle every project of the group before closing it.')]);
            }

            if ($locked->activeMembers()->exists()) {
                throw ValidationException::withMessages(['status' => __('Every member must take their money out before the group is closed.')]);
            }

            $locked->forceFill([
                'status' => InvestorGroup::STATUS_CLOSED,
                'closed_on' => now()->toDateString(),
                'close_reason' => $reason,
                'auto_cycle' => false,
            ])->save();

            app(AuditLogService::class)->record('investor_group_closed', $locked, null, [
                'reason' => $reason,
                'closed_by' => $by?->getKey(),
            ]);

            $group->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /** Project number `$sequence` of the group, dated from the group's anchor. Not saved twice: one project per position. */
    public function createProject(InvestorGroup $group, int $sequence): InvestmentProject
    {
        if (! $group->running_since) {
            throw ValidationException::withMessages(['running_since' => __('The group has no start date yet.')]);
        }

        $months = (int) $group->cycle_months;

        return InvestmentProject::query()->create([
            'company_id' => $group->company_id,
            'group_id' => $group->getKey(),
            'sequence_in_group' => $sequence,
            'name' => "{$group->name} — Project {$sequence}",
            // 2 months is the signed-sheet basis (60 days); other lengths use days.
            'duration_type' => $months === 2 ? '2_month' : 'custom_days',
            'trade_cycle_days' => $months * 30,
            'start_date' => GroupCycleCalendar::start($group->running_since, $months, $sequence)->toDateString(),
            'end_date' => GroupCycleCalendar::end($group->running_since, $months, $sequence)->toDateString(),
            'company_contribution_amount' => 0,
            'investor_share_percent' => $group->investor_share_percent,
            'channel_partner_share_percent' => $group->channel_partner_share_percent,
            'company_share_percent' => $group->company_share_percent,
            'receiving_fund_source_id' => $group->receiving_fund_source_id,
            'payout_fund_source_id' => $group->payout_fund_source_id,
            'status' => 'running',
        ]);
    }

    /**
     * Copies the group's ACTIVE members into the project as `investments`
     * rows (amount, date, payment details). Books no voucher: the money was
     * booked once, when the member joined. A withdrawn member is not copied.
     */
    public function snapshotMembers(InvestorGroup $group, InvestmentProject $project): int
    {
        $copied = 0;
        $capital = app(CapitalCalculator::class);

        foreach ($group->activeMembers()->orderBy('id')->get() as $member) {
            $firstProject = ! $member->investments()->exists();

            // IMS v2 (TRD §4.2): what goes on is the capital after earlier losses
            // and capital paid back; a member with nothing left is not copied.
            $carried = $firstProject ? (float) $member->amount : $capital->forMember($member)['outstanding'];

            if ($carried <= 0) {
                continue;
            }

            $investment = Investment::query()->create([
                'company_id' => $project->company_id,
                'project_id' => $project->getKey(),
                'group_member_id' => $member->getKey(),
                'investor_id' => $member->investor_id,
                'amount' => $carried,
                'payment_method' => $member->payment_method ?: 'other',
                'payment_reference' => $member->payment_reference,
                'invested_at' => $member->invested_at,
                'received_by' => $member->received_by,
            ]);

            // The stamp and cheque noted when the money came in belong to the
            // deed: they go on the member's first project row, once.
            if ($firstProject && filled($member->paperwork)) {
                InvestorSecurityInstrument::query()->create([
                    'investment_id' => $investment->getKey(),
                    'stamp_serial_numbers' => $member->paperwork['stamp_serial_numbers'] ?? null,
                    'cheque_number' => $member->paperwork['cheque_number'] ?? null,
                ]);
            }

            $copied++;
        }

        return $copied;
    }
}
