<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\StorefrontCart;

/**
 * What one storefront cart row shows and allows: stock, pre-order status,
 * quantity bounds, photo, line total and the next wholesale price step.
 * Every theme's cart.show reads these from here so a theme only changes the
 * design, never the ordering rules.
 */
final class StorefrontCartLine
{
    /**
     * @param  ?array{min_qty: int, price: float, add_qty: int}  $nextTier  The nearest wholesale step above this line's quantity that is cheaper than today's unit price.
     */
    private function __construct(
        public readonly Product $product,
        public readonly ?ProductVariant $variant,
        public readonly int $quantity,
        public readonly float $unitPrice,
        public readonly float $lineTotal,
        public readonly int $stock,
        public readonly bool $isPreorder,
        public readonly int $moq,
        public readonly int $minQuantity,
        public readonly int $maxQuantity,
        public readonly ?string $imageUrl,
        public readonly ?array $nextTier,
    ) {}

    /**
     * @param  array{product: Product, variant: ?ProductVariant, quantity: int, unit_price: float, subtotal: float}  $item  One row of StorefrontCart::items().
     */
    public static function make(array $item, Company $company): self
    {
        $product = $item['product'];
        $variant = $item['variant'] ?? null;
        $quantity = (int) $item['quantity'];
        $unitPrice = (float) $item['unit_price'];

        $stock = $variant ? (int) $variant->stock : (int) $product->stock;
        $isPreorder = ! $variant && (bool) $product->is_preorder;
        $maxQuantity = $isPreorder
            ? max($stock, StorefrontCart::PREORDER_STOCK_CEILING)
            : max(1, $stock);
        $moq = $product->effectiveMoq();

        $image = $variant && filled($variant->images) ? collect($variant->images)->first() : $product->image;

        return new self(
            product: $product,
            variant: $variant,
            quantity: $quantity,
            unitPrice: $unitPrice,
            lineTotal: (float) ($item['subtotal'] ?? $unitPrice * $quantity),
            stock: $stock,
            isPreorder: $isPreorder,
            moq: $moq,
            minQuantity: min($moq, $maxQuantity),
            maxQuantity: $maxQuantity,
            imageUrl: $image ? CompanyMedia::publicUrl($image, $company) : null,
            nextTier: $variant ? null : self::nextTier($product, $quantity, $unitPrice, $maxQuantity),
        );
    }

    /** Tier prices apply to the base product only; variant lines keep their own price. */
    private static function nextTier(Product $product, int $quantity, float $unitPrice, int $maxQuantity): ?array
    {
        foreach ($product->normalizedTiers() as $tier) {
            if ($tier['min_qty'] > $quantity && $tier['min_qty'] <= $maxQuantity && $tier['price'] < $unitPrice) {
                return $tier + ['add_qty' => $tier['min_qty'] - $quantity];
            }
        }

        return null;
    }
}
