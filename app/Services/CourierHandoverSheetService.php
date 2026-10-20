<?php

namespace App\Services;

use App\Models\CourierBooking;
use App\Models\CourierProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The sheet a rider takes for one courier on one day (16_AI_INBOX_CONTROL_PLANE_PLAN.md
 * §4.8, owner answer O8): every parcel booked through that courier that day,
 * with the cash to collect and the consignment/tracking numbers. There is no
 * rider block — the owner did not ask for one.
 */
class CourierHandoverSheetService
{
    /** Bookings that did not become a real parcel are not on the sheet. */
    private const EXCLUDED_STATUSES = [
        CourierBooking::STATUS_NOT_BOOKED,
        CourierBooking::STATUS_BOOKING_PENDING,
        CourierBooking::STATUS_CANCELLED,
        CourierBooking::STATUS_FAILED,
    ];

    /**
     * @return array{date:Carbon,parcels:int,cod_total:float,rows:Collection<int, array{invoice:?string,name:?string,phone:?string,address:?string,cod:float,consignment:?string,tracking:?string}>}
     */
    public function build(CourierProvider $provider, ?string $date = null): array
    {
        $timezone = $provider->company?->timezone ?: 'Asia/Dhaka';
        $day = Carbon::parse($date ?: now($timezone)->toDateString(), $timezone)->startOfDay();
        $appTimezone = (string) config('app.timezone');

        $rows = CourierBooking::query()
            ->where('courier_provider_id', $provider->getKey())
            ->whereBetween('booked_at', [$day->copy()->setTimezone($appTimezone), $day->copy()->endOfDay()->setTimezone($appTimezone)])
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->with('order:id,order_number')
            ->orderBy('booked_at')->orderBy('id')
            ->get()
            ->map(fn (CourierBooking $booking): array => [
                'invoice' => $booking->order?->order_number,
                'name' => $booking->recipient_name,
                'phone' => $booking->recipient_phone,
                'address' => $booking->recipient_address,
                'cod' => (float) $booking->cod_amount,
                'consignment' => $booking->provider_reference,
                'tracking' => $booking->tracking_id,
            ]);

        return [
            'date' => $day,
            'parcels' => $rows->count(),
            'cod_total' => round((float) $rows->sum('cod'), 2),
            'rows' => $rows,
        ];
    }
}
