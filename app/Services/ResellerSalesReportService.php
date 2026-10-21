<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\ResellerCommission;
use App\Models\ResellerPayoutRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The figures behind the admin "Reseller sales report": one row per reseller
 * for a date range, read from the reseller's own orders and the commission and
 * payout ledgers (so it always agrees with Reseller profits and Withdrawal
 * requests). Orders are placed in the range by their order date. Only
 * confirmed, processing and completed orders count as sales, the same rule the
 * reseller's own dashboard uses; cancelled and returned orders are counted
 * separately so staff can see them. Every query goes through the company scope
 * of the model it reads, so one company never sees another's resellers.
 */
class ResellerSalesReportService
{
    /**
     * @return Collection<int, array{
     *     id: int, name: string, orders: int, cancelled: int, returned: int,
     *     sales: float, cost: float, profit: float, payable: float, pending: float, paid_out: float
     * }>
     */
    public function rows(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $inRange = fn () => Order::query()
            ->whereNotNull('reseller_customer_id')
            ->whereDate('order_date', '>=', $from->toDateString())
            ->whereDate('order_date', '<=', $to->toDateString());

        $rows = [];

        $blank = fn (int $id): array => [
            'id' => $id, 'name' => '', 'orders' => 0, 'cancelled' => 0, 'returned' => 0,
            'sales' => 0.0, 'cost' => 0.0, 'profit' => 0.0, 'payable' => 0.0, 'pending' => 0.0, 'paid_out' => 0.0,
        ];

        $inRange()->toBase()
            ->selectRaw('reseller_customer_id as reseller_id, status, count(*) as total')
            ->groupBy('reseller_customer_id', 'status')
            ->get()
            ->each(function (object $row) use (&$rows, $blank): void {
                $id = (int) $row->reseller_id;
                $rows[$id] ??= $blank($id);

                if (in_array($row->status, Order::ACCOUNTED_STATUSES, true)) {
                    $rows[$id]['orders'] += (int) $row->total;
                } elseif ($row->status === Order::STATUS_CANCELLED) {
                    $rows[$id]['cancelled'] += (int) $row->total;
                } elseif (in_array($row->status, [Order::STATUS_RETURNED, Order::STATUS_REFUNDED], true)) {
                    $rows[$id]['returned'] += (int) $row->total;
                }
            });

        ResellerCommission::query()
            ->whereIn('order_id', $inRange()->whereIn('status', Order::ACCOUNTED_STATUSES)->select('orders.id'))
            ->where('status', '!=', ResellerCommission::STATUS_CANCELLED)
            ->toBase()
            ->selectRaw('customer_id as reseller_id, status, sum(sales_amount) as sales, sum(base_amount) as cost, sum(amount) as profit')
            ->groupBy('customer_id', 'status')
            ->get()
            ->each(function (object $row) use (&$rows, $blank): void {
                $id = (int) $row->reseller_id;
                $rows[$id] ??= $blank($id);

                $rows[$id]['sales'] += (float) $row->sales;
                $rows[$id]['cost'] += (float) $row->cost;
                $rows[$id]['profit'] += (float) $row->profit;

                if ($row->status === ResellerCommission::STATUS_PAYABLE) {
                    $rows[$id]['payable'] += (float) $row->profit;
                } else {
                    $rows[$id]['pending'] += (float) $row->profit;
                }
            });

        ResellerPayoutRequest::query()
            ->where('status', ResellerPayoutRequest::STATUS_PAID)
            ->whereDate('paid_at', '>=', $from->toDateString())
            ->whereDate('paid_at', '<=', $to->toDateString())
            ->toBase()
            ->selectRaw('customer_id as reseller_id, sum(amount) as paid_out')
            ->groupBy('customer_id')
            ->get()
            ->each(function (object $row) use (&$rows, $blank): void {
                $id = (int) $row->reseller_id;
                $rows[$id] ??= $blank($id);
                $rows[$id]['paid_out'] += (float) $row->paid_out;
            });

        if ($rows === []) {
            return collect();
        }

        $names = Customer::query()
            ->whereIn('id', array_keys($rows))
            ->get(['id', 'name', 'business_name'])
            ->mapWithKeys(fn ($reseller): array => [
                $reseller->getKey() => (string) ($reseller->business_name ?: $reseller->name),
            ]);

        return collect($rows)
            ->filter(fn (array $row): bool => $names->has($row['id']))
            ->map(function (array $row) use ($names): array {
                $row['name'] = $names[$row['id']];

                foreach (['sales', 'cost', 'profit', 'payable', 'pending', 'paid_out'] as $money) {
                    $row[$money] = round($row[$money], 2);
                }

                return $row;
            })
            ->sortByDesc('sales')
            ->values();
    }

    /**
     * The column totals for a set of rows.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{resellers: int, orders: int, cancelled: int, returned: int, sales: float, cost: float, profit: float, payable: float, pending: float, paid_out: float}
     */
    public function totals(Collection $rows): array
    {
        return [
            'resellers' => $rows->count(),
            'orders' => (int) $rows->sum('orders'),
            'cancelled' => (int) $rows->sum('cancelled'),
            'returned' => (int) $rows->sum('returned'),
            'sales' => round((float) $rows->sum('sales'), 2),
            'cost' => round((float) $rows->sum('cost'), 2),
            'profit' => round((float) $rows->sum('profit'), 2),
            'payable' => round((float) $rows->sum('payable'), 2),
            'pending' => round((float) $rows->sum('pending'), 2),
            'paid_out' => round((float) $rows->sum('paid_out'), 2),
        ];
    }
}
