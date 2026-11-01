<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\StockMovement;
use Illuminate\Validation\ValidationException;

/**
 * Books the stock of a goods receipt. For each receipt line:
 *   - a 'purchase' movement of the pieces that reached the godown (sent minus short),
 *   - a 'damage' movement of the pieces that arrived broken.
 * So stock = received - short - damage, which is the identity of the supplier account.
 */
class PurchaseReceiptService
{
    public function prepare(PurchaseReceiptItem $item): void
    {
        if (blank($item->quantity) && $item->cartons && $item->pcs_per_carton) {
            $item->quantity = (int) $item->cartons * (int) $item->pcs_per_carton;
        }

        $item->quantity = (int) $item->quantity;
        $item->short_quantity = (int) $item->short_quantity;
        $item->damage_quantity = (int) $item->damage_quantity;

        if ($item->quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => __('Pieces received must be more than zero.'),
            ]);
        }

        if ($item->short_quantity > $item->quantity) {
            throw ValidationException::withMessages([
                'short_quantity' => __('Short pieces cannot be more than the pieces received.'),
            ]);
        }

        if ($item->damage_quantity > $item->quantity - $item->short_quantity) {
            throw ValidationException::withMessages([
                'damage_quantity' => __('Damaged pieces cannot be more than the pieces that arrived.'),
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
    }

    public function syncStock(PurchaseReceiptItem $item): void
    {
        // History already counted in today's stock records the account facts without moving stock again.
        if ($item->receipt && ! $item->receipt->books_stock) {
            $this->releaseStock($item);

            return;
        }

        $number = (string) $item->receipt?->receipt_number;

        // A change that adds stock goes first, so a line edited from "100 sent, 10 broken"
        // to "90 sent, none broken" never trips the negative-stock check half way.
        $changes = [
            [
                $item->arrivedQuantity() - $this->bookedQuantity($item, 'purchase'),
                fn () => $this->syncMovement($item, 'purchase', $item->arrivedQuantity(), null, $number),
            ],
            [
                $this->bookedQuantity($item, 'damage') - (int) $item->damage_quantity,
                fn () => $this->syncMovement($item, 'damage', (int) $item->damage_quantity, __('Damaged on receipt :number', ['number' => $number]), $number),
            ],
        ];

        usort($changes, fn (array $a, array $b): int => $b[0] <=> $a[0]);

        foreach ($changes as [, $apply]) {
            $apply();
        }
    }

    public function releaseStock(PurchaseReceiptItem $item): void
    {
        foreach (['damage', 'purchase'] as $type) {
            $this->movements($item, $type)->get()->each->delete();
        }
    }

    /** Keeps the stock history dated on the day the goods came in. */
    public function syncDate(PurchaseReceipt $receipt): void
    {
        $ids = $receipt->items()->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        StockMovement::query()
            ->where('reference_type', PurchaseReceiptItem::class)
            ->whereIn('reference_id', $ids)
            ->update(['created_at' => $receipt->received_on->copy()->setTime(12, 0)]);
    }

    private function bookedQuantity(PurchaseReceiptItem $item, string $type): int
    {
        return (int) $this->movements($item, $type)->sum('quantity');
    }

    private function movements(PurchaseReceiptItem $item, string $type)
    {
        return StockMovement::query()
            ->where('type', $type)
            ->where('reference_type', PurchaseReceiptItem::class)
            ->where('reference_id', $item->getKey());
    }

    private function syncMovement(PurchaseReceiptItem $item, string $type, int $quantity, ?string $reason, string $number): void
    {
        $existing = $this->movements($item, $type)->first();

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
            'type' => $type,
            'reference_type' => PurchaseReceiptItem::class,
            'reference_id' => $item->getKey(),
        ]);

        $movement->fill([
            'company_id' => $item->company_id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'quantity' => $quantity,
            'reason' => $reason,
            'note' => __('Goods receipt :number', ['number' => $number]),
        ]);

        if (! $movement->exists && $item->receipt?->received_on) {
            $movement->created_at = $item->receipt->received_on->copy()->setTime(12, 0);
        }

        $movement->save();
    }
}
