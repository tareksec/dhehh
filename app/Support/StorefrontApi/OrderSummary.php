<?php

namespace App\Support\StorefrontApi;

use App\Models\Company;
use App\Models\Order;
use App\Models\StorefrontPayment;
use App\Models\StorefrontSetting;

/** The customer-facing order summary the headless success page shows (same facts as the Blade success page). */
final class OrderSummary
{
    /** @return array<string, mixed> */
    public static function make(Order $order, Company $company, StorefrontSetting $setting): array
    {
        $order->loadMissing(['items.product', 'customer', 'storefrontPayments']);
        $payment = $order->storefrontPayments->sortByDesc('id')->first();

        return [
            'order_number' => $order->order_number,
            'token' => Signing::orderToken($order),
            'status' => $order->status,
            'created_at' => $order->created_at?->toIso8601String(),
            'customer' => [
                'name' => $order->customer?->name,
                'phone' => $order->customer?->phone,
                'email' => $order->customer?->email,
                'address' => $order->customer?->address,
            ],
            'delivery_area' => $order->shipping_zone,
            'items' => $order->items->map(fn ($item): array => [
                'name' => $item->product?->name,
                'variant_label' => $item->variant_label,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal' => round((float) $item->unit_price * (int) $item->quantity, 2),
                'image_url' => Media::url($item->product?->image, $company),
            ])->values()->all(),
            'shipping_fee' => (float) $order->shipping_fee,
            'vat' => (float) $order->vat,
            'total_amount' => (float) $order->total_amount,
            'paid_amount' => (float) $order->paid_amount,
            'due_amount' => (float) $order->due_amount,
            'payment' => $payment ? [
                'kind' => $payment->gateway === 'manual' ? 'manual' : 'online',
                'amount' => (float) $payment->amount,
                'status' => $payment->status,
                'paid_in_full' => $payment->status === StorefrontPayment::STATUS_COMPLETED
                    && (float) $payment->amount >= (float) $order->total_amount - 0.01,
            ] : null,
            'whatsapp_group' => ['url' => $setting->whatsappGroupUrl(), 'message' => $setting->whatsappGroupMessage()],
        ];
    }
}
