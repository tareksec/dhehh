<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockPool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What moving a product to Trash does (owner request 2026-11-01, plan T5b; owner answers 2026-11-03).
 *
 * - The product leaves every list, the storefront, the API and the stock totals (the soft-delete scope).
 * - The orders and purchases that contain it go to Trash with it (an order is trashed whole, so its
 *   other products lose that sale too) and Restore brings back exactly those, remembered in
 *   `products.trash_cascade`.
 * - A product that was ordered more than twice, or whose orders have money received or are shipped,
 *   delivered or completed, is not moved: a warning says why. The reason "Test product" lifts those two
 *   limits, because test products and their test orders are meant to be thrown away.
 * - A returned or refunded order never goes to Trash (see Order::canBeMovedToTrash()), so a product in
 *   one stays put whatever the reason.
 * - A stock pool's source product stays put: delete or change the pool first. A pool member leaves the
 *   pool, like a pool in Trash does, and rejoins on Restore.
 */
class ProductTrashService
{
    public const REASON_TEST = 'test';

    public const REASONS = [
        self::REASON_TEST => 'Test product',
        'duplicate' => 'Duplicate or entered by mistake',
        'discontinued' => 'No longer sold',
        'other' => 'Other',
    ];

    /** More orders than this keep a product out of Trash (unless it is a test product). */
    public const MAX_ORDERS = 2;

    /** @return Builder<Order> */
    public function ordersOf(Product $product): Builder
    {
        return Order::query()
            ->withoutGlobalScopes()
            ->withoutTrashed()
            ->where('company_id', $product->company_id)
            ->whereHas('items', fn (Builder $items): Builder => $items->where('product_id', $product->getKey()));
    }

    /** @return Builder<Purchase> */
    public function purchasesOf(Product $product): Builder
    {
        return Purchase::query()
            ->withoutGlobalScopes()
            ->withoutTrashed()
            ->where('company_id', $product->company_id)
            ->whereHas('items', fn (Builder $items): Builder => $items->where('product_id', $product->getKey()));
    }

    /**
     * Why this product cannot go to Trash now (empty = it can).
     *
     * @return list<string>
     */
    public function blockers(Product $product, ?string $reason = null): array
    {
        $reason ??= $product->trash_reason;
        $isTest = $reason === self::REASON_TEST;
        $blockers = [];

        if ($product->isPoolSource()) {
            $blockers[] = __('This product is the source of a shared stock pool. Delete the pool, or choose another source, first.');
        }

        $orders = $this->ordersOf($product)->get(['id', 'order_number', 'status', 'paid_amount', 'delivery_status']);

        $returned = $orders->filter(fn (Order $order): bool => ! $order->canBeMovedToTrash());

        if ($returned->isNotEmpty()) {
            $blockers[] = __('It is in a returned or refunded order (:orders), which can never be moved to trash.', ['orders' => $returned->pluck('order_number')->implode(', ')]);
        }

        if (! $isTest) {
            if ($orders->count() > self::MAX_ORDERS) {
                $blockers[] = __('It is in :count orders. A product ordered more than :max times cannot be moved to trash. Choose the reason "Test product" only for a product made for testing.', ['count' => $orders->count(), 'max' => self::MAX_ORDERS]);
            }

            $busy = $orders->filter(fn (Order $order): bool => $this->hasMoneyOrShipment($order));

            if ($busy->isNotEmpty()) {
                $blockers[] = __('Money was received, or the goods were shipped, delivered or completed, in these orders: :orders.', ['orders' => $busy->pluck('order_number')->implode(', ')]);
            }
        }

        if (($orders->isNotEmpty() || $this->purchasesOf($product)->exists()) && blank($reason)) {
            $blockers[] = __('Choose a reason: this product is in orders or purchases that go to trash with it.');
        }

        return $blockers;
    }

    /** Called from the product's `deleting` event: check, then take the orders, purchases and pool membership with it. */
    public function moveToTrash(Product $product): void
    {
        $blockers = $this->blockers($product);

        if ($blockers !== []) {
            throw ValidationException::withMessages(['trash_reason' => implode(' ', $blockers)]);
        }

        $cascade = ['orders' => [], 'purchases' => [], 'stock_pool_id' => null];

        DB::transaction(function () use ($product, &$cascade): void {
            foreach ($this->ordersOf($product)->get() as $order) {
                $order->delete();
                $cascade['orders'][] = $order->getKey();
            }

            foreach ($this->purchasesOf($product)->get() as $purchase) {
                $purchase->delete();
                $cascade['purchases'][] = $purchase->getKey();
            }

            if ($product->stock_pool_id !== null) {
                $cascade['stock_pool_id'] = (int) $product->stock_pool_id;
                $product->stock_pool_id = null;
            }
        });

        $product->trash_cascade = $cascade;

        // The soft delete itself saves only deleted_at; the rest is written with it.
        $product->forceFill([
            'trash_cascade' => $cascade,
            'stock_pool_id' => $product->stock_pool_id,
        ])->saveQuietly();

        if ($cascade['stock_pool_id'] !== null) {
            app(StockMovementService::class)->syncProductStock((int) $product->getKey());
        }
    }

    /**
     * Move one product to Trash for a screen: the reason and note are saved with it.
     * Returns why it was refused (a warning to show), or null when it is in Trash.
     */
    public function tryMoveToTrash(Product $product, ?string $reason, ?string $note = null): ?string
    {
        $product->trash_reason = $reason;
        $product->trash_note = filled($note) ? mb_substr(trim((string) $note), 0, 500) : null;

        try {
            $product->delete();
        } catch (ValidationException $exception) {
            $product->trash_reason = null;
            $product->trash_note = null;

            return implode(' ', collect($exception->errors())->flatten()->all());
        }

        return null;
    }

    /** Called from the product's `restored` event: bring back what went to Trash with it. */
    public function restoreFromTrash(Product $product): void
    {
        $cascade = $product->trash_cascade ?? [];

        DB::transaction(function () use ($product, $cascade): void {
            foreach ((array) ($cascade['purchases'] ?? []) as $id) {
                Purchase::query()->withoutGlobalScopes()->onlyTrashed()->whereKey($id)->first()?->restore();
            }

            foreach ((array) ($cascade['orders'] ?? []) as $id) {
                Order::query()->withoutGlobalScopes()->onlyTrashed()->whereKey($id)->first()?->restore();
            }

            $poolId = $cascade['stock_pool_id'] ?? null;

            if ($poolId !== null && StockPool::query()->withoutGlobalScopes()->withoutTrashed()->whereKey($poolId)->exists()) {
                Product::query()->withoutGlobalScopes()->whereKey($product->getKey())->update(['stock_pool_id' => $poolId]);
            }

            Product::query()->withoutGlobalScopes()->whereKey($product->getKey())->update([
                'trash_cascade' => null,
                'trash_reason' => null,
                'trash_note' => null,
            ]);
        });

        $product->refresh();
        app(StockMovementService::class)->syncProductStock((int) $product->getKey());
    }

    private function hasMoneyOrShipment(Order $order): bool
    {
        return (float) $order->paid_amount > 0
            || $order->payments()->exists()
            || $order->status === Order::STATUS_COMPLETED
            || in_array($order->delivery_status, Order::SHIPPING_DELIVERY_STATUSES, true)
            || $order->courierBookings()->withoutGlobalScopes()->exists();
    }
}
