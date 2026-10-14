<?php

namespace App\Services;

use App\Models\Order;
use App\Models\StorefrontPayment;
use App\Models\StorefrontSetting;
use RuntimeException;

class StorefrontPaymentService
{
    public function __construct(
        protected PaymentGatewayResolver $gateways,
        protected StorefrontOrderPlacementService $orders,
    ) {}

    public function verifyAndFinalize(
        StorefrontPayment $payment,
        StorefrontSetting $setting,
        ?string $invoiceId = null,
        ?string $transactionId = null,
    ): ?Order {
        if ($payment->order_id && $payment->status === StorefrontPayment::STATUS_COMPLETED) {
            // A reseller's online advance that completed but was not entered yet (a crash in between) is entered now.
            app(ResellerOwnCourierService::class)->recordOnlinePayment($payment);

            return $payment->order()->withoutGlobalScopes()->first();
        }

        $invoiceId = trim((string) ($invoiceId ?: $payment->invoice_id));

        if ($invoiceId === '') {
            throw new RuntimeException('The payment invoice reference is missing.');
        }

        if ($payment->status !== StorefrontPayment::STATUS_COMPLETED) {
            // Verify with the gateway this payment was actually created
            // with ($payment->gateway) — never the setting's *currently*
            // selected gateway, so an admin switching gateways mid-flight
            // can't break a payment already in progress.
            $verified = $this->gateways->byKey($payment->gateway)->verifyPayment($setting, $invoiceId, $transactionId);
            $status = strtoupper((string) ($verified['status'] ?? ''));
            $amountMatches = abs(((float) ($verified['amount'] ?? 0)) - (float) $payment->amount) < 0.01;

            if ($status === 'COMPLETED' && $amountMatches) {
                $payment->update([
                    'status' => StorefrontPayment::STATUS_COMPLETED,
                    'invoice_id' => $invoiceId,
                    'payment_method' => $verified['payment_method'] ?? null,
                    'transaction_id' => $verified['transaction_id'] ?? null,
                    'payload' => $verified,
                ]);
            } elseif ($status === 'FAILED') {
                $payment->update(['status' => StorefrontPayment::STATUS_FAILED, 'payload' => $verified]);

                return null;
            } else {
                return null;
            }
        }

        // A reseller's own-courier advance paid online: enter it in the order's Payments History (once).
        if ($payment->purpose === StorefrontPayment::PURPOSE_RESELLER_ADVANCE) {
            app(ResellerOwnCourierService::class)->recordOnlinePayment($payment->fresh());
        }

        if (in_array($payment->purpose, [
            StorefrontPayment::PURPOSE_CHECKOUT_ADVANCE,
            StorefrontPayment::PURPOSE_NEW_CUSTOMER_DELIVERY,
        ], true)) {
            return $this->orders->placePaidCheckout($payment->fresh());
        }

        return $payment->order()->withoutGlobalScopes()->first();
    }
}
