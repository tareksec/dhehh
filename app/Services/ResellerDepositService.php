<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\ResellerDepositEntry;
use App\Models\User;
use App\Support\MoneyFormatter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff adjustments of a reseller's deposit (owner decision 2026-10-12):
 * add or deduct an amount with a reason that is always required. Each
 * adjustment is a ResellerDepositEntry of type `adjustment` (positive =
 * added, negative = deducted) that keeps the reason and the member of staff,
 * and is also written to the audit log (AuditObserver).
 *
 * A staff deduction never takes the deposit below zero: one bigger than the
 * balance is refused (only a return charge can leave it negative). It runs under the same reseller row lock as
 * ResellerOwnCourierService::applyDeposit(), so an adjustment and an order
 * that is using the deposit never read the same balance twice.
 */
class ResellerDepositService
{
    public const ADD = 'add';

    public const DEDUCT = 'deduct';

    public function adjust(Customer $reseller, string $direction, float $amount, string $reason, User $by): ResellerDepositEntry
    {
        $amount = round($amount, 2);
        $reason = trim($reason);

        if (! in_array($direction, [self::ADD, self::DEDUCT], true)) {
            throw ValidationException::withMessages(['direction' => __('Choose whether to add or deduct.')]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('Enter an amount above zero.')]);
        }

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('A reason is required.')]);
        }

        return DB::transaction(function () use ($reseller, $direction, $amount, $reason, $by): ResellerDepositEntry {
            $locked = Customer::withoutGlobalScopes()->lockForUpdate()->findOrFail($reseller->getKey());
            $balance = app(ResellerOwnCourierService::class)->depositBalance($locked);

            if ($direction === self::DEDUCT && $amount > $balance) {
                throw ValidationException::withMessages([
                    'amount' => __('The deposit holds only :balance, so :amount cannot be deducted.', [
                        'balance' => MoneyFormatter::number($balance),
                        'amount' => MoneyFormatter::number($amount),
                    ]),
                ]);
            }

            return ResellerDepositEntry::query()->create([
                'company_id' => $locked->company_id,
                'customer_id' => $locked->getKey(),
                'type' => ResellerDepositEntry::TYPE_ADJUSTMENT,
                'amount' => $direction === self::ADD ? $amount : -$amount,
                'note' => $reason,
                'created_by' => $by->getKey(),
            ]);
        });
    }
}
