<?php

namespace App\Services;

use App\Models\CourierProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Endpoint paths follow Steadfast's own merchant-portal "API guide"
 * (portal.packzy.com, September 2026), which lists all 18 endpoints under
 * the same base URL. Earlier paths were cross-checked against open-source
 * wrappers and confirmed live (get_balance / payments / payments/{id} on
 * 2026-08-13/14 — see `CourierPaymentHistory` for the response shape); the
 * guide agrees with every one of them.
 *
 * Two rules from the guide shape request(): a 401 must never be retried in
 * a loop (10 failures in 5 minutes from one address + key locks the key out
 * with 429 for 60 minutes, correct keys included), and a 5xx is safe to
 * retry because duplicate invoices are refused, so a retry can't double-book.
 */
class SteadfastCourierClient
{
    public const DEFAULT_BASE_URL = 'https://portal.packzy.com/api/v1';

    public function createOrder(CourierProvider $provider, array $payload): array
    {
        return $this->request($provider)
            ->post($this->baseUrl($provider).'/create_order', $payload)
            ->throw()
            ->json();
    }

    /**
     * The one call that needs no credentials — if it answers, the base URL
     * and network are fine and any later failure is the keys or the request.
     */
    public function ping(CourierProvider $provider): bool
    {
        try {
            return Http::acceptJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->get($this->baseUrl($provider).'/ping')
                ->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * A customer's delivery record across Steadfast: how much arrived, how
     * much came back, and what other merchants have reported. Returns the
     * raw response — SteadfastFraudClient interprets it. Not ->throw()n so
     * the caller can tell a 401 (bad keys, stop asking) from other failures.
     */
    public function fraudCheck(CourierProvider $provider, string $phone): Response
    {
        return $this->request($provider)
            ->get($this->baseUrl($provider).'/fraud_check/score/'.rawurlencode($phone));
    }

    public function statusByTrackingCode(CourierProvider $provider, string $trackingCode): array
    {
        return $this->request($provider)
            ->get($this->baseUrl($provider).'/status_by_trackingcode/'.rawurlencode($trackingCode))
            ->throw()
            ->json();
    }

    public function statusByInvoice(CourierProvider $provider, string $invoice): array
    {
        return $this->request($provider)
            ->get($this->baseUrl($provider).'/status_by_invoice/'.rawurlencode($invoice))
            ->throw()
            ->json();
    }

    public function balance(CourierProvider $provider): array
    {
        return $this->request($provider)
            ->get($this->baseUrl($provider).'/get_balance')
            ->throw()
            ->json();
    }

    public function createReturnRequest(CourierProvider $provider, array $payload): array
    {
        return $this->request($provider)
            ->post($this->baseUrl($provider).'/create_return_request', $payload)
            ->throw()
            ->json();
    }

    public function returnStatus(CourierProvider $provider, int|string $returnId): array
    {
        return $this->request($provider)
            ->get($this->baseUrl($provider).'/get_return_request/'.$returnId)
            ->throw()
            ->json();
    }

    public function returnRequests(CourierProvider $provider, array $query = []): array
    {
        return $this->request($provider)
            ->get($this->baseUrl($provider).'/get_return_requests', $query)
            ->throw()
            ->json();
    }

    public function payments(CourierProvider $provider, array $query = []): array
    {
        return $this->request($provider)
            ->get($this->baseUrl($provider).'/payments', $query)
            ->throw()
            ->json();
    }

    /**
     * A single payment/settlement's detail, including the consignments
     * cleared under it — mirrors the Steadfast merchant app's "Payment
     * Details" drill-down screen.
     */
    public function payment(CourierProvider $provider, int|string $paymentId): array
    {
        return $this->request($provider)
            ->get($this->baseUrl($provider).'/payments/'.$paymentId)
            ->throw()
            ->json();
    }

    protected function request(CourierProvider $provider): PendingRequest
    {
        $credentials = $provider->credentials ?? [];
        $apiKey = $credentials['api_key'] ?? null;
        $secretKey = $credentials['secret_key'] ?? null;

        if (blank($apiKey) || blank($secretKey)) {
            throw ValidationException::withMessages([
                'credentials' => 'Steadfast API key and secret key are required.',
            ]);
        }

        return Http::acceptJson()
            ->asJson()
            ->timeout(20)
            ->connectTimeout(5)
            ->retry(3, 500, fn (Throwable $exception): bool => $this->shouldRetry($exception), throw: false)
            ->withHeaders([
                'Api-Key' => $apiKey,
                'Secret-Key' => $secretKey,
                'Content-Type' => 'application/json',
            ]);
    }

    /**
     * Retry only what can succeed on a second try: dropped connections and
     * Steadfast's own 5xx. Never a 4xx — a 401/403/422 answers the same
     * every time, and repeated 401s lock the API key out for an hour.
     */
    protected function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException && $exception->response->serverError();
    }

    protected function baseUrl(CourierProvider $provider): string
    {
        return rtrim((string) (($provider->settings ?? [])['base_url'] ?? self::DEFAULT_BASE_URL), '/');
    }
}
