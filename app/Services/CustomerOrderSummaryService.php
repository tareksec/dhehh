<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;

/**
 * The money story of one customer for the customer page (owner request 2026-11-01): how much of our goods they
 * have taken and how much they have paid.
 *
 * It counts the same orders the customer's due counts (Customer::syncCurrentBalance()): confirmed, processing
 * and completed ones. A draft, a cancelled, a returned and a refunded order never became a sale, so they stay
 * out of the totals (they still show in the order list). Payments are what the Payments History of those orders
 * holds plus the customer's own payments against their due (CustomerPayment); the two are separate ledgers, so
 * nothing is counted twice.
 */
class CustomerOrderSummaryService
{
    /**
     * @return array{
     *     orders: int,
     *     purchased: float,
     *     paid_on_orders: float,
     *     paid_later: float,
     *     paid: float,
     *     due: float,
     *     opening_balance: float,
     *     first_order_date: ?string,
     *     last_order_date: ?string,
     * }
     */
    public function for(Customer $customer): array
    {
        $sales = $customer->orders()->whereIn('status', Order::ACCOUNTED_STATUSES);

        $totals = (clone $sales)
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(total_amount), 0) as purchased, COALESCE(SUM(paid_amount), 0) as paid, MIN(order_date) as first_date, MAX(order_date) as last_date')
            ->toBase()
            ->first();

        $paidOnOrders = round((float) ($totals->paid ?? 0), 2);
        $paidLater = round((float) $customer->payments()->sum('amount'), 2);

        return [
            'orders' => (int) ($totals->order_count ?? 0),
            'purchased' => round((float) ($totals->purchased ?? 0), 2),
            'paid_on_orders' => $paidOnOrders,
            'paid_later' => $paidLater,
            'paid' => round($paidOnOrders + $paidLater, 2),
            'due' => round((float) $customer->current_balance, 2),
            'opening_balance' => round((float) $customer->opening_balance, 2),
            'first_order_date' => $totals->first_date ? (string) $totals->first_date : null,
            'last_order_date' => $totals->last_date ? (string) $totals->last_date : null,
        ];
    }
}
