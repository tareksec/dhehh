<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseReceiptItem;
use App\Models\StockMovement;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use Illuminate\Validation\ValidationException;

/**
 * A supplier order takes its pieces out of our stock (a 'sale' movement per
 * line) for as long as the goods are with the customer. A returned or
 * cancelled order gives them back.
 */
class SupplierOrderService
{
    public function prepare(SupplierOrderItem $item): void
    {
        $item->quantity = (int) $item->quantity;

        if ($item->quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => __('Pieces must be more than zero.'),
            ]);
        }

        $product = Product::query()->withoutGlobalScopes()->find($item->product_id);

        if (! $product) {
            throw ValidationException::withMessages([
                'product_id' => __('Please select a valid product.'),
            ]);
        }

        if ($product->has_variants && ! $item->product_variant_id) {
            throw ValidationException::withMessages([
                'product_variant_id' => __('Please select the variant of this product.'),
            ]);
        }

        if ($item->product_variant_id) {
            $variant = ProductVariant::query()->withoutGlobalScopes()->find($item->product_variant_id);

            if (! $variant || (int) $variant->product_id !== (int) $product->getKey()) {
                throw ValidationException::withMessages([
                    'product_variant_id' => __('Please select a valid product variant.'),
                ]);
            }
        }

        // The buy price is what comes off the supplier's account, so it is fixed when the line is entered.
        if (blank($item->supplier_unit_cost) || (float) $item->supplier_unit_cost <= 0) {
            $item->supplier_unit_cost = $this->defaultBuyPrice($product, (int) $item->order?->supplier_id);
        }
    }

    public function syncOrder(SupplierOrder $order): void
    {
        foreach ($order->items()->get() as $item) {
            $item->setRelation('order', $order);
            $this->syncItem($item);
        }
    }

    public function syncItem(SupplierOrderItem $item): void
    {
        $order = $item->order;
        $quantity = $order && $order->isTaken() && $order->books_stock ? (int) $item->quantity : 0;
        $existing = $this->movements($item)->first();

        if ($quantity <= 0) {
            $existing?->delete();

            return;
        }

        $moved = $existing
            && (
                (int) $existing->product_id !== (int) $item->product_id
                || (int) $existing->product_variant_id !== (int) $item->product_variant_id
            );

        if ($moved) {
            $existing->delete();
            $existing = null;
        }

        $movement = $existing ?? new StockMovement([
            'type' => 'sale',
            'reference_type' => SupplierOrderItem::class,
            'reference_id' => $item->getKey(),
        ]);

        $movement->fill([
            'company_id' => $item->company_id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'quantity' => $quantity,
            'note' => __('Supplier order :number', ['number' => $order->order_number]),
        ]);

        if ($order->order_date) {
            $movement->created_at = $order->order_date->copy()->setTime(12, 0);
        }

        $movement->save();
    }

    public function releaseItem(SupplierOrderItem $item): void
    {
        $this->movements($item)->get()->each->delete();
    }

    /** The supplier's latest buy price for this product; the product's cost price when nothing was received yet. */
    public function defaultBuyPrice(Product $product, ?int $supplierId = null): float
    {
        $last = PurchaseReceiptItem::query()
            ->where('product_id', $product->getKey())
            ->when($supplierId, fn ($query) => $query->whereHas('receipt', fn ($receipt) => $receipt->where('supplier_id', $supplierId)))
            ->latest('id')
            ->value('unit_cost');

        return (float) ($last ?? $product->cost_price ?? 0);
    }

    private function movements(SupplierOrderItem $item)
    {
        return StockMovement::query()
            ->where('type', 'sale')
            ->where('reference_type', SupplierOrderItem::class)
            ->where('reference_id', $item->getKey());
    }
}
