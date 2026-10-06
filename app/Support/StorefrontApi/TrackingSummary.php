<?php

namespace App\Support\StorefrontApi;

use App\Models\Company;
use App\Models\Order;
use App\Models\StorefrontSetting;
use App\Services\StorefrontOrderTrackingService;

/**
 * An order plus where it is: delivery status, the courier parcel and the
 * history of status changes (the same facts the Blade tracking page shows).
 */
final class TrackingSummary
{
    /**
     * @param  bool  $withCustomer  false for the public order-number + phone lookup, which should
     *                              not hand a name, e-mail or address to someone who only knows a phone number
     * @return array<string, mixed>
     */
    public static function make(Order $order, Company $company, StorefrontSetting $setting, bool $withCustomer = true): array
    {
        $tracking = app(StorefrontOrderTrackingService::class);
        $deliveryStatus = $tracking->deliveryStatus($order);
        $summary = OrderSummary::make($order, $company, $setting);

        if (! $withCustomer) {
            unset($summary['customer']);
        }

        return $summary + [
            'tracking' => [
                'delivery_status' => $deliveryStatus,
                'delivery_status_label' => Order::DELIVERY_STATUSES[$deliveryStatus] ?? str($deliveryStatus)->headline()->toString(),
                'courier' => $tracking->courier($order),
                'updates' => $tracking->updates($order)->map(fn (array $update): array => [
                    'time' => $update['time']?->toIso8601String(),
                    'type' => $update['type'],
                    'status' => $update['status'],
                    'label' => $update['label'],
                    'note' => $update['note'],
                ])->all(),
            ],
        ];
    }
}
