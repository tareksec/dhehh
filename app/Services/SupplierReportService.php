<?php

namespace App\Services;

use App\Models\MetaAd;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Supplier-wise report: everything from buying a supplier's goods to selling
 * them (owner request 2026-10-18).
 *
 * Rules the figures follow:
 *  - Only `received` purchases count; the date range filters the purchase date.
 *  - Landed cost = item subtotal + the purchase's China-to-BD costs allocated
 *    to the item (the same figure PurchaseWorkflowService stores as
 *    allocated_cost / landed_unit_cost). Purchase discount and VAT are not in it.
 *  - Sales are not tied to a purchase, so a product's accounted sales are
 *    handed to its received units first-in-first-out: opening stock first,
 *    then purchases by date. Sales are counted up to today whatever the range.
 *  - Gross profit = sales - landed cost of the units sold - landed cost of the
 *    short and damaged units (the supplier's loss).
 *  - Net profit = gross profit - costs after arrival (the purchase's product
 *    cost lines, plus Meta ad spend linked to the product). Those costs are
 *    counted in proportion to the units sold, the same way the cost of goods is.
 */
class SupplierReportService
{
    /** @var list<string> */
    private const SUM_FIELDS = [
        'ordered', 'short', 'damaged', 'sellable', 'sold', 'in_stock',
        'purchase_value', 'import_cost', 'landed_cost',
        'revenue', 'cogs', 'loss', 'gross_profit',
        'extra_cost', 'ad_cost', 'net_profit',
    ];

    /**
     * @return array<int, string> product id => name, for the products this supplier delivered
     */
    public function productOptions(Supplier $supplier): array
    {
        $productIds = PurchaseItem::query()
            ->whereHas('purchase', fn ($query) => $query
                ->where('supplier_id', $supplier->getKey())
                ->where('status', 'received'))
            ->select('product_id');

        return Product::query()
            ->whereIn('id', $productIds)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(
        Supplier $supplier,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        ?int $productId = null,
    ): array {
        $purchases = Purchase::query()
            ->where('supplier_id', $supplier->getKey())
            ->where('status', 'received')
            ->when($from, fn ($query) => $query->whereDate('purchase_date', '>=', $from->toDateString()))
            ->when($to, fn ($query) => $query->whereDate('purchase_date', '<=', $to->toDateString()))
            ->when($productId, fn ($query) => $query->whereHas('items', fn ($items) => $items->where('product_id', $productId)))
            ->with(['items' => fn ($items) => $items
                ->when($productId, fn ($query) => $query->where('product_id', $productId))
                ->orderBy('id'), 'items.product'])
            ->orderBy('purchase_date')
            ->orderBy('id')
            ->get();

        $productIds = $purchases->flatMap(fn (Purchase $purchase) => $purchase->items->pluck('product_id'))
            ->unique()
            ->values()
            ->all();

        $layers = $this->fifoLayers($productIds);
        $adSpend = $productIds === []
            ? collect()
            : MetaAd::query()
                ->whereIn('product_id', $productIds)
                ->selectRaw('product_id, sum(spend) as spend')
                ->groupBy('product_id')
                ->pluck('spend', 'product_id');

        $batches = $this->batchRows($purchases, $layers, $adSpend);

        return [
            'supplier' => [
                'id' => $supplier->getKey(),
                'name' => $supplier->name,
                'company_name' => $supplier->company_name,
                'phone' => $supplier->phone,
                'email' => $supplier->email,
                'address' => $supplier->address,
                'payable' => (float) $supplier->current_balance,
            ],
            'period' => ['from' => $from, 'to' => $to],
            'product' => $productId
                ? ['id' => $productId, 'name' => Product::query()->whereKey($productId)->value('name')]
                : null,
            'summary' => $this->summary($batches, $purchases),
            'purchases' => $this->purchaseRows($batches),
            'products' => $this->productRows($batches),
            'batches' => $batches->values()->all(),
            'cost_labels' => $batches->flatMap(fn (array $row) => array_keys($row['cost_lines']))->unique()->values()->all(),
            'generated_at' => now(),
        ];
    }

    /**
     * Every received unit of the given products, oldest first, with the sales
     * handed to it first-in-first-out.
     *
     * @param  list<int>  $productIds
     * @return array<int, array{layers: list<array<string, mixed>>, sold_total: int}>
     */
    private function fifoLayers(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $byProduct = array_fill_keys($productIds, ['layers' => [], 'sold_total' => 0]);

        StockMovement::query()
            ->whereIn('product_id', $productIds)
            ->where('type', 'opening')
            ->selectRaw('product_id, sum(quantity) as quantity')
            ->groupBy('product_id')
            ->pluck('quantity', 'product_id')
            ->each(function ($quantity, $productId) use (&$byProduct): void {
                if ((int) $quantity > 0) {
                    $byProduct[(int) $productId]['layers'][] = ['item_id' => null, 'qty' => (int) $quantity, 'sold' => 0, 'revenue' => 0.0];
                }
            });

        Purchase::query()
            ->where('status', 'received')
            ->whereHas('items', fn ($items) => $items->whereIn('product_id', $productIds))
            ->with(['items' => fn ($items) => $items->whereIn('product_id', $productIds)->orderBy('id')])
            ->orderBy('purchase_date')
            ->orderBy('id')
            ->get()
            ->each(function (Purchase $purchase) use (&$byProduct): void {
                foreach ($purchase->items as $item) {
                    $byProduct[(int) $item->product_id]['layers'][] = [
                        'item_id' => $item->getKey(),
                        'qty' => $item->sellableQuantity(),
                        'sold' => 0,
                        'revenue' => 0.0,
                    ];
                }
            });

        $sales = OrderItem::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('order', fn ($order) => $order->whereIn('status', Order::ACCOUNTED_STATUSES))
            ->with('order')
            ->get()
            ->sort(fn (OrderItem $a, OrderItem $b): int => [$a->order?->order_date?->timestamp ?? 0, $a->getKey()]
                <=> [$b->order?->order_date?->timestamp ?? 0, $b->getKey()]);

        foreach ($sales as $sale) {
            $productId = (int) $sale->product_id;
            $remaining = (int) $sale->quantity;
            $price = (float) $sale->unit_price;
            $byProduct[$productId]['sold_total'] += $remaining;

            foreach ($byProduct[$productId]['layers'] as $index => $layer) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($layer['qty'] - $layer['sold'], $remaining);

                if ($take <= 0) {
                    continue;
                }

                $byProduct[$productId]['layers'][$index]['sold'] += $take;
                $byProduct[$productId]['layers'][$index]['revenue'] += $take * $price;
                $remaining -= $take;
            }
        }

        return $byProduct;
    }

    /**
     * One row per purchase item of the report.
     *
     * @param  Collection<int, Purchase>  $purchases
     * @param  array<int, array{layers: list<array<string, mixed>>, sold_total: int}>  $layers
     * @return Collection<int, array<string, mixed>>
     */
    private function batchRows(Collection $purchases, array $layers, Collection $adSpend): Collection
    {
        $rows = collect();

        foreach ($purchases as $purchase) {
            $itemsByProduct = $purchase->items->groupBy('product_id');

            foreach ($purchase->items as $item) {
                $productId = (int) $item->product_id;
                $layer = collect($layers[$productId]['layers'] ?? [])->firstWhere('item_id', $item->getKey())
                    ?? ['qty' => $item->sellableQuantity(), 'sold' => 0, 'revenue' => 0.0];

                $quantity = max((int) $item->quantity, 1);
                $landedCost = (float) $item->subtotal + (float) $item->allocated_cost;
                $landedUnit = $landedCost / $quantity;
                $sellable = $item->sellableQuantity();
                $sold = (int) $layer['sold'];
                $soldShare = $sellable > 0 ? $sold / $sellable : 0.0;

                $itemsQuantity = max((int) $itemsByProduct[$item->product_id]->sum('quantity'), 1);
                $costLines = [];

                foreach ($purchase->product_costs ?? [] as $line) {
                    if ((int) ($line['product_id'] ?? 0) !== $productId || blank($line['label'] ?? null)) {
                        continue;
                    }

                    $label = trim((string) $line['label']);
                    $applied = (float) ($line['amount'] ?? 0) * ($quantity / $itemsQuantity) * $soldShare;
                    $costLines[$label] = ($costLines[$label] ?? 0.0) + $applied;
                }

                $productSold = (int) ($layers[$productId]['sold_total'] ?? 0);
                $adCost = $productSold > 0
                    ? (float) ($adSpend[$productId] ?? 0) * $sold / $productSold
                    : 0.0;

                $revenue = (float) $layer['revenue'];
                $cogs = $sold * $landedUnit;
                $loss = ((int) $item->short_quantity + (int) $item->damaged_quantity) * $landedUnit;
                $extraCost = array_sum($costLines);
                $grossProfit = $revenue - $cogs - $loss;

                $rows->push([
                    'purchase_id' => $purchase->getKey(),
                    'purchase_number' => $purchase->purchase_number,
                    'purchase_date' => $purchase->purchase_date,
                    'product_id' => $productId,
                    'product' => $item->product?->name,
                    'sku' => $item->product?->sku,
                    'ordered' => (int) $item->quantity,
                    'short' => (int) $item->short_quantity,
                    'damaged' => (int) $item->damaged_quantity,
                    'sellable' => $sellable,
                    'sold' => $sold,
                    'in_stock' => $sellable - $sold,
                    'unit_cost' => (float) $item->unit_cost,
                    'purchase_value' => (float) $item->subtotal,
                    'import_cost' => (float) $item->allocated_cost,
                    'landed_cost' => $landedCost,
                    'revenue' => $revenue,
                    'cogs' => $cogs,
                    'loss' => $loss,
                    'gross_profit' => $grossProfit,
                    'cost_lines' => $costLines,
                    'extra_cost' => $extraCost,
                    'ad_cost' => $adCost,
                    'net_profit' => $grossProfit - $extraCost - $adCost,
                ]);
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $batches
     * @return list<array<string, mixed>>
     */
    private function purchaseRows(Collection $batches): array
    {
        return $batches
            ->groupBy('purchase_id')
            ->map(function (Collection $rows): array {
                $first = $rows->first();

                return [
                    'purchase_id' => $first['purchase_id'],
                    'purchase_number' => $first['purchase_number'],
                    'purchase_date' => $first['purchase_date'],
                    'products' => $rows->pluck('product')->filter()->unique()->values()->all(),
                    ...$this->totals($rows),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $batches
     * @return list<array<string, mixed>>
     */
    private function productRows(Collection $batches): array
    {
        return $batches
            ->groupBy('product_id')
            ->map(function (Collection $rows): array {
                $first = $rows->first();

                return [
                    'product_id' => $first['product_id'],
                    'product' => $first['product'],
                    'sku' => $first['sku'],
                    'purchases' => $rows->pluck('purchase_id')->unique()->count(),
                    ...$this->totals($rows),
                ];
            })
            ->sortBy('product')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $batches
     * @param  Collection<int, Purchase>  $purchases
     * @return array<string, mixed>
     */
    private function summary(Collection $batches, Collection $purchases): array
    {
        $enteredCosts = $purchases
            ->flatMap(fn (Purchase $purchase) => collect($purchase->product_costs ?? [])
                ->filter(fn (array $line): bool => $batches->contains('product_id', (int) ($line['product_id'] ?? 0))))
            ->sum(fn (array $line): float => (float) ($line['amount'] ?? 0));

        return [
            'purchase_count' => $purchases->count(),
            'product_count' => $batches->pluck('product_id')->unique()->count(),
            'first_date' => $purchases->first()?->purchase_date,
            'last_date' => $purchases->last()?->purchase_date,
            'extra_cost_entered' => (float) $enteredCosts,
            ...$this->totals($batches),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function totals(Collection $rows): array
    {
        $totals = [];

        foreach (self::SUM_FIELDS as $field) {
            $totals[$field] = $rows->sum($field);
        }

        $costLines = [];

        foreach ($rows as $row) {
            foreach ($row['cost_lines'] as $label => $amount) {
                $costLines[$label] = ($costLines[$label] ?? 0.0) + $amount;
            }
        }

        $totals['cost_lines'] = $costLines;
        $totals['avg_price'] = $totals['sold'] > 0 ? $totals['revenue'] / $totals['sold'] : null;
        $totals['landed_unit_cost'] = $totals['ordered'] > 0 ? $totals['landed_cost'] / $totals['ordered'] : null;

        return $totals;
    }
}
