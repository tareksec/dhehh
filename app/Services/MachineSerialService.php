<?php

namespace App\Services;

use App\Models\DeliveryChallan;
use App\Models\MachineSerial;
use App\Models\Purchase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Optional machine serial numbers (docs/tasneem-samples/PLAN.md §3.7): recorded
 * against the purchase they came with (never more than its quantity of that
 * item), and linked to the Delivery Challan they leave on (never more than the
 * challan's quantity of that item). Cancelling a challan frees its serials.
 */
class MachineSerialService
{
    /**
     * Adds serial numbers (one per line or comma separated) for one item of a purchase.
     *
     * @return int how many were added
     */
    public function addToPurchase(Purchase $purchase, int $productId, string $serials): int
    {
        $item = $purchase->items()->where('product_id', $productId)->first();

        if (! $item) {
            throw ValidationException::withMessages(['product_id' => __('This item is not on the purchase.')]);
        }

        $numbers = collect(preg_split('/[\r\n,;]+/', $serials) ?: [])
            ->map(fn ($number): string => trim((string) $number))
            ->filter()
            ->unique(fn (string $number): string => mb_strtolower($number))
            ->values();

        if ($numbers->isEmpty()) {
            throw ValidationException::withMessages(['serials' => __('Write at least one serial number.')]);
        }

        $quantity = (int) $purchase->items()->where('product_id', $productId)->sum('quantity');
        $already = MachineSerial::query()->where('purchase_id', $purchase->getKey())->where('product_id', $productId)->count();

        if ($already + $numbers->count() > $quantity) {
            throw ValidationException::withMessages(['serials' => __('Only :left more serial numbers fit: the purchase has :quantity of this item and :already are written.', [
                'left' => max($quantity - $already, 0),
                'quantity' => $quantity,
                'already' => $already,
            ])]);
        }

        $taken = MachineSerial::query()->where('product_id', $productId)->whereIn('serial_number', $numbers->all())->pluck('serial_number');

        if ($taken->isNotEmpty()) {
            throw ValidationException::withMessages(['serials' => __('Already written: :numbers', ['numbers' => $taken->implode(', ')])]);
        }

        DB::transaction(function () use ($numbers, $purchase, $productId): void {
            foreach ($numbers as $number) {
                MachineSerial::query()->create(['product_id' => $productId, 'purchase_id' => $purchase->getKey(), 'serial_number' => $number]);
            }
        });

        return $numbers->count();
    }

    /**
     * Serials still in the shop for the items on this challan, plus the ones
     * already on it, by item name.
     *
     * @return array<int, string>
     */
    public function optionsFor(DeliveryChallan $challan): array
    {
        $productIds = $challan->items()->whereNotNull('product_id')->pluck('product_id')->unique()->all();

        return MachineSerial::query()
            ->with('product')
            ->whereIn('product_id', $productIds)
            ->where(fn ($query) => $query->whereNull('delivery_challan_id')->orWhere('delivery_challan_id', $challan->getKey()))
            ->orderBy('serial_number')
            ->get()
            ->mapWithKeys(fn (MachineSerial $serial): array => [$serial->getKey() => $serial->serial_number.' — '.$serial->product?->name])
            ->all();
    }

    /**
     * Sets which serials left with the challan (replacing the earlier choice).
     *
     * @param  list<int|string>  $serialIds
     */
    public function setForChallan(DeliveryChallan $challan, array $serialIds): void
    {
        if ($challan->isCancelled()) {
            throw ValidationException::withMessages(['serials' => __('This challan is cancelled.')]);
        }

        $allowed = $this->optionsFor($challan);
        $ids = array_values(array_unique(array_map('intval', $serialIds)));

        foreach ($ids as $id) {
            if (! array_key_exists($id, $allowed)) {
                throw ValidationException::withMessages(['serials' => __('A chosen serial number is not available for this challan.')]);
            }
        }

        $chosen = MachineSerial::query()->whereIn('id', $ids)->get();
        $quantities = $challan->items()->whereNotNull('product_id')->get()->groupBy('product_id')->map(fn ($lines): int => (int) $lines->sum('quantity'));

        foreach ($chosen->groupBy('product_id') as $productId => $serials) {
            if ($serials->count() > (int) ($quantities[$productId] ?? 0)) {
                throw ValidationException::withMessages(['serials' => __('More serial numbers than the challan\'s quantity of ":item".', ['item' => $serials->first()->product?->name])]);
            }
        }

        DB::transaction(function () use ($challan, $ids): void {
            MachineSerial::query()->where('delivery_challan_id', $challan->getKey())->whereNotIn('id', $ids)->update(['delivery_challan_id' => null]);
            MachineSerial::query()->whereIn('id', $ids)->update(['delivery_challan_id' => $challan->getKey()]);
        });
    }

    /** A cancelled challan's machines are back in the shop. */
    public function releaseChallan(DeliveryChallan $challan): void
    {
        MachineSerial::query()->where('delivery_challan_id', $challan->getKey())->update(['delivery_challan_id' => null]);
    }
}
