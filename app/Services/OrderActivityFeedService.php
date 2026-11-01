<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CourierBooking;
use App\Models\Order;
use App\Models\OrderCost;
use App\Models\OrderPayment;
use App\Support\MoneyFormatter;
use Illuminate\Support\Collection;

/**
 * Turns this app's existing raw audit trail (AuditLog — attribute diffs
 * from create/update/delete, plus the 'viewed'/'printed' events
 * ViewOrder/the print routes record) and courier status history
 * (CourierStatusLog) into the kind of narrated, human-readable per-order
 * feed Nuport's "Logs" tab shows ("The sales order SO-1196 was printed by
 * X") — without adding any new storage; it's a presentation layer over
 * data that already exists.
 */
class OrderActivityFeedService
{
    public function narrate(Order $order): Collection
    {
        $paymentIds = $order->payments()->pluck('id');
        $costIds = $order->costs()->pluck('id');

        $entries = AuditLog::query()
            ->with('user')
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('auditable_type', Order::class)->where('auditable_id', $order->getKey()))
                ->orWhere(fn ($q) => $q->where('auditable_type', OrderPayment::class)->whereIn('auditable_id', $paymentIds))
                ->orWhere(fn ($q) => $q->where('auditable_type', OrderCost::class)->whereIn('auditable_id', $costIds)))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'at' => $log->created_at,
                'text' => $this->sentenceFor($log),
            ]);

        $booking = $order->latestCourierBooking;

        if ($booking) {
            $entries = $entries->concat(
                $booking->statusLogs()
                    ->with('creator')
                    ->latest()
                    ->get()
                    ->map(fn ($statusLog): array => [
                        'at' => $statusLog->created_at,
                        'text' => sprintf(
                            'Courier status changed from %s to %s by %s.',
                            CourierBooking::STATUSES[$statusLog->from_status] ?? ($statusLog->from_status ?: 'Not booked'),
                            CourierBooking::STATUSES[$statusLog->to_status] ?? $statusLog->to_status,
                            $statusLog->creator?->name ?? 'System'
                        ),
                    ])
            );
        }

        return $entries->sortByDesc('at')->values();
    }

    protected function sentenceFor(AuditLog $log): string
    {
        $actor = $log->user?->name ?? 'System';

        return match (true) {
            $log->auditable_type === Order::class => $this->orderSentence($log, $actor),
            $log->auditable_type === OrderPayment::class => $this->paymentSentence($log, $actor),
            $log->auditable_type === OrderCost::class => $this->costSentence($log, $actor),
            default => ucfirst($log->action)." by {$actor}.",
        };
    }

    protected function orderSentence(AuditLog $log, string $actor): string
    {
        return match ($log->action) {
            'created' => "Order created by {$actor}.",
            'viewed' => "Order viewed by {$actor}.",
            'printed' => "Order printed by {$actor}.",
            'deleted' => "Order deleted by {$actor}.",
            'courier_cod_changed' => sprintf(
                'Amount for the courier to collect changed from %s to %s by %s.',
                MoneyFormatter::number((float) ($log->old_values['cod_amount'] ?? 0)),
                MoneyFormatter::number((float) ($log->new_values['cod_amount'] ?? 0)),
                $actor,
            ),
            'short_shipment' => sprintf(
                'Order cut down to the pieces sent by %s (amount to collect now %s)%s.',
                $actor,
                MoneyFormatter::number((float) ($log->new_values['amount_to_collect'] ?? 0)),
                filled($log->new_values['note'] ?? null) ? ': '.$log->new_values['note'] : '',
            ),
            'updated' => match (true) {
                array_key_exists('status', $log->new_values ?? []) => sprintf(
                    'Order status changed to %s by %s.',
                    Order::STATUSES[$log->new_values['status']] ?? $log->new_values['status'],
                    $actor
                ),
                array_key_exists('delivery_status', $log->new_values ?? []) => sprintf(
                    'Delivery status changed to %s by %s.',
                    Order::DELIVERY_STATUSES[$log->new_values['delivery_status']] ?? $log->new_values['delivery_status'],
                    $actor
                ),
                default => "Order details updated by {$actor}.",
            },
            default => ucfirst($log->action)." by {$actor}.",
        };
    }

    protected function paymentSentence(AuditLog $log, string $actor): string
    {
        return match ($log->action) {
            'created' => sprintf('Payment of %s added by %s.', $this->money($log->new_values['amount'] ?? null), $actor),
            'updated' => sprintf('Payment updated to %s by %s.', $this->money($log->new_values['amount'] ?? null), $actor),
            'deleted' => sprintf('Payment of %s removed by %s.', $this->money($log->old_values['amount'] ?? null), $actor),
            default => ucfirst($log->action)." by {$actor}.",
        };
    }

    protected function costSentence(AuditLog $log, string $actor): string
    {
        $head = fn (?array $values): string => OrderCost::HEADS[$values['cost_head'] ?? ''] ?? 'Cost';

        return match ($log->action) {
            'created' => sprintf('%s of %s recorded by %s.', $head($log->new_values), $this->money($log->new_values['amount'] ?? null), $actor),
            'updated' => sprintf('%s updated to %s by %s.', $head($log->new_values), $this->money($log->new_values['amount'] ?? null), $actor),
            'deleted' => sprintf('%s of %s removed by %s.', $head($log->old_values), $this->money($log->old_values['amount'] ?? null), $actor),
            default => ucfirst($log->action)." by {$actor}.",
        };
    }

    protected function money(mixed $amount): string
    {
        return MoneyFormatter::currency((float) $amount);
    }
}
