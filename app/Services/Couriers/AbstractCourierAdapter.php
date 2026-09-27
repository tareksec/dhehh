<?php

namespace App\Services\Couriers;

use App\Contracts\CourierProviderInterface;
use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Services\CourierService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

abstract class AbstractCourierAdapter implements CourierProviderInterface
{
    public function cancel(CourierBooking $booking): CourierBooking
    {
        return app(CourierService::class)->updateStatus(
            $booking,
            CourierBooking::STATUS_CANCELLED,
            'Courier booking cancelled from ERP.',
        );
    }

    public function trackingUrl(CourierBooking $booking): ?string
    {
        $template = $booking->provider?->settings['tracking_url'] ?? null;

        return $template && $booking->tracking_id
            ? str_replace('{tracking_id}', rawurlencode($booking->tracking_id), $template)
            : null;
    }

    public function labelUrl(CourierBooking $booking): ?string
    {
        $template = $booking->provider?->settings['label_url'] ?? null;

        return $template
            ? str_replace(['{tracking_id}', '{reference}'], [rawurlencode((string) $booking->tracking_id), rawurlencode((string) $booking->provider_reference)], $template)
            : null;
    }

    public function balance(CourierProvider $provider): ?array
    {
        return null;
    }

    public function returns(CourierBooking $booking, array $data = []): array
    {
        $this->unsupported('return request');
    }

    public function paymentHistory(CourierProvider $provider, array $filters = []): array
    {
        $this->unsupported('payment history');
    }

    public function verifyWebhook(CourierProvider $provider, string $payload, ?string $signature): bool
    {
        // Some couriers' real webhook delivery doesn't sign requests at all
        // (confirm this per-provider once live webhooks are observed).
        // Providers can opt out of signature enforcement via this setting
        // rather than needing a code change once that's confirmed.
        if (($provider->settings['webhook_signature_required'] ?? true) === false) {
            return true;
        }

        $secret = $provider->credentials['webhook_secret'] ?? null;

        return filled($secret) && filled($signature)
            && hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    public function signatureHeaderDefault(): string
    {
        return 'X-Courier-Signature';
    }

    public function verifyWebhookRequest(CourierProvider $provider, Request $request): bool
    {
        $header = (string) ($provider->settings['signature_header'] ?? $this->signatureHeaderDefault());

        return $this->verifyWebhook($provider, $request->getContent(), $request->header($header));
    }

    public function webhookSkipReason(array $payload): ?string
    {
        return null;
    }

    protected function unsupported(string $operation): never
    {
        throw ValidationException::withMessages(['courier' => "The {$operation} operation is not supported by this courier provider."]);
    }
}
