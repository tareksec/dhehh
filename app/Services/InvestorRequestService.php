<?php

namespace App\Services;

use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\InvestmentWithdrawalNotice;
use App\Models\InvestorCycleElection;
use App\Models\InvestorDraft;
use App\Models\InvestorPortalAccount;
use App\Models\InvestorRequest;
use App\Models\User;
use App\Services\Investment\CycleElectionService;
use App\Services\Investment\InvestmentLedgerService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Investor requests from the portal (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md
 * Phase 4; owner's draft: top-up, withdrawal, reinvest, support, each
 * pending → approved / rejected with notes and audit). Approving one runs the
 * same flow staff already use, so no money rule is duplicated here:
 *
 * - top-up → a new Investment once staff confirm the money arrived (+ its
 *   pending capital voucher, InvestmentLedgerService)
 * - withdrawal → an InvestmentWithdrawalNotice (60 days, deed clauses 5–6)
 * - reinvest → CycleElectionService::recordElection() on a pending payout
 * - support → a written answer
 */
class InvestorRequestService
{
    public function __construct(protected InvestorPortalService $portal, protected AuditLogService $audit) {}

    /** @param  array<string, mixed>  $data */
    public function create(InvestorPortalAccount $account, string $type, array $data): InvestorRequest
    {
        if (! $account->isInvestor()) {
            throw ValidationException::withMessages(['type' => __('Only investors can send requests.')]);
        }

        $attributes = match ($type) {
            InvestorRequest::TYPE_TOP_UP => $this->topUpAttributes($data),
            InvestorRequest::TYPE_WITHDRAWAL => $this->withdrawalAttributes($account, $data),
            InvestorRequest::TYPE_REINVEST => $this->reinvestAttributes($account, $data),
            InvestorRequest::TYPE_SUPPORT => $this->supportAttributes($data),
            default => throw ValidationException::withMessages(['type' => __('Choose what the request is about.')]),
        };

        $duplicate = InvestorRequest::query()
            ->where('investor_id', $account->investor_id)
            ->where('type', $type)
            ->where('status', InvestorRequest::STATUS_PENDING)
            ->when($type === InvestorRequest::TYPE_WITHDRAWAL || $type === InvestorRequest::TYPE_TOP_UP, fn ($query) => $query->where('project_id', $attributes['project_id']))
            ->when($type === InvestorRequest::TYPE_REINVEST, fn ($query) => $query->where('settlement_payout_id', $attributes['settlement_payout_id']))
            ->exists();

        if ($duplicate && $type !== InvestorRequest::TYPE_SUPPORT) {
            throw ValidationException::withMessages(['data.type' => __('You already have a pending request of this kind. Wait for the answer or cancel it first.')]);
        }

        $request = InvestorRequest::query()->create([
            ...$attributes,
            'company_id' => $account->company_id,
            'portal_account_id' => $account->getKey(),
            'investor_id' => $account->investor_id,
            'type' => $type,
            'message' => filled($data['message'] ?? null) ? trim((string) $data['message']) : null,
        ]);

        $this->audit->record('investor_request.created', $request, null, ['type' => $type]);

        return $request;
    }

    public function cancel(InvestorPortalAccount $account, InvestorRequest $request): void
    {
        if ((int) $request->portal_account_id !== (int) $account->getKey() || ! $request->isPending()) {
            throw ValidationException::withMessages(['request' => __('Only a pending request can be cancelled.')]);
        }

        $request->forceFill(['status' => InvestorRequest::STATUS_CANCELLED])->save();
        $this->audit->record('investor_request.cancelled', $request);
    }

