<?php

namespace App\Services;

use App\Contracts\PaymentGatewayClient;
use App\Models\StorefrontSetting;
use App\Support\OutboundUrlGuard;
use RuntimeException;

/**
 * Minimal client for the ZiniPay hosted-checkout API.
 *
 * API reference (zinipay.com/docs):
 * - POST {base}/v1/payment/create  header "zini-api-key" -> {status, message, payment_url}
 * - POST {base}/v1/payment/verify  {invoice_id} -> {amount, invoice_id, payment_method, transaction_id, status}
 */
class ZiniPayClient implements PaymentGatewayClient
{
    public const DEFAULT_BASE_URL = 'https://api.zinipay.com';

    public static function isConfigured(StorefrontSetting $setting): bool
    {
        return (bool) $setting->online_payment_enabled
            && filled(data_get($setting->payment_credentials, 'zinipay_api_key'));
    }

    /**
     * Create a hosted invoice and return the payment URL and invoice id.
     *
     * @return array{payment_url: string, invoice_id: ?string}
     */
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
        // ZiniPay derives its own invoice id from the returned payment_url
        // and needs no phone/address on the create call — $customerPhone,
        // $customerAddress, and $merchantReference exist only because
        // PayStation (the other PaymentGatewayClient implementation) needs
        // them; ignored here to keep this interface shared.
        $response = OutboundUrlGuard::http($this->baseUrl($setting), timeout: 30)
            ->withHeaders(['zini-api-key' => $this->apiKey($setting)])
            ->post($this->baseUrl($setting).'/v1/payment/create', [
                'cus_name' => $customerName,
                'cus_email' => $customerEmail ?: 'noreply@example.com',
                'amount' => round($amount, 2),
                'redirect_url' => $redirectUrl,
                'cancel_url' => $cancelUrl,
                'webhook_url' => $webhookUrl,
                'metadata' => $metadata,
            ]);

        $paymentUrl = (string) $response->json('payment_url', '');

        if ($response->failed() || $paymentUrl === '') {
            throw new RuntimeException('ZiniPay payment could not be created: '.((string) $response->json('message', 'HTTP '.$response->status())));
        }

        return [
            'payment_url' => $paymentUrl,
            'invoice_id' => $this->invoiceIdFromUrl($paymentUrl),
        ];
    }

    /**
     * Server-side verification of an invoice. Returns the raw verify payload.
     */
    public function verifyPayment(StorefrontSetting $setting, string $invoiceId, ?string $transactionId = null): array
    {
        // $transactionId is unused — ZiniPay's verify call only needs the
        // invoice id; PayStation's retrive-transaction needs both.
        $response = OutboundUrlGuard::http($this->baseUrl($setting), timeout: 30)
            ->withHeaders(['zini-api-key' => $this->apiKey($setting)])
            ->post($this->baseUrl($setting).'/v1/payment/verify', [
                'invoice_id' => $invoiceId,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('ZiniPay verification failed (HTTP '.$response->status().').');
        }

        return (array) $response->json();
    }

    protected function apiKey(StorefrontSetting $setting): string
    {
        $key = (string) data_get($setting->payment_credentials, 'zinipay_api_key', '');

        if ($key === '') {
            throw new RuntimeException('ZiniPay API key is not configured in the storefront settings.');
        }

        return $key;
    }

    protected function baseUrl(StorefrontSetting $setting): string
    {
        return rtrim((string) data_get($setting->payment_credentials, 'zinipay_base_url') ?: self::DEFAULT_BASE_URL, '/');
    }

    protected function invoiceIdFromUrl(string $paymentUrl): ?string
    {
        // Hosted payment URLs end with the invoice reference; keep it when present.
        $path = parse_url($paymentUrl, PHP_URL_PATH) ?: '';
        $segment = basename($path);

        return $segment !== '' && $segment !== '/' ? $segment : null;
    }
}
