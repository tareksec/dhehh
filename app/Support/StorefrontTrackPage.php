<?php

namespace App\Support;

use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StorefrontSetting;

/**
 * What the order-tracking page shows about a found order: the progress steps,
 * the order and delivery status, the courier parcel, the items and the amounts
 * the buyer may see. Every theme's track.show reads them from here so a theme
 * only changes the design. A reseller's customer only ever sees the reseller's
 * prices (Order::showsResellerPricesToBuyer()), so for them the page carries
 * item lines and one total and never the store's breakdown.
 */
final class StorefrontTrackPage
{
    public const STEP_PLACED = 'placed';

    public const STEP_CONFIRMED = 'confirmed';

    public const STEP_ON_THE_WAY = 'on_the_way';

    public const STEP_DELIVERED = 'delivered';

    public const STATE_DONE = 'done';

    public const STATE_CURRENT = 'current';

    public const STATE_TODO = 'todo';

    /**
     * @param  list<array{key: string, label: string, state: string}>  $steps
     * @param  list<array{name: string, variant: ?string, quantity: int, unitPrice: float, lineTotal: float, imageUrl: ?string}>  $items
     * @param  array{provider: ?string, trackingId: ?string, statusLabel: string}|null  $courier
     */
    private function __construct(
        public readonly ?Order $order,
        public readonly string $currency,
        public readonly string $lookupUrl,
        public readonly string $productsUrl,
        public readonly ?string $complaintUrl,
        public readonly ?string $orderDate,
        public readonly ?string $statusLabel,
        public readonly string $deliveryStatus,
        public readonly ?string $deliveryStatusLabel,
        public readonly bool $hasDeliveryUpdate,
        public readonly array $steps,
        public readonly bool $isDelivered,
        public readonly ?string $exceptionMessage,
        public readonly ?array $courier,
        public readonly array $items,
        public readonly bool $showsBreakdown,
        public readonly float $subtotal,
        public readonly float $discount,
        public readonly float $vat,
        public readonly float $shippingFee,
        public readonly float $packagingFee,
        public readonly float $total,
        public readonly float $paid,
        public readonly float $due,
        public readonly ?string $areaLabel,
        public readonly ?string $deliveryTime,
        public readonly ?string $vatLabel,
    ) {}

    public static function make(Company $company, StorefrontSetting $setting, ?Order $order, ?string $previewSlug = null): self
    {
        $lookupUrl = StorefrontUrl::route('track.index', [], $previewSlug);
        $productsUrl = StorefrontUrl::route('products.index', [], $previewSlug);
        $currency = $company->currency ?: 'BDT';

        if (! $order) {
            return new self(
                order: null,
                currency: $currency,
                lookupUrl: $lookupUrl,
                productsUrl: $productsUrl,
                complaintUrl: null,
                orderDate: null,
                statusLabel: null,
                deliveryStatus: CourierBooking::STATUS_NOT_BOOKED,
                deliveryStatusLabel: null,
                hasDeliveryUpdate: false,
                steps: [],
                isDelivered: false,
                exceptionMessage: null,
                courier: null,
                items: [],
                showsBreakdown: false,
                subtotal: 0.0,
                discount: 0.0,
                vat: 0.0,
                shippingFee: 0.0,
                packagingFee: 0.0,
                total: 0.0,
                paid: 0.0,
                due: 0.0,
                areaLabel: null,
                deliveryTime: null,
                vatLabel: null,
            );
        }

        $order->loadMissing(['items.product', 'latestCourierBooking.provider']);

        $booking = $order->latestCourierBooking;
        $deliveryStatus = (string) ($order->delivery_status ?: CourierBooking::STATUS_NOT_BOOKED);
        $reseller = $order->showsResellerPricesToBuyer();
        $total = $reseller ? $order->buyerTotal() : (float) $order->total_amount;
        $paid = (float) $order->paid_amount;
        $isDelivered = self::isDelivered($order, $deliveryStatus);
        $exception = self::exception($order, $deliveryStatus);

        return new self(
            order: $order,
            currency: $currency,
            lookupUrl: $lookupUrl,
            productsUrl: $productsUrl,
            complaintUrl: StorefrontUrl::route('complaints.show', ['order' => $order->order_number], $previewSlug),
            orderDate: $order->order_date?->translatedFormat('d M Y'),
            statusLabel: self::statusLabel($order),
            deliveryStatus: $deliveryStatus,
            deliveryStatusLabel: __(Order::DELIVERY_STATUSES[$deliveryStatus] ?? str($deliveryStatus)->headline()->toString()),
            hasDeliveryUpdate: $deliveryStatus !== CourierBooking::STATUS_NOT_BOOKED || $booking !== null,
            steps: self::steps($order, $deliveryStatus, $isDelivered, $exception !== null),
            isDelivered: $isDelivered,
            exceptionMessage: $exception,
            courier: $booking ? [
                'provider' => $booking->provider?->name,
                'trackingId' => filled($booking->tracking_id) ? (string) $booking->tracking_id : null,
                'statusLabel' => __(CourierBooking::STATUSES[$booking->status] ?? str((string) $booking->status)->headline()->toString()),
            ] : null,
            items: $order->items->map(fn (OrderItem $item): array => [
                'name' => (string) ($item->product?->name ?? __('Product')),
                'variant' => filled($item->variant_label) ? (string) $item->variant_label : null,
                'quantity' => (int) $item->quantity,
                'unitPrice' => $reseller ? $item->buyerUnitPrice() : (float) $item->unit_price,
                'lineTotal' => $reseller ? $item->buyerSubtotal() : (float) $item->subtotal,
                'imageUrl' => $item->product?->image ? CompanyMedia::publicUrl($item->product->image, $company) : null,
            ])->all(),
            showsBreakdown: ! $reseller,
            subtotal: (float) $order->subtotal,
            discount: (float) $order->discount,
            vat: (float) $order->vat,
            shippingFee: (float) $order->shipping_fee,
            packagingFee: (float) $order->packaging_fee,
            total: $total,
            paid: $paid,
            due: max($total - $paid, 0),
            areaLabel: match ($order->shipping_zone) {
                'inside' => __('Inside Dhaka'),
                'outside' => __('Outside Dhaka'),
                default => null,
            },
            deliveryTime: $order->shipping_zone ? $setting->deliveryTimeFor($order->shipping_zone) : null,
            vatLabel: $setting->vatLabel(),
        );
    }

