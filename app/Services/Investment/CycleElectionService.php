<?php

namespace App\Services\Investment;

use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\InvestorCycleElection;
use App\Models\SettlementPayout;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records what an investor chose to do with a settlement cycle's payout, and
 * -- for a (full or partial) reinvest -- creates the rollover Investment in
 * the chosen next project in the same transaction (v3 P2.4).
 */
class CycleElectionService
{
    public function recordElection(
        SettlementPayout $payout,
        string $election,
        ?float $reinvestAmount,
        ?InvestmentProject $nextProject,
        int $recordedByUserId,
    ): InvestorCycleElection {
        if ($payout->cycleElection()->exists()) {
            throw new RuntimeException('This payout already has a recorded election.');
        }

        if ($election !== 'withdraw' && ($batch = app(PayoutCompletionService::class)->liveBatchItem($payout)?->batch)) {
            throw new RuntimeException(__('This payout is in :batch. Take it out of the batch before recording a reinvest.', ['batch' => $batch->label()]));
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

            if ($election !== 'withdraw') {
                $reinvestment = Investment::query()->create([
                    'company_id' => $nextProject->company_id,
                    'project_id' => $nextProject->id,
                    'investor_id' => $payout->investor_id,
                    'amount' => $reinvestAmount,
                    'payment_method' => 'other',
                    'payment_reference' => "Reinvested from settlement payout #{$payout->id}",
                    'invested_at' => now()->toDateString(),
                    'received_by' => $recordedByUserId,
                ]);
                app(InvestmentLedgerService::class)->recordInvestmentReceived($reinvestment);
                $reinvestmentId = $reinvestment->id;
            }

            return InvestorCycleElection::query()->create([
                'company_id' => $payout->company_id,
                'settlement_payout_id' => $payout->id,
                'election' => $election,
                'reinvest_amount' => $election === 'withdraw' ? null : $reinvestAmount,
                'next_project_id' => $nextProject?->id,
                'reinvestment_id' => $reinvestmentId,
                'recorded_by' => $recordedByUserId,
            ]);
        });
    }
}
