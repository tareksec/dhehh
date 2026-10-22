<?php

namespace App\Ims\Profit;

use App\Models\InvestmentProject;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A project's own figures, worked out from the ERP (IMS-V2/TRD.md §4.4,
 * PRD FR-44 a): the goods of the purchases linked to the project, what they
 * cost to bring in, what of them was sold in the project's dates, and what
 * is left in stock. Only totals; never a customer, supplier, invoice or unit
 * price.
 *
 * Sales count from orders that are confirmed, processing or completed (the
 * ERP's own statuses), dated within the project's start and end. A product
 * that also came in on another project's purchase in overlapping dates is
 * left out of the automatic sales and reported as "shared": which project
 * such a sale belongs to is the owner's open question (TRD §4.4), so staff
 * enter that profit by hand. The figures are only a proposal; staff accept
 * them or write the profit themselves.
 */
class ProjectFinancials
{
    public const SALE_STATUSES = [Order::STATUS_CONFIRMED, Order::STATUS_PROCESSING, Order::STATUS_COMPLETED];

    /**
     * @return array{period_start: string|null, period_end: string|null, costs: array{goods: float, import: float, landed_items: float, local_items: float, total: float}, units_bought: int, units_sold: int, units_in_stock: int, revenue: float, net_profit: float, shared_products: int, has_purchases: bool}
     */
    public function compute(InvestmentProject $project, ?CarbonImmutable $until = null): array
    {
        $start = $project->start_date ? CarbonImmutable::parse($project->start_date)->startOfDay() : null;
        $end = $project->end_date ? CarbonImmutable::parse($project->end_date)->endOfDay() : null;
        $until ??= CarbonImmutable::now();
        $end = $end !== null && $end->lt($until) ? $end : $until->endOfDay();

        $purchaseIds = $project->allPurchaseIds();
        $purchases = Purchase::query()->whereIn('id', $purchaseIds)->get();

        // A purchase already entered as a cost line of the project is not counted twice.
        $costedPurchaseIds = $project->costItems()->whereNotNull('purchase_id')->pluck('purchase_id')->map(fn ($id): int => (int) $id)->all();
        $autoCosted = $purchases->reject(fn (Purchase $purchase): bool => in_array((int) $purchase->getKey(), $costedPurchaseIds, true));

        $goods = round((float) $autoCosted->sum(fn (Purchase $purchase): float => (float) $purchase->subtotal), 2);
        $import = round((float) $autoCosted->sum(fn (Purchase $purchase): float => $purchase->chinaToBdCostTotal()), 2);
        $landedItems = round($project->totalLandedCost(), 2);
        $localItems = round($project->totalLocalExpense(), 2);
        $totalCost = round($goods + $import + $landedItems + $localItems, 2);

        $items = PurchaseItem::query()->whereIn('purchase_id', $purchaseIds)->get();
        $productIds = $items->pluck('product_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $unitsBought = (int) $items->sum(fn (PurchaseItem $item): int => max(0, (int) $item->quantity - (int) $item->short_quantity - (int) $item->damaged_quantity));

        $shared = $this->sharedProducts($project, $productIds, $start, $end);
        $own = array_values(array_diff($productIds, $shared));

        $sales = ['units' => 0, 'revenue' => 0.0];

        if ($own !== []) {
            $row = OrderItem::query()
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereIn('order_items.product_id', $own)
                ->whereIn('orders.status', self::SALE_STATUSES)
                ->where('orders.company_id', $project->company_id)
                ->when($start, fn ($query) => $query->where('orders.order_date', '>=', $start->toDateString()))
                ->where('orders.order_date', '<=', $end->toDateString())
                ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as units, COALESCE(SUM(order_items.subtotal), 0) as revenue')
                ->first();

            $sales = ['units' => (int) ($row->units ?? 0), 'revenue' => round((float) ($row->revenue ?? 0), 2)];
        }

        return [
            'period_start' => $start?->toDateString(),
            'period_end' => $end->toDateString(),
            'costs' => ['goods' => $goods, 'import' => $import, 'landed_items' => $landedItems, 'local_items' => $localItems, 'total' => $totalCost],
            'units_bought' => $unitsBought,
            'units_sold' => $sales['units'],
            'units_in_stock' => max(0, $unitsBought - $sales['units']),
            'revenue' => $sales['revenue'],
            'net_profit' => round($sales['revenue'] - $totalCost, 2),
            'shared_products' => count($shared),
            'has_purchases' => $purchaseIds !== [],
        ];
    }

    /**
     * Products of this project that another project with overlapping dates
     * also bought.
     *
     * @param  list<int>  $productIds
     * @return list<int>
     */
    private function sharedProducts(InvestmentProject $project, array $productIds, ?CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($productIds === []) {
            return [];
        }

        $otherProjects = InvestmentProject::query()
            ->whereKeyNot($project->getKey())
            ->where(fn ($query) => $query->whereNull('start_date')->orWhere('start_date', '<=', $end->toDateString()))
            ->when($start, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('end_date')->orWhere('end_date', '>=', $start->toDateString())))
            ->get();

        $otherPurchaseIds = $otherProjects->flatMap(fn (InvestmentProject $other): array => $other->allPurchaseIds())->unique()->values()->all();

        if ($otherPurchaseIds === []) {
            return [];
        }

        return DB::table('purchase_items')
            ->whereIn('purchase_id', $otherPurchaseIds)
            ->whereIn('product_id', $productIds)
            ->distinct()
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
}
