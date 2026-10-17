<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * Turns the headless website's cart (product slug, optional variation id,
 * quantity — never a price) into priced lines of exactly the shape
 * StorefrontCart::items() returns, using the same line rules as the Blade
 * storefront: a product must be active and available, a variation must be an
 * active one of that product, quantity is floored at the MOQ then capped at
 * stock (pre-order products are capped at a high ceiling), and a line with no
 * stock is dropped. The price comes from StorefrontCatalogService::unitPrice().
 *
 * Unlike the session cart, nothing is silently changed: every adjustment or
 * dropped line is reported in `issues`, and the caller decides whether it is
 * safe to go on (placing an order is refused until the customer has seen it).
 */
class StorefrontApiCartResolver
{
    public const MAX_LINES = 100;

    public function __construct(protected StorefrontCatalogService $catalog) {}

    /**
     * @param  list<array{slug: string, quantity: int, variant_id?: int|null}>  $requested
     * @return array{lines: Collection<int, array<string, mixed>>, issues: list<array<string, mixed>>}
     */
    public function resolve(array $requested): array
    {
        $wanted = $this->merge($requested);
        $products = Product::query()
            ->with(['category', 'activeVariants'])
            ->whereIn('slug', collect($wanted)->pluck('slug')->unique()->all())
            ->where('is_active', true)
            ->where('status', Product::STATUS_AVAILABLE)
            ->get()
            ->keyBy('slug');

        $lines = collect();
        $issues = [];

        foreach ($wanted as $line) {
            $product = $products->get($line['slug']);
            $variantId = $line['variant_id'];
            $variant = null;

            if (! $product) {
                $issues[] = $this->issue($line, 'unavailable');

                continue;
            }

            if ($product->has_variants) {
                $variant = $variantId ? $product->activeVariants->firstWhere('id', $variantId) : null;

                if (! $variant) {
                    $issues[] = $this->issue($line, $variantId ? 'unavailable' : 'variant_required');

                    continue;
                }
            } elseif ($variantId) {
                $issues[] = $this->issue($line, 'unavailable');

                continue;
            }

            $maxStock = $this->availableStock($product, $variant);

            if ($maxStock < 1) {
                $issues[] = $this->issue($line, 'out_of_stock');

                continue;
            }

            // MOQ floor first, then the stock cap wins if stock is below the MOQ.
            $quantity = min(max($line['quantity'], $product->effectiveMoq()), $maxStock);

            if ($quantity !== $line['quantity']) {
                $issues[] = $this->issue($line, 'quantity_adjusted') + ['quantity' => $quantity];
            }

            $unitPrice = $this->catalog->unitPrice($product, $variant, $quantity);

            $lines->push([
                'product' => $product,
                'variant' => $variant,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => $unitPrice * $quantity,
                'reseller_base_price' => null,
            ]);
        }

        return ['lines' => $lines, 'issues' => $issues];
    }

    protected function availableStock(Product $product, ?ProductVariant $variant): int
    {
        if ($variant) {
            return (int) $variant->stock;
        }

        // Pre-order products can be ordered beyond current stock; the
        // checkout collects an online advance for those lines.
        if ($product->is_preorder) {
            return max((int) $product->stock, StorefrontCart::PREORDER_STOCK_CEILING);
        }

        return (int) $product->stock;
    }

    /**
     * Collapse repeated slug+variation lines into one and sanitise the input.
     *
     * @return list<array{slug: string, quantity: int, variant_id: int|null}>
     */
    protected function merge(array $requested): array
    {
        $merged = [];

        foreach (array_slice($requested, 0, self::MAX_LINES) as $line) {
            $slug = trim((string) ($line['slug'] ?? ''));
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($slug === '' || $quantity < 1) {
                continue;
            }

            $variantId = ! empty($line['variant_id']) ? (int) $line['variant_id'] : null;
            $key = $slug.':'.($variantId ?? 0);
            $merged[$key] = [
                'slug' => $slug,
                'variant_id' => $variantId,
                'quantity' => ($merged[$key]['quantity'] ?? 0) + $quantity,
            ];
        }

        return array_values($merged);
    }

    /** @return array{slug: string, variant_id: int|null, code: string, requested: int} */
    protected function issue(array $line, string $code): array
    {
        return ['slug' => $line['slug'], 'variant_id' => $line['variant_id'], 'code' => $code, 'requested' => $line['quantity']];
    }
}
