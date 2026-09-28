<?php

namespace App\Services\Investment;

use App\Models\FundSource;
use App\Models\Investment;
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

            $fundSource = FundSource::query()->find($data['fund_source_id'] ?? null);

            if (! $fundSource || ! $fundSource->account_id) {
                throw ValidationException::withMessages(['fund_source_id' => __('Pick a fund source that has a linked account.')]);
            }

            $locked->loadMissing('investor', 'project');

            $voucher = $this->vouchers->submit([
                'company_id' => $locked->company_id,
                'type' => Voucher::TYPE_DEBIT,
                'transaction_type' => 'investor_payout',
                'amount' => (float) $locked->amount,
                'fund_source_id' => $fundSource->getKey(),
                'account_id' => $fundSource->account_id,
                'payment_method' => $data['payment_method'],
                'transaction_id' => $data['payment_reference'] ?? null,
                'confirmation_source' => 'manual',
                'purpose' => "Mudarabah principal withdrawal — {$locked->investor?->name} — {$locked->project?->project_code} — withdrawn on {$data['withdrawn_at']}",
                'remarks' => $data['note'] ?? null,
            ], $user);

            $locked->update([
                'status' => Investment::STATUS_WITHDRAWN,
                'withdrawn_at' => $data['withdrawn_at'],
                'withdrawal_voucher_id' => $voucher->getKey(),
                'withdrawal_note' => $data['note'] ?? null,
            ]);

            $investment->setRawAttributes($locked->getAttributes(), true);

            return $voucher;
        });
    }
}