    /** @param  array<string, mixed>  $data  staff's confirmation (top-up: amount, invested_at, payment_method, payment_reference; all: admin_note) */
    public function approve(InvestorRequest $request, User $by, array $data = []): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages(['request' => __('This request was already decided.')]);
        }

        $this->ensurePermission($request, $by);

        DB::transaction(function () use ($request, $by, $data): void {
            $result = match ($request->type) {
                InvestorRequest::TYPE_TOP_UP => $this->approveTopUp($request, $by, $data),
                InvestorRequest::TYPE_WITHDRAWAL => InvestmentWithdrawalNotice::query()->create([
                    'company_id' => $request->company_id,
                    'investor_id' => $request->investor_id,
                    'project_id' => $request->project_id,
                    'notice_given_at' => $request->created_at->toDateString(),
                    'reason' => $request->message,
                ]),
                InvestorRequest::TYPE_REINVEST => $this->approveReinvest($request, $by),
                InvestorRequest::TYPE_SUPPORT => null,
            };

            if ($request->type === InvestorRequest::TYPE_SUPPORT && blank($data['admin_note'] ?? null)) {
                throw ValidationException::withMessages(['admin_note' => __('Write the answer for the investor.')]);
            }

            $request->forceFill([
                'status' => InvestorRequest::STATUS_APPROVED,
                'admin_note' => filled($data['admin_note'] ?? null) ? trim((string) $data['admin_note']) : null,
                'decided_by' => $by->getKey(),
                'decided_at' => now(),
                'result_type' => $result?->getMorphClass(),
                'result_id' => $result?->getKey(),
            ])->save();
        });

        if ($request->type === InvestorRequest::TYPE_TOP_UP && $request->result instanceof Investment) {
            // The same pending capital voucher every new investment gets.
            app(InvestmentLedgerService::class)->recordInvestmentReceived($request->result);
        }

        $this->audit->record('investor_request.approved', $request, ['status' => InvestorRequest::STATUS_PENDING], ['status' => InvestorRequest::STATUS_APPROVED, 'result' => $request->result_type ? class_basename($request->result_type).' #'.$request->result_id : null]);
    }

    public function reject(InvestorRequest $request, User $by, string $note): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages(['request' => __('This request was already decided.')]);
        }

        if (blank($note)) {
            throw ValidationException::withMessages(['admin_note' => __('Give the investor a reason.')]);
        }

        $request->forceFill([
            'status' => InvestorRequest::STATUS_REJECTED,
            'admin_note' => trim($note),
            'decided_by' => $by->getKey(),
            'decided_at' => now(),
        ])->save();

        $this->audit->record('investor_request.rejected', $request, ['status' => InvestorRequest::STATUS_PENDING], ['status' => InvestorRequest::STATUS_REJECTED, 'note' => trim($note)]);
    }

    /** Projects an investor may add money to: open or running, and inside the investment window when one is set. */
    public function openProjects(): Collection
    {
        return InvestmentProject::query()
            ->whereIn('status', InvestorDraft::OPEN_PROJECT_STATUSES)
            ->orderByDesc('id')
            ->get()
            ->filter(fn (InvestmentProject $project): bool => $project->investment_closes_at === null || $project->isWithinInvestmentWindow())
            ->values();
    }

    /** The permission the existing flow needs: new money and notices = manage, payout choices = settle. */
    public static function permissionFor(string $type): string
    {
        return $type === InvestorRequest::TYPE_REINVEST ? 'investments.settle' : ($type === InvestorRequest::TYPE_SUPPORT ? 'investments.portal_manage' : 'investments.manage');
    }

    protected function ensurePermission(InvestorRequest $request, User $by): void
    {
        if (! $by->hasPermission(self::permissionFor($request->type))) {
            throw ValidationException::withMessages(['request' => __('You do not have permission to decide this request.')]);
        }
    }

    /** @return array<string, mixed> */
    protected function topUpAttributes(array $data): array
    {
        $project = $this->openProjects()->firstWhere('id', (int) ($data['project_id'] ?? 0));
        $amount = round((float) ($data['amount'] ?? 0), 2);

        if (! $project) {
            throw ValidationException::withMessages(['data.project_id' => __('Choose a project that is open for investment.')]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['data.amount' => __('Enter the amount.')]);
        }

        if (! array_key_exists($data['payment_method'] ?? '', Investment::PAYMENT_METHODS)) {
            throw ValidationException::withMessages(['data.payment_method' => __('Choose how you sent the money.')]);
        }

        return [
            'project_id' => $project->getKey(),
            'amount' => $amount,
            'payload' => [
                'payment_method' => $data['payment_method'],
                'payment_reference' => filled($data['payment_reference'] ?? null) ? trim((string) $data['payment_reference']) : null,
                'sent_on' => filled($data['sent_on'] ?? null) ? (string) $data['sent_on'] : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function withdrawalAttributes(InvestorPortalAccount $account, array $data): array
    {
        $projectId = (int) ($data['project_id'] ?? 0);
        $hasActive = $this->portal->investments($account)->where('project_id', $projectId)->where('status', Investment::STATUS_ACTIVE)->exists();

        if (! $hasActive) {
            throw ValidationException::withMessages(['data.project_id' => __('Choose a project where you have money invested.')]);
        }

        if (InvestmentWithdrawalNotice::query()->where('investor_id', $account->investor_id)->where('project_id', $projectId)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['data.project_id' => __('A withdrawal notice for this project is already running.')]);
        }

        return ['project_id' => $projectId];
    }

    /** @return array<string, mixed> */
    protected function reinvestAttributes(InvestorPortalAccount $account, array $data): array
    {
        $payout = $this->portal->payouts($account)
            ->whereKey((int) ($data['settlement_payout_id'] ?? 0))
            ->where('payment_status', 'pending')
            ->whereDoesntHave('cycleElection')
            ->first();

        if (! $payout) {
            throw ValidationException::withMessages(['data.settlement_payout_id' => __('Choose a payout that is not paid yet.')]);
        }

        $election = (string) ($data['election'] ?? '');

        if (! array_key_exists($election, InvestorCycleElection::ELECTIONS)) {
            throw ValidationException::withMessages(['data.election' => __('Choose what to do with the payout.')]);
        }

        $amount = null;
        $nextProjectId = null;

        if ($election !== 'withdraw') {
            $nextProject = $this->openProjects()->firstWhere('id', (int) ($data['next_project_id'] ?? 0));

            if (! $nextProject) {
                throw ValidationException::withMessages(['data.next_project_id' => __('Choose a project that is open for investment.')]);
            }

            $nextProjectId = $nextProject->getKey();
            $amount = $election === 'reinvest' ? (float) $payout->total_payout : round((float) ($data['amount'] ?? 0), 2);

            if ($amount <= 0 || $amount > (float) $payout->total_payout) {
                throw ValidationException::withMessages(['data.amount' => __('The reinvested amount must be more than 0 and not more than the payout.')]);
            }
        }

        return [
            'settlement_payout_id' => $payout->getKey(),
            'project_id' => $nextProjectId,
            'amount' => $amount,
            'payload' => ['election' => $election],
        ];
    }

    /** @return array<string, mixed> */
    protected function supportAttributes(array $data): array
    {
        if (blank($data['message'] ?? null)) {
            throw ValidationException::withMessages(['data.message' => __('Write your question.')]);
        }

        return [];
    }

    protected function approveTopUp(InvestorRequest $request, User $by, array $data): Investment
    {
        $amount = round((float) ($data['amount'] ?? $request->amount), 2);
        $method = (string) ($data['payment_method'] ?? $request->payload['payment_method'] ?? '');

        if ($amount <= 0 || ! array_key_exists($method, Investment::PAYMENT_METHODS) || blank($data['invested_at'] ?? null)) {
            throw ValidationException::withMessages(['amount' => __('Enter the amount received, the date and how it was paid.')]);
        }

        return Investment::query()->create([
            'company_id' => $request->company_id,
            'project_id' => $request->project_id,
            'investor_id' => $request->investor_id,
            'amount' => $amount,
            'payment_method' => $method,
            'payment_reference' => filled($data['payment_reference'] ?? null) ? trim((string) $data['payment_reference']) : ($request->payload['payment_reference'] ?? null),
            'invested_at' => $data['invested_at'],
            'override_reason' => $data['override_reason'] ?? null,
            'received_by' => $by->getKey(),
        ]);
    }

    protected function approveReinvest(InvestorRequest $request, User $by): InvestorCycleElection
    {
        $payout = $request->payout;

        if (! $payout || $payout->payment_status !== 'pending' || $payout->cycleElection()->exists()) {
            throw ValidationException::withMessages(['request' => __('This payout is already paid or already has a choice recorded.')]);
        }

        try {
            return app(CycleElectionService::class)->recordElection(
                $payout,
                (string) ($request->payload['election'] ?? ''),
                $request->amount !== null ? (float) $request->amount : null,
                $request->project_id ? InvestmentProject::query()->find($request->project_id) : null,
                (int) $by->getKey(),
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['request' => $exception->getMessage()]);
        }
    }
}
