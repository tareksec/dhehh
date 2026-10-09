<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;

/**
 * Checks an order's lines against available stock before the order is
 * saved, so the Create order page can warn about every short product at
 * once (and offer to add stock) instead of failing on the first stock
 * movement StockMovementService::validate() rejects.
 */
class OrderStockCheckService
{
    public function __construct(protected StockMovementService $stockMovements) {}

    /**
     * Lines for the same product (and variant) are added together, matching
     * how OrderWorkflowService::syncStockMovements() books one sale movement
     * per product + variant.
     *
     * For an order that is already saved, the stock its sale movements have
     * booked is already out of the available count, so only the quantity
     * added on top of that ("ordered" in the result) needs to be in stock.
     *
     * @param  array<array-key, mixed>  $items  repeater rows (product_id, product_variant_id, quantity)
     * @return list<array{key: string, product_id: int, product_variant_id: ?int, name: string, ordered: int, available: int, short: int}>
     */
    public function shortages(array $items, ?Order $order = null): array
    {
        $lines = [];

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $variantId = filled($item['product_variant_id'] ?? null) ? (int) $item['product_variant_id'] : null;
            $key = 'p'.$productId.'v'.($variantId ?? 0);

            $lines[$key] ??= ['product_id' => $productId, 'product_variant_id' => $variantId, 'ordered' => 0];
            $lines[$key]['ordered'] += $quantity;
        }

        $booked = $order?->exists ? $this->bookedQuantities($order) : [];
        $shortages = [];

        foreach ($lines as $key => $line) {
            $line['ordered'] -= $booked[$key] ?? 0;

            if ($line['ordered'] <= 0) {
                continue;
            }

            $product = Product::query()->find($line['product_id']);

            if (! $product) {
                continue;
            }

            $variant = $line['product_variant_id']
                ? ProductVariant::query()->where('product_id', $product->getKey())->find($line['product_variant_id'])
                : null;

            // A variable product's line without a variant is caught by the
            // form's own required rule.
            if ($product->has_variants && ! $variant) {
                continue;
            }

            $available = $variant
                ? (int) $variant->stock
                : $this->stockMovements->projectedStockFor((int) $product->getKey(), 'sale', 0);

            if ($available >= $line['ordered']) {
                continue;
            }

            // Stock can already be below zero (a WooCommerce sale is never
            // blocked), so "short" covers that too — adding only the ordered
            // quantity would still leave the sale short.

            $shortages[] = [
                'key' => $key,
                'product_id' => (int) $product->getKey(),
                'product_variant_id' => $variant?->getKey(),
                'name' => $variant ? $product->name.' ('.$variant->label().')' : $product->name,
                'ordered' => $line['ordered'],
                'available' => $available,
                'short' => $line['ordered'] - $available,
            ];
        }

        return $shortages;
    }

    /**
     * @return array<string, int> sale quantity this order has booked, keyed like the shortage lines
     */
    protected function bookedQuantities(Order $order): array
    {
        return StockMovement::query()
            ->where('type', 'sale')
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->getKey())
            ->get(['product_id', 'product_variant_id', 'quantity'])
            ->mapWithKeys(fn (StockMovement $movement): array => [
                'p'.$movement->product_id.'v'.((int) $movement->product_variant_id) => abs((int) $movement->quantity),
            ])
            ->all();
    }

    /**
     * Books the added quantity as a stock adjustment, with the reason the
     * Stock Movement page requires for every adjustment.
     */
    public function addStock(int $productId, ?int $variantId, int $quantity, string $orderNumber, bool $editing = false): StockMovement
    {
        return StockMovement::query()->create([
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'type' => 'adjustment',
            'quantity' => $quantity,
            'reason' => 'Stock added from the order form',
            'note' => ($editing ? 'Added while editing invoice ' : 'Added while creating invoice ').$orderNumber,
        ]);
    }
}
