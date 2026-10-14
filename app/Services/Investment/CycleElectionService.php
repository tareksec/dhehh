<?php

namespace App\Services\Investment;

use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\InvestorCycleElection;
use App\Models\InvestorGroup;
use App\Models\InvestorGroupMember;
use App\Models\SettlementPayout;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records what an investor chose to do with a settlement cycle's payout, and
 * -- for a (full or partial) reinvest -- creates the rollover Investment in
 * the chosen next project in the same transaction (v3 P2.4).
 *
 * New money never joins a running group (16_INVESTOR_GROUPS_PLAN.md): a
 * reinvest goes into an OPEN GROUP as a new member. An ungrouped project is
 * still accepted for investments that are not part of a group.
 */
class CycleElectionService
{
    public function recordElection(
        SettlementPayout $payout,
        string $election,
        ?float $reinvestAmount,
        InvestorGroup|InvestmentProject|null $nextProject,
        int $recordedByUserId,
    ): InvestorCycleElection {
        if ($payout->cycleElection()->exists()) {
            throw new RuntimeException('This payout already has a recorded election.');
        }

        if ($election !== 'withdraw' && ($batch = app(PayoutCompletionService::class)->liveBatchItem($payout)?->batch)) {
            throw new RuntimeException(__('This payout is in :batch. Take it out of the batch before recording a reinvest.', ['batch' => $batch->label()]));
        }

        if ($election !== 'withdraw' && $nextProject === null) {
            throw new RuntimeException(__('Choose an open group to reinvest into.'));
        }

        if ($election !== 'withdraw' && $nextProject instanceof InvestmentProject && $nextProject->group_id) {
            throw new RuntimeException(__('Reinvested money goes into an open group, not into a project of a group.'));
        }

        if ($election !== 'withdraw' && $nextProject instanceof InvestorGroup && ! $nextProject->isOpen()) {
            throw new RuntimeException(__('Reinvested money can go only into an open group.'));
        }

        $totalPayout = (float) $payout->total_payout;

        if ($election === 'reinvest') {
            $reinvestAmount = $totalPayout;
        }

        if ($election === 'partial_reinvest' && $reinvestAmount > $totalPayout) {
            throw new RuntimeException('The reinvested amount cannot exceed the total payout.');
        }

        return DB::transaction(function () use ($payout, $election, $reinvestAmount, $nextProject, $recordedByUserId): InvestorCycleElection {
            $reinvestmentId = null;
            $reinvestmentMemberId = null;

            if ($election !== 'withdraw') {
                $reinvestment = app(InvestmentTargets::class)->place($nextProject, $payout->investor, [
                    'amount' => $reinvestAmount,
                    'payment_method' => 'other',
                    'payment_reference' => "Reinvested from settlement payout #{$payout->id}",
                    'invested_at' => now()->toDateString(),
                ], User::query()->find($recordedByUserId));

                if ($reinvestment instanceof InvestorGroupMember) {
                    $reinvestmentMemberId = $reinvestment->getKey();
                } else {
                    $reinvestmentId = $reinvestment->getKey();
                }
            }

            return InvestorCycleElection::query()->create([
                'company_id' => $payout->company_id,
                'settlement_payout_id' => $payout->id,
                'election' => $election,
                'reinvest_amount' => $election === 'withdraw' ? null : $reinvestAmount,
                'next_project_id' => $nextProject instanceof InvestmentProject ? $nextProject->id : null,
                'next_group_id' => $nextProject instanceof InvestorGroup ? $nextProject->id : null,
                'reinvestment_id' => $reinvestmentId,
                'reinvestment_member_id' => $reinvestmentMemberId,
                'recorded_by' => $recordedByUserId,
            ]);
        });
    }
}
