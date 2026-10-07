<?php

namespace Tests\Feature;

use App\Filament\Pages\CourierMerchantDashboard;
use App\Filament\Pages\CourierPaymentHistory;
use App\Filament\Resources\CourierProviders\CourierProviderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Jobs\ProcessCourierWebhook;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Models\CourierStatusLog;
use App\Models\CourierWebhookLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CourierManager;
use App\Services\CourierService;
use App\Services\SteadfastCourierClient;
use Filament\Forms\Components\Select;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CourierIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_courier_dashboard_and_provider_navigation_use_distinct_semantic_icons(): void
    {
        $this->assertSame(Heroicon::OutlinedRectangleGroup, CourierMerchantDashboard::getNavigationIcon());
        $this->assertSame(Heroicon::OutlinedTruck, CourierProviderResource::getNavigationIcon());
        $this->assertNotSame(CourierMerchantDashboard::getNavigationIcon(), CourierProviderResource::getNavigationIcon());
    }

    public function test_courier_credentials_use_text_storage_for_the_encrypted_array_cast(): void
    {
        $company = $this->company('Encrypted Courier Company', 'encrypted-courier-company', 'ENC');
        app(CompanyContext::class)->set($company);

        $provider = $this->steadfastProvider($company);
        $rawCredentials = DB::table('courier_providers')
            ->where('id', $provider->getKey())
            ->value('credentials');

        $this->assertSame('text', Schema::getColumnType('courier_providers', 'credentials'));
        $this->assertIsString($rawCredentials);
        $this->assertStringNotContainsString('test-api-key', $rawCredentials);
        $this->assertSame('test-api-key', $provider->fresh()->credentials['api_key']);
        $this->assertSame('test-secret-key', $provider->fresh()->credentials['secret_key']);
    }

    public function test_courier_manager_resolves_manual_adapter_and_creates_booking(): void
    {
        $company = $this->company('Manager Company', 'manager-company', 'MGR');
        app(CompanyContext::class)->set($company);
        $order = $this->orderForCompany($company);
        $provider = app(CourierService::class)->manualProvider($company);

        $booking = app(CourierManager::class)->create($order, $provider, ['tracking_id' => 'MGR-001']);

        $this->assertSame('MGR-001', $booking->tracking_id);
        $this->assertSame($company->getKey(), $booking->company_id);
    }

    public function test_live_courier_adapters_require_credentials_before_booking(): void
    {
        $company = $this->company('Pending Courier Company', 'pending-courier-company', 'PND');
        app(CompanyContext::class)->set($company);
        $order = $this->orderForCompany($company);

        foreach ([CourierProvider::DRIVER_PATHAO, CourierProvider::DRIVER_REDX, CourierProvider::DRIVER_ECOURIER] as $driver) {
            $provider = CourierProvider::query()->create([
                'company_id' => $company->getKey(),
                'name' => CourierProvider::DRIVERS[$driver],
                'slug' => $driver,
                'driver' => $driver,
                'credentials' => [],
                'settings' => [],
                'is_active' => true,
            ]);

            $this->assertSame($driver, app(CourierManager::class)->adapter($provider)->driver());

            try {
                app(CourierManager::class)->create($order, $provider, [
                    'recipient_address' => 'Dhaka',
                    'cod_amount' => 500,
                ]);
                $this->fail("Expected {$driver} adapter to reject booking without credentials.");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('required', $exception->getMessage());
            }
        }
    }

    public function test_signed_courier_webhook_is_idempotently_queued(): void
    {
        Queue::fake();
        $company = $this->company('Webhook Company', 'webhook-company', 'WHK');
        app(CompanyContext::class)->set($company);
        $provider = $this->pathaoProvider($company);
        $credentials = $provider->credentials;
        $credentials['webhook_secret'] = 'webhook-test-secret';
        $provider->update(['credentials' => $credentials]);

        $payload = json_encode(['event_id' => 'evt-001', 'tracking_code' => 'TRACK-001', 'delivery_status' => 'delivered']);
        $signature = hash_hmac('sha256', $payload, 'webhook-test-secret');
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_COURIER_SIGNATURE' => $signature, 'HTTP_X_WEBHOOK_ID' => 'evt-001'];

        $this->call('POST', route('couriers.webhook', $provider), [], [], [], $server, $payload)->assertAccepted();
        $this->call('POST', route('couriers.webhook', $provider), [], [], [], $server, $payload)->assertAccepted();

        $this->assertDatabaseCount('courier_webhook_logs', 1);
        Queue::assertPushed(ProcessCourierWebhook::class, 1);
    }

    /**
     * Steadfast's webhook panel only offers a Callback URL + a static
     * "Auth Token (Bearer)" — it does not sign payloads, so this courier
     * uses plain bearer-token verification instead of the generic
     * HMAC-signature check the other API couriers inherit.
     */
    public function test_steadfast_webhook_accepts_the_configured_bearer_token_and_rejects_others(): void
    {
        Queue::fake();
        $company = $this->company('Steadfast Webhook Company', 'steadfast-webhook-company', 'SFW');
        app(CompanyContext::class)->set($company);
        $provider = $this->steadfastProvider($company);
        $credentials = $provider->credentials;
        $credentials['webhook_secret'] = 'steadfast-bearer-token';
        $provider->update(['credentials' => $credentials]);

        $payload = json_encode(['notification_type' => 'delivery_status', 'consignment_id' => 12345, 'tracking_code' => 'TRACK-001', 'status' => 'delivered']);

        $this->call('POST', route('couriers.webhook', $provider), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer steadfast-bearer-token',
        ], $payload)->assertOk();

        $this->call('POST', route('couriers.webhook', $provider), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer wrong-token',
        ], $payload)->assertUnauthorized();

        $this->call('POST', route('couriers.webhook', $provider), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertUnauthorized();

        // The accepted call, plus one "rejected" row for the turned-away body.
        $this->assertDatabaseCount('courier_webhook_logs', 2);
        $this->assertSame(1, CourierWebhookLog::query()->where('status', 'rejected')->count());
        Queue::assertPushed(ProcessCourierWebhook::class, 1);
    }

    public function test_manual_courier_booking_updates_order_delivery_status_without_changing_order_status(): void
    {
        $company = $this->company('Gift Items', 'gift-items-courier', 'GFT');
        app(CompanyContext::class)->set($company);
        $order = $this->orderForCompany($company);

        $booking = app(CourierService::class)->createManualBooking($order, [
            'tracking_id' => 'MANUAL-001',
            'recipient_name' => 'Courier Customer',
            'recipient_phone' => '+8801700000000',
            'recipient_address' => 'Dhaka',
            'cod_amount' => 500,
            'note' => 'Manual test booking.',
        ]);

        $this->assertSame($company->getKey(), $booking->company_id);
        $this->assertSame(CourierBooking::STATUS_BOOKED, $booking->status);
        $this->assertSame(CourierBooking::STATUS_BOOKED, $order->refresh()->delivery_status);
        $this->assertSame('completed', $order->status);
        $this->assertDatabaseHas('courier_providers', [
            'company_id' => $company->getKey(),
            'slug' => 'manual',
        ]);
        $this->assertDatabaseHas('courier_status_logs', [
            'courier_booking_id' => $booking->getKey(),
            'from_status' => null,
            'to_status' => CourierBooking::STATUS_BOOKED,
        ]);
    }

    public function test_manual_courier_status_updates_are_logged_and_company_scoped(): void
    {
        $garments = $this->company('Garments Machinery', 'garments-courier', 'GM');
        $solar = $this->company('Solar Items', 'solar-courier', 'SOL');

        app(CompanyContext::class)->set($garments);
        $garmentsBooking = app(CourierService::class)->createManualBooking($this->orderForCompany($garments));

        app(CompanyContext::class)->set($solar);
        app(CourierService::class)->createManualBooking($this->orderForCompany($solar), [
            'tracking_id' => 'SOL-TRACK-001',
        ]);

        app(CompanyContext::class)->set($garments);
        app(CourierService::class)->updateStatus($garmentsBooking, CourierBooking::STATUS_DELIVERED, 'Delivered by rider.');

        $this->assertSame(CourierBooking::STATUS_DELIVERED, $garmentsBooking->refresh()->status);
        $this->assertSame(CourierBooking::STATUS_DELIVERED, $garmentsBooking->order->refresh()->delivery_status);
        $this->assertSame(1, CourierBooking::query()->count());
        $this->assertSame(2, CourierStatusLog::query()->count());

        app(CompanyContext::class)->set($solar);
        $this->assertSame(['SOL-TRACK-001'], CourierBooking::query()->pluck('tracking_id')->all());
    }

    public function test_manual_booking_can_use_selected_custom_provider(): void
    {
        $company = $this->company('Custom Courier Company', 'custom-courier-company', 'CCC');
        app(CompanyContext::class)->set($company);
        $order = $this->orderForCompany($company);

        $provider = CourierProvider::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Local Rider Team',
            'slug' => 'local-rider-team',
            'driver' => CourierProvider::DRIVER_MANUAL,
            'settings' => [
                'contact_person' => 'Rider Manager',
                'phone' => '+8801711111111',
                'warehouse' => 'Main Warehouse',
                'delivery_fees' => [
                    'inside' => 50,
                    'outside' => 100,
                    'suburb' => 70,
                ],
            ],
            'credentials' => [],
            'is_active' => true,
        ]);

        $booking = app(CourierService::class)->createManualBooking($order, [
            'courier_provider_id' => $provider->getKey(),
            'tracking_id' => 'LOCAL-001',
        ]);

        $this->assertSame($provider->getKey(), $booking->courier_provider_id);
        $this->assertSame('Rider Manager', $booking->provider->settings['contact_person']);
        $this->assertSame(CourierBooking::STATUS_BOOKED, $order->refresh()->delivery_status);
    }

    public function test_courier_provider_from_another_company_cannot_be_used_for_manual_booking(): void
    {
        $orderCompany = $this->company('Order Company', 'order-company', 'ORD');
        $providerCompany = $this->company('Provider Company', 'provider-company', 'PRV');

        app(CompanyContext::class)->set($orderCompany);
        $order = $this->orderForCompany($orderCompany);

        app(CompanyContext::class)->set($providerCompany);
        $provider = CourierProvider::query()->create([
            'company_id' => $providerCompany->getKey(),
            'name' => 'Provider Company Courier',
            'slug' => 'provider-company-courier',
            'driver' => CourierProvider::DRIVER_MANUAL,
            'credentials' => [],
            'settings' => [],
            'is_active' => true,
        ]);

        app(CompanyContext::class)->all();

        $this->expectException(ValidationException::class);

        app(CourierService::class)->createManualBooking($order, [
            'courier_provider_id' => $provider->getKey(),
            'tracking_id' => 'WRONG-COMPANY',
        ]);
    }

    public function test_courier_provider_from_another_company_cannot_be_used_for_steadfast_booking(): void
    {
        $orderCompany = $this->company('Steadfast Order Company', 'steadfast-order-company', 'SOC');
        $providerCompany = $this->company('Steadfast Provider Company', 'steadfast-provider-company', 'SPC');

        app(CompanyContext::class)->set($orderCompany);
        $order = $this->orderForCompany($orderCompany);

        app(CompanyContext::class)->set($providerCompany);
        $provider = $this->steadfastProvider($providerCompany);

        app(CompanyContext::class)->all();

        $this->expectException(ValidationException::class);

        app(CourierService::class)->createSteadfastBooking($order, $provider, [
            'recipient_address' => 'Dhaka',
            'cod_amount' => 500,
        ]);
    }

    public function test_courier_admin_pages_render_for_sales_user(): void
    {
        $user = User::factory()->create([
            'role' => 'sales_staff',
            'is_active' => true,
        ]);
        $company = Company::defaultCompany();
        $user->companies()->sync([
            $company->getKey() => [
                'role' => 'sales_staff',
                'is_default' => true,
            ],
        ]);
        app(CompanyContext::class)->set($company);

        app(CourierService::class)->createManualBooking($this->orderForCompany($company), [
            'tracking_id' => 'ADMIN-COURIER-001',
        ]);

        $this->actingAs($user)
            ->get('/admin/courier/courier-bookings')
            ->assertOk()
            ->assertSee('ADMIN-COURIER-001');

        $this->actingAs($user)
            ->get('/admin/courier/courier-providers')
            ->assertOk()
            ->assertSee('Manual Courier');

        $this->actingAs($user)
            ->get('/admin/courier/courier-providers/create')
            ->assertOk()
            ->assertSee('Select Delivery Partner')
            ->assertSee('Set Delivery Fees')
            ->assertDontSee('Courier Delivery Cost');

        $this->actingAs($user)
            ->get('/admin/courier/courier-merchant-dashboard')
            ->assertOk()
            ->assertSee('Courier Merchant Dashboard');

        $this->actingAs($user)
            ->get('/admin/courier/courier-payment-history')
            ->assertOk()
            ->assertSee('Payment / Settlement History');

        $this->actingAs($user)
            ->get('/admin/courier/courier-returns')
            ->assertOk()
            ->assertSee('Returns');
    }

    public function test_super_admin_can_open_courier_provider_create_and_must_select_company_when_all_companies_are_selected(): void
    {
        Company::defaultCompany();

        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['current_company_id' => 'all', 'current_company_selection_explicit' => true])
            ->get('/admin/courier/courier-providers/create')
            ->assertOk()
            ->assertSee('Company')
            ->assertSee('Select the company that will own this courier provider.');
    }

    public function test_steadfast_booking_posts_order_and_stores_consignment_response(): void
    {
        $company = $this->company('Steadfast Company', 'steadfast-company', 'STF');
        app(CompanyContext::class)->set($company);
        $order = $this->orderForCompany($company);
        $provider = $this->steadfastProvider($company);

        Http::fake([
            SteadfastCourierClient::DEFAULT_BASE_URL.'/create_order' => Http::response([
                'status' => 200,
                'message' => 'Consignment has been created successfully.',
                'consignment' => [
                    'consignment_id' => 1424107,
                    'invoice' => $order->order_number,
                    'tracking_code' => '15BAEB8A',
                    'recipient_name' => 'Courier Customer',
                    'recipient_phone' => '+8801700000000',
                    'recipient_address' => 'Dhaka',
                    'cod_amount' => 500,
                    'status' => 'in_review',
                ],
            ]),
        ]);

        $booking = app(CourierService::class)->createSteadfastBooking($order, $provider, [
            'recipient_address' => 'Dhaka',
            'cod_amount' => 500,
            'delivery_type' => 0,
        ]);

        $this->assertSame('15BAEB8A', $booking->tracking_id);
        $this->assertSame('1424107', $booking->provider_reference);
        $this->assertSame(CourierBooking::STATUS_BOOKING_PENDING, $booking->status);
        $this->assertSame(CourierBooking::STATUS_BOOKING_PENDING, $order->refresh()->delivery_status);

        Http::assertSent(function ($request) use ($order): bool {
            return $request->hasHeader('Api-Key', 'test-api-key')
                && $request->hasHeader('Secret-Key', 'test-secret-key')
                && $request['invoice'] === $order->order_number
                && $request['recipient_name'] === 'Courier Customer'
                && (float) $request['cod_amount'] === 500.0;
        });
    }

    public function test_every_courier_booking_note_says_which_company_the_order_came_from(): void
    {
        $company = $this->company('Zam Zam Gadget', 'zam-zam-gadget-courier', 'ZZG');
        $order = $this->orderForCompany($company);
        $service = app(CourierService::class);

        $this->assertSame('Order placed from Zam Zam Gadget', $service->bookingFormDefaults($order)['note']);
        $this->assertSame('Order placed from Zam Zam Gadget', $service->courierNote($order));
        $this->assertSame('Order placed from Zam Zam Gadget | Call before delivery', $service->courierNote($order, 'Call before delivery'));
        // Staff kept the prefilled line and added to it: never duplicated.
        $this->assertSame('Order placed from Zam Zam Gadget - fragile', $service->courierNote($order, 'Order placed from Zam Zam Gadget - fragile'));

        $booking = $service->createManualBooking($order, ['tracking_id' => 'NOTE-001']);

        $this->assertSame('Order placed from Zam Zam Gadget', $booking->note);
    }

    public function test_steadfast_booking_sends_the_company_note_in_the_note_field(): void
    {
        $company = $this->company('Zam Zam Gadget', 'zam-zam-gadget-steadfast', 'ZZS');
        $order = $this->orderForCompany($company);
        $provider = $this->steadfastProvider($company);

        Http::fake([
            SteadfastCourierClient::DEFAULT_BASE_URL.'/create_order' => Http::response([
                'status' => 200,
                'consignment' => [
                    'consignment_id' => 555,
                    'tracking_code' => 'ZZ555',
                    'status' => 'in_review',
                ],
            ]),
        ]);

        $booking = app(CourierService::class)->createSteadfastBooking($order, $provider);

        Http::assertSent(fn ($request): bool => $request['note'] === 'Order placed from Zam Zam Gadget');
        $this->assertSame('Order placed from Zam Zam Gadget', $booking->note);
    }

    public function test_steadfast_status_sync_maps_delivery_status(): void
    {
        $company = $this->company('Steadfast Sync Company', 'steadfast-sync-company', 'STS');
        app(CompanyContext::class)->set($company);
        $order = $this->orderForCompany($company);
        $provider = $this->steadfastProvider($company);

        $booking = CourierBooking::query()->create([
            'company_id' => $company->getKey(),
            'courier_provider_id' => $provider->getKey(),
            'order_id' => $order->getKey(),
            'tracking_id' => 'SYNC123',
            'provider_reference' => '999',
            'recipient_name' => 'Courier Customer',
            'recipient_phone' => '+8801700000000',
            'recipient_address' => 'Dhaka',
            'cod_amount' => 500,
            'status' => CourierBooking::STATUS_BOOKED,
            'booked_at' => now(),
        ]);

        Http::fake([
            SteadfastCourierClient::DEFAULT_BASE_URL.'/status_by_trackingcode/SYNC123' => Http::response([
                'status' => 200,
                'delivery_status' => 'delivered',
            ]),
        ]);

        app(CourierService::class)->syncSteadfastStatus($booking);

        $this->assertSame(CourierBooking::STATUS_DELIVERED, $booking->refresh()->status);
        $this->assertSame(CourierBooking::STATUS_DELIVERED, $order->refresh()->delivery_status);
        $this->assertDatabaseHas('courier_status_logs', [
            'courier_booking_id' => $booking->getKey(),
            'from_status' => CourierBooking::STATUS_BOOKED,
            'to_status' => CourierBooking::STATUS_DELIVERED,
        ]);
    }

    public function test_steadfast_booking_calculates_delivery_margin_when_provider_has_fee_settings(): void
    {
        $company = $this->company('Margin Company', 'margin-company', 'MRG');
        app(CompanyContext::class)->set($company);
        $order = $this->orderForCompany($company);
        $provider = $this->steadfastProvider($company);
        $provider->update([
            'settings' => array_merge($provider->settings ?? [], [
                'delivery_fees' => ['inside' => 70, 'outside' => 100, 'suburb' => 90],
                'delivery_costs' => ['inside' => 60, 'outside' => 80, 'suburb' => 70],
                'cod_charge_percent' => 1,
            ]),
        ]);

        Http::fake([
            SteadfastCourierClient::DEFAULT_BASE_URL.'/create_order' => Http::response([
                'status' => 200,
                'message' => 'Consignment has been created successfully.',
                'consignment' => [
                    'consignment_id' => 1424108,
                    'invoice' => $order->order_number,
                    'tracking_code' => 'MARGIN-TRACK',
                    'recipient_name' => 'Courier Customer',
                    'recipient_phone' => '+8801700000000',
                    'recipient_address' => 'Dhaka',
                    'cod_amount' => 1000,
                    'status' => 'in_review',
                ],
            ]),
        ]);

        $booking = app(CourierService::class)->createSteadfastBooking($order, $provider, [
            'recipient_address' => 'Dhaka',
            'cod_amount' => 1000,
        ]);

        $booking->refresh();

        $this->assertEquals(100.0, (float) $booking->delivery_fee_charged);
        $this->assertEquals(80.0, (float) $booking->delivery_cost);
        $this->assertEquals(10.0, (float) $booking->cod_charge_amount);
        $this->assertEquals(10.0, (float) $booking->margin);
    }

    public function test_steadfast_return_request_is_recorded_locally(): void
    {
        $company = $this->company('Return Company', 'return-company', 'RTN');
        app(CompanyContext::class)->set($company);
        $order = $this->orderForCompany($company);
        $provider = $this->steadfastProvider($company);

        $booking = CourierBooking::query()->create([
            'company_id' => $company->getKey(),
            'courier_provider_id' => $provider->getKey(),
            'order_id' => $order->getKey(),
            'tracking_id' => 'RETURN-TRACK',
            'provider_reference' => '555',
            'recipient_name' => 'Courier Customer',
            'recipient_phone' => '+8801700000000',
            'recipient_address' => 'Dhaka',
            'cod_amount' => 500,
            'status' => CourierBooking::STATUS_IN_TRANSIT,
            'booked_at' => now(),
        ]);

        Http::fake([
            SteadfastCourierClient::DEFAULT_BASE_URL.'/create_return_request' => Http::response([
                'status' => 200,
                'message' => 'Return request submitted.',
                'return' => ['id' => 321],
            ]),
        ]);

        $return = app(CourierService::class)->requestReturn($booking, ['reason' => 'Customer refused delivery.']);

        $this->assertSame($company->getKey(), $return->company_id);
        $this->assertSame($booking->getKey(), $return->courier_booking_id);
        $this->assertSame('321', $return->provider_reference);
        $this->assertSame('Customer refused delivery.', $return->reason);
        $this->assertDatabaseHas('courier_returns', [
            'courier_booking_id' => $booking->getKey(),
            'company_id' => $company->getKey(),
        ]);

        Http::assertSent(fn ($request): bool => $request->hasHeader('Api-Key', 'test-api-key')
            && $request['consignment_id'] === '555'
            && $request['tracking_code'] === 'RETURN-TRACK');
    }

    public function test_steadfast_payment_history_is_fetched_through_the_adapter(): void
    {
        $company = $this->company('Payment History Company', 'payment-history-company', 'PMT');
        app(CompanyContext::class)->set($company);
        $provider = $this->steadfastProvider($company);

        Http::fake([
            SteadfastCourierClient::DEFAULT_BASE_URL.'/payments' => Http::response([
                'status' => 200,
                'data' => [
                    ['id' => 1, 'amount' => 5000, 'status' => 'paid'],
                ],
            ]),
        ]);

        $response = app(CourierManager::class)->adapter($provider)->paymentHistory($provider);

        $this->assertSame(200, $response['status']);
        $this->assertSame(5000, $response['data'][0]['amount']);

        Http::assertSent(fn ($request): bool => $request->hasHeader('Api-Key', 'test-api-key')
            && str_ends_with((string) $request->url(), '/payments'));
    }

    public function test_steadfast_single_payment_and_return_status_hit_the_corrected_endpoint_paths(): void
    {
        // Regression test for a live-verified bug: the original guessed paths
        // (/payment and /return/{id}) 404'd against a real Steadfast account
        // on 2026-08-13; the corrected paths below were cross-checked against
        // two independent open-source Steadfast API wrapper packages.
        $company = $this->company('Endpoint Fix Company', 'endpoint-fix-company', 'EFX');
        app(CompanyContext::class)->set($company);
        $provider = $this->steadfastProvider($company);

        Http::fake([
            SteadfastCourierClient::DEFAULT_BASE_URL.'/payments/77' => Http::response([
                'status' => 200,
                'data' => ['id' => 77, 'total' => 40541, 'consignments' => [['cn' => 'CN#1', 'status' => 'delivered']]],
            ]),
            SteadfastCourierClient::DEFAULT_BASE_URL.'/get_return_request/321' => Http::response([
                'status' => 200,
                'return' => ['id' => 321, 'status' => 'approved'],
            ]),
        ]);

        $payment = app(SteadfastCourierClient::class)->payment($provider, 77);
        $return = app(SteadfastCourierClient::class)->returnStatus($provider, 321);

        $this->assertSame(40541, $payment['data']['total']);
        $this->assertSame('approved', $return['return']['status']);

        Http::assertSent(fn ($request): bool => str_ends_with((string) $request->url(), '/payments/77'));
        Http::assertSent(fn ($request): bool => str_ends_with((string) $request->url(), '/get_return_request/321'));
    }

    public function test_courier_merchant_dashboard_shows_booking_status_summary_counts(): void
    {
        $user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $company = Company::defaultCompany();
        $user->companies()->sync([$company->getKey() => ['role' => 'sales_staff', 'is_default' => true]]);
        app(CompanyContext::class)->set($company);
        $provider = $this->steadfastProvider($company);

        $order = $this->orderForCompany($company);

        foreach ([CourierBooking::STATUS_DELIVERED, CourierBooking::STATUS_DELIVERED, CourierBooking::STATUS_CANCELLED] as $i => $status) {
            CourierBooking::query()->create([
                'company_id' => $company->getKey(),
                'courier_provider_id' => $provider->getKey(),
                'order_id' => $order->getKey(),
                'tracking_id' => "STATUS-{$i}",
                'recipient_name' => 'Courier Customer',
                'recipient_phone' => '+8801700000000',
                'recipient_address' => 'Dhaka',
                'cod_amount' => 500,
                'status' => $status,
                'booked_at' => now(),
            ]);
        }

        $this->actingAs($user)
            ->get('/admin/courier/courier-merchant-dashboard')
            ->assertOk()
            ->assertSee('Booking Status Summary')
            ->assertSeeText('Delivered')
            ->assertSeeText('Cancelled');
    }

    public function test_booking_status_summary_card_click_opens_a_modal_with_only_matching_bookings(): void
    {
        // Regression test: an earlier version tried to make cards clickable by
        // deep-linking to CourierBookingResource with ?tableFilters=... query
        // params. Confirmed live (browser) that this Filament install does not
        // apply table filters from a cold GET request — the filter badge stays
        // at 0 and every row still shows. Cards now open a modal on the
        // Dashboard page itself instead (Filament\Pages\Page already
        // implements HasActions/InteractsWithActions), which this test proves
        // actually filters, unlike the abandoned deep-link approach.
        $user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $company = Company::defaultCompany();
        $user->companies()->sync([$company->getKey() => ['role' => 'sales_staff', 'is_default' => true]]);
        app(CompanyContext::class)->set($company);
        $provider = $this->steadfastProvider($company);
        $order = $this->orderForCompany($company);

        foreach ([CourierBooking::STATUS_DELIVERED => 'DELIVERED-CARD', CourierBooking::STATUS_CANCELLED => 'CANCELLED-CARD'] as $status => $trackingId) {
            CourierBooking::query()->create([
                'company_id' => $company->getKey(),
                'courier_provider_id' => $provider->getKey(),
                'order_id' => $order->getKey(),
                'tracking_id' => $trackingId,
                'recipient_name' => 'Courier Customer',
                'recipient_phone' => '+8801700000000',
                'recipient_address' => 'Dhaka',
                'cod_amount' => 500,
                'status' => $status,
                'booked_at' => now(),
            ]);
        }

        $this->actingAs($user);

        Livewire::test(CourierMerchantDashboard::class)
            ->mountAction('bookingStatus', arguments: ['key' => 'delivered'])
            ->assertMountedActionModalSee(['DELIVERED-CARD'])
            ->assertMountedActionModalDontSee(['CANCELLED-CARD']);
    }

    public function test_courier_merchant_dashboard_aggregates_all_couriers_by_default_and_can_be_scoped_to_one(): void
    {
        $user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $company = Company::defaultCompany();
        $user->companies()->sync([$company->getKey() => ['role' => 'sales_staff', 'is_default' => true]]);
        app(CompanyContext::class)->set($company);

        $steadfast = $this->steadfastProvider($company);
        $pathao = $this->pathaoProvider($company);
        $order = $this->orderForCompany($company);

        foreach ([$steadfast->getKey() => 'STEADFAST-MULTI', $pathao->getKey() => 'PATHAO-MULTI'] as $providerId => $trackingId) {
            CourierBooking::query()->create([
                'company_id' => $company->getKey(),
                'courier_provider_id' => $providerId,
                'order_id' => $order->getKey(),
                'tracking_id' => $trackingId,
                'recipient_name' => 'Courier Customer',
                'recipient_phone' => '+8801700000000',
                'recipient_address' => 'Dhaka',
                'cod_amount' => 500,
                'status' => CourierBooking::STATUS_DELIVERED,
                'booked_at' => now(),
            ]);
        }

        $this->actingAs($user);

        $dashboard = Livewire::test(CourierMerchantDashboard::class)
            ->assertSchemaComponentExists('providerFilter', 'form', function (Select $field) use ($steadfast, $pathao): bool {
                $options = $field->getOptions();

                return $field->getLabel() === 'Courier'
                    && ! $field->isNative()
                    && $field->isLive()
                    && $field->getMaxWidth() === Width::ExtraSmall
                    && $field->isVisible()
                    && $options['all'] === 'All Couriers'
                    && array_key_exists($steadfast->getKey(), $options)
                    && array_key_exists($pathao->getKey(), $options);
            });

        // Default is "All Couriers" ('providerFilter' === 'all') — both
        // providers' bookings are counted together.
        $counts = $dashboard->instance()->statusCounts();
        $this->assertSame(2, $counts['all']);
        $this->assertSame(2, $counts['delivered']);

        // Scoping to one provider only counts that provider's bookings.
        $dashboard->set('providerFilter', (string) $pathao->getKey());
        $pathaoCounts = $dashboard->instance()->statusCounts();
        $this->assertSame(1, $pathaoCounts['all']);

        $dashboard->set('providerFilter', (string) $steadfast->getKey());
        $steadfastCounts = $dashboard->instance()->statusCounts();
        $this->assertSame(1, $steadfastCounts['all']);

        $pathao->update(['is_active' => false]);

        Livewire::test(CourierMerchantDashboard::class)
            ->assertSchemaComponentHidden('providerFilter', 'form');
    }

    public function test_booking_status_summary_modal_shows_which_courier_each_booking_belongs_to(): void
    {
        // Regression test for the owner's question "কিভাবে বুঝব কোন কুরিয়ারে
        // বুকিং হয়েছে" (how will I know which courier a booking went
        // through) once a company has more than one active courier — the
        // modal must label each row with its provider, not just its status.
        $user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $company = Company::defaultCompany();
        $user->companies()->sync([$company->getKey() => ['role' => 'sales_staff', 'is_default' => true]]);
        app(CompanyContext::class)->set($company);

        $steadfast = $this->steadfastProvider($company);
        $pathao = $this->pathaoProvider($company);
        $order = $this->orderForCompany($company);

        foreach ([$steadfast->getKey() => 'STEADFAST-DELIVERED', $pathao->getKey() => 'PATHAO-DELIVERED'] as $providerId => $trackingId) {
            CourierBooking::query()->create([
                'company_id' => $company->getKey(),
                'courier_provider_id' => $providerId,
                'order_id' => $order->getKey(),
                'tracking_id' => $trackingId,
                'recipient_name' => 'Courier Customer',
                'recipient_phone' => '+8801700000000',
                'recipient_address' => 'Dhaka',
                'cod_amount' => 500,
                'status' => CourierBooking::STATUS_DELIVERED,
                'booked_at' => now(),
            ]);
        }

        $this->actingAs($user);

        Livewire::test(CourierMerchantDashboard::class)
            ->mountAction('bookingStatus', arguments: ['key' => 'delivered'])
            ->assertMountedActionModalSee(['STEADFAST-DELIVERED', 'Steadfast', 'PATHAO-DELIVERED', 'Pathao']);
    }

    public function test_courier_merchant_dashboard_balance_section_marks_couriers_without_a_balance_endpoint_as_unsupported(): void
    {
        // Only Steadfast's adapter overrides balance(); Pathao/RedX/E-Courier
        // fall back to AbstractCourierAdapter::balance() => null. All
        // Couriers hides those unsupported rows entirely; explicitly
        // selecting one still says so rather than showing nothing.
        $user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $company = Company::defaultCompany();
        $user->companies()->sync([$company->getKey() => ['role' => 'sales_staff', 'is_default' => true]]);
        app(CompanyContext::class)->set($company);

        $pathao = $this->pathaoProvider($company);

        $this->actingAs($user);

        $dashboard = Livewire::test(CourierMerchantDashboard::class);

        $allCouriersBalances = $dashboard->instance()->balances();
        $this->assertTrue($allCouriersBalances->every(fn (array $row): bool => $row['provider']->driver !== CourierProvider::DRIVER_PATHAO));

        $dashboard->set('providerFilter', (string) $pathao->getKey());
        $pathaoBalances = $dashboard->instance()->balances();
        $this->assertCount(1, $pathaoBalances);
        $this->assertFalse($pathaoBalances->first()['supported']);
    }

    public function test_default_courier_is_enforced_to_one_per_company_and_pre_fills_new_orders(): void
    {
        $company = Company::defaultCompany();
        app(CompanyContext::class)->set($company);

        $steadfast = $this->steadfastProvider($company);
        $steadfast->update(['is_default' => true]);
        $pathao = $this->pathaoProvider($company);

        $this->assertSame($steadfast->getKey(), CourierProvider::defaultForCompany($company->getKey())?->getKey());

        // Only one provider per company may be default — flipping Pathao's
        // flag on must flip Steadfast's off (CourierProvider::booted()'s
        // `saved` hook).
        $pathao->update(['is_default' => true]);
        $this->assertFalse($steadfast->fresh()->is_default);
        $this->assertTrue($pathao->fresh()->is_default);
        $this->assertSame($pathao->getKey(), CourierProvider::defaultForCompany($company->getKey())?->getKey());

        // A brand new order (this is exactly what storefront checkout and
        // admin order creation both go through — Order::query()->create())
        // is pre-assigned to whichever provider is default, without being
        // told to.
        $order = $this->orderForCompany($company);
        $this->assertSame($pathao->getKey(), $order->fresh()->courier_provider_id);

        // Explicitly choosing a different courier at creation time is
        // respected, not overridden by the default.
        $order->courierProvider()->associate($steadfast);
        $order->save();
        $this->assertSame($steadfast->getKey(), $order->fresh()->courier_provider_id);
    }

    public function test_orders_list_bulk_book_courier_uses_the_one_courier_chosen_in_the_popup_for_the_whole_batch(): void
    {
        // Owner asked that the bulk "Book courier" action open a popup
        // showing the default courier pre-selected, changeable, and applied
        // to every selected order — not each order's own individually
        // assigned provider (the earlier design). Pathao/RedX/E-Courier are
        // deliberately not offered as options at all in the bulk popup
        // (see BULK_SAFE_DRIVERS) since their per-order fields can't be
        // safely filled for a batch of different recipients.
        $user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $company = Company::defaultCompany();
        $user->companies()->sync([$company->getKey() => ['role' => 'sales_staff', 'is_default' => true]]);
        app(CompanyContext::class)->set($company);

        $steadfast = $this->steadfastProvider($company);
        $steadfast->update(['is_default' => true]);

        Http::fake([
            SteadfastCourierClient::DEFAULT_BASE_URL.'/create_order' => Http::response([
                'status' => 200,
                'consignment' => ['consignment_id' => 991, 'tracking_code' => 'BULK-STEADFAST', 'status' => 'in_review'],
            ]),
        ]);

        // Reuse one customer/product across every order in this test —
        // orderForCompany() creates a fresh product with a company-scoped
        // SKU, and calling it more than once for the same company collides
        // on that unique SKU (a real bug this session already found once).
        $seed = $this->orderForCompany($company);
        $customer = $seed->customer;
        $product = $seed->items->first()->product;

        $makeOrder = function () use ($customer, $product): Order {
            $order = Order::query()->create([
                'customer_id' => $customer->getKey(),
                'order_date' => now()->toDateString(),
                'discount' => 0,
                'vat' => 0,
                'paid_amount' => 0,
                'status' => 'draft',
            ]);
            OrderItem::query()->create([
                'order_id' => $order->getKey(),
                'product_id' => $product->getKey(),
                'quantity' => 1,
                'unit_price' => 500,
            ]);
            $order->update(['status' => 'completed']);

            return $order->refresh();
        };

        $orderOne = $makeOrder();
        $orderTwo = $makeOrder();

        $alreadyBookedOrder = $makeOrder();
        CourierBooking::query()->create([
            'company_id' => $company->getKey(),
            'courier_provider_id' => $steadfast->getKey(),
            'order_id' => $alreadyBookedOrder->getKey(),
            'tracking_id' => 'ALREADY-BOOKED',
            'recipient_name' => 'Courier Customer',
            'recipient_phone' => '+8801700000000',
            'recipient_address' => 'Dhaka',
            'cod_amount' => 500,
            'status' => CourierBooking::STATUS_BOOKED,
            'booked_at' => now(),
        ]);
        $alreadyBookedOrder->update(['delivery_status' => CourierBooking::STATUS_BOOKED]);

        $this->actingAs($user);

        Livewire::test(ListOrders::class)
            ->mountTableBulkAction('bookCourierBulk', [$orderOne, $orderTwo, $alreadyBookedOrder])
            ->assertTableBulkActionDataSet(['courier_provider_id' => $steadfast->getKey()]) // pre-selected default
            ->callMountedTableBulkAction();

        $this->assertNotSame(CourierBooking::STATUS_NOT_BOOKED, $orderOne->fresh()->delivery_status);
        $this->assertNotSame(CourierBooking::STATUS_NOT_BOOKED, $orderTwo->fresh()->delivery_status);
        $this->assertSame(CourierBooking::STATUS_BOOKED, $alreadyBookedOrder->fresh()->delivery_status);

        Http::assertSentCount(2); // one create_order call per not-yet-booked order, none for the already-booked one.
    }

    public function test_orders_list_book_courier_action_is_unified_and_books_through_the_selected_driver(): void
    {
        // Owner asked to consolidate the per-driver row buttons (Book
        // Steadfast / Book Pathao / ...) into one "Book courier" action
        // whose popup lets the courier be picked (default pre-selected)
        // instead of one button per courier.
        $user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $company = Company::defaultCompany();
        $user->companies()->sync([$company->getKey() => ['role' => 'sales_staff', 'is_default' => true]]);
        app(CompanyContext::class)->set($company);

        $steadfast = $this->steadfastProvider($company);
        $manual = CourierProvider::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'In-house Rider',
            'slug' => 'in-house-rider',
            'driver' => CourierProvider::DRIVER_MANUAL,
            'is_active' => true,
            'is_default' => true,
        ]);
        $order = $this->orderForCompany($company);

        $this->actingAs($user);

        // The old per-driver buttons no longer exist.
        Livewire::test(ListOrders::class)
            ->assertTableActionExists('bookCourier', record: $order)
            ->assertTableActionDoesNotExist('bookSteadfast', record: $order)
            ->assertTableActionDoesNotExist('bookPathao', record: $order)
            // No explicit assignment on this order, so the unified popup
            // defaults to the company's default courier (Manual, here).
            ->mountTableAction('bookCourier', $order)
            ->assertTableActionDataSet(['courier_provider_id' => $manual->getKey()])
            // Switch the popup's selection to Steadfast and submit — proves
            // the choice isn't locked to the pre-filled default.
            ->setTableActionData(['courier_provider_id' => $steadfast->getKey()]);

        Http::fake([
            SteadfastCourierClient::DEFAULT_BASE_URL.'/create_order' => Http::response([
                'status' => 200,
                'consignment' => ['consignment_id' => 992, 'tracking_code' => 'UNIFIED-STEADFAST', 'status' => 'in_review'],
            ]),
        ]);

        Livewire::test(ListOrders::class)
            ->mountTableAction('bookCourier', $order)
            ->setTableActionData(['courier_provider_id' => $steadfast->getKey()])
            ->callMountedTableAction();

        $this->assertSame($steadfast->getKey(), CourierBooking::where('order_id', $order->getKey())->first()?->courier_provider_id);
        Http::assertSent(fn ($request): bool => str_ends_with((string) $request->url(), '/create_order'));
    }

    public function test_courier_cod_amount_is_the_full_invoice_total_with_a_zone_fee_fallback(): void
    {
        $company = Company::query()->create([
            'name' => 'COD Co', 'slug' => 'cod-co', 'invoice_prefix' => 'CODC',
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
            'settings' => ['shipping_zones' => ['inside' => ['dhaka']]],
        ]);
        app(CompanyContext::class)->set($company);

        CourierProvider::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Rider', 'slug' => 'rider', 'driver' => CourierProvider::DRIVER_MANUAL,
            'is_active' => true,
            'settings' => ['delivery_fees' => ['inside' => 60]],
        ]);

        $customer = Customer::query()->create([
            'name' => 'COD Buyer', 'phone' => '+8801700000001', 'address' => 'Mirpur, Dhaka',
            'opening_balance' => 0, 'is_active' => true,
        ]);
        $product = Product::query()->create([
            'name' => 'COD Product', 'sku' => 'COD-SKU-1', 'price' => 500, 'sale_price' => 500,
            'cost_price' => 300, 'stock' => 5, 'unit' => 'pcs', 'reorder_level' => 1,
            'vat_rate' => 0, 'is_active' => true, 'status' => Product::STATUS_AVAILABLE,
        ]);
        StockMovement::query()->create(['product_id' => $product->getKey(), 'type' => 'opening', 'quantity' => 5]);

        $order = Order::query()->create([
            'customer_id' => $customer->getKey(), 'order_date' => now()->toDateString(),
            'discount' => 0, 'vat' => 0, 'paid_amount' => 200, 'status' => 'draft',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->getKey(), 'product_id' => $product->getKey(),
            'quantity' => 1, 'unit_price' => 500,
        ]);
        $order->update(['status' => 'confirmed']);
        $order->refresh();

        // The order auto-picked up the ৳60 "inside" zone fee on creation, so
        // total_amount is 560. COD is that full total, NOT the ৳300 still due
        // after the ৳200 advance.
        $this->assertSame('560.00', $order->total_amount);
        $this->assertSame(560.0, $order->courierCodAmount());

        // Order with no shipping fee of its own (staff zeroed it, or no zone
        // config): the zone delivery charge is added back on top of the total.
        $order->update(['shipping_fee' => 0]);
        $order->refresh();
        $this->assertSame('500.00', $order->total_amount);
        $this->assertSame(560.0, $order->courierCodAmount());
    }

    public function test_book_courier_popup_prefills_recipient_from_the_order_customer_and_the_full_cod(): void
    {
        $company = Company::defaultCompany();
        $user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $user->companies()->sync([$company->getKey() => ['role' => 'sales_staff', 'is_default' => true]]);
        app(CompanyContext::class)->set($company);
        CourierProvider::query()->create([
            'company_id' => $company->getKey(), 'name' => 'Rider', 'slug' => 'rider',
            'driver' => CourierProvider::DRIVER_MANUAL, 'is_active' => true, 'is_default' => true,
        ]);

        $order = $this->orderForCompany($company);
        $this->actingAs($user);

        Livewire::test(ListOrders::class)
            ->mountTableAction('bookCourier', $order)
            ->assertTableActionDataSet([
                'recipient_name' => 'Courier Customer',
                'recipient_phone' => '+8801700000000',
                'recipient_address' => 'Dhaka',
                'cod_amount' => $order->courierCodAmount(),
            ]);
    }

    public function test_courier_payment_history_view_details_action_fetches_the_payment_via_the_corrected_endpoint(): void
    {
        $user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $company = Company::defaultCompany();
        $user->companies()->sync([$company->getKey() => ['role' => 'sales_staff', 'is_default' => true]]);
        app(CompanyContext::class)->set($company);
        $this->steadfastProvider($company);

        Http::fake([
            SteadfastCourierClient::DEFAULT_BASE_URL.'/payments' => Http::response([
                'status' => 200,
                'data' => [
                    ['id' => 501, 'reference' => 'SFC#501', 'amount' => 12345, 'status' => 'paid'],
                ],
            ]),
            SteadfastCourierClient::DEFAULT_BASE_URL.'/payments/501' => Http::response([
                'status' => 200,
                'data' => ['total' => 40541, 'recipient' => 'ZamZam International'],
            ]),
        ]);

        $this->actingAs($user);

        Livewire::test(CourierPaymentHistory::class)
            ->assertTableActionExists('viewPaymentDetails', record: 'payment-0')
            ->mountTableAction('viewPaymentDetails', 'payment-0')
            ->assertMountedActionModalSee(['৳ 40,541', 'ZamZam International']);

        Http::assertSent(fn ($request): bool => str_ends_with((string) $request->url(), '/payments/501'));
    }

    protected function company(string $name, string $slug, string $prefix): Company
    {
        return Company::query()->create([
            'name' => $name,
            'slug' => $slug,
            'invoice_prefix' => $prefix,
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
    }

    protected function orderForCompany(Company $company): Order
    {
        app(CompanyContext::class)->set($company);

        $customer = Customer::query()->create([
            'name' => 'Courier Customer',
            'phone' => '+8801700000000',
            'address' => 'Dhaka',
            'opening_balance' => 0,
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'name' => 'Courier Product '.$company->getKey(),
            'sku' => 'COURIER-SKU-'.$company->getKey(),
            'price' => 500,
            'sale_price' => 500,
            'cost_price' => 300,
            'stock' => 5,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);
        StockMovement::query()->create([
            'product_id' => $product->getKey(),
            'type' => 'opening',
            'quantity' => 5,
            'reference_type' => Product::class,
            'reference_id' => $product->getKey(),
            'note' => 'Courier test stock',
        ]);
        $order = Order::query()->create([
            'customer_id' => $customer->getKey(),
            'order_date' => now()->toDateString(),
            'discount' => 0,
            'vat' => 0,
            'paid_amount' => 0,
            'status' => 'draft',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => 1,
            'unit_price' => 500,
        ]);
        $order->update(['status' => 'completed']);

        return $order->refresh();
    }

    protected function steadfastProvider(Company $company): CourierProvider
    {
        return CourierProvider::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'driver' => CourierProvider::DRIVER_STEADFAST,
            'credentials' => [
                'api_key' => 'test-api-key',
                'secret_key' => 'test-secret-key',
            ],
            'settings' => [
                'base_url' => SteadfastCourierClient::DEFAULT_BASE_URL,
            ],
            'is_active' => true,
        ]);
    }

    protected function pathaoProvider(Company $company): CourierProvider
    {
        return CourierProvider::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Pathao',
            'slug' => 'pathao',
            'driver' => CourierProvider::DRIVER_PATHAO,
            'credentials' => [
                'client_id' => 'test-client-id',
                'client_secret' => 'test-client-secret',
                'username' => 'test-username',
                'password' => 'test-password',
            ],
            'is_active' => true,
        ]);
    }
}
