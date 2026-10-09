<?php

namespace App\Support\StorefrontApi;

use App\Models\Order;
use App\Models\StorefrontPayment;

/**
 * Unguessable references the headless website carries in URLs, derived from the
 * app key (nothing extra is stored): a payment `ref` lets the customer's return
 * from the payment gateway finalize exactly that payment, and an order `token`
 * lets the success page read exactly that order. Order numbers and payment ids
 * are sequential, so neither works on its own.
 */
final class Signing
{
    public static function paymentRef(StorefrontPayment $payment): string
    {
        return hash_hmac('sha256', 'checkout-payment:'.$payment->getKey().':'.$payment->company_id, (string) config('app.key'));
    }

    public static function orderToken(Order $order): string
    {
        return hash_hmac('sha256', 'storefront-order:'.$order->getKey().':'.$order->company_id, (string) config('app.key'));
    }

    public static function matches(string $expected, mixed $given): bool
    {
        return is_string($given) && $given !== '' && hash_equals($expected, $given);
    }
}
