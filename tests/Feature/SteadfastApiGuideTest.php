<?php

namespace Tests\Feature;

use App\Filament\Resources\CourierProviders\CourierProviderResource;
use App\Jobs\ProcessCourierWebhook;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Models\CourierWebhookLog;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CourierManager;
use App\Services\CourierService;
use App\Services\SteadfastCourierClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Behaviour required by Steadfast's merchant-portal API guide and Webhook
 * page (September 2026).
 */
class SteadfastApiGuideTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'steadfast-auth-token';

    public function test_webhook_checks_the_x_signature_hmac_alongside_the_bearer_token(): void
    {
        Queue::fake();
        $provider = $this->provider();
        $payload = json_encode(['notification_type' => 'delivery_status', 'consignment_id' => 12345, 'invoice' => 'INV-1', 'status' => 'delivered']);

        $this->webhook($provider, $payload, [
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $payload, 'some-other-token'),
        ])->assertUnauthorized();

        $this->webhook($provider, $payload, [
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $payload, self::TOKEN),
        ])->assertOk()->assertJsonPath('status', 'success');

        Queue::assertPushed(ProcessCourierWebhook::class, 1);
    }

    public function test_a_retried_webhook_with_the_same_idempotency_key_is_queued_once(): void
    {
        Queue::fake();
        $provider = $this->provider();
        $first = json_encode(['notification_type' => 'delivery_status', 'consignment_id' => 1, 'status' => 'pending', 'updated_at' => '2026-09-27 10:00:00']);
        $retry = json_encode(['notification_type' => 'delivery_status', 'consignment_id' => 1, 'status' => 'pending', 'updated_at' => '2026-09-27 10:00:30']);

        $this->webhook($provider, $first, ['HTTP_IDEMPOTENCY_KEY' => 'evt-42'])->assertOk()->assertJsonPath('status', 'success');
        $this->webhook($provider, $retry, ['HTTP_IDEMPOTENCY_KEY' => 'evt-42'])->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame(1, CourierWebhookLog::withoutGlobalScopes()->count());
        $this->assertSame('delivery_status', CourierWebhookLog::withoutGlobalScopes()->value('event'));
        Queue::assertPushed(ProcessCourierWebhook::class, 1);
    }

    public function test_account_events_are_logged_as_ignored_instead_of_failing(): void
    {
        $provider = $this->provider();
        $log = CourierWebhookLog::withoutGlobalScopes()->create([
            'company_id' => $provider->company_id,
            'courier_provider_id' => $provider->getKey(),
            'delivery_id' => 'evt-payout',
            'event' => 'payment_request',
            'payload' => ['notification_type' => 'payment_request', 'amount' => 5000],
            'status' => 'pending',
        ]);

        app()->call([new ProcessCourierWebhook($log->getKey()), 'handle']);

        $log->refresh();
        $this->assertSame('ignored', $log->status);
        $this->assertNotNull($log->processed_at);
        $this->assertStringContainsString('payment_request', (string) $log->error);
    }

    public function test_statuses_follow_the_guide(): void
    {
        $service = app(CourierService::class);

        // "_approval_pending" is the rider's word only — never settle on it.
        $this->assertSame(CourierBooking::STATUS_IN_TRANSIT, $service->normalizeSteadfastStatus('delivered_approval_pending'));
        $this->assertSame(CourierBooking::STATUS_IN_TRANSIT, $service->normalizeSteadfastStatus('cancelled_approval_pending'));
        $this->assertSame(CourierBooking::STATUS_DELIVERED, $service->normalizeSteadfastStatus('delivered'));
        $this->assertSame(CourierBooking::STATUS_BOOKING_PENDING, $service->normalizeSteadfastStatus('in_review'));
        $this->assertSame(CourierBooking::STATUS_FAILED, $service->normalizeSteadfastStatus('exception'));
        $this->assertSame(CourierBooking::STATUS_RETURNED, $service->normalizeSteadfastStatus('cancelled_return_received'));
        $this->assertSame(CourierBooking::STATUS_PARTIAL_DELIVERED, $service->normalizeSteadfastStatus('partial_delivered_return_received'));
        $this->assertNull($service->normalizeSteadfastStatus('unknown'));

        $adapter = app(CourierManager::class)->adapter($this->provider());
        $this->assertNotNull($adapter->webhookSkipReason(['notification_type' => 'delivery_status', 'status' => 'unknown']));
        $this->assertNull($adapter->webhookSkipReason(['notification_type' => 'delivery_status', 'status' => 'delivered']));
    }

    public function test_a_401_is_never_retried_but_a_5xx_is(): void
    {
        $provider = $this->provider();
        $client = app(SteadfastCourierClient::class);

        Http::fake(['*' => Http::sequence()
            ->push(['message' => 'Unauthenticated'], 401)
            ->push('', 500)
            ->push(['current_balance' => 1200])]);

        try {
            $client->balance($provider);
            $this->fail('Expected a 401 to throw.');
        } catch (RequestException $exception) {
            $this->assertSame(401, $exception->response->status());
            $this->assertStringContainsString('60 minutes', CourierProviderResource::steadfastFailureMessage($provider, $exception));
        }

        Http::assertSentCount(1);

        $this->assertSame(1200, $client->balance($provider)['current_balance']);
        Http::assertSentCount(3);
    }

    public function test_steadfast_settings_show_the_callback_url_and_not_the_old_fraud_login(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($user)
            ->withSession(['current_company_id' => $provider->company_id, 'current_company_selection_explicit' => true])
            ->get('/admin/courier/courier-providers/'.$provider->getKey().'/edit')
            ->assertOk()
            ->assertSee(route('couriers.webhook', $provider))
            ->assertSee('Webhook Callback URL')
            ->assertDontSee('External Fraud Check (Merchant Panel Login)');
    }

    protected function webhook(CourierProvider $provider, string $payload, array $headers = [])
    {
        return $this->call('POST', route('couriers.webhook', $provider), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            ...$headers,
        ], $payload);
    }

    protected function provider(): CourierProvider
    {
        $company = Company::query()->create([
            'name' => 'Steadfast Guide Co',
            'slug' => 'steadfast-guide-co-'.uniqid(),
            'invoice_prefix' => 'SGC',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);

        return CourierProvider::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Steadfast',
            'slug' => 'steadfast-'.uniqid(),
            'driver' => CourierProvider::DRIVER_STEADFAST,
            'credentials' => ['api_key' => 'test-api-key', 'secret_key' => 'test-secret-key', 'webhook_secret' => self::TOKEN],
            'settings' => [],
            'is_active' => true,
        ]);
    }
}
