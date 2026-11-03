<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\TransactionLedger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class ReportService
{
    public function dateRange(?string $from = null, ?string $to = null): array
    {
        return [
            $from ? Carbon::parse($from)->startOfDay() : now()->startOfDay(),
            $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay(),
        ];
    }

    public function dashboardSummary(): array
    {
        [$from, $to] = $this->dateRange();

        return [
            'sales_today' => $this->salesQuery($from, $to)->sum('total_amount'),
            'storefront_pending_orders' => Order::query()
                ->whereIn('source', [Order::SOURCE_STOREFRONT, Order::SOURCE_OFFER])
                ->where('status', 'draft')
                ->count(),
            'storefront_pending_amount' => Order::query()
                ->whereIn('source', [Order::SOURCE_STOREFRONT, Order::SOURCE_OFFER])
                ->where('status', 'draft')
                ->sum('total_amount'),
            'purchases_today' => $this->purchasesQuery($from, $to)->sum('total_amount'),
            'customer_payments_today' => $this->ledgerQuery('customer_payment', $from, $to)->sum('amount'),
            'supplier_payments_today' => $this->ledgerQuery('supplier_payment', $from, $to)->sum('amount'),
            'expenses_today' => $this->expensesQuery($from, $to)->sum('amount'),
            'customer_due' => app(CustomerDueAlertService::class)->totalDue(),
            'supplier_due' => Supplier::query()->sum('current_balance'),
            'low_stock_count' => app(LowStockAlertService::class)->count(),
            'coming_soon_count' => Product::query()->where('status', Product::STATUS_COMING_SOON)->count(),
            'account_balance' => Account::query()->sum('current_balance'),
        ];
    }

    /** @return array{total: float, count: int} the counted sales between $from and $to */
    public function salesTotals(CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            'total' => (float) $this->salesQuery($from, $to)->sum('total_amount'),
            'count' => (int) $this->salesQuery($from, $to)->count(),
        ];
    }

    public function sales(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->salesQuery($from, $to)
            ->with('customer')
            ->orderByDesc('order_date')
            ->get();
    }

    public function salesForExport(CarbonInterface $from, CarbonInterface $to): LazyCollection
    {
        return $this->salesQuery($from, $to)
            ->with('customer')
            ->lazyById();
    }

    public function purchases(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->purchasesQuery($from, $to)
            ->with('supplier')
            ->orderByDesc('purchase_date')
            ->get();
    }

    public function storefrontPendingOrders(int $limit = 50): Collection
    {
        return Order::query()
            ->whereIn('source', [Order::SOURCE_STOREFRONT, Order::SOURCE_OFFER])
            ->where('status', Order::STATUS_DRAFT)
            ->with('customer')
            ->orderByDesc('order_date')
            ->limit($limit)
            ->get();
    }

    public function customerPayments(CarbonInterface $from, CarbonInterface $to, int $limit = 50): Collection
    {
        return $this->ledgerQuery('customer_payment', $from, $to)
            ->limit($limit)
            ->get();
    }

    public function supplierPayments(CarbonInterface $from, CarbonInterface $to, int $limit = 50): Collection
    {
        return $this->ledgerQuery('supplier_payment', $from, $to)
            ->limit($limit)
            ->get();
    }

    public function comingSoonProducts(int $limit = 50): Collection
    {
        return Product::query()
            ->where('status', Product::STATUS_COMING_SOON)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function purchasesForExport(CarbonInterface $from, CarbonInterface $to): LazyCollection
    {
        return $this->purchasesQuery($from, $to)
            ->with('supplier')
            ->lazyById();
    }

    public function expenses(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->expensesQuery($from, $to)
            ->with(['category', 'account'])
            ->orderByDesc('expense_date')
            ->get();
    }

    public function expensesForExport(CarbonInterface $from, CarbonInterface $to): LazyCollection
    {
        return $this->expensesQuery($from, $to)
            ->with(['category', 'account'])
            ->lazyById();
    }

    public function ledger(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return TransactionLedger::query()
            ->with('account')
            ->whereDate('transaction_date', '>=', $from->toDateString())
            ->whereDate('transaction_date', '<=', $to->toDateString())
            ->orderByDesc('transaction_date')
            ->get();
    }

    public function ledgerForExport(CarbonInterface $from, CarbonInterface $to): LazyCollection
    {
        return TransactionLedger::query()
            ->with('account')
            ->whereDate('transaction_date', '>=', $from->toDateString())
            ->whereDate('transaction_date', '<=', $to->toDateString())
            ->lazyById();
    }

    public function stock(): Collection
    {
        return Product::query()
            ->with('category')
            ->orderBy('name')
            ->get();
    }

    public function stockForExport(): LazyCollection
    {
        return Product::query()
            ->with('category')
            ->lazyById();
    }

    public function lowStock(): Collection
    {
        return app(LowStockAlertService::class)
            ->query()
            ->orderBy('name')
            ->get();
    }

    public function lowStockForExport(): LazyCollection
    {
        return app(LowStockAlertService::class)
            ->query()
            ->lazyById();
    }

    public function customerDues(): Collection
    {
        return app(CustomerDueAlertService::class)
            ->query()
            ->orderByDesc('current_balance')
            ->get();
    }

    public function customerDuesForExport(): LazyCollection
    {
        return app(CustomerDueAlertService::class)
            ->query()
            ->lazyById();
    }

    public function supplierDues(): Collection
    {
        return Supplier::query()
            ->where('current_balance', '>', 0)
            ->orderByDesc('current_balance')
            ->get();
    }

    public function supplierDuesForExport(): LazyCollection
    {
        return Supplier::query()
            ->where('current_balance', '>', 0)
            ->lazyById();
    }

    public function profit(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->profitForExport($from, $to)->collect();
    }

    public function profitForExport(CarbonInterface $from, CarbonInterface $to): LazyCollection
    {
        return $this->salesQuery($from, $to)
            ->with('items.product')
            ->lazyById()
            ->flatMap(fn (Order $order) => $order->items->map(function ($item) use ($order): array {
                $unitCost = $item->unit_cost !== null
                    ? (float) $item->unit_cost
                    : (float) ($item->product?->cost_price ?? 0);
                $cost = $unitCost * (int) $item->quantity;
                $revenue = (float) $item->subtotal;

                return [
                    'date' => optional($order->order_date)->toDateString(),
                    'invoice' => $order->order_number,
                    'product' => $item->product?->name,
                    'quantity' => $item->quantity,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => $revenue - $cost,
                ];
            }));
    }

    public function monthlySalesAndPurchases(int $months = 6): Collection
    {
        $start = now()->startOfMonth()->subMonths($months - 1);
        $periods = collect(range(0, $months - 1))
            ->map(fn (int $offset): Carbon => $start->copy()->addMonths($offset))
            ->mapWithKeys(fn (Carbon $month): array => [
                $month->format('Y-m') => [
                    'label' => $month->format('M Y'),
                    'sales' => 0.0,
                    'purchases' => 0.0,
                ],
            ]);

        $orderPeriod = $this->monthPeriodExpression('order_date');
        $purchasePeriod = $this->monthPeriodExpression('purchase_date');

        $sales = Order::query()
            ->selectRaw("{$orderPeriod} as period, sum(total_amount) as total")
            ->whereIn('status', Order::ACCOUNTED_STATUSES)
            ->whereDate('order_date', '>=', $start->toDateString())
            ->groupBy('period')
            ->pluck('total', 'period');

        $purchases = Purchase::query()
            ->selectRaw("{$purchasePeriod} as period, sum(total_amount) as total")
            ->where('status', 'received')
            ->whereDate('purchase_date', '>=', $start->toDateString())
            ->groupBy('period')
            ->pluck('total', 'period');

        return $periods
            ->map(fn (array $period, string $key): array => [
                ...$period,
                'sales' => (float) ($sales[$key] ?? 0),
                'purchases' => (float) ($purchases[$key] ?? 0),
            ])
            ->values();
    }

    public function topSellingProducts(int $limit = 5): Collection
    {
        return OrderItem::query()
            ->select([
                'products.name',
                DB::raw('sum(order_items.quantity) as quantity'),
                DB::raw('sum(order_items.subtotal) as revenue'),
            ])
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('orders.status', Order::ACCOUNTED_STATUSES)
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('quantity')
            ->limit($limit)
            ->get();
    }

    public function topCustomers(int $limit = 5): Collection
    {
        return Customer::query()
            ->select([
                'customers.name',
                'customers.current_balance',
                DB::raw('coalesce(sum(orders.total_amount), 0) as total_sales'),
            ])
            ->leftJoin('orders', function ($join): void {
                $join->on('orders.customer_id', '=', 'customers.id')
                    ->whereIn('orders.status', Order::ACCOUNTED_STATUSES);
            })
            ->groupBy('customers.id', 'customers.name', 'customers.current_balance')
            ->orderByDesc('total_sales')
            ->limit($limit)
            ->get();
    }

    public function topSuppliers(int $limit = 5): Collection
    {
        return Supplier::query()
            ->select([
                'suppliers.name',
                'suppliers.current_balance',
                DB::raw('coalesce(sum(purchases.total_amount), 0) as total_purchases'),
            ])
            ->leftJoin('purchases', function ($join): void {
                $join->on('purchases.supplier_id', '=', 'suppliers.id')
                    ->where('purchases.status', 'received')
                    ->whereNull('purchases.deleted_at');
            })
            ->groupBy('suppliers.id', 'suppliers.name', 'suppliers.current_balance')
            ->orderByDesc('total_purchases')
            ->limit($limit)
            ->get();
    }

    protected function monthPeriodExpression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "date_format({$column}, '%Y-%m')",
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            default => "strftime('%Y-%m', {$column})",
        };
    }

    protected function salesQuery(CarbonInterface $from, CarbonInterface $to): Builder
    {
        return Order::query()
            ->whereIn('status', Order::ACCOUNTED_STATUSES)
            ->whereDate('order_date', '>=', $from->toDateString())
            ->whereDate('order_date', '<=', $to->toDateString());
    }

    protected function purchasesQuery(CarbonInterface $from, CarbonInterface $to): Builder
    {
        return Purchase::query()
            ->where('status', 'received')
            ->whereDate('purchase_date', '>=', $from->toDateString())
            ->whereDate('purchase_date', '<=', $to->toDateString());
    }

    protected function expensesQuery(CarbonInterface $from, CarbonInterface $to): Builder
    {
        return Expense::query()
            ->whereDate('expense_date', '>=', $from->toDateString())
            ->whereDate('expense_date', '<=', $to->toDateString());
    }

    protected function ledgerQuery(string $type, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return TransactionLedger::query()
            ->with('account')
            ->where('type', $type)
            ->whereDate('transaction_date', '>=', $from->toDateString())
            ->whereDate('transaction_date', '<=', $to->toDateString())
            ->orderByDesc('transaction_date');
    }
}
