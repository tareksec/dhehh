<?php

namespace App\Services\CourierFraud;

use App\Models\CourierProvider;
use App\Services\SteadfastCourierClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Steadfast now publishes an official fraud-check endpoint in its merchant
 * API guide — `GET /fraud_check/score/{phone}` — authenticated with the same
 * Api-Key/Secret-Key as booking, so the courier's booking API keys are all
 * this needs. That replaces the old approach of logging into the merchant
 * web portal with an email/password and reading its fraud page, which broke
 * silently (a wrong password redirected like a right one, and the portal
 * has since moved) — the "fraud checker doesn't work" the owner reported.
 */
class SteadfastFraudClient implements CourierFraudClient
{
    /**
     * After a 401 the keys are left alone for this long. Steadfast locks a
     * key out for 60 minutes after 10 failures in 5 minutes — booking
     * included — and the order form runs this check on every customer
     * selection, so wrong keys must not be retried on each click.
     */
    protected const AUTH_FAILURE_PAUSE_MINUTES = 15;

    public function checkByPhone(string $phone, array $credentials): ?array
    {
        if (blank($credentials['api_key'] ?? null) || blank($credentials['secret_key'] ?? null)) {
            return null;
        }

        $pauseKey = 'steadfast-fraud-auth-failed:'.hash('sha256', $credentials['api_key'].'|'.$credentials['secret_key']);

        if (Cache::has($pauseKey)) {
            return null;
        }

        $provider = (new CourierProvider)->forceFill([
            'driver' => CourierProvider::DRIVER_STEADFAST,
            'credentials' => ['api_key' => $credentials['api_key'], 'secret_key' => $credentials['secret_key']],
            'settings' => array_filter(['base_url' => $credentials['base_url'] ?? null]),
        ]);

        try {
            $response = app(SteadfastCourierClient::class)->fraudCheck($provider, $phone);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if ($response->status() === 401) {
            Cache::put($pauseKey, true, now()->addMinutes(self::AUTH_FAILURE_PAUSE_MINUTES));

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return $this->parseScore($response->json());
    }

    /**
     * The guide describes the answer ("how much of it arrived, how much came
     * back, and what other merchants have reported") but not its field
     * names, so the known spellings are all accepted, at the top level or
     * under `data`. A response with neither a delivered nor a cancelled
     * count is treated as a failed check, never as a clean 0/0 record.
     */
    public function parseScore(mixed $body): ?array
    {
        if (! is_array($body)) {
            return null;
        }

        $data = is_array($body['data'] ?? null) ? $body['data'] + $body : $body;

        $delivered = $this->firstNumber($data, ['total_delivered', 'delivered', 'success', 'success_count', 'delivered_count', 'total_delivered_parcels']);
        $cancelled = $this->firstNumber($data, ['total_cancelled', 'cancelled', 'cancel', 'returned', 'total_returned', 'cancelled_count', 'total_cancelled_parcels']);

        if ($delivered === null && $cancelled === null) {
            return null;
        }

        $delivered ??= 0;
        $cancelled ??= 0;

        // Total is delivered + came back, like the other couriers — a parcel
        // still on the road says nothing yet about the customer.
        $result = [
            'success' => $delivered,
            'cancel' => $cancelled,
            'total' => $delivered + $cancelled,
        ];

        $reports = $this->reportCount($data);

        if ($reports !== null) {
            $result['reports'] = $reports;
        }

        return $result;
    }

    protected function firstNumber(array $data, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (is_numeric($data[$key] ?? null)) {
                return (int) $data[$key];
            }
        }

        return null;
    }

    protected function reportCount(array $data): ?int
    {
        foreach (['total_fraud_reports', 'fraud_report_count', 'report_count', 'reports_count'] as $key) {
            if (is_numeric($data[$key] ?? null)) {
                return (int) $data[$key];
            }
        }

        foreach (['total_fraud_reports', 'fraud_reports', 'reports'] as $key) {
            if (is_array($data[$key] ?? null)) {
                return count($data[$key]);
            }
        }

        return null;
    }
}