    /** What the customer reads as the order's status (a draft order is waiting for the team's confirmation). */
    private static function statusLabel(Order $order): string
    {
        if ($order->status === Order::STATUS_DRAFT) {
            return __('Awaiting confirmation');
        }

        return __(Order::STATUSES[$order->status] ?? ucfirst((string) $order->status));
    }

    private static function isDelivered(Order $order, string $deliveryStatus): bool
    {
        return $deliveryStatus === CourierBooking::STATUS_DELIVERED
            || $order->status === Order::STATUS_COMPLETED;
    }

    /** The sentence shown when the order did not end in a delivery, or null. */
    private static function exception(Order $order, string $deliveryStatus): ?string
    {
        return match (true) {
            $order->status === Order::STATUS_CANCELLED => __('This order was cancelled.'),
            $order->status === Order::STATUS_REFUNDED => __('This order was refunded.'),
            $order->status === Order::STATUS_RETURNED,
            $deliveryStatus === CourierBooking::STATUS_RETURNED => __('This order was returned.'),
            default => null,
        };
    }

    /**
     * Placed -> confirmed -> on the way -> delivered. A cancelled, returned or
     * refunded order stops after "placed" so the page never promises a delivery.
     *
     * @return list<array{key: string, label: string, state: string}>
     */
    private static function steps(Order $order, string $deliveryStatus, bool $isDelivered, bool $stopped): array
    {
        $reached = match (true) {
            $stopped => 0,
            $isDelivered => 3,
            in_array($deliveryStatus, [
                CourierBooking::STATUS_PICKED_UP,
                CourierBooking::STATUS_IN_TRANSIT,
                CourierBooking::STATUS_PARTIAL_DELIVERED,
            ], true) => 2,
            in_array($order->status, [Order::STATUS_CONFIRMED, Order::STATUS_PROCESSING], true),
            in_array($deliveryStatus, [CourierBooking::STATUS_BOOKING_PENDING, CourierBooking::STATUS_BOOKED], true) => 1,
            default => 0,
        };

        $labels = [
            self::STEP_PLACED => __('Order placed'),
            self::STEP_CONFIRMED => __('Confirmed'),
            self::STEP_ON_THE_WAY => __('On the way'),
            self::STEP_DELIVERED => __('Delivered'),
        ];
        $last = count($labels) - 1;
        $steps = [];

        foreach (array_keys($labels) as $index => $key) {
            $steps[] = [
                'key' => $key,
                'label' => $labels[$key],
                'state' => match (true) {
                    $index < $reached, $index === $reached && $index === $last => self::STATE_DONE,
                    $index === $reached => $stopped ? self::STATE_DONE : self::STATE_CURRENT,
                    default => self::STATE_TODO,
                },
            ];
        }

        return $steps;
    }
}
