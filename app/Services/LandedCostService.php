<?php

namespace App\Services;

use App\Models\LandedCostLine;
use App\Models\LcFile;
use App\Models\PurchaseItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The landed cost worksheet of one LC file (docs/tasneem-samples/SUMMARY.md §4
 * report 3, PLAN.md §3.6):
 *
 *   goods in Taka  = CFR value (USD) × the bank's settlement rate
 *                    (or, with no rate, the purchase lines' buying price)
 *   + every cost line (bank, insurance, duty, port, C&F, PSI, truck, loading…)
 *   = total landed cost; ÷ quantity = landed cost per unit.
 *
 * With several kinds of goods on one LC the total is shared over the lines by
 * their value (FOB USD when the lines have it, else their buying price, else
 * their quantity) — the same sharing the ERP's purchase uses. Nothing here
 * changes the purchase total or the supplier's due; applyToStock() writes the
 * landed unit cost to the items' buying price only when the owner asks.
 */
class LandedCostService
{
    /**
     * @return array{rate: float|null, goods_usd: float, goods_bdt: float, goods_from: string, lines: list<LandedCostLine>, costs: float, total: float, quantity: int, per_unit: float, items: list<array{product: string, quantity: int, share: float, landed_total: float, landed_unit: float, current_cost: float}>}
     */
    public function worksheet(LcFile $file): array
    {
        $file->loadMissing(['purchase.items.product', 'landedCostLines']);
        $items = $file->purchase?->items ?? collect();
        $rate = $file->exchange_rate !== null && (float) $file->exchange_rate > 0 ? (float) $file->exchange_rate : null;
        $goodsUsd = $file->lcValue();
        $lineValue = round((float) $items->sum(fn (PurchaseItem $item): float => (int) $item->quantity * (float) $item->unit_cost), 2);

        [$goodsBdt, $goodsFrom] = $rate !== null && $goodsUsd > 0
            ? [round($goodsUsd * $rate, 2), 'rate']
            : [$lineValue, 'lines'];

        $lines = $file->landedCostLines->all();
        $costs = round((float) $file->landedCostLines->sum('amount'), 2);
        $total = round($goodsBdt + $costs, 2);
        $quantity = (int) $items->sum('quantity');

        return [
            'rate' => $rate,
            'goods_usd' => $goodsUsd,
            'goods_bdt' => $goodsBdt,
            'goods_from' => $goodsFrom,
            'lines' => $lines,
            'costs' => $costs,
            'total' => $total,
            'quantity' => $quantity,
            'per_unit' => $quantity > 0 ? round($total / $quantity, 2) : 0.0,
            'items' => $this->share($items->all(), $total),
        ];
    }

    /**
     * The total shared over the purchase lines, the last line taking what is
     * left after rounding.
     *
     * @param  list<PurchaseItem>  $items
     * @return list<array{product: string, product_id: int|null, quantity: int, share: float, landed_total: float, landed_unit: float, current_cost: float}>
     */
    protected function share(array $items, float $total): array
    {
        $weights = array_map(fn (PurchaseItem $item): float => (int) $item->quantity * (float) ($item->fob_unit_price_usd ?? 0), $items);

        if (array_sum($weights) <= 0) {
            $weights = array_map(fn (PurchaseItem $item): float => (int) $item->quantity * (float) $item->unit_cost, $items);
        }

        if (array_sum($weights) <= 0) {
            $weights = array_map(fn (PurchaseItem $item): float => (float) (int) $item->quantity, $items);
        }

        $sum = array_sum($weights);
        $left = $total;
        $rows = [];

        foreach (array_values($items) as $index => $item) {
            $share = $sum > 0 ? $weights[$index] / $sum : 0.0;
            $landed = $index === count($items) - 1 ? round($left, 2) : round($total * $share, 2);
            $left = round($left - $landed, 2);
            $quantity = (int) $item->quantity;

            $rows[] = [
                'product' => (string) ($item->product?->name ?? ''),
                'product_id' => $item->product_id ? (int) $item->product_id : null,
                'quantity' => $quantity,
                'share' => round($share * 100, 2),
                'landed_total' => $landed,
                'landed_unit' => $quantity > 0 ? round($landed / $quantity, 2) : 0.0,
                'current_cost' => (float) ($item->product?->cost_price ?? 0),
            ];
        }

        return $rows;
    }

    /** @param  array<string, mixed>  $data  kind, label (for "other"), source, amount, paid_on, note */
    public function addLine(LcFile $file, array $data): LandedCostLine
    {
        $kind = (string) ($data['kind'] ?? '');

        if (! array_key_exists($kind, LandedCostLine::KINDS)) {
            throw ValidationException::withMessages(['kind' => __('Choose what the cost was for.')]);
        }

        $label = $kind === 'other' ? trim((string) ($data['label'] ?? '')) : LandedCostLine::KINDS[$kind][0];

        if ($label === '') {
            throw ValidationException::withMessages(['label' => __('Write the name of the cost.')]);
        }

        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('The amount must be more than zero.')]);
        }

        $source = array_key_exists((string) ($data['source'] ?? ''), LandedCostLine::SOURCES) ? (string) $data['source'] : LandedCostLine::KINDS[$kind][1];

        return $file->landedCostLines()->create([
            'kind' => $kind,
            'label' => $label,
            'source' => $source,
            'amount' => $amount,
            'paid_on' => filled($data['paid_on'] ?? null) ? $data['paid_on'] : null,
            'note' => filled($data['note'] ?? null) ? trim((string) $data['note']) : null,
        ]);
    }

    public function setRate(LcFile $file, ?float $rate): void
    {
        if ($rate !== null && $rate <= 0) {
            throw ValidationException::withMessages(['exchange_rate' => __('The rate must be more than zero.')]);
        }

        $file->update(['exchange_rate' => $rate]);
    }

    /**
     * Writes each line's landed unit cost to its item's buying price, so the
     * stock value and the profit use it. Returns how many items were changed.
     */
    public function applyToStock(LcFile $file): int
    {
        $sheet = $this->worksheet($file);

        if ($sheet['total'] <= 0 || $sheet['quantity'] <= 0) {
            throw ValidationException::withMessages(['total' => __('There is no landed cost to apply yet.')]);
        }

        return DB::transaction(function () use ($file, $sheet): int {
            $changed = 0;

            foreach ($file->purchase->items as $index => $item) {
                $row = $sheet['items'][$index] ?? null;

                if ($row && $item->product && $row['landed_unit'] > 0) {
                    $item->product->update(['cost_price' => $row['landed_unit']]);
                    $changed++;
                }
            }

            $file->update(['landed_cost_applied_at' => now()]);

            return $changed;
        });
    }
}
