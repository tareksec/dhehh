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
 * them (owner request 2026-10-18), one account page per product (owner
 * request 2026-11-01).
 *
 * Rules the figures follow:
 *  - Only `received` purchases count; the date range filters the purchase date.
 *  - Landed cost = item subtotal + the purchase's China-to-BD costs allocated
 *    to the item (the same figure PurchaseWorkflowService stores as
 *    allocated_cost / landed_unit_cost). Purchase discount and VAT are not in it.
 *  - Sales are not tied to a purchase, so a product's stock leaves its received
 *    units first-in-first-out: opening stock first, then purchases by date.
 *    What leaves is, oldest event first: accounted sales (with their price),
 *    "damage" stock entries (customer-return, shipment, warehouse damage) and
 *    stock adjustments that took pieces out. Positive stock adjustments are a
 *    last lot of their own. Events count up to today whatever the date range.
 *  - Every product's units close exactly: billed - short - damaged on arrival
 *    = received good = sold + damaged after arrival + taken out by adjustments
 *    + in stock. Events that no recorded lot could cover are listed apart
 *    ("unmatched"), never hidden inside a supplier's figures.
 *  - Gross profit = sales - landed cost of the units sold - landed cost of the
 *    short and damaged units (the supplier's loss) - landed cost of units
 *    damaged after arrival.
 *  - Net profit = gross profit - costs after arrival (the purchase's product
 *    cost lines, plus Meta ad spend linked to the product). Those costs are
 *    counted in proportion to the units sold, the same way the cost of goods is.
 */
class SupplierReportService
{
    public const DAMAGE_UNSPECIFIED = 'unspecified';

    /** @var list<string> */
    private const SUM_FIELDS = [
        'ordered', 'short', 'damaged', 'sellable', 'sold', 'damaged_after', 'adjusted_out', 'in_stock',
        'purchase_value', 'import_cost', 'landed_cost',
        'revenue', 'cogs', 'loss', 'loss_after', 'gross_profit',
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
     * Where damaged pieces came from, as the report labels them.
     *
     * @return array<string, string>
     */
    public static function damageLabels(): array
    {
        return StockMovement::damageSources() + [self::DAMAGE_UNSPECIFIED => __('Source not recorded')];
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
            'product_pages' => $this->productPages($batches, $layers),
            'batches' => $batches->values()->all(),
            'cost_labels' => $batches->flatMap(fn (array $row) => array_keys($row['cost_lines']))->unique()->values()->all(),
            'damage_labels' => self::damageLabels(),
            'generated_at' => now(),
        ];
    }

    /**
     * Every received unit of the given products, oldest first, with the
     * sales, damage and adjustments handed to it first-in-first-out.
     *
     * @param  list<int>  $productIds
     * @return array<int, array<string, mixed>>
     */
    private function fifoLayers(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $stock = Product::query()->whereIn('id', $productIds)->pluck('stock', 'id');
        $byProduct = [];

        foreach ($productIds as $productId) {
            $byProduct[$productId] = [
                'layers' => [],
                'sold_total' => 0,
                'stock' => (int) ($stock[$productId] ?? 0),
                'unmatched' => ['sold' => 0, 'revenue' => 0.0, 'damaged' => 0, 'adjusted_out' => 0],
            ];
        }

        StockMovement::query()
            ->whereIn('product_id', $productIds)
            ->where('type', 'opening')
            ->selectRaw('product_id, sum(quantity) as quantity')
            ->groupBy('product_id')
            ->pluck('quantity', 'product_id')
            ->each(function ($quantity, $productId) use (&$byProduct): void {
                if ((int) $quantity > 0) {
                    $byProduct[(int) $productId]['layers'][] = $this->layer('opening', (int) $quantity);
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
                    $byProduct[(int) $item->product_id]['layers'][] = $this->layer(
                        'purchase',
                        $item->sellableQuantity(),
                        $item->getKey(),
                        (int) $purchase->supplier_id,
                    );
                }
            });

        $movements = StockMovement::query()
            ->whereIn('product_id', $productIds)
            ->whereIn('type', ['damage', 'adjustment'])
            ->get();

        foreach ($movements->where('type', 'adjustment')->where('quantity', '>', 0)->groupBy('product_id') as $productId => $added) {
            $byProduct[(int) $productId]['layers'][] = $this->layer('adjustment', (int) $added->sum('quantity'));
        }

        $events = collect();

        OrderItem::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('order', fn ($order) => $order->whereIn('status', Order::ACCOUNTED_STATUSES))
            ->with('order')
            ->get()
            ->each(function (OrderItem $sale) use ($events): void {
                $events->push([
                    'at' => $sale->order?->order_date?->timestamp ?? 0,
                    'rank' => 0,
                    'id' => $sale->getKey(),
                    'type' => 'sale',
                    'product_id' => (int) $sale->product_id,
                    'quantity' => (int) $sale->quantity,
                    'price' => (float) $sale->unit_price,
                ]);
            });

        foreach ($movements as $movement) {
            $isDamage = $movement->type === 'damage';

            if (! $isDamage && (int) $movement->quantity >= 0) {
                continue;
            }

            $events->push([
                'at' => $movement->created_at?->timestamp ?? 0,
                'rank' => $isDamage ? 1 : 2,
                'id' => $movement->getKey(),
                'type' => $isDamage ? 'damage' : 'adjustment',
                'product_id' => (int) $movement->product_id,
                'quantity' => abs((int) $movement->quantity),
                'source' => $movement->damage_source ?: self::DAMAGE_UNSPECIFIED,
            ]);
        }

        foreach ($events->sort(fn (array $a, array $b): int => [$a['at'], $a['rank'], $a['id']] <=> [$b['at'], $b['rank'], $b['id']]) as $event) {
            $productId = $event['product_id'];
            $left = $event['quantity'];

            if ($event['type'] === 'sale') {
                $byProduct[$productId]['sold_total'] += $left;
            }

            foreach ($byProduct[$productId]['layers'] as $index => $layer) {
                if ($left <= 0) {
                    break;
                }

                $take = min($layer['qty'] - $layer['sold'] - $layer['damaged'] - $layer['adjusted_out'], $left);

                if ($take <= 0) {
                    continue;
                }

                $target = &$byProduct[$productId]['layers'][$index];

                if ($event['type'] === 'sale') {
                    $key = number_format($event['price'], 2, '.', '');
                    $target['sold'] += $take;
                    $target['revenue'] += $take * $event['price'];
                    $target['prices'][$key] = [
                        'price' => $event['price'],
                        'pieces' => ($target['prices'][$key]['pieces'] ?? 0) + $take,
                        'amount' => ($target['prices'][$key]['amount'] ?? 0.0) + $take * $event['price'],
                    ];
                } elseif ($event['type'] === 'damage') {
                    $target['damaged'] += $take;
                    $target['damage_sources'][$event['source']] = ($target['damage_sources'][$event['source']] ?? 0) + $take;
                } else {
                    $target['adjusted_out'] += $take;
                }

                unset($target);
                $left -= $take;
            }

            if ($left > 0) {
                $unmatched = &$byProduct[$productId]['unmatched'];

                match ($event['type']) {
                    'sale' => $this->addUnmatchedSale($unmatched, $left, $event['price']),
                    'damage' => $unmatched['damaged'] += $left,
                    default => $unmatched['adjusted_out'] += $left,
                };

                unset($unmatched);
            }
        }

        return $byProduct;
    }

    /**
     * @return array<string, mixed>
     */
    private function layer(string $kind, int $quantity, ?int $itemId = null, ?int $supplierId = null): array
    {
        return [
            'kind' => $kind,
            'item_id' => $itemId,
            'supplier_id' => $supplierId,
            'qty' => $quantity,
            'sold' => 0,
            'revenue' => 0.0,
            'prices' => [],
            'damaged' => 0,
            'damage_sources' => [],
            'adjusted_out' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $unmatched
     */
    private function addUnmatchedSale(array &$unmatched, int $quantity, float $price): void
    {
        $unmatched['sold'] += $quantity;
        $unmatched['revenue'] += $quantity * $price;
    }

    /**
     * One row per purchase item of the report.
     *
     * @param  Collection<int, Purchase>  $purchases
     * @param  array<int, array<string, mixed>>  $layers
     * @return Collection<int, array<string, mixed>>
     */
    private function batchRows(Collection $purchases, array $layers, Collection $adSpend): Collection
    {
        $rows = collect();

        foreach ($purchases as $purchase) {
            $itemsByProduct = $purchase->items->groupBy('product_id');

            foreach ($purchase->items as $item) {
                $productId = (int) $item->product_id;
                $sellable = $item->sellableQuantity();
                $layer = collect($layers[$productId]['layers'] ?? [])->firstWhere('item_id', $item->getKey())
                    ?? $this->layer('purchase', $sellable, $item->getKey());

                $quantity = max((int) $item->quantity, 1);
                $landedCost = (float) $item->subtotal + (float) $item->allocated_cost;
                $landedUnit = $landedCost / $quantity;
                $sold = (int) $layer['sold'];
                $damagedAfter = (int) $layer['damaged'];
                $adjustedOut = (int) $layer['adjusted_out'];
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
                $lossAfter = $damagedAfter * $landedUnit;
                $extraCost = array_sum($costLines);
                $grossProfit = $revenue - $cogs - $loss - $lossAfter;

                $rows->push([
                    'item_id' => $item->getKey(),
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
                    'damaged_after' => $damagedAfter,
                    'damage_sources' => $layer['damage_sources'],
                    'adjusted_out' => $adjustedOut,
                    'in_stock' => $sellable - $sold - $damagedAfter - $adjustedOut,
                    'unit_cost' => (float) $item->unit_cost,
                    'purchase_value' => (float) $item->subtotal,
                    'import_cost' => (float) $item->allocated_cost,
                    'landed_cost' => $landedCost,
                    'revenue' => $revenue,
                    'prices' => $layer['prices'],
                    'cogs' => $cogs,
                    'loss' => $loss,
                    'loss_after' => $lossAfter,
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
     * One account per product: this supplier's lots, how many pieces went
     * where and at what price, and how the product's stock stands today.
     *
     * @param  Collection<int, array<string, mixed>>  $batches
     * @param  array<int, array<string, mixed>>  $layers
     * @return list<array<string, mixed>>
     */
    private function productPages(Collection $batches, array $layers): array
    {
        return $batches
            ->groupBy('product_id')
            ->map(function (Collection $rows, $productId) use ($layers): array {
                $productId = (int) $productId;
                $first = $rows->first();
                $totals = $this->totals($rows);
                $info = $layers[$productId] ?? ['layers' => [], 'sold_total' => 0, 'stock' => 0, 'unmatched' => ['sold' => 0, 'revenue' => 0.0, 'damaged' => 0, 'adjusted_out' => 0]];
                $shownItems = $rows->pluck('item_id')->all();

                $prices = [];
                $damageSources = [];

                foreach ($rows as $row) {
                    foreach ($row['prices'] as $key => $price) {
                        $prices[$key] = [
                            'price' => $price['price'],
                            'pieces' => ($prices[$key]['pieces'] ?? 0) + $price['pieces'],
                            'amount' => ($prices[$key]['amount'] ?? 0.0) + $price['amount'],
                        ];
                    }

                    foreach ($row['damage_sources'] as $source => $pieces) {
                        $damageSources[$source] = ($damageSources[$source] ?? 0) + $pieces;
                    }
                }

                uasort($prices, fn (array $a, array $b): int => $b['price'] <=> $a['price']);

                $otherLayers = collect($info['layers'])->reject(fn (array $layer): bool => in_array($layer['item_id'], $shownItems, true));
                $otherSold = (int) $otherLayers->sum('sold');
                $otherInStock = (int) $otherLayers->sum(fn (array $layer): int => $layer['qty'] - $layer['sold'] - $layer['damaged'] - $layer['adjusted_out']);
                $reportedStock = $totals['in_stock'] + $otherInStock;

                return [
                    'product_id' => $productId,
                    'product' => $first['product'],
                    'sku' => $first['sku'],
                    'batches' => $rows->values()->all(),
                    'totals' => $totals,
                    'units' => [
                        'billed' => $totals['ordered'],
                        'short' => $totals['short'],
                        'damaged' => $totals['damaged'],
                        'received' => $totals['sellable'],
                        'sold' => $totals['sold'],
                        'damaged_after' => $totals['damaged_after'],
                        'damage_sources' => $damageSources,
                        'adjusted_out' => $totals['adjusted_out'],
                        'in_stock' => $totals['in_stock'],
                        'difference' => $totals['ordered'] - $totals['short'] - $totals['damaged'] - $totals['sold']
                            - $totals['damaged_after'] - $totals['adjusted_out'] - $totals['in_stock'],
                    ],
                    'prices' => array_values($prices),
                    'sales_split' => [
                        'all' => (int) $info['sold_total'],
                        'this_supplier' => $totals['sold'],
                        'other_lots' => $otherSold,
                        'unmatched' => (int) $info['unmatched']['sold'],
                    ],
                    'unmatched' => $info['unmatched'],
                    'stock_check' => [
                        'this_supplier' => $totals['in_stock'],
                        'other_lots' => $otherInStock,
                        'reported' => $reportedStock,
                        'system' => (int) $info['stock'],
                        'difference' => (int) $info['stock'] - $reportedStock,
                    ],
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
