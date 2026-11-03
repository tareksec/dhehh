<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockPool;
use Illuminate\Validation\ValidationException;

/**
 * Who is in a stock pool (see StockPool), and what Trash does to it (plan T5a).
 *
 * Deleting a pool always took its products out of the pool: each one went back to its
 * own stock ledger. Moving a pool to Trash does the same and remembers who was in it
 * (`stock_pools.member_product_ids`), so a Restore can put them back.
 */
class StockPoolMembership
{
    /**
     * Sets stock_pool_id on the source + selected member products, clears it from anything
     * previously in this pool that is no longer selected, and works out every touched
     * product's displayed stock again so removed members immediately revert to their own
     * independent ledger.
     *
     * @param  list<int>  $memberProductIds
     */
    public function sync(StockPool $pool, array $memberProductIds): void
    {
        $desiredIds = collect($memberProductIds)
            ->push($pool->source_product_id)
            ->filter()
            ->unique()
            ->values();

        $previousIds = Product::query()->withoutGlobalScopes()->where('stock_pool_id', $pool->getKey())->pluck('id');

        if ($desiredIds->isNotEmpty()) {
            Product::query()->withoutGlobalScopes()->whereIn('id', $desiredIds)->update(['stock_pool_id' => $pool->getKey()]);
        }

        $removedIds = $previousIds->diff($desiredIds);

        if ($removedIds->isNotEmpty()) {
            Product::query()->withoutGlobalScopes()->whereIn('id', $removedIds)->update(['stock_pool_id' => null]);
        }

        foreach ($desiredIds->merge($removedIds)->unique() as $id) {
            app(StockMovementService::class)->syncProductStock((int) $id);
        }
    }

    /** The pool is going to Trash: remember its products, then give each back its own ledger. */
    public function release(StockPool $pool): void
    {
        $ids = Product::query()->withoutGlobalScopes()->where('stock_pool_id', $pool->getKey())->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($pool->exists) {
            $pool->forceFill(['member_product_ids' => $ids])->saveQuietly();
        }

        if ($ids === []) {
            return;
        }

        Product::query()->withoutGlobalScopes()->whereIn('id', $ids)->update(['stock_pool_id' => null]);

        foreach ($ids as $id) {
            app(StockMovementService::class)->syncProductStock($id);
        }
    }

    /**
     * Before a Restore: the pool's real stock lives on its source product, so the source must
     * still exist and must not have joined another pool meanwhile.
     */
    public function assertCanRestore(StockPool $pool): void
    {
        $source = Product::query()->withoutGlobalScopes()->find($pool->source_product_id);

        if (! $source || $source->stock_pool_id !== null) {
            throw ValidationException::withMessages([
                'source_product_id' => __('This pool cannot be restored: its source product was deleted or is now in another stock pool.'),
            ]);
        }
    }

    /** After a Restore: link the remembered products again. One that joined another pool meanwhile stays where it is. */
    public function restoreMembers(StockPool $pool): void
    {
        $ids = collect($pool->member_product_ids ?? [])
            ->push($pool->source_product_id)
            ->filter()
            ->unique();

        $freeIds = Product::query()->withoutGlobalScopes()->whereIn('id', $ids)->whereNull('stock_pool_id')->pluck('id')->map(fn ($id): int => (int) $id);

        if ($freeIds->isNotEmpty()) {
            Product::query()->withoutGlobalScopes()->whereIn('id', $freeIds)->update(['stock_pool_id' => $pool->getKey()]);

            foreach ($freeIds as $id) {
                app(StockMovementService::class)->syncProductStock($id);
            }
        }

        $pool->forceFill(['member_product_ids' => null])->saveQuietly();
    }
}
