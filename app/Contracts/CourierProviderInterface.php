<?php

namespace App\Contracts;

use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Models\Order;
use Illuminate\Http\Request;

interface CourierProviderInterface
{
    public function driver(): string;

    public function create(Order $order, CourierProvider $provider, array $data = []): CourierBooking;

    public function sync(CourierBooking $booking): CourierBooking;

    public function cancel(CourierBooking $booking): CourierBooking;

    public function trackingUrl(CourierBooking $booking): ?string;

    public function labelUrl(CourierBooking $booking): ?string;

    public function balance(CourierProvider $provider): ?array;

    /**
     * Request a return/reverse pickup for a booking. Returns the raw
     * provider response array (e.g. an assigned return reference/status).
     * Providers without a return API should throw via unsupported().
     */
    public function returns(CourierBooking $booking, array $data = []): array;

    /**
     * Fetch payment/settlement history for a provider. Returns the raw
     * provider response array. Providers without a payment API should
     * throw via unsupported().
     */
    public function paymentHistory(CourierProvider $provider, array $filters = []): array;

    public function verifyWebhook(CourierProvider $provider, string $payload, ?string $signature): bool;

    /**
     * The HTTP header a webhook's signature/token is read from when the
     * provider row hasn't set settings.signature_header explicitly —
     * couriers that don't sign payloads at all (e.g. a static bearer
     * token) override this to point at the header they actually use.
     */
    public function signatureHeaderDefault(): string;

    /**
     * Authenticates an incoming webhook request as a whole. The default
     * reads the signature header and defers to verifyWebhook(); couriers
     * that send more than one proof (e.g. a bearer token plus an HMAC
     * signature) override this.
     */
    public function verifyWebhookRequest(CourierProvider $provider, Request $request): bool;

    public function webhookStatus(array $payload): ?string;

    /**
     * Why a verified webhook needs no status change (an account or payout
     * event, or a status the courier itself can't name) — null when it
     * should be processed as a delivery status update.
     */
    public function webhookSkipReason(array $payload): ?string;
}
