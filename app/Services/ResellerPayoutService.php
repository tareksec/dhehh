<?php

namespace App\Services;

use App\Models\FundSource;
use App\Models\ResellerPayoutRequest;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks a reseller withdrawal paid and books it in Finance in one step
 * (owner, 2026-09-28: every paid withdrawal gets a voucher automatically).
 *
 * The voucher is a `business_expense` debit: a reseller's commission is a
 * selling cost, so once Finance approves the voucher through the normal
 * Voucher workflow it becomes an Expense and leaves the chosen fund
 * source's account. Unlike the investment payouts, booking is not
 * optional here — if the voucher can't be created, nothing is marked paid.
 */
class ResellerPayoutService
{
    public function __construct(protected VoucherService $vouchers) {}

    /**
     * @param  array{payment_reference: string, fund_source_id: int|string, expense_category_id: int|string, admin_note?: string|null}  $data
     */
    public function markPaid(ResellerPayoutRequest $request, array $data, User $user): Voucher
    {
        return DB::transaction(function () use ($request, $data, $user): Voucher {
            $locked = ResellerPayoutRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if (! in_array($locked->status, [ResellerPayoutRequest::STATUS_REQUESTED, ResellerPayoutRequest::STATUS_APPROVED], true)) {
                throw ValidationException::withMessages([
                    'status' => __('This withdrawal request has already been processed.'),
                ]);
            }

            $fundSource = FundSource::query()->findOrFail($data['fund_source_id']);

            if (! $fundSource->account_id) {
                throw ValidationException::withMessages([
                    'fund_source_id' => __('This fund source has no linked account.'),
                ]);
            }

            $reseller = $locked->reseller;

            $voucher = $this->vouchers->submit([
                'company_id' => $locked->company_id,
                'type' => Voucher::TYPE_DEBIT,
                'transaction_type' => 'business_expense',
                'amount' => (float) $locked->amount,
                'fund_source_id' => $fundSource->getKey(),
                'account_id' => $fundSource->account_id,
                'expense_category_id' => $data['expense_category_id'],
                'payment_method' => $locked->method,
                'transaction_id' => $data['payment_reference'],
                'confirmation_source' => 'manual',
                'purpose' => 'Reseller commission payout — '.($reseller?->business_name ?: $reseller?->name).' ('.$reseller?->phone.') — request #'.$locked->getKey(),
                'remarks' => $data['admin_note'] ?? null,
            ], $user);

            $locked->update([
                'status' => ResellerPayoutRequest::STATUS_PAID,
                'payment_reference' => $data['payment_reference'],
                'admin_note' => $data['admin_note'] ?? $locked->admin_note,
                'approved_at' => $locked->approved_at ?? now(),
                'paid_at' => now(),
                'processed_by' => $user->getKey(),
                'voucher_id' => $voucher->getKey(),
            ]);

            $request->setRawAttributes($locked->getAttributes());

            return $voucher;
        });
    }
}
