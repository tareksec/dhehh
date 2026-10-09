<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CourierBooking;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a customer may see about where their order is: the status changes
 * recorded in the audit log plus the courier's own status log. Shared by the
 * Blade tracking page and the headless storefront API so both show the same
 * history. Rows are structured (type, status key, English label, note) — each
 * site words them itself.
 */
class StorefrontOrderTrackingService
{
    public const TYPE_ORDER = 'order';

    public const TYPE_DELIVERY = 'delivery';

    public const TYPE_COURIER = 'courier';

    /**
     * Newest first.
     *
     * @return Collection<int, array{time: Carbon|null, type: string, status: string, label: string, note: string|null}>
     */
    public function updates(Order $order): Collection
    {
        $auditUpdates = AuditLog::query()
            ->where('auditable_type', Order::class)
            ->where('auditable_id', $order->getKey())
            ->where('action', 'updated')
            ->latest()
            ->get()
            ->flatMap(function (AuditLog $log): array {
                $updates = [];

                if (array_key_exists('delivery_status', $log->new_values ?? [])) {
                    $status = (string) $log->new_values['delivery_status'];
                    $updates[] = [
                        'time' => $log->created_at,
                        'type' => self::TYPE_DELIVERY,
                        'status' => $status,
                        'label' => Order::DELIVERY_STATUSES[$status] ?? str($status)->headline()->toString(),
                        'note' => null,
                    ];
                }

                if (array_key_exists('status', $log->new_values ?? [])) {
                    $status = (string) $log->new_values['status'];
                    $updates[] = [
                        'time' => $log->created_at,
                        'type' => self::TYPE_ORDER,
                        'status' => $status,
                        'label' => Order::STATUSES[$status] ?? str($status)->headline()->toString(),
                        'note' => null,
                    ];
                }

                return $updates;
            });

        $order->loadMissing('latestCourierBooking.statusLogs');

        $courierUpdates = $order->latestCourierBooking?->statusLogs
            ->map(fn ($log): array => [
                'time' => $log->created_at,
                'type' => self::TYPE_COURIER,
                'status' => (string) $log->to_status,
                'label' => CourierBooking::STATUSES[$log->to_status] ?? str((string) $log->to_status)->headline()->toString(),
                'note' => filled($log->note) ? (string) $log->note : null,
            ]) ?? collect();

        return $auditUpdates
            ->merge($courierUpdates)
            ->sortByDesc('time')
            ->values();
    }

    /** The courier parcel behind the order, if one was booked. */
    public function courier(Order $order): ?array
    {
        $order->loadMissing('latestCourierBooking');
        $booking = $order->latestCourierBooking;

        if (! $booking) {
            return null;
        }

        return [
            'tracking_id' => filled($booking->tracking_id) ? (string) $booking->tracking_id : null,
            'status' => (string) $booking->status,
            'status_label' => CourierBooking::STATUSES[$booking->status] ?? str((string) $booking->status)->headline()->toString(),
        ];
    }

    public function deliveryStatus(Order $order): string
    {
        return (string) ($order->delivery_status ?: CourierBooking::STATUS_NOT_BOOKED);
    }
}
