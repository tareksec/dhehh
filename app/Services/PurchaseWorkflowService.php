<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Support\Collection;

class PurchaseWorkflowService
{
    public function sync(Purchase $purchase): void
    {
        if (! $purchase->exists) {
            return;
        }

        $items = $purchase->items()->with('product')->get();
        $subtotal = $items->sum(fn (PurchaseItem $item): float => (int) $item->quantity * (float) $item->unit_cost);
        $total = max($subtotal + $purchase->chinaToBdCostTotal() - (float) $purchase->discount + (float) $purchase->vat, 0);
        $due = max($total - (float) $purchase->paid_amount, 0);

        if ($purchase->subtotal != $subtotal || $purchase->total_amount != $total || $purchase->due_amount != $due) {
            $purchase->forceFill([
                'subtotal' => $subtotal,
                'total_amount' => $total,
                'due_amount' => $due,
            ])->saveQuietly();
        }

        $this->syncLandedCosts($purchase, $items);

        if ($purchase->status === 'received') {
            $this->syncStockMovements($purchase, $items);
        } else {
            $this->deleteStockMovements($purchase);
        }

        $this->syncSupplierBalance($purchase);
    }

    /**
     * Fills the given trade document's number/date only when blank, derived
     * from the purchase number (e.g. "PUR-...-XXXXX-PI"), then saves quietly.
     * An existing value (already generated, or entered manually) is left
     * untouched — Generate is a one-way default-fill, never an overwrite.
     */
    public function ensureDocumentNumber(Purchase $purchase, string $type): void
    {
        $numberColumn = "{$type}_number";
        $dateColumn = "{$type}_date";

        if (filled($purchase->{$numberColumn}) && filled($purchase->{$dateColumn})) {
            return;
        }

        $purchase->forceFill([
            $numberColumn => $purchase->{$numberColumn} ?: $purchase->purchase_number.'-'.strtoupper($type),
            $dateColumn => $purchase->{$dateColumn} ?: now()->toDateString(),
        ])->saveQuietly();
    }

    public function syncPreviousSupplierBalance(Purchase $purchase): void
    {
        if ($purchase->wasChanged('supplier_id')) {
            Supplier::withTrashed()->find($purchase->getOriginal('supplier_id'))?->syncCurrentBalance();
        }
    }

    public function syncSupplierBalance(Purchase $purchase): void
    {
        $purchase->supplier?->syncCurrentBalance();
    }

    public function deleteStockMovements(Purchase $purchase): void
    {
        StockMovement::query()
            ->where('type', 'purchase')
            ->where('reference_type', Purchase::class)
            ->where('reference_id', $purchase->getKey())
            ->get()
            ->each
            ->delete();
    }

    public function syncLandedCosts(Purchase $purchase, Collection $items): void
    {
        $extraCostTotal = $purchase->chinaToBdCostTotal();
        $subtotal = $items->sum(fn (PurchaseItem $item): float => (float) $item->subtotal);
        $remainingAllocatedCost = round($extraCostTotal, 2);
        $lastItemKey = $items->keys()->last();

        foreach ($items as $key => $item) {
            $allocatedCost = 0.0;

            if ($subtotal > 0 && $extraCostTotal > 0) {
                $allocatedCost = $key === $lastItemKey
                    ? $remainingAllocatedCost
                    : round($extraCostTotal * ((float) $item->subtotal / $subtotal), 2);
            }

            $remainingAllocatedCost = round($remainingAllocatedCost - $allocatedCost, 2);
            $quantity = max((int) $item->quantity, 1);
            $landedUnitCost = round(((float) $item->subtotal + $allocatedCost) / $quantity, 2);

            if (
                (float) $item->allocated_cost !== $allocatedCost ||
                (float) $item->landed_unit_cost !== $landedUnitCost
            ) {
                $item->forceFill([
                    'allocated_cost' => $allocatedCost,
                    'landed_unit_cost' => $landedUnitCost,
                ])->saveQuietly();
            }
        }
    }

    protected function syncStockMovements(Purchase $purchase, Collection $items): void
    {
        // Only units that arrived in sellable shape reach stock: short and
        // damaged units stay on the supplier report as a loss. A product whose
        // every unit was lost has no movement (a movement must be > 0).
        $quantitiesByProduct = $items
            ->groupBy('product_id')
            ->map(fn ($productItems): int => $productItems->sum(fn (PurchaseItem $item): int => $item->sellableQuantity()))
            ->filter(fn (int $quantity): bool => $quantity > 0);

        foreach ($quantitiesByProduct as $productId => $quantity) {
            StockMovement::query()->updateOrCreate(
                [
                    'product_id' => $productId,
                    'type' => 'purchase',
                    'reference_type' => Purchase::class,
                    'reference_id' => $purchase->getKey(),
                ],
                [
                    'company_id' => $purchase->company_id,
                    'quantity' => $quantity,
                    'note' => "Purchase {$purchase->purchase_number}",
                ],
            );
        }

        StockMovement::query()
            ->where('type', 'purchase')
            ->where('reference_type', Purchase::class)
            ->where('reference_id', $purchase->getKey())
            ->whereNotIn('product_id', $quantitiesByProduct->keys()->all())
            ->get()
            ->each
            ->delete();

        if ($purchase->update_cost_price) {
            $items->each(function (PurchaseItem $item): void {
                $item->product?->update(['cost_price' => $item->landed_unit_cost ?: $item->unit_cost]);
            });
        }
    }
}
