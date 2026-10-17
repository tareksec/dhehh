<?php

namespace App\Services;

use App\Models\User;
use App\Models\Voucher;
use App\Services\Investment\InvestmentWithdrawalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owner's rule (2026-09-28): a voucher is never deleted directly. It moves to
 * Trash first, and is deleted permanently only from the Trash page, only by a
 * user with the `voucher.delete` permission.
 *
 * An approved voucher has already moved money (Expense / payment / account
 * transaction), so it cannot go to Trash: trashing it would leave the books
 * showing money that no voucher explains.
 */
class VoucherTrashService
{
    public function __construct(private InvestmentWithdrawalService $withdrawals, private VoucherLinkedPayoutsService $payouts) {}

    public function trash(Voucher $voucher, User $user): void
    {
        $this->authorize($user);

        DB::transaction(function () use ($voucher, $user): void {
            $locked = Voucher::query()->lockForUpdate()->findOrFail($voucher->getKey());

            if ($locked->status === Voucher::STATUS_APPROVED) {
                throw ValidationException::withMessages(['voucher' => __('An approved voucher has already moved money, so it cannot be moved to trash.')]);
            }

            $locked->forceFill(['deleted_by' => $user->getKey()])->saveQuietly();
            $locked->delete();
            $this->withdrawals->voucherVoided($locked);
            $this->payouts->voucherTrashed($locked);

            $voucher->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function restore(Voucher $voucher, User $user): void
    {
        $this->authorize($user);

        DB::transaction(function () use ($voucher): void {
            $locked = Voucher::onlyTrashed()->lockForUpdate()->findOrFail($voucher->getKey());
            $locked->restore();
            $locked->forceFill(['deleted_by' => null])->saveQuietly();
            $this->withdrawals->voucherRestored($locked);
            $this->payouts->voucherRestored($locked);

            $voucher->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function deletePermanently(Voucher $voucher, User $user): void
    {
        $this->authorize($user);

        DB::transaction(function () use ($voucher): void {
            $locked = Voucher::onlyTrashed()->lockForUpdate()->find($voucher->getKey());

            if (! $locked) {
                throw ValidationException::withMessages(['voucher' => __('Only a voucher in the trash can be deleted permanently.')]);
            }

            $locked->forceDelete();
        });
    }

    private function authorize(User $user): void
    {
        if (! $user->canDeleteVoucher()) {
            throw ValidationException::withMessages(['voucher' => __('You do not have permission to delete vouchers.')]);
        }
    }
}
