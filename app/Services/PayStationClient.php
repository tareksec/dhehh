<?php

namespace App\Services;

use App\Contracts\PaymentGatewayClient;
use App\Models\StorefrontSetting;
use App\Support\OutboundUrlGuard;
use RuntimeException;

/**
 * Client for the PayStation (paystation.com.bd) hosted-checkout API.
 *
 * Endpoint shapes below were verified this session against the actual
 * source of two independent open-source integrations (the `xenon/paystation`
 * Composer package and a Laravel example app built on it) — paystation.com.bd
 * blocked automated fetches of its own documentation page. Two things
 * remain genuinely unconfirmed and are marked with TODOs: whether the
 * request body must be JSON (assumed here, matching Laravel's Http default)
 * or form-encoded, and whether a `callback_url` that already carries its
 * own `?expires=&signature=` query string survives PayStation's redirect
 * intact (`&invoice_number=...` appended) or gets corrupted. Both must be
 * confirmed against a real PayStation sandbox account before relying on
 * this in production.
 *
 * - POST {base}/grant-token          headers: merchantId, password -> {token}
 * - POST {base}/create-payment       header: token -> {status, payment_url}
 * - POST {base}/retrive-transaction  header: token -> {trx_status, payment_amount, trx_id, payment_method}
 *
 * Unlike ZiniPay, PayStation only accepts ONE callback URL (no separate
 * success/cancel/webhook URLs) and never echoes back an invoice/payment
 * identifier — the merchant must invent and remember its own invoice_number.
 */
class PayStationClient implements PaymentGatewayClient
{
    public const DEFAULT_BASE_URL = 'https://api.paystation.com.bd';

    public static function isConfigured(StorefrontSetting $setting): bool
    {
        return (bool) $setting->online_payment_enabled
            && filled(data_get($setting->payment_credentials, 'paystation_merchant_id'))
            && filled(data_get($setting->payment_credentials, 'paystation_password'));
    }

    public function createPayment(
        StorefrontSetting $setting,
        float $amount,
        string $customerName,
        ?string $customerEmail,
        string $customerPhone,
        string $customerAddress,
        string $redirectUrl,
        string $cancelUrl,
        string $webhookUrl,
        string $merchantReference,
        array $metadata = [],
    ): array {
        // PayStation has no separate cancel/webhook URL — $cancelUrl and
        // $webhookUrl are unused; $redirectUrl doubles as the one
        // callback_url for both success and failure (verifyPayment always
        // re-confirms the real status server-side before finalizing).
        $response = OutboundUrlGuard::http($this->baseUrl($setting), timeout: 30)
            ->withHeaders(['token' => $this->token($setting)])
            ->post($this->baseUrl($setting).'/create-payment', [
                // TODO: verify JSON vs Http::asForm() against a real sandbox call.
                'invoice_number' => $merchantReference,
                'currency' => 'BDT',
                'payment_amount' => round($amount, 2),
                'reference' => (string) ($metadata['purpose'] ?? 'storefront_checkout'),
                'cust_name' => $customerName,
                'cust_phone' => $customerPhone,
                'cust_email' => $customerEmail ?: 'noreply@example.com',
                'cust_address' => $customerAddress,
                'callback_url' => $redirectUrl,
            ]);

        $paymentUrl = (string) $response->json('payment_url', '');

        if ($response->failed() || (string) $response->json('status') !== 'success' || $paymentUrl === '') {
            throw new RuntimeException('PayStation payment could not be created: '.((string) $response->json('message', 'HTTP '.$response->status())));
        }

        // PayStation never returns its own invoice/payment id — echo back
        // the reference we generated and sent, since that's what
        // retrive-transaction needs later.
        return [
            'payment_url' => $paymentUrl,
            'invoice_id' => $merchantReference,
        ];
    }

    public function verifyPayment(StorefrontSetting $setting, string $invoiceId, ?string $transactionId = null): array
    {
        if (blank($transactionId)) {
            // TODO: confirm in sandbox whether invoice_number alone resolves
            // retrive-transaction, or trx_id is genuinely required as the
            // reference SDK's required-params list implies.
            throw new RuntimeException('PayStation verification requires a transaction reference (trx_id) which was not provided.');
        }

        $response = OutboundUrlGuard::http($this->baseUrl($setting), timeout: 30)
            ->withHeaders(['token' => $this->token($setting)])
            ->post($this->baseUrl($setting).'/retrive-transaction', [
                'invoice_number' => $invoiceId,
                'trx_id' => $transactionId,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('PayStation verification failed (HTTP '.$response->status().').');
        }

        $raw = (array) $response->json();

        // Normalize PayStation's own field names to the shared shape
        // StorefrontPaymentService::verifyAndFinalize() expects (uppercase
        // COMPLETED/FAILED 'status', 'amount', 'transaction_id') — it reads
        // these keys gateway-agnostically, so all three must be mapped here,
        // not just status.
        $raw['status'] = match (strtoupper((string) ($raw['trx_status'] ?? ''))) {
            'SUCCESS' => 'COMPLETED',
            'FAILED' => 'FAILED',
            default => strtoupper((string) ($raw['trx_status'] ?? '')),
        };
        $raw['amount'] = $raw['payment_amount'] ?? null;
        $raw['transaction_id'] = $raw['trx_id'] ?? null;

        return $raw;
    }

    /**
     * PayStation's reference SDK fetches a fresh token for every single
     * operation rather than caching one — mirrored here to avoid any token
     * expiry/caching bugs; the extra round-trip is a low-frequency,
     * low-volume cost (one hosted checkout per customer order).
     */
    protected function token(StorefrontSetting $setting): string
    {
        $response = OutboundUrlGuard::http($this->baseUrl($setting), timeout: 30)
            ->withHeaders([
                'merchantId' => $this->credential($setting, 'paystation_merchant_id'),
                'password' => $this->credential($setting, 'paystation_password'),
            ])
            ->post($this->baseUrl($setting).'/grant-token');

        $token = (string) $response->json('token', '');

        if ($response->failed() || $token === '') {
            throw new RuntimeException('PayStation authentication failed: '.((string) $response->json('message', 'HTTP '.$response->status())));
        }

        return $token;
    }

    protected function credential(StorefrontSetting $setting, string $key): string
    {
        $value = (string) data_get($setting->payment_credentials, $key, '');

        if ($value === '') {
            throw new RuntimeException("PayStation {$key} is not configured in the storefront settings.");
        }

        return $value;
    }

    protected function baseUrl(StorefrontSetting $setting): string
    {
        return rtrim((string) data_get($setting->payment_credentials, 'paystation_base_url') ?: self::DEFAULT_BASE_URL, '/');
    }
}
