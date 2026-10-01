<?php

namespace App\Services\Investment;

use App\Models\ChannelPartnerPayout;
use App\Models\InvestmentProject;
use App\Models\ProjectSettlement;
use App\Models\SettlementPayout;
use App\Services\AuditLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SettlementService
{
    /**
     * @param  string  $outcome  profit | loss_investor_borne | loss_manager_borne
     *                           (v3 P1.3 — deed clause 4 / channel-partner
     *                           agreement clause 4). Ignored when net profit is
     *                           positive.
     */
    public function calculateAndSettle(
        InvestmentProject $project,
        float $totalRevenue,
        int $settledByUserId,
        bool $acknowledgeUnprovenCosts = false,
        string $outcome = 'profit',
        ?string $lossReason = null,
    ): ProjectSettlement {
        if ($project->status !== 'closed') {
            throw new RuntimeException('Only a closed project can be settled.');
        }

        if ($totalRevenue < 0) {
            throw new RuntimeException('Total revenue cannot be negative.');
        }

        return DB::transaction(function () use ($project, $totalRevenue, $settledByUserId, $acknowledgeUnprovenCosts, $outcome, $lossReason): ProjectSettlement {
            $lockedProject = InvestmentProject::query()->whereKey($project)->lockForUpdate()->firstOrFail();

            if ($lockedProject->settlement()->exists()) {
                throw new RuntimeException('This project already has a settlement.');
            }

            // Transparency rule (deed clause 3 / channel-partner agreement
            // clause 2): every direct cost must be traceable. Not a hard block —
            // the settler can acknowledge and proceed — but never silent.
            if (! $acknowledgeUnprovenCosts) {
                $unproven = $lockedProject->costItems()
                    ->whereNull('purchase_id')
                    ->withCount('documents')
                    ->get()
                    ->filter(fn ($item) => (int) $item->documents_count === 0);

                if ($unproven->isNotEmpty()) {
                    throw new RuntimeException(
                        'These cost items have no linked purchase or uploaded receipt: '
                        .$unproven->pluck('label')->implode(', ')
                        .'. Attach proof, or confirm the acknowledgement to settle anyway.'
                    );
                }
            }

            // Owner (2026-09-28): an investment leaves the settlement from the
            // moment its principal is withdrawn.
            $investments = $lockedProject->investments()->active()->with('investor')->get();
            $externalInvested = round((float) $investments->sum('amount'), 2);

            if ($externalInvested <= 0) {
                throw new RuntimeException('At least one investment is required before settlement.');
            }

            // Capital the profit pool and "rate per lac" are spread over: the
            // external investors plus any capital the company itself put in to
            // close a funding gap (v3 P1.7).
            $companyContribution = round(max((float) $lockedProject->company_contribution_amount, 0), 2);
            $capitalBase = round($externalInvested + $companyContribution, 2);

            $totalCost = round($lockedProject->totalCostItems(), 2);
            $netProfit = round($totalRevenue - $totalCost, 2);

            $grouped = $investments->groupBy('investor_id');

            if ($netProfit < 0) {
                if (! in_array($outcome, ['loss_investor_borne', 'loss_manager_borne'], true)) {
                    throw new RuntimeException('Net profit is negative. Choose how the loss is borne (per the contract) and give a reason.');
                }
                if (blank($lossReason)) {
                    throw new RuntimeException('A loss reason is required when settling a losing project.');
                }

                $figures = $this->allocateLoss($outcome, $netProfit, $capitalBase, $grouped);
            } else {
                $outcome = 'profit';
                $lossReason = null;
                $figures = $this->allocateProfit($lockedProject, $netProfit, $externalInvested, $capitalBase, $companyContribution, $investments, $grouped);
            }

            $settlement = ProjectSettlement::query()->create([
                'company_id' => $lockedProject->company_id,
                'project_id' => $lockedProject->id,
                'total_revenue' => $totalRevenue,
                'total_cost' => $totalCost,
                'net_profit' => $netProfit,
                'investor_pool_amount' => $figures['investor_pool'],
                'channel_partner_amount' => $figures['channel_payout'],
                'company_net_amount' => $figures['company_net'],
                'annualized_return_percent' => $figures['annualized'],
                'rate_per_lac' => $figures['rate_per_lac'],
                'outcome' => $outcome,
                'loss_reason' => $lossReason,
                'status' => 'draft',
                'settled_by' => $settledByUserId,
                'settled_at' => now(),
            ]);

            foreach ($figures['payout_rows'] as $row) {
                SettlementPayout::query()->create([
                    'company_id' => $lockedProject->company_id,
                    'settlement_id' => $settlement->id,
                    'investor_id' => $row['investor_id'],
                    'investment_amount' => $row['principal'],
                    'profit_share_amount' => $row['profit_share'],
                    'total_payout' => round($row['principal'] + $row['profit_share'], 2),
                    'recipient_name' => $row['recipient_name'],
                ]);
            }

            // Several partners per project (owner, 2026-09-28): one payout each.
            foreach ($figures['partner_rows'] as $partnerId => $amount) {
                if ($amount > 0) {
                    ChannelPartnerPayout::query()->create([
                        'company_id' => $lockedProject->company_id,
                        'settlement_id' => $settlement->id,
                        'channel_partner_id' => $partnerId,
                        'amount' => $amount,
                    ]);
                }
            }

            $lockedProject->forceFill(['status' => 'settled'])->saveQuietly();

            app(AuditLogService::class)->record('project_settled', $settlement, null, [
                'project_id' => $lockedProject->id,
                'total_revenue' => $totalRevenue,
                'total_cost' => $totalCost,
                'net_profit' => $netProfit,
                'outcome' => $outcome,
                'company_contribution_amount' => $companyContribution,
            ]);

            return $settlement->load(['payouts', 'channelPartnerPayouts']);
        });
    }

    /** Promote a reviewed draft settlement to confirmed (v3 P1.4). */
    public function confirmSettlement(ProjectSettlement $settlement, int $userId): ProjectSettlement
    {
        if ($settlement->status !== 'draft') {
            throw new RuntimeException('Only a draft settlement can be confirmed.');
        }

        $settlement->forceFill(['status' => 'confirmed'])->save();

        app(AuditLogService::class)->record('settlement_confirmed', $settlement, ['status' => 'draft'], ['status' => 'confirmed', 'confirmed_by' => $userId]);

        return $settlement->refresh();
    }

    /**
     * Void a draft or confirmed settlement while no payout has been paid, and
     * re-open the project so it can be settled again (v3 P1.4).
     */
    public function voidSettlement(ProjectSettlement $settlement, string $reason, int $userId): void
    {
        if (blank($reason)) {
            throw new RuntimeException('A void reason is required.');
        }

        DB::transaction(function () use ($settlement, $reason, $userId): void {
            $locked = ProjectSettlement::query()->whereKey($settlement)->lockForUpdate()->firstOrFail();

            if ($locked->hasPaidPayouts()) {
                throw new RuntimeException('A payout on this settlement has already been paid — it cannot be voided. Record a reversal instead.');
            }

            $snapshot = $locked->only(['status', 'outcome', 'net_profit', 'investor_pool_amount', 'channel_partner_amount', 'company_net_amount']);
            $project = $locked->project()->lockForUpdate()->first();

            $locked->payouts()->delete();
            $locked->channelPartnerPayouts()->delete();
            $locked->documents()->delete();

            app(AuditLogService::class)->record('settlement_voided', $locked, $snapshot, [
                'reason' => $reason,
                'voided_by' => $userId,
            ]);

            $locked->delete();

            $project?->forceFill(['status' => 'closed'])->saveQuietly();
        });
    }

    /**
     * @return array{investor_pool: float, channel_payout: float, partner_rows: array<int, float>, company_net: float, annualized: float|null, rate_per_lac: float|null, payout_rows: list<array{investor_id:int, principal:float, profit_share:float, recipient_name:string}>}
     */
    private function allocateProfit(
        InvestmentProject $project,
        float $netProfit,
        float $externalInvested,
        float $capitalBase,
        float $companyContribution,
        Collection $investments,
        Collection $grouped,
    ): array {
        $investorPool = round($netProfit * ((float) $project->investor_share_percent / 100), 2);
        $configuredChannelPool = round($netProfit * ((float) $project->channel_partner_share_percent / 100), 2);

        // The channel partner's 10% is prorated over EXTERNAL investor capital
        // only — the company's own gap-fill contribution is neither "referred"
        // nor "direct investor" capital, so it never enters this calculation.
        // Each partner gets the pool's share for the capital of the investors
        // they brought in (owner, 2026-09-28: several partners per project).
        $partnerRows = $investments
            ->filter(fn ($investment) => filled($investment->investor->channel_partner_id))
            ->groupBy(fn ($investment) => (int) $investment->investor->channel_partner_id)
            ->map(fn (Collection $referred): float => round($configuredChannelPool * ((float) $referred->sum('amount') / $externalInvested), 2))
            ->all();
        $channelPayout = round(array_sum($partnerRows), 2);

        $ratePerLac = round($investorPool / max($capitalBase / 100000, 0.01), 2);

        // Investors see an annualized *investor* yield on a 360-day year — pool
        // ÷ capital, not net-profit ÷ capital (v3 P1.5, matches the signed
        // settlement sheet: 3.51% per 60 days → 21.08% yearly).
        $annualizedReturn = $project->trade_cycle_days
            ? round(($investorPool / $capitalBase) * (360 / $project->trade_cycle_days) * 100, 2)
            : null;

        $hasCompanyContribution = $companyContribution > 0;
        $lastInvestorId = $grouped->keys()->last();
        $allocatedProfit = 0.0;
        $payoutRows = [];

        foreach ($grouped as $investorId => $rows) {
            $principal = round((float) $rows->sum('amount'), 2);

            // Without a company contribution the last investor takes the
            // rounding remainder so the schedule equals the pool exactly
            // (unchanged behaviour). With a contribution, every external
            // investor is strictly proportional and the unpaid slice (company's
            // share + dust) falls into company_net.
            $profitShare = (! $hasCompanyContribution && (int) $investorId === (int) $lastInvestorId)
                ? round($investorPool - $allocatedProfit, 2)
                : round($investorPool * ($principal / $capitalBase), 2);
            $allocatedProfit = round($allocatedProfit + $profitShare, 2);

            $payoutRows[] = [
                'investor_id' => (int) $investorId,
                'principal' => $principal,
                'profit_share' => $profitShare,
                'recipient_name' => $rows->first()->investor->name,
            ];
        }

        return [
            'investor_pool' => $investorPool,
            'channel_payout' => $channelPayout,
            'partner_rows' => $partnerRows,
            'company_net' => round($netProfit - $allocatedProfit - $channelPayout, 2),
            'annualized' => $annualizedReturn,
            'rate_per_lac' => $ratePerLac,
            'payout_rows' => $payoutRows,
        ];
    }

    /**
     * Mudarabah loss rule (deed clause 4): the financial loss falls on the
     * capital owners (investors + the company's own gap-fill) in proportion to
     * capital — unless it was the company's negligence or breach, in which case
     * the company bears all of it and investors get their principal back.
     *
     * @return array{investor_pool: float, channel_payout: float, partner_rows: array<int, float>, company_net: float, annualized: null, rate_per_lac: float|null, payout_rows: list<array{investor_id:int, principal:float, profit_share:float, recipient_name:string}>}
     */
    private function allocateLoss(
        string $outcome,
        float $netProfit,
        float $capitalBase,
        Collection $grouped,
    ): array {
        $loss = round(-$netProfit, 2); // positive
        $payoutRows = [];

        if ($outcome === 'loss_manager_borne') {
            foreach ($grouped as $investorId => $rows) {
                $principal = round((float) $rows->sum('amount'), 2);
                $payoutRows[] = [
                    'investor_id' => (int) $investorId,
                    'principal' => $principal,
                    'profit_share' => 0.0,
                    'recipient_name' => $rows->first()->investor->name,
                ];
            }

            return [
                'investor_pool' => 0.0,
                'channel_payout' => 0.0,
                'partner_rows' => [],
                'company_net' => round($netProfit, 2), // company eats the whole loss
                'annualized' => null,
                'rate_per_lac' => null,
                'payout_rows' => $payoutRows,
            ];
        }

        // loss_investor_borne
        $externalLossBorne = 0.0;

        foreach ($grouped as $investorId => $rows) {
            $principal = round((float) $rows->sum('amount'), 2);
            $borne = round($loss * ($principal / $capitalBase), 2);
            $externalLossBorne = round($externalLossBorne + $borne, 2);

            $payoutRows[] = [
                'investor_id' => (int) $investorId,
                'principal' => $principal,
                'profit_share' => round(-$borne, 2), // negative — capital eroded
                'recipient_name' => $rows->first()->investor->name,
            ];
        }

        $investorPool = round(-$externalLossBorne, 2);

        return [
            'investor_pool' => $investorPool,
            'channel_payout' => 0.0,
            'partner_rows' => [],
            // netProfit is negative; adding back what investors bear leaves the
            // company's own gap-fill share of the loss (also negative, or zero).
            'company_net' => round($netProfit + $externalLossBorne, 2),
            'annualized' => null,
            'rate_per_lac' => round($investorPool / max($capitalBase / 100000, 0.01), 2),
            'payout_rows' => $payoutRows,
        ];
    }
}
