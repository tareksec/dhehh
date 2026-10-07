<?php

namespace App\Services;

use App\Models\CourierBooking;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ResellerCommission;
use App\Models\ResellerPayoutRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps one ResellerCommission row per reseller-attributed order in step
 * with that order, and works out a reseller's balances.
 *
 * Owner's rule: commission becomes payable when the order is delivered;
 * a cancelled/returned/refunded order earns nothing.
 */
class ResellerCommissionService
{
    /** Cached once true: the table never disappears after migrating. */
    protected static bool $tableExists = false;

    public function sync(Order $order): void
    {
        // Every order save lands here; a plain (never reseller) order has
        // nothing to sync, so skip the lookup entirely.
        if (! $order->getKey() || (! $order->reseller_customer_id && ! $order->wasChanged('reseller_customer_id'))) {
            return;
        }

        if (! self::$tableExists && ! (self::$tableExists = Schema::hasTable('reseller_commissions'))) {
            return;
        }

        $existing = ResellerCommission::withoutGlobalScopes()->where('order_id', $order->getKey())->first();

        $lines = $order->reseller_customer_id
            ? $order->items()->withoutGlobalScopes()->whereNotNull('reseller_base_price')->get()
            : collect();

        if ($lines->isEmpty()) {
            $existing?->delete();

            return;
        }

        $sales = round((float) $lines->sum(fn ($line): float => (float) $line->unit_price * (int) $line->quantity), 2);
        $base = round((float) $lines->sum(fn ($line): float => (float) $line->reseller_base_price * (int) $line->quantity), 2);
        $status = $this->statusFor($order);

        $commission = $existing ?? new ResellerCommission(['order_id' => $order->getKey()]);
        $commission->fill([
            'company_id' => $order->company_id,
            'customer_id' => $order->reseller_customer_id,
            'sales_amount' => $sales,
            'base_amount' => $base,
            'amount' => max(0, round($sales - $base, 2)),
            'status' => $status,
            'payable_at' => $status === ResellerCommission::STATUS_PAYABLE
                ? ($commission->payable_at ?? now())
                : null,
        ]);
        $commission->save();
    }

    public function statusFor(Order $order): string
    {
        if (in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_RETURNED, Order::STATUS_REFUNDED], true)) {
            return ResellerCommission::STATUS_CANCELLED;
        }

        if ($order->delivery_status === CourierBooking::STATUS_DELIVERED || $order->status === Order::STATUS_COMPLETED) {
            return ResellerCommission::STATUS_PAYABLE;
        }

        return ResellerCommission::STATUS_PENDING;
    }

    /**
     * Creates a withdrawal request against the reseller's available balance,
     * or returns null when the amount is more than that balance. Serialised
     * per reseller so two quick submissions can't both pass the balance
     * check against the same money. Shared by the storefront account page
     * and the reseller portal.
     */
    public function requestPayout(Customer $reseller, float $amount, string $method, string $accountDetails): ?ResellerPayoutRequest
    {
        return Cache::lock('reseller-payout:'.$reseller->getKey(), 10)->block(5, function () use ($reseller, $amount, $method, $accountDetails): ?ResellerPayoutRequest {
            if ($amount > $this->balances($reseller)['available']) {
                return null;
            }

            return ResellerPayoutRequest::query()->create([
                'customer_id' => $reseller->getKey(),
                'amount' => round($amount, 2),
                'method' => $method,
                'account_details' => $accountDetails,
                'status' => ResellerPayoutRequest::STATUS_REQUESTED,
            ]);
        });
    }

    /**
     * @return array{pending: float, earned: float, withdrawn: float, in_process: float, available: float}
     */
    public function balances(Customer $reseller): array
    {
        $commissions = ResellerCommission::withoutGlobalScopes()
            ->where('company_id', $reseller->company_id)
            ->where('customer_id', $reseller->getKey());

        $payouts = ResellerPayoutRequest::withoutGlobalScopes()
            ->where('company_id', $reseller->company_id)
            ->where('customer_id', $reseller->getKey());

        $pending = (float) (clone $commissions)->where('status', ResellerCommission::STATUS_PENDING)->sum('amount');
        $earned = (float) (clone $commissions)->where('status', ResellerCommission::STATUS_PAYABLE)->sum('amount');
        $withdrawn = (float) (clone $payouts)->where('status', ResellerPayoutRequest::STATUS_PAID)->sum('amount');
        $inProcess = (float) (clone $payouts)
            ->whereIn('status', [ResellerPayoutRequest::STATUS_REQUESTED, ResellerPayoutRequest::STATUS_APPROVED])
            ->sum('amount');

        return [
            'pending' => round($pending, 2),
            'earned' => round($earned, 2),
            'withdrawn' => round($withdrawn, 2),
            'in_process' => round($inProcess, 2),
            // Can go below zero if a delivered order is later returned
            // after its commission was withdrawn -- future earnings then
            // cover it before anything more can be withdrawn.
            'available' => round($earned - $withdrawn - $inProcess, 2),
        ];
    }
}
