<?php

namespace App\Services\Investment;

use App\Models\FundSource;
use App\Models\Investment;
use App\Models\InvestorGroupMember;
use App\Models\User;
use App\Models\Voucher;
use App\Services\VoucherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An investor takes their principal back out of a project (owner,
 * 2026-09-28: profit is paid at the end of each 2-month cycle; afterwards an
 * investor may withdraw the principal).
 *
 * Fixed rule from the owner: every withdrawal creates a voucher, and the
 * investment becomes "withdrawn" only once that voucher exists. Both happen in
 * one transaction, so a voucher failure leaves the investment active. The
 * record itself is never deleted.
 */
class InvestmentWithdrawalService
{
    public function __construct(private VoucherService $vouchers) {}

    /**
     * @param  array{withdrawn_at: string, fund_source_id: int|string, payment_method: string, payment_reference?: string|null, note?: string|null}  $data
     */
    public function withdraw(Investment $investment, array $data, User $user): Voucher
    {
        return DB::transaction(function () use ($investment, $data, $user): Voucher {
            $locked = Investment::query()->lockForUpdate()->findOrFail($investment->getKey());

            if ($locked->isWithdrawn()) {
                throw ValidationException::withMessages(['status' => __('This investment has already been withdrawn.')]);
            }

            $locked->loadMissing('investor', 'project');

            $voucher = $this->submitWithdrawalVoucher(
                (float) $locked->amount,
                $locked->company_id,
                $data,
                $user,
                "Mudarabah principal withdrawal — {$locked->investor?->name} — {$locked->project?->project_code} — withdrawn on {$data['withdrawn_at']}",
            );

            $locked->update([
                'status' => Investment::STATUS_WITHDRAWN,
                'withdrawn_at' => $data['withdrawn_at'],
                'withdrawal_voucher_id' => $voucher->getKey(),
                'withdrawal_note' => $data['note'] ?? null,
            ]);

            $this->withdrawMemberOfSnapshot($locked);

            $investment->setRawAttributes($locked->getAttributes(), true);

            return $voucher;
        });
    }

    /**
     * A member takes their deposit back out of a group that has not started
     * running (16_INVESTOR_GROUPS_PLAN.md): nothing has been copied into a
     * project yet, so it is the voucher plus the member's status, in one
     * transaction. Withdrawing from a running group follows the cycle-end rule
     * of the plan (phase G3).
     *
     * @param  array{withdrawn_at: string, fund_source_id: int|string, payment_method: string, payment_reference?: string|null, note?: string|null}  $data
     */
    public function withdrawGroupMember(InvestorGroupMember $member, array $data, User $user): Voucher
    {
        return DB::transaction(function () use ($member, $data, $user): Voucher {
            $locked = InvestorGroupMember::query()->lockForUpdate()->findOrFail($member->getKey());

            if ($locked->isWithdrawn()) {
                throw ValidationException::withMessages(['status' => __('This member has already withdrawn.')]);
            }

            if ($locked->investments()->exists() || ! $locked->group?->isOpen()) {
                throw ValidationException::withMessages(['status' => __('A member can take the deposit back here only while the group is still Open. After that, the money leaves at the end of a cycle.')]);
            }

            $locked->loadMissing('investor', 'group');

            $voucher = $this->submitWithdrawalVoucher(
                (float) $locked->amount,
                $locked->company_id,
                $data,
                $user,
                "Mudarabah deposit withdrawal — {$locked->investor?->name} — {$locked->group?->name} ({$locked->group?->group_code}) — withdrawn on {$data['withdrawn_at']}",
            );

            $locked->update([
                'status' => InvestorGroupMember::STATUS_WITHDRAWN,
                'withdrawn_at' => $data['withdrawn_at'],
                'withdrawal_voucher_id' => $voucher->getKey(),
                'withdrawal_note' => $data['note'] ?? null,
            ]);

            $member->setRawAttributes($locked->getAttributes(), true);

            return $voucher;
        });
    }

    /**
     * Withdrawing a group project's snapshot row takes the member out too, as
     * long as it is the member's latest row (an older, settled project's row
     * keeps its history). The member then drops out of every later snapshot.
     */
    private function withdrawMemberOfSnapshot(Investment $investment): void
    {
        if (! $investment->group_member_id) {
            return;
        }

        $member = InvestorGroupMember::query()->lockForUpdate()->find($investment->group_member_id);

        if (! $member || $member->isWithdrawn()) {
            return;
        }

        $latest = $member->investments()->with('project')->get()
            ->sortByDesc(fn (Investment $row): int => (int) ($row->project?->sequence_in_group ?? 0))
            ->first();

        if ($latest && $latest->getKey() === $investment->getKey()) {
            $member->update([
                'status' => InvestorGroupMember::STATUS_WITHDRAWN,
                'withdrawn_at' => $investment->withdrawn_at,
                'withdrawal_voucher_id' => $investment->withdrawal_voucher_id,
                'withdrawal_note' => $investment->withdrawal_note,
            ]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function submitWithdrawalVoucher(float $amount, int $companyId, array $data, User $user, string $purpose): Voucher
    {
        $fundSource = FundSource::query()->find($data['fund_source_id'] ?? null);

        if (! $fundSource || ! $fundSource->account_id) {
            throw ValidationException::withMessages(['fund_source_id' => __('Pick a fund source that has a linked account.')]);
        }

        return $this->vouchers->submit([
            'company_id' => $companyId,
            'type' => Voucher::TYPE_DEBIT,
            'transaction_type' => 'investor_payout',
            'amount' => $amount,
            'fund_source_id' => $fundSource->getKey(),
            'account_id' => $fundSource->account_id,
            'payment_method' => $data['payment_method'],
            'transaction_id' => $data['payment_reference'] ?? null,
            'confirmation_source' => 'manual',
            'purpose' => $purpose,
            'remarks' => $data['note'] ?? null,
        ], $user);
    }

    /**
     * The withdrawal voucher went to Trash, or was rejected or cancelled
     * (owner, 2026-09-28), so the withdrawal is no longer backed by a voucher:
     * its investments go back to active. The withdrawal date, voucher link and
     * note are kept as history.
     */
    public function voucherVoided(Voucher $voucher): void
    {
        Investment::query()
            ->where('withdrawal_voucher_id', $voucher->getKey())
            ->where('status', Investment::STATUS_WITHDRAWN)
            ->get()
            ->each(fn (Investment $investment) => $investment->update(['status' => Investment::STATUS_ACTIVE]));

        InvestorGroupMember::query()
            ->where('withdrawal_voucher_id', $voucher->getKey())
            ->where('status', InvestorGroupMember::STATUS_WITHDRAWN)
            ->get()
            ->each(fn (InvestorGroupMember $member) => $member->update(['status' => InvestorGroupMember::STATUS_ACTIVE]));
    }

    /** The withdrawal voucher came back from Trash: its investments are withdrawn again. */
    public function voucherRestored(Voucher $voucher): void
    {
        if ($voucher->isVoid()) {
            return;
        }

        Investment::query()
            ->where('withdrawal_voucher_id', $voucher->getKey())
            ->where('status', Investment::STATUS_ACTIVE)
            ->get()
            ->each(fn (Investment $investment) => $investment->update(['status' => Investment::STATUS_WITHDRAWN]));

        InvestorGroupMember::query()
            ->where('withdrawal_voucher_id', $voucher->getKey())
            ->where('status', InvestorGroupMember::STATUS_ACTIVE)
            ->get()
            ->each(fn (InvestorGroupMember $member) => $member->update(['status' => InvestorGroupMember::STATUS_WITHDRAWN]));
    }
}
