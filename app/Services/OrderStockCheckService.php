<?php

namespace App\Services;

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
     * @param  array<array-key, mixed>  $items  repeater rows (product_id, product_variant_id, quantity)
     * @return list<array{key: string, product_id: int, product_variant_id: ?int, name: string, ordered: int, available: int, short: int}>
     */
    public function shortages(array $items): array
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

        $shortages = [];

        foreach ($lines as $key => $line) {
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

            $shortages[] = [
                'key' => $key,
                'product_id' => (int) $product->getKey(),
                'product_variant_id' => $variant?->getKey(),
                'name' => $variant ? $product->name.' ('.$variant->label().')' : $product->name,
                'ordered' => $line['ordered'],
                'available' => max($available, 0),
                'short' => $line['ordered'] - max($available, 0),
            ];
        }

        return $shortages;
    }

    /**
     * Books the added quantity as a stock adjustment, with the reason the
     * Stock Movement page requires for every adjustment.
     */
    public function addStock(int $productId, ?int $variantId, int $quantity, string $orderNumber): StockMovement
    {
        return StockMovement::query()->create([
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'type' => 'adjustment',
            'quantity' => $quantity,
            'reason' => 'Stock added from the order form',
            'note' => "Added while creating invoice {$orderNumber}",
        ]);
    }
}
