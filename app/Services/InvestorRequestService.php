<?php

namespace App\Services;

use App\Ims\Kyc\KycService;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\InvestmentWithdrawalNotice;
use App\Models\InvestorCycleElection;
use App\Models\InvestorGroup;
use App\Models\InvestorGroupMember;
use App\Models\InvestorPortalAccount;
use App\Models\InvestorRequest;
use App\Models\User;
use App\Services\Investment\CycleElectionService;
use App\Services\Investment\InvestmentTargets;
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
 * - top-up → a new member of an OPEN GROUP once staff confirm the money
 *   arrived (+ its pending capital voucher); new money never joins a running
 *   group. A project that is not in a group still takes a plain Investment.
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

        // IMS v2 (PRD FR-14): new money needs a verified KYC while IMS is on.
        if ($type === InvestorRequest::TYPE_TOP_UP && KycService::blocksNewMoney($account->investor)) {
            throw ValidationException::withMessages(['data.type' => __('Please complete your KYC first (Account → KYC). New money can be put in only after it is verified.')]);
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
            ->when($type === InvestorRequest::TYPE_WITHDRAWAL || $type === InvestorRequest::TYPE_TOP_UP, fn ($query) => $query
                ->where('project_id', $attributes['project_id'] ?? null)
                ->where('group_id', $attributes['group_id'] ?? null))
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
                    'group_id' => $request->group_id,
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

    /** Projects outside any group that an investor may still add money to (open or running, inside the investment window). */
    public function openProjects(): Collection
    {
        return $this->targets()->openProjects();
    }

    /** Where new money can go: the open groups, then projects outside any group. */
    public function openTargets(): \Illuminate\Support\Collection
    {
        return $this->targets()->open();
    }

    protected function targets(): InvestmentTargets
    {
        return app(InvestmentTargets::class);
    }

    /**
     * The target picked in a form: `target` (`g12` / `p5`), or the older
     * `group_id` / `project_id` fields.
     *
     * @param  array<string, mixed>  $data
     */
    protected function targetKey(array $data, string $target = 'target', string $project = 'project_id', string $group = 'group_id'): ?string
    {
        return match (true) {
            filled($data[$target] ?? null) => (string) $data[$target],
            filled($data[$group] ?? null) => 'g'.(int) $data[$group],
            filled($data[$project] ?? null) => 'p'.(int) $data[$project],
            default => null,
        };
    }

    /** @return array{project_id: int|null, group_id: int|null} */
    protected function targetColumns(InvestorGroup|InvestmentProject|null $target): array
    {
        return [
            'project_id' => $target instanceof InvestmentProject ? $target->getKey() : null,
            'group_id' => $target instanceof InvestorGroup ? $target->getKey() : null,
        ];
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
        $target = $this->targets()->find($this->targetKey($data));
        $amount = round((float) ($data['amount'] ?? 0), 2);

        if (! $target) {
            throw ValidationException::withMessages(['data.'.(filled($data['target'] ?? null) || filled($data['group_id'] ?? null) ? 'target' : 'project_id') => __('Choose a group that is open for new members.')]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['data.amount' => __('Enter the amount.')]);
        }

        if (! array_key_exists($data['payment_method'] ?? '', Investment::PAYMENT_METHODS)) {
            throw ValidationException::withMessages(['data.payment_method' => __('Choose how you sent the money.')]);
        }

        return [
            ...$this->targetColumns($target),
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
        $key = $this->targetKey($data, 'target', 'project_id', 'group_id');
        $kind = $key ? substr($key, 0, 1) : null;
        $id = $key ? (int) substr($key, 1) : 0;
        $errorKey = 'data.'.(filled($data['target'] ?? null) || filled($data['group_id'] ?? null) ? 'target' : 'project_id');

        if ($kind === 'g') {
            $inGroup = InvestorGroupMember::query()
                ->where('investor_id', $account->investor_id)
                ->where('group_id', $id)
                ->where('status', InvestorGroupMember::STATUS_ACTIVE)
                ->exists();

            if (! $inGroup) {
                throw ValidationException::withMessages([$errorKey => __('Choose a group where you have money invested.')]);
            }

            if (InvestmentWithdrawalNotice::query()->where('investor_id', $account->investor_id)->where('group_id', $id)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages([$errorKey => __('A withdrawal notice for this group is already running.')]);
            }

            return ['group_id' => $id, 'project_id' => null];
        }

        $hasActive = $this->portal->investments($account)->where('project_id', $id)->where('status', Investment::STATUS_ACTIVE)->exists();

        if (! $hasActive) {
            throw ValidationException::withMessages([$errorKey => __('Choose a project where you have money invested.')]);
        }

        if (InvestmentWithdrawalNotice::query()->where('investor_id', $account->investor_id)->where('project_id', $id)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages([$errorKey => __('A withdrawal notice for this project is already running.')]);
        }

        return ['project_id' => $id, 'group_id' => null];
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
        $next = ['project_id' => null, 'group_id' => null];

        if ($election !== 'withdraw') {
            $nextTarget = $this->targets()->find($this->targetKey($data, 'next_target', 'next_project_id', 'next_group_id'));

            if (! $nextTarget) {
                throw ValidationException::withMessages(['data.'.(filled($data['next_target'] ?? null) || filled($data['next_group_id'] ?? null) ? 'next_target' : 'next_project_id') => __('Choose a group that is open for new members.')]);
            }

            $next = $this->targetColumns($nextTarget);
            $amount = $election === 'reinvest' ? (float) $payout->total_payout : round((float) ($data['amount'] ?? 0), 2);

            if ($amount <= 0 || $amount > (float) $payout->total_payout) {
                throw ValidationException::withMessages(['data.amount' => __('The reinvested amount must be more than 0 and not more than the payout.')]);
            }
        }

        return [
            'settlement_payout_id' => $payout->getKey(),
            ...$next,
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

    protected function approveTopUp(InvestorRequest $request, User $by, array $data): InvestorGroupMember|Investment
    {
        $amount = round((float) ($data['amount'] ?? $request->amount), 2);
        $method = (string) ($data['payment_method'] ?? $request->payload['payment_method'] ?? '');

        if ($amount <= 0 || ! array_key_exists($method, Investment::PAYMENT_METHODS) || blank($data['invested_at'] ?? null)) {
            throw ValidationException::withMessages(['amount' => __('Enter the amount received, the date and how it was paid.')]);
        }

        // Staff may pick another open group if the one asked for has started since.
        $key = filled($data['target'] ?? null)
            ? (string) $data['target']
            : ($request->group_id ? 'g'.$request->group_id : ($request->project_id ? 'p'.$request->project_id : null));
        $target = $this->targets()->find($key);

        if (! $target) {
            throw ValidationException::withMessages(['target' => __('That group is not open any more. Pick an open group for this money.')]);
        }

        return $this->targets()->place($target, $request->investor, [
            'amount' => $amount,
            'payment_method' => $method,
            'payment_reference' => filled($data['payment_reference'] ?? null) ? trim((string) $data['payment_reference']) : ($request->payload['payment_reference'] ?? null),
            'invested_at' => $data['invested_at'],
            'override_reason' => $data['override_reason'] ?? null,
        ], $by);
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
                $request->group_id
                    ? InvestorGroup::query()->find($request->group_id)
                    : ($request->project_id ? InvestmentProject::query()->find($request->project_id) : null),
                (int) $by->getKey(),
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['request' => $exception->getMessage()]);
        }
    }
}
