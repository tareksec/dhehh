<?php

namespace App\Support;

use App\Models\Order;
use App\Models\StorefrontPayment;
use App\Models\StorefrontSetting;
use Illuminate\Support\Facades\URL;

/**
 * What the order-success page shows about the placed order: the amounts the
 * order really holds (subtotal, VAT, delivery, total, paid, due), the payment
 * the customer made at checkout and the links on the page. Every theme's
 * checkout.success reads them from here so a theme only changes the design.
 */
final class StorefrontOrderSuccessPage
{
    private function __construct(
        public readonly Order $order,
        public readonly ?StorefrontPayment $payment,
        public readonly bool $isManualPayment,
        public readonly ?string $manualPaymentLabel,
        public readonly bool $isFullyPaidOnline,
        public readonly float $subtotal,
        public readonly float $discount,
        public readonly float $vat,
        public readonly float $shippingFee,
        public readonly float $packagingFee,
        public readonly float $total,
        public readonly float $paid,
        public readonly float $due,
        public readonly string $trackUrl,
        public readonly string $orderHistoryUrl,
        public readonly bool $showOrderHistoryLink,
        public readonly string $complaintUrl,
        public readonly string $productsUrl,
    ) {}

    public static function make(Order $order, StorefrontSetting $setting, ?string $previewSlug = null): self
    {
        $payment = $order->storefrontPayments()->latest()->first();

        // 'manual_bkash'/'manual_nagad' are legacy literal gateway values from
        // before payment methods became dashboard-managed (StorefrontPaymentMethod),
        // kept so historical orders still display correctly. New manual payments
        // use the generic 'manual' gateway with the channel's own name.
        $isManualPayment = $payment !== null && in_array($payment->gateway, ['manual', 'manual_bkash', 'manual_nagad'], true);

        $total = (float) $order->total_amount;
        $paid = (float) $order->paid_amount;

        return new self(
            order: $order,
            payment: $payment,
            isManualPayment: $isManualPayment,
            manualPaymentLabel: match (true) {
                ! $isManualPayment => null,
                $payment->gateway === 'manual_bkash' => 'bKash',
                $payment->gateway === 'manual_nagad' => 'Nagad',
                default => $payment->paymentMethod?->name ?? __('Manual'),
            },
            // The "pay the full order online" checkout option reuses the same purpose
            // as a partial advance, so an amount that covers the whole order is what
            // tells them apart.
            isFullyPaidOnline: $payment !== null && ! $isManualPayment && (float) $payment->amount >= $total - 0.01,
            subtotal: (float) $order->subtotal,
            discount: (float) $order->discount,
            vat: (float) $order->vat,
            shippingFee: (float) $order->shipping_fee,
            packagingFee: (float) $order->packaging_fee,
            total: $total,
            paid: $paid,
            due: max($total - $paid, 0),
            trackUrl: $previewSlug !== null
                ? route('storefront.preview.track.show', ['company' => $previewSlug, 'orderNo' => $order->order_number, 'phone' => $order->customer?->phone])
                : URL::temporarySignedRoute('storefront.track.show', now()->addDays(7), ['orderNo' => $order->order_number]),
            orderHistoryUrl: StorefrontUrl::route('account.orders', [], $previewSlug),
            showOrderHistoryLink: $previewSlug !== null || (bool) ($setting->customer_accounts_enabled ?? true),
            complaintUrl: StorefrontUrl::route('complaints.show', ['order' => $order->order_number], $previewSlug),
            productsUrl: StorefrontUrl::route('products.index', [], $previewSlug),
        );
    }

    /** What the customer reads as the order's status (a draft order is waiting for the team's confirmation). */
    public function statusLabel(): string
    {
        return match ($this->order->status) {
            Order::STATUS_DRAFT => __('Awaiting confirmation'),
            default => __(ucfirst((string) $this->order->status)),
        };
    }
}
