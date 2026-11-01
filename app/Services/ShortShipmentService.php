<?php

namespace App\Services;

use App\Models\CourierBooking;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A parcel went out with fewer pieces than were ordered, and the customer confirmed what arrived (on a video call
 * or from the unboxing video): the order is cut down to the pieces actually sent (owner request 2026-11-01). The
 * courier's cash on delivery then has to come down by the price of the pieces left out, and this service works out
 * that figure so staff do not have to.
 *
 * The rule is the plain one: the new amount for the courier to collect is the current amount minus the price of the
 * pieces left out, never below zero. The order's quantities are lowered to the pieces sent, so its total, its due,
 * the customer's balance and the stock all follow by themselves (the pieces that did not leave are back in stock).
 * An order total made of a fixed discount, VAT and delivery charge keeps those, as they are amounts, not rates.
 * Telling the courier the new amount is the same step as for any other change (CourierService::codMismatch()).
 */
class ShortShipmentService
{
    /** Whether this order can still be cut down: an open order (confirmed, processing, shipping) of our own. */
    public function allowedFor(Order $order): bool
    {
        return in_array($order->status, [Order::STATUS_CONFIRMED, Order::STATUS_PROCESSING], true)
            && $order->delivery_status !== CourierBooking::STATUS_DELIVERED
            && blank($order->reseller_customer_id)
            && ! $order->reseller_own_courier
            && $order->items()->exists();
    }

    /**
     * The numbers for a set of pieces sent, keyed by order line id. Used live in the popup and again when applying.
     *
     * @param  array<int|string, mixed>  $sent  order item id => pieces sent
     * @return array{
     *     lines: list<array{id: int, name: string, ordered: int, sent: int, missing: int, price: float, missing_value: float}>,
     *     missing_pieces: int,
     *     missing_value: float,
     *     current_cod: float,
     *     new_cod: float,
     *     new_total: float,
     * }
     */
    public function preview(Order $order, array $sent): array
    {
        $order->loadMissing('items.product');

        $lines = [];
        $missingValue = 0.0;
        $missingPieces = 0;

        foreach ($order->items as $item) {
            $ordered = (int) $item->quantity;
            $pieces = $this->clamp($sent[$item->getKey()] ?? $ordered, $ordered);
            $missing = $ordered - $pieces;
            $price = (float) $item->unit_price;
            $lines[] = [
                'id' => (int) $item->getKey(),
                'name' => trim(($item->product?->name ?? __('Item')).($item->variant_label ? ' ('.$item->variant_label.')' : '')),
                'ordered' => $ordered,
                'sent' => $pieces,
                'missing' => $missing,
                'price' => $price,
                'missing_value' => round($missing * $price, 2),
            ];
            $missingValue += $missing * $price;
            $missingPieces += $missing;
        }

        $missingValue = round($missingValue, 2);
        $currentCod = $order->courierCodAmount();

        return [
            'lines' => $lines,
            'missing_pieces' => $missingPieces,
            'missing_value' => $missingValue,
            'current_cod' => $currentCod,
            'new_cod' => round(max($currentCod - $missingValue, 0), 2),
            'new_total' => round(max((float) $order->total_amount - $missingValue, 0), 2),
        ];
    }

    /**
     * Cuts the order down to the pieces sent. A line with nothing sent is removed; at least one piece must be sent
     * and nothing can be raised above what was ordered. The change goes to the order's activity with the note
     * (who confirmed, how). Returns the preview that was applied, so the caller can tell staff the new amount.
     *
     * @param  array<int|string, mixed>  $sent  order item id => pieces sent
     * @return array<string, mixed> preview() of the applied quantities
     */
    public function apply(Order $order, array $sent, ?string $note = null): array
    {
        if (! $this->allowedFor($order)) {
            throw ValidationException::withMessages(['sent' => __('This order can no longer be cut down to the pieces sent.')]);
        }

        $preview = $this->preview($order, $sent);

        if ($preview['missing_pieces'] === 0) {
            throw ValidationException::withMessages(['sent' => __('Every ordered piece is marked as sent, so there is nothing to change.')]);
        }

        if (array_sum(array_column($preview['lines'], 'sent')) < 1) {
            throw ValidationException::withMessages(['sent' => __('At least one piece must have been sent. To stop the whole order, cancel it instead.')]);
        }

        DB::transaction(function () use ($order, $preview, $note): void {
            $old = [];
            $new = [];

            foreach ($preview['lines'] as $line) {
                if ($line['missing'] === 0) {
                    continue;
                }

                /** @var OrderItem $item */
                $item = $order->items()->whereKey($line['id'])->firstOrFail();
                $old[$line['name']] = $line['ordered'];
                $new[$line['name']] = $line['sent'];

                if ($line['sent'] === 0) {
                    $item->delete();
                } else {
                    $item->forceFill(['quantity' => $line['sent']])->save();
                }
            }

            app(AuditLogService::class)->record(
                'short_shipment',
                $order,
                ['pieces' => $old],
                ['pieces' => $new, 'amount_to_collect' => $preview['new_cod'], 'note' => filled($note) ? trim((string) $note) : null],
            );
        });

        $order->unsetRelation('items');
        $order->refresh();

        return $preview;
    }

    /** Pieces sent as a whole number between 0 and what was ordered. */
    protected function clamp(mixed $value, int $ordered): int
    {
        return max(0, min($ordered, (int) (is_numeric($value) ? $value : $ordered)));
    }
}
