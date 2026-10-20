<?php

namespace App\Ims\Profit;

use App\Models\Company;
use App\Models\InvestmentProject;
use App\Models\ProfitProposal;
use App\Models\ProjectCostItem;
use App\Models\ProjectSettlement;
use App\Models\Purchase;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Investment\SettlementService;
use App\Support\ImsNumbers;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The system's profit proposal when a cycle ends (IMS-V2/PRD.md FR-45,
 * ARCHITECTURE §8.1): for every closed project without a settlement it
 * works out the figures (ProjectFinancials) and asks staff to decide. A
 * proposal shares out nothing by itself. Staff either accept the figures
 * (the linked purchases' costs are then written as the project's cost lines
 * and the normal Calculate & Settle runs with the worked-out sales), enter
 * the profit by hand, or dismiss it. A proposal for a project that was
 * settled another way becomes "no longer needed".
 */
class ProposalService
{
    public function __construct(
        private ProjectFinancials $financials,
        private SettlementService $settlements,
        private AuditLogService $audit,
    ) {}

    /** @return int proposals made */
    public function generate(Company $company): int
    {
        $this->markStale();
        $made = 0;

        $projects = InvestmentProject::query()->where('company_id', $company->getKey())->where('status', 'closed')
            ->whereDoesntHave('settlement')
            ->whereDoesntHave('profitProposals', fn ($query) => $query->whereIn('status', [ProfitProposal::STATUS_PENDING, ProfitProposal::STATUS_DISMISSED]))
            ->get();

        foreach ($projects as $project) {
            $figures = $this->financials->compute($project);

            ProfitProposal::query()->create([
                'company_id' => $project->company_id,
                'project_id' => $project->getKey(),
                'period_start' => $figures['period_start'],
                'period_end' => $figures['period_end'],
                'figures' => $figures,
            ]);

            $made++;
        }

        if ($made > 0) {
            $this->tellStaff($company, $made);
        }

        return $made;
    }

    public function markStale(): void
    {
        ProfitProposal::query()->where('status', ProfitProposal::STATUS_PENDING)
            ->whereHas('project', fn ($query) => $query->whereHas('settlement'))
            ->update(['status' => ProfitProposal::STATUS_STALE]);
    }

    /**
     * @param  array{outcome?: string|null, loss_reason?: string|null}  $options
     */
    public function accept(ProfitProposal $proposal, User $by, array $options = []): ProjectSettlement
    {
        $this->authorize($by);

        return DB::transaction(function () use ($proposal, $by, $options): ProjectSettlement {
            $locked = $this->lockPending($proposal);
            $project = $locked->project()->firstOrFail();

            // The goods of the linked purchases become the project's cost lines once,
            // so the settlement's cost is the same as the proposal's.
            $costed = $project->costItems()->whereNotNull('purchase_id')->pluck('purchase_id')->map(fn ($id): int => (int) $id)->all();

            foreach (Purchase::query()->whereIn('id', $project->allPurchaseIds())->get() as $purchase) {
                if (! in_array((int) $purchase->getKey(), $costed, true)) {
                    ProjectCostItem::query()->create([
                        'project_id' => $project->getKey(),
                        'category' => 'landed_cost',
                        'label' => __('Purchase :number (goods and import)', ['number' => $purchase->purchase_number]),
                        'amount' => round($purchase->landedCostTotal(), 2),
                        'purchase_id' => $purchase->getKey(),
                    ]);
                }
            }

            $figures = $this->financials->compute($project->refresh());
            $isLoss = $figures['net_profit'] < 0;

            $settlement = $this->settlements->calculateAndSettle(
                $project,
                (float) $figures['revenue'],
                $by->getKey(),
                acknowledgeUnprovenCosts: true,
                outcome: $isLoss ? (string) ($options['outcome'] ?? '') : 'profit',
                lossReason: $isLoss ? ($options['loss_reason'] ?? null) : null,
            );

            return $this->close($locked, $settlement, $by, 'accepted');
        });
    }

    public function enterByHand(ProfitProposal $proposal, User $by, float $netProfit): ProjectSettlement
    {
        $this->authorize($by);

        return DB::transaction(function () use ($proposal, $by, $netProfit): ProjectSettlement {
            $locked = $this->lockPending($proposal);
            $settlement = $this->settlements->calculateAndSettle($locked->project()->firstOrFail(), 0, $by->getKey(), manualNetProfit: $netProfit);

            return $this->close($locked, $settlement, $by, 'manual');
        });
    }

    public function dismiss(ProfitProposal $proposal, User $by, string $reason): void
    {
        $this->authorize($by);

        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => __('Write a reason of at least 5 letters.')]);
        }

        $locked = $this->lockPending($proposal);
        $locked->forceFill(['status' => ProfitProposal::STATUS_DISMISSED, 'decided_by' => $by->getKey(), 'decided_at' => now(), 'reason' => trim($reason)])->save();
        $this->audit->record('ims.proposal_dismissed', $locked, null, ['reason' => trim($reason), 'by' => $by->getKey()]);
    }

    private function close(ProfitProposal $proposal, ProjectSettlement $settlement, User $by, string $how): ProjectSettlement
    {
        $proposal->forceFill(['status' => ProfitProposal::STATUS_APPROVED, 'decided_by' => $by->getKey(), 'decided_at' => now(), 'settlement_id' => $settlement->getKey(), 'reason' => $how])->save();
        $this->audit->record('ims.proposal_approved', $proposal, null, ['settlement_id' => $settlement->getKey(), 'how' => $how, 'by' => $by->getKey()]);

        return $settlement;
    }

    private function lockPending(ProfitProposal $proposal): ProfitProposal
    {
        $locked = ProfitProposal::query()->lockForUpdate()->findOrFail($proposal->getKey());

        if ($locked->status !== ProfitProposal::STATUS_PENDING) {
            throw ValidationException::withMessages(['proposal' => __('This proposal was already decided.')]);
        }

        return $locked;
    }

    private function authorize(User $by): void
    {
        if (! $by->hasPermission('ims.proposals.approve')) {
            throw ValidationException::withMessages(['proposal' => __('You do not have permission to decide profit proposals.')]);
        }
    }

    private function tellStaff(Company $company, int $count): void
    {
        $company->users()->get()
            ->filter(fn (User $user): bool => $user->is_active !== false && $user->hasPermission('ims.proposals.approve'))
            ->each(fn (User $user) => Notification::make()
                ->title(__(':count profit proposals are waiting', ['count' => ImsNumbers::count($count)]))
                ->body(__('A cycle has ended. Open the IMS office to check the figures and decide.'))
                ->sendToDatabase($user));
    }
}
