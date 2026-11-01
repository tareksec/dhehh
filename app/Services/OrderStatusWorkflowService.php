<?php

namespace App\Services;

use App\Models\CourierBooking;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderStatusWorkflowService
{
    public function transition(
        Order $order,
        string $stage,
        ?string $reason = null,
        string $source = 'manual',
        ?int $actorId = null,
    ): Order {
        if (! array_key_exists($stage, Order::WORKFLOW_STAGES)) {
            throw ValidationException::withMessages([
                'stage' => 'Select a valid order workflow stage.',
            ]);
        }

        if (in_array($stage, Order::REASON_REQUIRED_STAGES, true) && blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required for cancelled, returned, and refunded orders.',
            ]);
        }

        return DB::transaction(function () use ($order, $stage, $reason, $source, $actorId): Order {
            $current = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $allowed = $current->allowedWorkflowStages();

            if (! in_array($stage, $allowed, true)) {
                throw ValidationException::withMessages([
                    'stage' => sprintf(
                        'The order cannot move from %s to %s.',
                        Order::WORKFLOW_STAGES[$current->workflowStage()] ?? ucfirst($current->workflowStage()),
                        Order::WORKFLOW_STAGES[$stage],
                    ),
                ]);
            }

            $attributes = $this->attributesForStage($current, $stage);
            // The courier booking follows the stage the order moves to. Going back is the exception: the booking
            // is the courier's own record, so it stays as it is and only the order's delivery state is reset.
            $bookingAttributes = $attributes;

            if ($this->isStepBackFromShipping($current, $stage)) {
                $attributes['delivery_status'] = $this->deliveryStatusAfterStepBack($current);
            }

            $current->statusTransitionStage = $stage;
            $current->statusTransitionReason = filled($reason) ? trim((string) $reason) : null;
            $current->statusTransitionSource = $source;
            $current->statusTransitionActorId = $actorId ?? Auth::id();

            $this->ensureStockFor($current, $attributes['status'] ?? $current->status);

            try {
                $this->syncLatestCourierBooking($current, $bookingAttributes, $reason, $stage);
                $current->forceFill($attributes)->save();
            } catch (ValidationException $exception) {
                // A stock or courier rule rejected the change under a key
                // ("quantity", "product_variant_id"...) that no status popup
                // shows -- report it on the stage field so it is never silent.
                throw ValidationException::withMessages([
                    'stage' => collect($exception->errors())->flatten()->implode(' '),
                ]);
            } finally {
                $current->clearStatusTransitionContext();
            }

            return $current->refresh();
        });
    }

    /**
     * Moving an order into a status that takes stock (e.g. a draft from the
     * storefront or the reseller portal being confirmed) names every short
     * product, instead of failing on the first stock movement.
     */
    protected function ensureStockFor(Order $order, string $newStatus): void
    {
        if ($order->isAccounted() || ! in_array($newStatus, Order::ACCOUNTED_STATUSES, true)) {
            return;
        }

        $shortages = app(OrderStockCheckService::class)->shortages(
            $order->items()->get(['product_id', 'product_variant_id', 'quantity'])->toArray(),
            $order,
        );

        if ($shortages === []) {
            return;
        }

        throw ValidationException::withMessages([
            'stage' => __('Not enough stock: :products. Add stock or change the order first.', [
                'products' => collect($shortages)
                    ->map(fn (array $line): string => __(':name (ordered :ordered, in stock :available)', $line))
                    ->implode('; '),
            ]),
        ]);
    }

    protected function attributesForStage(Order $order, string $stage): array
    {
        return match ($stage) {
            Order::STAGE_CONFIRMED => ['status' => Order::STATUS_CONFIRMED],
            Order::STAGE_PROCESSING => ['status' => Order::STATUS_PROCESSING],
            Order::STAGE_SHIPPING => [
                'status' => Order::STATUS_PROCESSING,
                'delivery_status' => CourierBooking::STATUS_IN_TRANSIT,
            ],
            Order::STAGE_DELIVERED => [
                'status' => Order::STATUS_COMPLETED,
                'delivery_status' => CourierBooking::STATUS_DELIVERED,
            ],
            Order::STAGE_COMPLETED => ['status' => Order::STATUS_COMPLETED],
            Order::STAGE_CANCELLED => [
                'status' => Order::STATUS_CANCELLED,
                'delivery_status' => $order->delivery_status === CourierBooking::STATUS_NOT_BOOKED
                    ? CourierBooking::STATUS_NOT_BOOKED
                    : CourierBooking::STATUS_CANCELLED,
            ],
            Order::STAGE_RETURNED => [
                'status' => Order::STATUS_RETURNED,
                'delivery_status' => CourierBooking::STATUS_RETURNED,
            ],
            Order::STAGE_REFUNDED => ['status' => Order::STATUS_REFUNDED],
        };
    }

    /** A Shipping order sent back to Processing or Confirmed: the parcel is no longer counted as on its way. */
    protected function isStepBackFromShipping(Order $order, string $stage): bool
    {
        return $order->workflowStage() === Order::STAGE_SHIPPING
            && in_array($stage, [Order::STAGE_CONFIRMED, Order::STAGE_PROCESSING], true);
    }

    /**
     * The delivery state of an order that went back from Shipping: Booked while a courier booking is still live
     * (the parcel stays booked, it just is not moving), otherwise Not Booked, as before the "Shipping" click.
     */
    protected function deliveryStatusAfterStepBack(Order $order): string
    {
        $booking = $order->latestCourierBooking()->first();

        return $booking && ! in_array($booking->status, [CourierBooking::STATUS_CANCELLED, CourierBooking::STATUS_FAILED], true)
            ? CourierBooking::STATUS_BOOKED
            : CourierBooking::STATUS_NOT_BOOKED;
    }

    protected function syncLatestCourierBooking(Order $order, array $attributes, ?string $reason, string $stage): void
    {
        $deliveryStatus = $attributes['delivery_status'] ?? null;

        if (! $deliveryStatus) {
            return;
        }

        $booking = $order->latestCourierBooking()->first();

        if (! $booking || $booking->status === $deliveryStatus) {
            return;
        }

        app(CourierService::class)->updateStatus(
            $booking,
            $deliveryStatus,
            filled($reason) ? $reason : 'Order workflow moved to '.Order::WORKFLOW_STAGES[$stage].'.',
            syncOrder: false,
        );
    }
}
