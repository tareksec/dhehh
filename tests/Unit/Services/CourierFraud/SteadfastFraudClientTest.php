<?php

namespace Tests\Unit\Services\CourierFraud;

use App\Services\CourierFraud\SteadfastFraudClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SteadfastFraudClientTest extends TestCase
{
    protected const KEYS = ['api_key' => 'test-api-key', 'secret_key' => 'test-secret-key'];

    public function test_it_returns_null_without_api_keys_and_makes_no_request(): void
    {
        Http::fake();

        $this->assertNull((new SteadfastFraudClient)->checkByPhone('01712345678', []));
        $this->assertNull((new SteadfastFraudClient)->checkByPhone('01712345678', ['username' => 'owner@example.com', 'password' => 'secret']));

        Http::assertNothingSent();
    }

    public function test_it_calls_the_official_fraud_check_endpoint_with_the_api_keys(): void
    {
        Http::fake([
            'portal.packzy.com/api/v1/fraud_check/score/01712345678' => Http::response([
                'total_parcels' => 12,
                'total_delivered' => 9,
                'total_cancelled' => 3,
            ]),
        ]);

        $result = (new SteadfastFraudClient)->checkByPhone('01712345678', self::KEYS);

        $this->assertSame(['success' => 9, 'cancel' => 3, 'total' => 12], $result);

        Http::assertSent(fn ($request) => $request->hasHeader('Api-Key', 'test-api-key')
            && $request->hasHeader('Secret-Key', 'test-secret-key'));
    }

    public function test_it_uses_the_providers_base_url_override(): void
    {
        Http::fake(['sandbox.example.test/*' => Http::response(['data' => ['delivered' => 1, 'cancelled' => 0]])]);

        $result = (new SteadfastFraudClient)->checkByPhone('01712345678', self::KEYS + ['base_url' => 'https://sandbox.example.test/api/v1']);

        $this->assertSame(['success' => 1, 'cancel' => 0, 'total' => 1], $result);
    }

    public function test_it_reads_counts_nested_under_data_and_counts_merchant_reports(): void
    {
        $result = (new SteadfastFraudClient)->parseScore([
            'status' => 200,
            'data' => [
                'success' => 46,
                'cancel' => 3,
                'fraud_reports' => [['note' => 'Refused parcel'], ['note' => 'Fake order']],
            ],
        ]);

        $this->assertSame(['success' => 46, 'cancel' => 3, 'total' => 49, 'reports' => 2], $result);
    }

    public function test_a_response_without_delivery_counts_is_not_read_as_a_clean_record(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Something else'])]);

        $this->assertNull((new SteadfastFraudClient)->checkByPhone('01712345678', self::KEYS));
    }

    public function test_a_401_is_not_retried_and_pauses_further_checks_with_the_same_keys(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated'], 401)]);

        $this->assertNull((new SteadfastFraudClient)->checkByPhone('01712345678', self::KEYS));
        $this->assertNull((new SteadfastFraudClient)->checkByPhone('01812345678', self::KEYS));

        // One request total: no retry loop, and the second lookup is skipped
        // so wrong keys can't trip Steadfast's 10-failures lockout.
        Http::assertSentCount(1);
    }
}
