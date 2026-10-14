<?php

namespace App\Services;

use App\Models\StorefrontSetting;

class StorefrontDeliveryService
{
    /**
     * @return array{weight: float, billed_weight: int, fee: float, first_kg: float, additional_kg: int, additional_rate: float}
     */
    public function quote(iterable $items, string $area, StorefrontSetting $setting): array
    {
        $weight = round((float) collect($items)->sum(
            fn (array $item): float => max(0, (float) (($item['variant'] ?? null)?->weight_kg ?? $item['product']->weight_kg ?? 0)) * max(1, (int) $item['quantity'])
        ), 3);
        $billedWeight = max(1, (int) ceil($weight));
        $firstKg = $area === 'outside'
            ? (float) ($setting->delivery_first_kg_outside ?? 110)
            : (float) ($setting->delivery_first_kg_inside ?? 70);
        $additionalRate = (float) ($setting->delivery_additional_per_kg ?? 20);
        $additionalKg = max(0, $billedWeight - 1);

        return [
            'weight' => $weight,
            'billed_weight' => $billedWeight,
            'fee' => round($firstKg + ($additionalKg * $additionalRate), 2),
            'first_kg' => $firstKg,
            'additional_kg' => $additionalKg,
            'additional_rate' => $additionalRate,
        ];
    }
}
