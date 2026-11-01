<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseReceiptItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierAccountAdjustment;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use App\Models\SupplierPayment;
use App\Reports\SupplierStatement;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Works out one supplier's account from the records the ERP already keeps:
 * goods receipts (received, short, buy price), supplier orders (goods he took
 * and the advance he collected), our own orders (sold, in the courier
 * pipeline), stock movements (damage, stock) and the money we gave
 * (supplier payments into the account, shipping and ad cost expenses).
 */
class SupplierAccountService
{
    public function statement(Supplier $supplier, ?CarbonInterface $asOf = null): SupplierStatement
    {
        $asOf = ($asOf ?? now())->copy()->endOfDay();
        $date = $asOf->toDateString();
        $companyId = (int) $supplier->company_id;

        $received = $this->received($supplier, $companyId, $date);
        $taken = $this->taken($supplier, $companyId, $date);
        $advance = $this->advanceByProduct($supplier, $date);

        $productIds = $received->keys()
            ->merge($taken->keys())
            ->merge(Product::query()->where('supplier_id', $supplier->getKey())->pluck('id'))
            ->unique()
            ->values();

        $sold = $this->ownSales($companyId, $productIds, $date, [Order::STATUS_COMPLETED]);
        $outside = $this->ownSales($companyId, $productIds, $date, [Order::STATUS_CONFIRMED, Order::STATUS_PROCESSING]);
        $damage = $this->damage($supplier, $companyId, $productIds, $asOf);
        $stock = $this->stockOnHand($companyId, $productIds, $asOf);
        $names = Product::query()->withoutGlobalScopes()->whereIn('id', $productIds)->pluck('name', 'id');

        $rows = $productIds
            ->map(function (int $id) use ($received, $taken, $advance, $sold, $outside, $damage, $stock, $names): array {
                $row = [
                    'product_id' => $id,
                    'product' => (string) ($names[$id] ?? "#{$id}"),
                    'received' => (int) ($received[$id]->qty ?? 0),
                    'sold' => (int) ($sold[$id]->qty ?? 0),
                    'sales_amount' => round((float) ($sold[$id]->amount ?? 0), 2),
                    'damage' => (int) ($damage[$id] ?? 0),
                    'short' => (int) ($received[$id]->short ?? 0),
                    'stock' => (int) ($stock[$id] ?? 0),
                    'outside' => (int) ($outside[$id]->qty ?? 0),
                    'taken' => (int) ($taken[$id]->qty ?? 0),
                    'value_received' => round((float) ($received[$id]->value ?? 0), 2),
                    'goods_taken' => round((float) ($taken[$id]->value ?? 0), 2),
                    'advance' => round((float) ($advance[$id] ?? 0), 2),
                    'missing_price' => (int) ($received[$id]->missing_price ?? 0) > 0,
                ];

                $row['avg_rate'] = $row['sold'] > 0 ? round($row['sales_amount'] / $row['sold'], 2) : 0.0;
                // Books agree when every piece received is sold, in the pipeline, taken, lost, broken or still here.
                $row['difference'] = $row['received'] - $row['short'] - $row['damage'] - $row['sold'] - $row['outside'] - $row['taken'] - $row['stock'];

                return $row;
            })
            ->sortBy('product', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $totals = [];

        foreach (['received', 'sold', 'sales_amount', 'damage', 'short', 'stock', 'outside', 'taken', 'value_received', 'goods_taken', 'advance', 'difference'] as $key) {
            $totals[$key] = collect($rows)->sum($key);
        }

        $totals['avg_rate'] = $totals['sold'] > 0 ? round($totals['sales_amount'] / $totals['sold'], 2) : 0.0;

        return new SupplierStatement(
            supplier: $supplier,
            asOf: $asOf,
            products: $rows,
            productTotals: $totals,
            valueReceived: round((float) $totals['value_received'], 2),
            goodsTaken: round((float) $totals['goods_taken'], 2),
            // The advance of an order counts whole, even when it has no priced line to spread it over.
            customerAdvance: $this->advanceTotal($supplier, $date),
            cash: round((float) SupplierPayment::query()
                ->where('supplier_id', $supplier->getKey())
                ->where('for_supplier_account', true)
                ->whereDate('payment_date', '<=', $date)
                ->sum('amount') + $this->adjustmentTotal($supplier, SupplierAccountAdjustment::TYPE_CASH, $date), 2),
            shipping: round($this->expenseTotal($supplier, Expense::SUPPLIER_COST_SHIPPING, $date)
                + $this->adjustmentTotal($supplier, SupplierAccountAdjustment::TYPE_SHIPPING, $date), 2),
            adCost: round($this->expenseTotal($supplier, Expense::SUPPLIER_COST_AD, $date)
                + $this->adjustmentTotal($supplier, SupplierAccountAdjustment::TYPE_AD_COST, $date), 2),
            productsWithoutBuyPrice: collect($rows)->where('missing_price', true)->count(),
            productsWithDifference: collect($rows)->where('difference', '!=', 0)->count(),
        );
    }

    /**
     * Everything behind one product's row in the statement: the receipts, the
     * sales by price, the stock, the damage, the goods in the courier pipeline,
     * what the supplier took and the advance he collected.
     *
     * @return array<string, mixed>
     */
    public function productDetail(Supplier $supplier, Product $product, ?CarbonInterface $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy()->endOfDay();
        $date = $asOf->toDateString();
        $companyId = (int) $supplier->company_id;

        $statement = $this->statement($supplier, $asOf);
        $row = collect($statement->products)->firstWhere('product_id', $product->getKey());

        $receipts = PurchaseReceiptItem::query()
            ->with(['receipt', 'variant'])
            ->where('product_id', $product->getKey())
            ->whereHas('receipt', fn ($query) => $query->where('supplier_id', $supplier->getKey())->whereDate('received_on', '<=', $date))
            ->get()
            ->sortBy(fn (PurchaseReceiptItem $item): string => $item->receipt->received_on->toDateString().'|'.str_pad((string) $item->getKey(), 12, '0', STR_PAD_LEFT))
            ->values();

        // Sold pieces by the price they actually went for (after the line discount).
        $salesByPrice = DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.company_id', $companyId)
            ->whereNull('o.deleted_at')
            ->where('o.status', Order::STATUS_COMPLETED)
            ->whereDate('o.order_date', '<=', $date)
            ->where('i.product_id', $product->getKey())
            ->groupBy(DB::raw('ROUND((i.subtotal - i.discount_amount) / i.quantity, 2)'))
            ->orderBy(DB::raw('ROUND((i.subtotal - i.discount_amount) / i.quantity, 2)'))
            ->selectRaw('ROUND((i.subtotal - i.discount_amount) / i.quantity, 2) as price, SUM(i.quantity - i.returned_quantity) as qty')
            ->get()
            ->map(fn ($line): array => ['price' => (float) $line->price, 'quantity' => (int) $line->qty, 'amount' => round((float) $line->price * (int) $line->qty, 2)])
            ->filter(fn (array $line): bool => $line['quantity'] > 0)
            ->values()
            ->all();

        $damage = StockMovement::query()
            ->where('product_id', $product->getKey())
            ->where('type', 'damage')
            ->where('created_at', '<=', $asOf)
            ->orderBy('created_at')
            ->get(['created_at', 'quantity', 'reason', 'note']);

        $pipeline = DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.company_id', $companyId)
            ->whereNull('o.deleted_at')
            ->whereIn('o.status', [Order::STATUS_CONFIRMED, Order::STATUS_PROCESSING])
            ->whereDate('o.order_date', '<=', $date)
            ->where('i.product_id', $product->getKey())
            ->groupBy('o.status')
            ->selectRaw('o.status as status, SUM(i.quantity) as qty')
            ->pluck('qty', 'status')
            ->map(fn ($qty): int => (int) $qty)
            ->all();

        $taken = SupplierOrderItem::query()
            ->with(['order', 'variant'])
            ->where('product_id', $product->getKey())
            ->whereHas('order', fn ($query) => $query
                ->where('supplier_id', $supplier->getKey())
                ->whereIn('status', SupplierOrder::TAKEN_STATUSES)
                ->whereDate('order_date', '<=', $date))
            ->get()
            ->sortBy(fn (SupplierOrderItem $item): string => $item->order->order_date->toDateString())
            ->values();

        return [
            'statement' => $statement,
            'row' => $row,
            'receipts' => $receipts,
            'sales_by_price' => $salesByPrice,
            'damage' => $damage,
            'pipeline' => $pipeline,
            'taken' => $taken,
            'advance' => collect($this->advanceOrders($supplier, $date))
                ->filter(fn (SupplierOrder $order): bool => $order->items->contains('product_id', $product->getKey()))
                ->values(),
        ];
    }

    /**
     * Pieces sent, lost on the way and their value at the buy price, per product.
     *
     * @return Collection<int, object>
     */
    private function received(Supplier $supplier, int $companyId, string $date): Collection
    {
        return DB::table('purchase_receipt_items as i')
            ->join('purchase_receipts as r', 'r.id', '=', 'i.purchase_receipt_id')
            ->where('r.company_id', $companyId)
            ->where('r.supplier_id', $supplier->getKey())
            ->whereDate('r.received_on', '<=', $date)
            ->groupBy('i.product_id')
            ->selectRaw('i.product_id, SUM(i.quantity) as qty, SUM(i.short_quantity) as short, SUM(i.quantity * i.unit_cost) as value, SUM(CASE WHEN i.unit_cost <= 0 THEN 1 ELSE 0 END) as missing_price')
            ->get()
            ->keyBy('product_id');
    }

    /**
     * What the supplier took from our stock, per product, at his buy price.
     *
     * @return Collection<int, object>
     */
    private function taken(Supplier $supplier, int $companyId, string $date): Collection
    {
        return DB::table('supplier_order_items as i')
            ->join('supplier_orders as o', 'o.id', '=', 'i.supplier_order_id')
            ->where('o.company_id', $companyId)
            ->where('o.supplier_id', $supplier->getKey())
            ->whereIn('o.status', SupplierOrder::TAKEN_STATUSES)
            ->whereDate('o.order_date', '<=', $date)
            ->groupBy('i.product_id')
            ->selectRaw('i.product_id, SUM(i.quantity) as qty, SUM(i.quantity * i.supplier_unit_cost) as value')
            ->get()
            ->keyBy('product_id');
    }

    /**
     * The advance the supplier holds, spread over the lines of each order by
     * what the lines are worth to the customer (by pieces when nothing is priced).
     *
     * @return array<int, float>
     */
    private function advanceByProduct(Supplier $supplier, string $date): array
    {
        $byProduct = [];

        foreach ($this->advanceOrders($supplier, $date) as $order) {
            $items = $order->items;
            $weights = $items->map(fn ($item): float => $order->totalAmount() > 0 ? $item->amount() : (float) $item->quantity);
            $weightTotal = (float) $weights->sum();
            $left = round((float) $order->advance_amount, 2);

            foreach ($items->values() as $index => $item) {
                $share = $index === $items->count() - 1
                    ? $left
                    : round((float) $order->advance_amount * ($weightTotal > 0 ? $weights[$index] / $weightTotal : 0), 2);

                $byProduct[$item->product_id] = ($byProduct[$item->product_id] ?? 0) + $share;
                $left = round($left - $share, 2);
            }
        }

        return $byProduct;
    }

    private function advanceTotal(Supplier $supplier, string $date): float
    {
        return round((float) $this->advanceOrders($supplier, $date)->sum(fn (SupplierOrder $order): float => (float) $order->advance_amount), 2);
    }

    /**
     * @return Collection<int, SupplierOrder>
     */
    private function advanceOrders(Supplier $supplier, string $date): Collection
    {
        return SupplierOrder::query()
            ->with('items')
            ->where('supplier_id', $supplier->getKey())
            ->whereIn('status', SupplierOrder::TAKEN_STATUSES)
            ->where('advance_collected_by', SupplierOrder::COLLECTED_BY_SUPPLIER)
            ->where('advance_amount', '>', 0)
            ->whereDate('order_date', '<=', $date)
            ->get()
            ->filter(fn (SupplierOrder $order): bool => $order->items->isNotEmpty())
            ->values();
    }

    /**
     * Pieces and money of our own orders in the given statuses, per product.
     *
     * @param  Collection<int, int>  $productIds
     * @param  array<int, string>  $statuses
     * @return Collection<int, object>
     */
    private function ownSales(int $companyId, Collection $productIds, string $date, array $statuses): Collection
    {
        if ($productIds->isEmpty()) {
            return collect();
        }

        return DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.company_id', $companyId)
            ->whereNull('o.deleted_at')
            ->whereIn('o.status', $statuses)
            ->whereDate('o.order_date', '<=', $date)
            ->whereIn('i.product_id', $productIds)
            ->groupBy('i.product_id')
            // Pieces that came back (returned or damaged after delivery) were not sold; the line discount comes off the price.
            ->selectRaw('i.product_id, SUM(i.quantity - i.returned_quantity) as qty, SUM((i.subtotal - i.discount_amount) * (i.quantity - i.returned_quantity) / i.quantity) as amount')
            ->get()
            ->keyBy('product_id');
    }

    /**
     * On-hand stock as of the date, from the movement ledger (the same signs
     * StockMovementService uses): sales and damage subtract, adjustments and
     * transfers keep their own sign, everything else adds.
     *
     * @param  Collection<int, int>  $productIds
     * @return array<int, int>
     */
    private function stockOnHand(int $companyId, Collection $productIds, CarbonInterface $asOf): array
    {
        if ($productIds->isEmpty()) {
            return [];
        }

        return DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->whereIn('product_id', $productIds)
            ->where('created_at', '<=', $asOf)
            ->groupBy('product_id')
            ->selectRaw("product_id, COALESCE(SUM(CASE WHEN type IN ('sale', 'damage') THEN -ABS(quantity) WHEN type IN ('adjustment', 'transfer') THEN quantity ELSE ABS(quantity) END), 0) as stock")
            ->pluck('stock', 'product_id')
            ->map(fn ($stock): int => (int) $stock)
            ->all();
    }

    /**
     * Pieces that arrived broken: the damage written on the supplier's receipts
     * (whether or not the receipt moved stock) plus damage entered by hand or
     * from a return, per product.
     *
     * @param  Collection<int, int>  $productIds
     * @return array<int, int>
     */
    private function damage(Supplier $supplier, int $companyId, Collection $productIds, CarbonInterface $asOf): array
    {
        if ($productIds->isEmpty()) {
            return [];
        }

        $fromReceipts = DB::table('purchase_receipt_items as i')
            ->join('purchase_receipts as r', 'r.id', '=', 'i.purchase_receipt_id')
            ->where('r.company_id', $companyId)
            ->where('r.supplier_id', $supplier->getKey())
            ->whereDate('r.received_on', '<=', $asOf->toDateString())
            ->whereIn('i.product_id', $productIds)
            ->groupBy('i.product_id')
            ->selectRaw('i.product_id, SUM(i.damage_quantity) as qty')
            ->pluck('qty', 'product_id');

        // The damage a receipt booked is already in the receipt's own figure above.
        $elsewhere = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->whereIn('product_id', $productIds)
            ->where('type', 'damage')
            ->where(fn ($query) => $query->whereNull('reference_type')->orWhere('reference_type', '!=', PurchaseReceiptItem::class))
            ->where('created_at', '<=', $asOf)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(ABS(quantity)) as qty')
            ->pluck('qty', 'product_id');

        return $productIds
            ->mapWithKeys(fn (int $id): array => [$id => (int) ($fromReceipts[$id] ?? 0) + (int) ($elsewhere[$id] ?? 0)])
            ->all();
    }

    private function adjustmentTotal(Supplier $supplier, string $type, string $date): float
    {
        return (float) SupplierAccountAdjustment::query()
            ->where('supplier_id', $supplier->getKey())
            ->where('type', $type)
            ->whereDate('entry_date', '<=', $date)
            ->sum('amount');
    }

    private function expenseTotal(Supplier $supplier, string $type, string $date): float
    {
        return round((float) Expense::query()
            ->where('supplier_id', $supplier->getKey())
            ->where('supplier_cost_type', $type)
            ->whereDate('expense_date', '<=', $date)
            ->sum('amount'), 2);
    }
}
