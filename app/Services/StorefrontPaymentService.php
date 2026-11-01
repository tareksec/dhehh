<?php

namespace App\Services;

use App\Models\Order;
use App\Models\StorefrontPayment;
use App\Models\StorefrontSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $invoiceId = $this->invoiceToVerify($payment, $invoiceId);

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
                if (! $this->markCompleted($payment, $invoiceId, $verified)) {
                    return null;
                }
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

    /**
     * Which gateway invoice is checked for this payment. The invoice reference
     * stored when the payment was created is the only one that counts: the
     * webhook endpoints are public, so an invoice id sent in their body is
     * caller-controlled. Without this, a real, paid invoice of the same amount
     * could be replayed against any other pending payment to get it (and its
     * order) marked as paid. A caller-supplied id is used only for a payment
     * that has no stored reference at all.
     */
    protected function invoiceToVerify(StorefrontPayment $payment, ?string $supplied): string
    {
        $stored = trim((string) $payment->invoice_id);
        $supplied = trim((string) $supplied);

        if ($stored !== '') {
            if ($supplied !== '' && ! hash_equals($stored, $supplied)) {
                Log::warning('A payment callback named a different invoice than the one stored for the payment; the stored invoice is verified instead.', [
                    'payment' => $payment->getKey(),
                ]);
            }

            return $stored;
        }

        return $supplied;
    }

    /**
     * Records the verified payment as completed, once. The row is locked so two
     * simultaneous callbacks cannot both complete it, and a gateway invoice or
     * transaction that already completed another payment is refused (a paid
     * invoice settles one payment only).
     *
     * @param  array<string, mixed>  $verified
     */
    protected function markCompleted(StorefrontPayment $payment, string $invoiceId, array $verified): bool
    {
        $transactionId = filled($verified['transaction_id'] ?? null) ? (string) $verified['transaction_id'] : null;

        return DB::transaction(function () use ($payment, $invoiceId, $verified, $transactionId): bool {
            $locked = StorefrontPayment::withoutGlobalScopes()->lockForUpdate()->find($payment->getKey());

            if (! $locked) {
                return false;
            }

            if ($locked->status === StorefrontPayment::STATUS_COMPLETED) {
                return true;
            }

            $alreadyUsed = StorefrontPayment::withoutGlobalScopes()
                ->where('gateway', $locked->gateway)
                ->where('status', StorefrontPayment::STATUS_COMPLETED)
                ->whereKeyNot($locked->getKey())
                ->where(function ($query) use ($invoiceId, $transactionId): void {
                    $query->where('invoice_id', $invoiceId);

                    if ($transactionId !== null) {
                        $query->orWhere('transaction_id', $transactionId);
                    }
                })
                ->exists();

            if ($alreadyUsed) {
                Log::warning('A verified gateway payment was already used to settle another payment; this one stays unpaid.', [
                    'payment' => $locked->getKey(),
                    'gateway' => $locked->gateway,
                ]);

                return false;
            }

            $locked->update([
                'status' => StorefrontPayment::STATUS_COMPLETED,
                'invoice_id' => $invoiceId,
                'payment_method' => $verified['payment_method'] ?? null,
                'transaction_id' => $transactionId,
                'payload' => $verified,
            ]);

            $payment->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }
}
