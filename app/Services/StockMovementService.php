<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockPool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class StockMovementService
{
    /**
     * Signed on-hand stock for the movements matched by $query, summed in SQL.
     * Mirrors signedQuantityFor(): sales and damage subtract, adjustments and
     * transfers keep their own sign, everything else adds. Aggregating in the
     * database avoids loading a product's entire movement history into memory
     * on every recompute.
     */
    protected function signedStockSum(Builder $query): int
    {
        return (int) $query
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN type IN ('sale', 'damage') THEN -ABS(quantity) "
                ."WHEN type IN ('adjustment', 'transfer') THEN quantity ELSE ABS(quantity) END), 0) as signed_stock"
            )
            ->value('signed_stock');
    }

    /**
     * The other Product ids sharing this product's stock pool (see
     * StockPool), or null when the product isn't pooled. Always reads across
     * companies deliberately — a pool exists specifically to link products
     * that belong to different companies.
     */
    protected function poolMemberIds(int $productId): ?Collection
    {
        $poolId = Product::query()->withoutGlobalScopes()->whereKey($productId)->value('stock_pool_id');

        if (! $poolId) {
            return null;
        }

        return Product::query()->withoutGlobalScopes()->where('stock_pool_id', $poolId)->pluck('id');
    }

    /** The StockMovement query for a product's own ledger, or its whole pool's combined ledger when pooled. */
    protected function ledgerQueryFor(int $productId): Builder
    {
        $poolMemberIds = $this->poolMemberIds($productId);

        return $poolMemberIds
            ? StockMovement::query()->withoutGlobalScopes()->whereIn('product_id', $poolMemberIds)
            : StockMovement::query()->where('product_id', $productId);
    }

    public function prepareForSave(StockMovement $movement): void
    {
        $movement->quantity = $this->normalizeQuantity($movement->type, (int) $movement->quantity);

        // Only damage carries a source; a value left over from a changed type is dropped.
        if ($movement->type !== 'damage') {
            $movement->damage_source = null;
        } elseif (filled($movement->damage_source) && ! array_key_exists($movement->damage_source, StockMovement::damageSources())) {
            throw ValidationException::withMessages([
                'damage_source' => 'Please select where the damage came from.',
            ]);
        }

        $this->validate($movement);
    }

    public function assertCanDelete(StockMovement $movement): void
    {
        if (! $movement->exists) {
            return;
        }

        $stockAfterDelete = $this->signedStockSum(
            $this->ledgerQueryFor((int) $movement->product_id)->whereKeyNot($movement->getKey())
        );

        if ($stockAfterDelete < 0) {
            throw ValidationException::withMessages([
                'quantity' => 'This stock movement cannot be removed because product stock would become negative.',
            ]);
        }
    }

    public function syncProductStock(?int $productId): void
    {
        if (! $productId) {
            return;
        }

        // Variable products own their stock as the sum of active variant
        // stock (synced by ProductVariant hooks) — the movement ledger
        // must not overwrite it.
        $product = Product::query()->withoutGlobalScopes()->whereKey($productId)->first();

        if (! $product || $product->has_variants) {
            return;
        }

        $poolMemberIds = $this->poolMemberIds($productId);

        if ($poolMemberIds) {
            $stock = $this->signedStockSum(
                StockMovement::query()->withoutGlobalScopes()->whereIn('product_id', $poolMemberIds)
            );

            // Every member shows the same live pool-wide total, regardless
            // of which company's order actually moved it.
            Product::query()->withoutGlobalScopes()->whereIn('id', $poolMemberIds)->update(['stock' => $stock]);

            return;
        }

        $stock = $this->signedStockSum(StockMovement::query()->where('product_id', $productId));

        Product::query()->whereKey($productId)->update(['stock' => $stock]);
    }

    public function normalizeQuantity(?string $type, int $quantity): int
    {
        return match ($type) {
            'adjustment', 'transfer' => $quantity,
            'sale', 'damage', 'opening', 'purchase', 'return' => abs($quantity),
            default => $quantity,
        };
    }

    public function signedQuantityFor(?string $type, int $quantity): int
    {
        return match ($type) {
            'sale', 'damage' => -abs($quantity),
            'adjustment', 'transfer' => $quantity,
            default => abs($quantity),
        };
    }

    public function projectedStockFor(
        int $productId,
        string $type,
        int $quantity,
        ?int $excludingMovementId = null,
    ): int {
        $currentStock = $this->signedStockSum(
            $this->ledgerQueryFor($productId)
                ->when($excludingMovementId, fn ($query) => $query->whereKeyNot($excludingMovementId))
        );

        return $currentStock + $this->signedQuantityFor($type, $quantity);
    }

    /**
     * Mirrors a real movement (sale/purchase/return/damage/adjustment/
     * opening) recorded against a non-source pooled product as an
     * equal-and-opposite 'transfer' pair: the non-source product's own
     * ledger is offset back to a net-zero pass-through, and the pool's
     * source product records the real, signed effect instead — so the
     * pool's true total moves exactly once no matter which company's order
     * triggered it, while both companies' own stock history stays
     * coherent. `updateOrCreate` keyed on the triggering movement keeps the
     * mirror in sync if that movement's quantity is later edited (e.g. an
     * order's line quantity changes) instead of only firing once.
     *
     * A no-op for: 'transfer' movements themselves (they never trigger
     * another pair — that's what stops this from recursing), variant-scoped
     * movements (pooling only supports simple products), unpooled products,
     * and movements recorded directly against the pool's own source
     * product (nothing to mirror — it already holds the real stock).
     */
    public function maybeCreatePoolTransfer(StockMovement $movement): void
    {
        if ($movement->type === 'transfer' || $movement->product_variant_id) {
            return;
        }

        $product = Product::query()->withoutGlobalScopes()->find($movement->product_id);

        if (! $product || ! $product->stock_pool_id || $product->has_variants) {
            return;
        }

        $pool = StockPool::query()->find($product->stock_pool_id);

        if (! $pool || ! $pool->source_product_id || (int) $pool->source_product_id === (int) $product->getKey()) {
            return;
        }

        $sourceProduct = Product::query()->withoutGlobalScopes()->find($pool->source_product_id);

        if (! $sourceProduct) {
            return;
        }

        $signed = $this->signedQuantityFor($movement->type, (int) $movement->quantity);

        StockMovement::query()->withoutGlobalScopes()->updateOrCreate(
            [
                'product_id' => $product->getKey(),
                'type' => 'transfer',
                'reference_type' => StockMovement::class,
                'reference_id' => $movement->getKey(),
            ],
            [
                'company_id' => $product->company_id,
                'quantity' => -$signed,
                'reason' => 'Shared stock pass-through',
                'note' => "Auto-mirrors this product's own {$movement->type} movement #{$movement->getKey()}.",
            ],
        );

        StockMovement::query()->withoutGlobalScopes()->updateOrCreate(
            [
                'product_id' => $sourceProduct->getKey(),
                'type' => 'transfer',
                'reference_type' => StockMovement::class,
                'reference_id' => $movement->getKey(),
            ],
            [
                'company_id' => $sourceProduct->company_id,
                'quantity' => $signed,
                'reason' => 'Shared stock pass-through',
                'note' => "Auto-mirrors a {$movement->type} movement #{$movement->getKey()} recorded against \"{$product->name}\" ({$product->company?->name}).",
            ],
        );
    }

    public function projectedVariantStockFor(StockMovement $movement): int
    {
        $variant = ProductVariant::withoutGlobalScopes()->find($movement->product_variant_id);

        if (! $variant || (int) $variant->product_id !== (int) $movement->product_id) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'Please select a valid product variant.',
            ]);
        }

        $oldSigned = 0;

        if (
            $movement->exists
            && (int) $movement->getOriginal('product_variant_id') === (int) $movement->product_variant_id
        ) {
            $oldSigned = $this->signedQuantityFor(
                (string) $movement->getOriginal('type', $movement->type),
                (int) $movement->getOriginal('quantity', 0),
            );
        }

        return (int) $variant->stock
            + $this->signedQuantityFor($movement->type, (int) $movement->quantity)
            - $oldSigned;
    }

    /**
     * Whether saving this movement lowers stock compared to what it booked
     * before. Only then can it be blamed for negative stock: re-saving an
     * order's unchanged sale line (every item save re-syncs all of them)
     * or adding stock must still work when the product is already below
     * zero, e.g. after a WooCommerce sale went through without stock.
     */
    protected function takesStock(StockMovement $movement): bool
    {
        $before = 0;

        if (
            $movement->exists
            && (int) $movement->getOriginal('product_id') === (int) $movement->product_id
            && (int) $movement->getOriginal('product_variant_id') === (int) $movement->product_variant_id
        ) {
            $before = $this->signedQuantityFor(
                (string) $movement->getOriginal('type', $movement->type),
                (int) $movement->getOriginal('quantity', 0),
            );
        }

        return $this->signedQuantityFor($movement->type, (int) $movement->quantity) < $before;
    }

    public function validate(StockMovement $movement): void
    {
        if (! array_key_exists($movement->type, StockMovement::TYPES)) {
            throw ValidationException::withMessages([
                'type' => 'Please select a valid stock movement type.',
            ]);
        }

        if (in_array($movement->type, ['adjustment', 'transfer'], true)) {
            if ((int) $movement->quantity === 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Adjustment quantity must be a non-zero signed value.',
                ]);
            }
        } elseif ((int) $movement->quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity must be greater than zero.',
            ]);
        }

        // Adjustments and damage both need a paper trail explaining why
        // stock changed outside the normal sale/purchase/return flow.
        if (in_array($movement->type, ['adjustment', 'damage'], true) && blank($movement->reason)) {
            throw ValidationException::withMessages([
                'reason' => $movement->type === 'adjustment'
                    ? 'Please enter a reason for this stock adjustment.'
                    : 'Please enter a reason for this damage.',
            ]);
        }

        $projectedStock = $movement->product_variant_id
            ? $this->projectedVariantStockFor($movement)
            : $this->projectedStockFor(
                (int) $movement->product_id,
                $movement->type,
                (int) $movement->quantity,
                $movement->exists ? (int) $movement->getKey() : null,
            );

        if ($projectedStock < 0 && ! $movement->allowNegativeStock && $this->takesStock($movement)) {
            $message = match ($movement->type) {
                'sale' => 'Insufficient stock for this sale quantity.',
                'damage' => 'Insufficient stock to record this much damage.',
                'transfer' => 'Insufficient shared pool stock for this transfer.',
                default => 'This stock movement would make product stock negative.',
            };

            throw ValidationException::withMessages([
                'quantity' => $message,
            ]);
        }
    }
}
