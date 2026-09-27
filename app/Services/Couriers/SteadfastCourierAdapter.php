<?php

namespace App\Services\Couriers;

use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Models\Order;
use App\Services\CourierService;
use App\Services\SteadfastCourierClient;
use Illuminate\Http\Request;

class SteadfastCourierAdapter extends AbstractCourierAdapter
{
    public function driver(): string
    {
        return CourierProvider::DRIVER_STEADFAST;
    }

    public function create(Order $order, CourierProvider $provider, array $data = []): CourierBooking
    {
        return app(CourierService::class)->createSteadfastBooking($order, $provider, $data);
    }

    public function sync(CourierBooking $booking): CourierBooking
    {
        return app(CourierService::class)->syncSteadfastStatus($booking);
    }

    public function balance(CourierProvider $provider): ?array
    {
        return app(SteadfastCourierClient::class)->balance($provider);
    }

    public function returns(CourierBooking $booking, array $data = []): array
    {
        return app(CourierService::class)->requestReturn($booking, $data);
    }

    public function paymentHistory(CourierProvider $provider, array $filters = []): array
    {
        return app(SteadfastCourierClient::class)->payments($provider, $filters);
    }

    /**
     * Events from Steadfast's webhook panel that carry a parcel status. The
     * others (consignment_update, return_list_accepted, payment_request,
     * cancel_request, return_request, pickup_request, user_update) are about
     * the account or a request, not where the parcel is.
     */
    public const STATUS_EVENTS = ['delivery_status', 'tracking_update'];

    public function webhookStatus(array $payload): ?string
    {
        $status = $payload['delivery_status'] ?? $payload['status'] ?? null;

        return $status ? app(CourierService::class)->normalizeSteadfastStatus((string) $status) : null;
    }

    public function webhookSkipReason(array $payload): ?string
    {
        $event = $payload['notification_type'] ?? null;

        if (filled($event) && ! in_array($event, self::STATUS_EVENTS, true)) {
            return "Steadfast {$event} event — no delivery status to update.";
        }

        $status = $payload['delivery_status'] ?? $payload['status'] ?? null;

        if ($event === 'tracking_update' && blank($status)) {
            return 'Steadfast tracking step: '.str((string) ($payload['tracking_message'] ?? '-'))->limit(200);
        }

        if (filled($status) && $this->webhookStatus($payload) === null) {
            return "Steadfast reported status \"{$status}\" — current status kept.";
        }

        return null;
    }

    /**
     * Steadfast's webhook (merchant panel → Webhook) sends the auth token
     * two ways on every call: `Authorization: Bearer <token>`, and an
     * `X-Signature` header — HMAC-SHA256 of the raw body, hex, keyed with
     * the same token. The bearer token must match; the signature is checked
     * whenever it's present (older panel setups sent only the bearer).
     */
    public function verifyWebhookRequest(CourierProvider $provider, Request $request): bool
    {
        if (! $this->verifyWebhook($provider, $request->getContent(), $request->header($provider->settings['signature_header'] ?? $this->signatureHeaderDefault()))) {
            return false;
        }

        $signature = $request->header('X-Signature');
        $token = (string) ($provider->credentials['webhook_secret'] ?? '');

        if (blank($signature) || $token === '') {
            return true;
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $token), strtolower((string) $signature));
    }

    /**
     * Bearer-token half of the check: a constant-time comparison of the
     * Authorization header against the stored token.
     */
    public function verifyWebhook(CourierProvider $provider, string $payload, ?string $signature): bool
    {
        if (($provider->settings['webhook_signature_required'] ?? true) === false) {
            return true;
        }

        $token = $provider->credentials['webhook_secret'] ?? null;
        if (blank($token) || blank($signature)) {
            return false;
        }

        $received = str_starts_with($signature, 'Bearer ') ? substr($signature, 7) : $signature;

        return hash_equals((string) $token, $received);
    }

    public function signatureHeaderDefault(): string
    {
        return 'Authorization';
    }
}
