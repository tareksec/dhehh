<?php

namespace Tests\Feature;

use App\Filament\Pages\Integrations;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StorefrontCartRecord;
use App\Models\StorefrontMetaAttribution;
use App\Models\StorefrontMetaEvent;
use App\Models\StorefrontPayment;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\OrderStatusWorkflowService;
use App\Services\StorefrontMetaConversionsService;
use App\Services\StorefrontMetaDispatchService;
use App\Services\StorefrontMetaTrackingService;
use App\Services\StorefrontOrderPlacementService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class StorefrontMetaTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_browser_pixel_and_funnel_events_only_render_when_enabled(): void
    {
        [$company, $product, $setting] = $this->store('Pixel Store', 'pixel.example.test');

        $this->get('http://pixel.example.test/product/'.$product->slug)
            ->assertOk()
            ->assertDontSee('connect.facebook.net', false)
            ->assertDontSee("fbq('track', 'ViewContent'", false);

        $setting->update([
            'meta_tracking_enabled' => true,
            'meta_consent_required' => false,
            'meta_browser_tracking_enabled' => true,
            'meta_pixel_id' => '123456789012345',
        ]);

        $this->get('http://pixel.example.test/product/'.$product->slug)
            ->assertOk()
            ->assertSee('connect.facebook.net/en_US/fbevents.js', false)
            ->assertSee("fbq('track', 'PageView'", false)
            ->assertSee("fbq('track', 'ViewContent'", false)
            ->assertSee('123456789012345', false);

        $this->post('http://pixel.example.test/cart/items/'.$product->slug, ['quantity' => 2])
            ->assertRedirect();

        $this->get('http://pixel.example.test/checkout')
            ->assertOk()
            ->assertSee("'AddToCart'", false)
            ->assertSee("fbq('track', 'InitiateCheckout'", false);

        $this->get("http://127.0.0.1/storefront/{$company->slug}/product/{$product->slug}")
            ->assertOk()
            ->assertDontSee('connect.facebook.net', false);
    }

    public function test_an_empty_saved_browser_events_list_falls_back_to_the_default_events_instead_of_firing_none(): void
    {
        // Regression test: this is the exact bug behind a real report —
        // Meta Pixel Helper showed the Pixel ID as "installed" (fbq('init')
        // ran fine) but "hasn't fired recently" / "no events recorded",
        // because `meta_browser_events` was saved as `[]` (every Browser
        // Events checkbox unchecked) rather than left unset. The old
        // fallback only kicked in for a genuinely unset (null) value, so a
        // saved `[]` silently meant "fire nothing, forever" while every
        // readiness indicator in the admin panel still said "Ready".
        [, $product, $setting] = $this->store('Empty Browser Events Store', 'empty-browser-events.example.test');
        $setting->update([
            'meta_tracking_enabled' => true,
            'meta_consent_required' => false,
            'meta_browser_tracking_enabled' => true,
            'meta_browser_events' => [],
            'meta_pixel_id' => '5566778899001122',
        ]);

        $this->get('http://empty-browser-events.example.test/product/'.$product->slug)
            ->assertOk()
            ->assertSee('connect.facebook.net/en_US/fbevents.js', false)
            ->assertSee("fbq('track', 'PageView'", false)
            ->assertSee("fbq('track', 'ViewContent'", false);
    }

    public function test_purchase_is_sent_server_side_with_hashed_customer_data_and_shared_event_id(): void
    {
        [, $product, $setting] = $this->store('CAPI Store', 'capi.example.test');
        $setting->update([
            'meta_tracking_enabled' => true,
            'meta_consent_required' => false,
            'meta_browser_tracking_enabled' => true,
            'meta_capi_enabled' => true,
            'meta_pixel_id' => '9988776655443322',
            'meta_tracking_credentials' => [
                'access_token' => 'secret-capi-token',
                'test_event_code' => 'TEST12345',
            ],
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'trace-123'], 200),
        ]);

        $this->post('http://capi.example.test/cart/items/'.$product->slug, ['quantity' => 2]);
        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.42', 'HTTP_USER_AGENT' => 'Storefront Test Browser'])
            ->withCookie('_fbp', 'fb.1.1720000000.123456789')
            ->withCookie('_fbc', 'fb.1.1720000000.clickABC123')
            ->post('http://capi.example.test/checkout', $this->checkoutData());

        $order = Order::query()->where('source', Order::SOURCE_STOREFRONT)->firstOrFail();
        $eventId = "purchase-{$order->company_id}-{$order->getKey()}";

        $response->assertRedirectContains('/checkout/success/'.$order->getKey());
        Http::assertSent(function (Request $request) use ($eventId, $order): bool {
            if (! str_contains($request->url(), '/9988776655443322/events')) {
                return false;
            }

            $event = $request->data()['data'][0] ?? [];
            $userData = $event['user_data'] ?? [];

            return $event['event_name'] === 'Purchase'
                && $event['event_id'] === $eventId
                && $event['action_source'] === 'website'
                && $event['custom_data']['currency'] === 'BDT'
                && (float) $event['custom_data']['value'] === (float) $order->total_amount
                && $request->data()['access_token'] === 'secret-capi-token'
                && $request->data()['test_event_code'] === 'TEST12345'
                && ($userData['em'][0] ?? null) === hash('sha256', 'buyer@example.test')
                && ($userData['ph'][0] ?? null) === hash('sha256', '8801700111222');
        });

        $audit = StorefrontMetaEvent::withoutGlobalScopes()->sole();
        $this->assertSame(StorefrontMetaEvent::STATUS_SENT, $audit->status);
        $this->assertSame($eventId, $audit->event_id);
        $this->assertSame(1, $audit->attempts);
        $this->assertSame(1, $audit->response_summary['events_received']);
        $this->assertStringNotContainsString('buyer@example.test', json_encode($audit->toArray()));
        $this->assertStringNotContainsString('01700111222', json_encode($audit->toArray()));
        $this->assertStringNotContainsString('secret-capi-token', json_encode($audit->toArray()));

        $this->get((string) $response->headers->get('Location'))
            ->assertOk()
            ->assertSee("'Purchase'", false)
            ->assertSee($eventId, false);

        app(StorefrontMetaConversionsService::class)->sendPurchase($order->fresh(), []);
        $metaRequests = collect(Http::recorded())->filter(
            fn (array $record): bool => str_contains($record[0]->url(), '/9988776655443322/events'),
        );
        $this->assertCount(1, $metaRequests);
    }

    public function test_meta_api_failure_does_not_fail_checkout_and_is_audited_without_secrets(): void
    {
        [, $product, $setting] = $this->store('Fail Open Store', 'fail-open.example.test');
        $setting->update([
            'meta_tracking_enabled' => true,
            'meta_consent_required' => false,
            'meta_capi_enabled' => true,
            'meta_pixel_id' => '1122334455667788',
            'meta_tracking_credentials' => ['access_token' => 'do-not-log-this-token'],
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Provider unavailable access_token=do-not-log-this-token'],
            ], 503),
        ]);

        $this->post('http://fail-open.example.test/cart/items/'.$product->slug, ['quantity' => 1]);
        $response = $this->post('http://fail-open.example.test/checkout', $this->checkoutData());

        $response->assertRedirectContains('/checkout/success/');
        $this->assertDatabaseCount('orders', 1);

        $audit = StorefrontMetaEvent::withoutGlobalScopes()->sole();
        $this->assertSame(StorefrontMetaEvent::STATUS_FAILED, $audit->status);
        $this->assertSame(1, $audit->attempts);
        $this->assertStringContainsString('[redacted]', $audit->error);
        $this->assertStringNotContainsString('do-not-log-this-token', $audit->error);
    }

    public function test_verified_advance_payment_order_sends_purchase_with_saved_tracking_context(): void
    {
        [$company, $product, $setting] = $this->store('Paid CAPI Store', 'paid-capi.example.test');
        $setting->update([
            'meta_tracking_enabled' => true,
            'meta_consent_required' => false,
            'meta_capi_enabled' => true,
            'meta_pixel_id' => '6677889900112233',
            'meta_tracking_credentials' => ['access_token' => 'paid-flow-token'],
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1], 200),
        ]);

        $payment = StorefrontPayment::query()->create([
            'company_id' => $company->getKey(),
            'gateway' => 'zinipay',
            'purpose' => StorefrontPayment::PURPOSE_CHECKOUT_ADVANCE,
            'amount' => 100,
            'status' => StorefrontPayment::STATUS_COMPLETED,
            'checkout_data' => [
                ...$this->checkoutData(),
                'delivery_area' => 'inside',
                'shipping_fee' => 70,
                'advance_reasons' => ['courier_low_success_ratio' => 100],
                'meta_tracking_context' => [
                    'client_ip_address' => '198.51.100.9',
                    'client_user_agent' => 'Saved Payment Browser',
                    'fbp' => 'fb.1.1720000000.24681012',
                    'event_source_url' => 'http://paid-capi.example.test/checkout',
                ],
                'items' => [[
                    'product_id' => $product->getKey(),
                    'product_variant_id' => null,
                    'variant_label' => null,
                    'quantity' => 1,
                    'unit_price' => 950,
                    'unit_cost' => 500,
                ]],
            ],
        ]);

        $order = app(StorefrontOrderPlacementService::class)->placePaidCheckout($payment);

        $this->assertSame(Order::SOURCE_STOREFRONT, $order->source);
        $this->assertDatabaseHas('storefront_meta_events', [
            'company_id' => $company->getKey(),
            'order_id' => $order->getKey(),
            'event_id' => "purchase-{$company->getKey()}-{$order->getKey()}",
            'status' => StorefrontMetaEvent::STATUS_SENT,
        ]);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/6677889900112233/events')
            && ($request->data()['data'][0]['user_data']['client_ip_address'] ?? null) === '198.51.100.9'
            && ($request->data()['data'][0]['user_data']['fbp'] ?? null) === 'fb.1.1720000000.24681012');
    }

    public function test_legacy_meta_events_page_redirects_to_consolidated_event_log(): void
    {
        $company = Company::query()->create([
            'name' => 'Legacy Meta Events Co', 'slug' => 'legacy-meta-events-co',
            'invoice_prefix' => 'LME', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $user->companies()->attach($company, ['role' => 'super_admin', 'is_default' => true]);

        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true])
            ->get('/admin/storefront/storefront-meta-events')
            ->assertRedirect(Integrations::getUrl());

        $this->get(Integrations::getUrl())
            ->assertOk()
            ->assertSee('Event Log &amp; Retries', false);
    }

    public function test_selected_order_status_event_is_sent_with_system_source_and_is_idempotent(): void
    {
        [, $product, $setting] = $this->store('Status Event Store', 'status-event.example.test');
        $setting->update([
            'meta_tracking_enabled' => true,
            'meta_consent_required' => false,
            'meta_capi_enabled' => true,
            'meta_status_events_enabled' => true,
            'meta_status_events' => [Order::STAGE_CONFIRMED, Order::STAGE_DELIVERED],
            'meta_pixel_id' => '4455667788990011',
            'meta_tracking_credentials' => ['access_token' => 'status-event-token'],
        ]);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'status-trace'], 200),
        ]);
        $order = $this->draftStorefrontOrder($product);

        $order = app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_CONFIRMED);
        $transition = $order->statusTransitions()->sole();
        $eventId = "status-confirmed-{$order->company_id}-{$order->getKey()}-{$transition->getKey()}";

        Http::assertSent(function (Request $request) use ($eventId, $order): bool {
            $event = $request->data()['data'][0] ?? [];
            $userData = $event['user_data'] ?? [];

            return str_contains($request->url(), '/4455667788990011/events')
                && ($event['event_name'] ?? null) === 'Confirmed'
                && ($event['event_id'] ?? null) === $eventId
                && ($event['action_source'] ?? null) === 'system_generated'
                && ($event['custom_data']['workflow_stage'] ?? null) === Order::STAGE_CONFIRMED
                && ($event['custom_data']['order_status'] ?? null) === Order::STATUS_CONFIRMED
                && ($event['custom_data']['order_id'] ?? null) === $order->order_number
                && ($userData['em'][0] ?? null) === hash('sha256', 'status-buyer@example.test')
                && ($userData['ph'][0] ?? null) === hash('sha256', '8801712345678')
                && ! isset($userData['client_ip_address'], $userData['client_user_agent'], $userData['fbp'], $userData['fbc']);
        });
        $this->assertDatabaseHas('storefront_meta_events', [
            'order_id' => $order->getKey(),
            'event_name' => 'Confirmed',
            'event_id' => $eventId,
            'status' => StorefrontMetaEvent::STATUS_SENT,
        ]);

        app(StorefrontMetaConversionsService::class)->sendOrderStatus($transition->fresh());

        $metaRequests = collect(Http::recorded())->filter(
            fn (array $record): bool => str_contains($record[0]->url(), '/4455667788990011/events'),
        );
        $this->assertCount(1, $metaRequests);

        $order = app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_PROCESSING);
        $order = app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_SHIPPING);
        $order = app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_DELIVERED);

        $this->assertSame(Order::STATUS_COMPLETED, $order->status);
        $this->assertDatabaseHas('storefront_meta_events', [
            'order_id' => $order->getKey(),
            'event_name' => 'Delivered',
            'status' => StorefrontMetaEvent::STATUS_SENT,
        ]);
        $this->assertCount(2, collect(Http::recorded())->filter(
            fn (array $record): bool => str_contains($record[0]->url(), '/4455667788990011/events'),
        ));
    }

    public function test_disabled_or_unselected_status_events_do_not_send(): void
    {
        [, $product, $setting] = $this->store('Filtered Status Store', 'filtered-status.example.test');
        $setting->update([
            'meta_tracking_enabled' => true,
            'meta_consent_required' => false,
            'meta_capi_enabled' => true,
            'meta_status_events_enabled' => true,
            'meta_status_events' => [Order::STAGE_DELIVERED],
            'meta_pixel_id' => '2233445566778899',
            'meta_tracking_credentials' => ['access_token' => 'filtered-status-token'],
        ]);
        Http::fake();
        $order = $this->draftStorefrontOrder($product);

        app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_CONFIRMED);

        Http::assertNothingSent();
        $this->assertDatabaseCount('storefront_meta_events', 0);
    }

    public function test_an_empty_saved_status_events_list_falls_back_to_the_default_events_instead_of_sending_none(): void
    {
        // Regression test: Filament's CheckboxList dehydrates an
        // all-unchecked selection as `[]`, not `null`. The old fallback
        // (`is_array($enabled) ? $enabled : DEFAULT`) treated a saved `[]`
        // as "administrator explicitly wants zero events" and silently sent
        // nothing forever — indistinguishable from a healthy "Ready" status
        // in the admin panel. An empty array is now treated the same as an
        // unset value: fall back to the default event list.
        [, $product, $setting] = $this->store('Empty Status Events Store', 'empty-status-events.example.test');
        $setting->update([
            'meta_tracking_enabled' => true,
            'meta_consent_required' => false,
            'meta_capi_enabled' => true,
            'meta_status_events_enabled' => true,
            'meta_status_events' => [],
            'meta_pixel_id' => '4455667788990011',
            'meta_tracking_credentials' => ['access_token' => 'empty-status-token'],
        ]);
        Http::fake();
        $order = $this->draftStorefrontOrder($product);

        app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_CONFIRMED);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('storefront_meta_events', 1);
    }

    public function test_status_event_failure_never_rolls_back_order_and_is_safely_audited(): void
    {
        [, $product, $setting] = $this->store('Failed Status Store', 'failed-status.example.test');
        $setting->update([
            'meta_tracking_enabled' => true,
            'meta_consent_required' => false,
            'meta_capi_enabled' => true,
            'meta_status_events_enabled' => true,
            'meta_status_events' => [Order::STAGE_CONFIRMED],
            'meta_pixel_id' => '3344556677889900',
            'meta_tracking_credentials' => ['access_token' => 'never-log-status-token'],
        ]);
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Rejected access_token=never-log-status-token'],
            ], 503),
        ]);
        $order = $this->draftStorefrontOrder($product);

        $order = app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_CONFIRMED);

        $this->assertSame(Order::STATUS_CONFIRMED, $order->status);
        $audit = StorefrontMetaEvent::withoutGlobalScopes()->sole();
        $this->assertSame('Confirmed', $audit->event_name);
        $this->assertSame(StorefrontMetaEvent::STATUS_FAILED, $audit->status);
        $this->assertStringContainsString('[redacted]', $audit->error);
        $this->assertStringNotContainsString('never-log-status-token', $audit->error);
    }

    /**
     * MetaCapiSettings' bespoke section-nav header (the CSS/section-switch
     * assertions the old version of this test carried) is retired along with
     * that page — every Meta field now lives in one flat Tab on Integrations
     * via Filament's own Tabs component, which renders every tab's content
     * up front rather than section-by-section, so no "select a section"
     * step is needed to reach any field.
     */
    public function test_integrations_page_exposes_the_full_meta_capi_configuration_and_event_log(): void
    {
        $this->store('Status Settings Store', 'status-settings.example.test');
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($user);

        Livewire::test(Integrations::class)
            ->assertFormFieldExists('meta_status_events_enabled')
            ->assertFormFieldExists('meta_status_events')
            ->assertFormFieldExists('meta_consent_required')
            ->assertFormFieldExists('meta_browser_events')
            ->assertFormFieldExists('meta_custom_events')
            ->assertFormFieldExists('meta_purchase_timing')
            ->assertFormFieldExists('meta_tracking_credentials.additional_pixels')
            ->assertFormFieldExists('meta_domain_verification_tags')
            ->assertSee('Connection & Pixels')
            ->assertSee('Consent & Advanced Matching')
            ->assertSee('Event Log & Retries')
            ->assertFormFieldHidden('meta_tracking_credentials.access_token')
            ->assertFormFieldHidden('meta_status_events')
            ->fillForm([
                'meta_capi_enabled' => true,
                'meta_status_events_enabled' => true,
            ])
            ->assertFormFieldVisible('meta_tracking_credentials.access_token')
            ->assertFormFieldVisible('meta_tracking_credentials.additional_pixels')
            ->assertFormFieldVisible('meta_domain_verification_tags')
            ->assertFormFieldVisible('meta_status_events');
    }

    public function test_integrations_page_saves_the_full_meta_capi_configuration(): void
    {
        [, , $setting] = $this->store('CAPI Settings Store', 'capi-settings.example.test');
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($user);

        Livewire::test(Integrations::class)
            ->fillForm([
                'meta_tracking_enabled' => true,
                'meta_pixel_id' => '9988776655443322',
                'meta_capi_enabled' => true,
                'meta_tracking_credentials.access_token' => 'encrypted-capi-token',
                'meta_consent_required' => true,
                'meta_browser_tracking_enabled' => true,
                'meta_browser_events' => ['PageView', 'Purchase'],
                'meta_purchase_timing' => 'confirmed',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $setting->refresh();

        $this->assertTrue($setting->meta_tracking_enabled);
        $this->assertTrue($setting->meta_capi_enabled);
        $this->assertSame('9988776655443322', $setting->meta_pixel_id);
        $this->assertSame('encrypted-capi-token', $setting->meta_tracking_credentials['access_token']);
        $this->assertTrue($setting->meta_consent_required);
        $this->assertSame(['PageView', 'Purchase'], $setting->meta_browser_events);
        $this->assertSame('confirmed', $setting->meta_purchase_timing);
    }

    public function test_consent_blocks_tracking_until_granted_and_keeps_domain_verification_available(): void
    {
        [$company, $product, $setting] = $this->store('Consent Store', 'consent.example.test');
        $setting->update([
            'meta_tracking_enabled' => true,
            'meta_consent_required' => true,
            'meta_browser_tracking_enabled' => true,
            'meta_advanced_matching_enabled' => true,
            'meta_pixel_id' => '1010101010101010',
            'meta_tracking_credentials' => [
                'additional_pixels' => [['pixel_id' => '1111111111111111']],
            ],
            'meta_domain_verification_tags' => [['content' => 'verification_token_12345']],
            'meta_custom_events_enabled' => true,
            'meta_custom_events' => [[
                'type' => 'click',
                'event_name' => 'WhatsAppClick',
                'selector' => '#whatsapp-button',
            ]],
        ]);

        $this->get('http://consent.example.test/product/'.$product->slug)
            ->assertOk()
            ->assertSee('facebook-domain-verification', false)
            ->assertSee('verification_token_12345', false)
            ->assertSee('Accept Meta measurement')
            ->assertDontSee('connect.facebook.net', false)
            ->assertDontSee('WhatsAppClick', false);

        $cookieName = "storefront_meta_consent_{$company->getKey()}";
        $customer = Customer::query()->create([
            'name' => 'Consent Buyer',
            'phone' => '01700009999',
            'email' => 'consent-buyer@example.test',
            'customer_source' => 'website',
        ]);

        $this->actingAs($customer, 'customer')
            ->withCookie($cookieName, 'granted')
            ->get('http://consent.example.test/product/'.$product->slug)
            ->assertOk()
            ->assertSee('connect.facebook.net/en_US/fbevents.js', false)
            ->assertSee('1111111111111111', false)
            ->assertSee(hash('sha256', 'consent-buyer@example.test'), false)
            ->assertDontSee('consent-buyer@example.test', false)
            ->assertSee('WhatsAppClick', false)
            ->assertSee('Privacy choices');

        $this->withCookie($cookieName, 'denied')
            ->get('http://consent.example.test/product/'.$product->slug)
            ->assertOk()
            ->assertDontSee('connect.facebook.net', false);
    }

    public function test_meta_consent_form_sets_a_properly_encrypted_cookie_the_browser_can_send_back(): void
    {
        // Regression test: the consent banner used to set the cookie via raw
        // `document.cookie` JavaScript. Laravel's EncryptCookies middleware
        // (in the default 'web' group) silently discards any cookie it can't
        // decrypt, so that plain-text cookie was invisible to consentStatus()
        // on every subsequent request — the banner never went away and the
        // Pixel/CAPI never fired even after "Accept" was clicked. The fix
        // routes consent through a real POST so Laravel sets (and later
        // reads) the cookie through its own encryption pipeline.
        [$company, $product, $setting] = $this->store('Consent Cookie Store', 'consent-cookie.example.test');
        $setting->update([
            'meta_tracking_enabled' => true,
            'meta_consent_required' => true,
            'meta_browser_tracking_enabled' => true,
            'meta_pixel_id' => '1313131313131313',
        ]);

        $productUrl = 'http://consent-cookie.example.test/product/'.$product->slug;
        $cookieName = "storefront_meta_consent_{$company->getKey()}";

        $response = $this->post('http://consent-cookie.example.test/meta-consent', [
            'choice' => 'granted',
            'redirect_to' => $productUrl,
        ]);

        $response->assertRedirect($productUrl);
        $response->assertCookie($cookieName, 'granted');

        $this->withCookie($cookieName, 'granted')
            ->get($productUrl)
            ->assertOk()
            ->assertSee('connect.facebook.net/en_US/fbevents.js', false)
            ->assertSee('data-meta-consent-panel', false);
    }

    public function test_required_consent_also_blocks_capi_delivery(): void
    {
        [, $product, $setting] = $this->store('CAPI Consent Store', 'capi-consent.example.test');
        $setting->update([
            'meta_tracking_enabled' => true,
            'meta_consent_required' => true,
            'meta_capi_enabled' => true,
            'meta_pixel_id' => '1212121212121212',
            'meta_tracking_credentials' => ['access_token' => 'consent-token'],
        ]);
        Http::fake();

        $this->post('http://capi-consent.example.test/cart/items/'.$product->slug, ['quantity' => 1]);
        $this->post('http://capi-consent.example.test/checkout', $this->checkoutData())
            ->assertRedirectContains('/checkout/success/');

        Http::assertNothingSent();
        $this->assertDatabaseCount('storefront_meta_attributions', 0);
        $this->assertDatabaseCount('storefront_meta_events', 0);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_expanded_browser_commerce_events_render_for_catalog_cart_remove_and_registration(): void
    {
        [, $product, $setting] = $this->store('Commerce Events Store', 'commerce-events.example.test');
        $category = Category::query()->create(['name' => 'Tracked Category', 'slug' => 'tracked-category', 'is_active' => true]);
        $product->update(['category_id' => $category->getKey()]);
        $setting->update([
            'meta_tracking_enabled' => true,
            'meta_consent_required' => false,
            'meta_browser_tracking_enabled' => true,
            'meta_pixel_id' => '2020202020202020',
        ]);

        $this->get('http://commerce-events.example.test/category/'.$category->slug.'?q=Product')
            ->assertOk()
            ->assertSee("'ViewCategory'", false)
            ->assertSee("'ViewItemList'", false)
            ->assertSee("'Search'", false);

        $this->post('http://commerce-events.example.test/cart/items/'.$product->slug, ['quantity' => 1]);
        $this->get('http://commerce-events.example.test/cart')
            ->assertOk()
            ->assertSee("'ViewCart'", false);

        $this->delete('http://commerce-events.example.test/cart/items/'.$product->slug)->assertRedirect();
        $this->get('http://commerce-events.example.test/cart')
            ->assertOk()
            ->assertSee("'RemoveFromCart'", false);

        $response = $this->post('http://commerce-events.example.test/account/register', [
            'name' => 'Registered Meta Buyer',
            'phone' => '01711112222',
            'email' => 'registered-meta@example.test',
            'address' => 'Dhaka',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ]);
        $this->get((string) $response->headers->get('Location'))
            ->assertOk()
            ->assertSee("'CompleteRegistration'", false);
    }

    public function test_risk_aware_purchase_waits_for_confirmation_and_clears_encrypted_context_after_send(): void
    {
        [, $product, $setting] = $this->store('Delayed Purchase Store', 'delayed-purchase.example.test');
        $setting->update([
            'meta_tracking_enabled' => true,
            'meta_consent_required' => false,
            'meta_browser_tracking_enabled' => true,
            'meta_capi_enabled' => true,
            'meta_purchase_timing' => 'risk_aware',
            'meta_purchase_success_ratio_threshold' => 70,
            'meta_pixel_id' => '3030303030303030',
            'meta_tracking_credentials' => ['access_token' => 'delayed-token'],
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
        $order = $this->draftStorefrontOrder($product);
        $context = [
            'client_ip_address' => '198.51.100.44',
            'client_user_agent' => 'Delayed Browser',
            'fbp' => 'fb.1.1720000000.delayed123',
            'event_source_url' => 'https://delayed-purchase.example.test/checkout',
            'consent_granted' => true,
        ];

        app(StorefrontMetaDispatchService::class)->captureOrder($order, $setting, $context, 25.0);

        Http::assertNothingSent();
        $attribution = StorefrontMetaAttribution::withoutGlobalScopes()->sole();
        $this->assertSame(StorefrontMetaAttribution::DUE_CONFIRMED, $attribution->purchase_due_stage);
        $this->assertNotNull($attribution->context);
        $this->assertStringNotContainsString('Delayed Browser', (string) DB::table('storefront_meta_attributions')->value('context'));

        $order = app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_CONFIRMED);

        Http::assertSent(fn (Request $request): bool => ($request->data()['data'][0]['event_name'] ?? null) === 'Purchase'
            && ($request->data()['data'][0]['user_data']['client_ip_address'] ?? null) === '198.51.100.44');
        $this->assertNull($attribution->fresh()->context);
        $this->assertNotNull($attribution->fresh()->purchase_dispatched_at);
        $this->assertSame(Order::STATUS_CONFIRMED, $order->status);
    }

    public function test_completed_purchase_timing_waits_for_order_status_to_show_completed(): void
    {
        [, $product, $setting] = $this->store('Completed Timing Store', 'completed-timing.example.test');
        $setting->update([
            'meta_tracking_enabled' => true,
            'meta_consent_required' => false,
            'meta_capi_enabled' => true,
            'meta_purchase_timing' => 'completed',
            'meta_pixel_id' => '6060606060606060',
            'meta_tracking_credentials' => ['access_token' => 'completed-timing-token'],
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
        $order = $this->draftStorefrontOrder($product);

        app(StorefrontMetaDispatchService::class)->captureOrder(
            $order,
            $setting,
            ['event_source_url' => 'https://completed-timing.example.test/checkout', 'consent_granted' => true],
        );

        $attribution = StorefrontMetaAttribution::withoutGlobalScopes()->sole();
        $this->assertSame(StorefrontMetaAttribution::DUE_COMPLETED, $attribution->purchase_due_stage);
        Http::assertNothingSent();

        $order = app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_CONFIRMED);
        Http::assertNothingSent();

        app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_COMPLETED);

        Http::assertSent(fn (Request $request): bool => ($request->data()['data'][0]['event_name'] ?? null) === 'Purchase');
        $this->assertNotNull($attribution->fresh()->purchase_dispatched_at);
    }

    public function test_completed_purchase_timing_also_fires_when_the_delivered_stage_completes_the_order(): void
    {
        // Delivered sets the order's status to Completed too (courier-tracked
        // fulfillment never passes through the separate "Completed" stage
        // explicitly) — "only after order status shows Completed" must cover
        // this path as well, not just an explicit Completed transition.
        [, $product, $setting] = $this->store('Delivered Completes Store', 'delivered-completes.example.test');
        $setting->update([
            'meta_tracking_enabled' => true,
            'meta_consent_required' => false,
            'meta_capi_enabled' => true,
            'meta_purchase_timing' => 'completed',
            'meta_pixel_id' => '7070707070707070',
            'meta_tracking_credentials' => ['access_token' => 'delivered-completes-token'],
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
        $order = $this->draftStorefrontOrder($product);

        app(StorefrontMetaDispatchService::class)->captureOrder(
            $order,
            $setting,
            ['event_source_url' => 'https://delivered-completes.example.test/checkout', 'consent_granted' => true],
        );
        $order = app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_CONFIRMED);
        Http::assertNothingSent();

        $order = app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_DELIVERED);

        $this->assertSame(Order::STATUS_COMPLETED, $order->status);
        Http::assertSent(fn (Request $request): bool => ($request->data()['data'][0]['event_name'] ?? null) === 'Purchase');
    }

    public function test_recovered_checkout_sends_recovered_event_from_recovery_subsystem(): void
    {
        [, $product, $setting] = $this->store('Recovered Event Store', 'recovered-event.example.test');
        $setting->update([
            'meta_tracking_enabled' => true,
            'meta_consent_required' => false,
            'meta_capi_enabled' => true,
            'meta_purchase_timing' => 'confirmed',
            'meta_status_events_enabled' => true,
            'meta_status_events' => [StorefrontMetaTrackingService::RECOVERED_STAGE],
            'meta_pixel_id' => '4040404040404040',
            'meta_tracking_credentials' => ['access_token' => 'recovered-token'],
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
        $order = $this->draftStorefrontOrder($product);
        $cart = StorefrontCartRecord::withoutGlobalScopes()->create([
            'company_id' => $order->company_id,
            'session_id' => 'recovered-session',
            'items' => [['product_id' => $product->getKey(), 'quantity' => 1]],
            'subtotal' => 950,
            'status' => StorefrontCartRecord::STATUS_CONVERTED,
            'reminded_at' => now()->subHour(),
            'recovery_opened_at' => now()->subMinutes(30),
            'recovered_at' => now(),
            'recovered_order_id' => $order->getKey(),
            'last_reminder_channels' => ['whatsapp'],
        ]);

        app(StorefrontMetaDispatchService::class)->captureOrder(
            $order,
            $setting,
            ['event_source_url' => 'https://recovered-event.example.test/checkout', 'consent_granted' => true],
            null,
            $cart->getKey(),
        );

        Http::assertSent(fn (Request $request): bool => ($request->data()['data'][0]['event_name'] ?? null) === 'Recovered'
            && ($request->data()['data'][0]['custom_data']['workflow_stage'] ?? null) === 'recovered');
        $this->assertDatabaseHas('storefront_meta_events', [
            'event_name' => 'Recovered',
            'storefront_cart_record_id' => $cart->getKey(),
            'status' => StorefrontMetaEvent::STATUS_SENT,
        ]);
        $this->assertNotNull(StorefrontMetaAttribution::withoutGlobalScopes()->sole()->recovered_dispatched_at);
    }

    public function test_multiple_pixels_receive_the_same_event_and_failed_delivery_can_be_replayed(): void
    {
        [, $product, $setting] = $this->store('Multi Pixel Store', 'multi-pixel.example.test');
        $setting->update([
            'meta_tracking_enabled' => true,
            'meta_consent_required' => false,
            'meta_capi_enabled' => true,
            'meta_pixel_id' => '5050505050505050',
            'meta_tracking_credentials' => [
                'access_token' => 'primary-token',
                'additional_pixels' => [[
                    'pixel_id' => '6060606060606060',
                    'access_token' => 'secondary-token',
                ]],
            ],
        ]);
        $order = $this->draftStorefrontOrder($product);
        $secondaryRecovered = false;
        Http::fake(function (Request $request) use (&$secondaryRecovered) {
            return str_contains($request->url(), '/6060606060606060/events') && ! $secondaryRecovered
                ? Http::response(['error' => ['message' => 'Temporary secondary failure']], 503)
                : Http::response(['events_received' => 1], 200);
        });

        $this->assertFalse(app(StorefrontMetaConversionsService::class)->sendPurchase($order, []));
        $this->assertDatabaseHas('storefront_meta_events', ['pixel_id' => '5050505050505050', 'status' => StorefrontMetaEvent::STATUS_SENT]);
        $this->assertDatabaseHas('storefront_meta_events', ['pixel_id' => '6060606060606060', 'status' => StorefrontMetaEvent::STATUS_FAILED]);

        $failed = StorefrontMetaEvent::withoutGlobalScopes()->where('status', StorefrontMetaEvent::STATUS_FAILED)->sole();
        $failed->forceFill(['updated_at' => now()->subMinutes(11)])->saveQuietly();
        $expiredOrder = $this->draftStorefrontOrder($product);
        $expiredAttribution = StorefrontMetaAttribution::withoutGlobalScopes()->create([
            'company_id' => $expiredOrder->company_id,
            'order_id' => $expiredOrder->getKey(),
            'context' => ['client_ip_address' => '198.51.100.55'],
            'purchase_due_stage' => StorefrontMetaAttribution::DUE_IMMEDIATE,
            'consent_granted' => true,
        ]);
        DB::table('storefront_meta_attributions')
            ->where('id', $expiredAttribution->getKey())
            ->update(['updated_at' => now()->subDays(31)]);
        $secondaryRecovered = true;

        $this->artisan('storefront:retry-meta-events')
            ->assertSuccessful()
            ->expectsOutput('Meta event retries queued: 1.')
            ->expectsOutput('Expired Meta attribution contexts cleared: 1.');

        $this->assertSame(StorefrontMetaEvent::STATUS_SENT, $failed->fresh()->status);
        $this->assertSame(2, $failed->fresh()->attempts);
        $this->assertNull($expiredAttribution->fresh()->context);
        $this->assertDatabaseCount('storefront_meta_events', 2);
    }

    public function test_the_purchase_pixel_comes_from_the_site_the_order_was_placed_on(): void
    {
        [$company] = $this->store('Two Sites', 'twosites.example.test');
        $nextjs = StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'site_type' => StorefrontSetting::SITE_NEXTJS,
            'nextjs_site_enabled' => true,
            'meta_tracking_enabled' => true,
            'meta_consent_required' => false,
            'meta_capi_enabled' => true,
            'meta_pixel_id' => '1122334455667788',
            'meta_tracking_credentials' => ['access_token' => 'nextjs-token'],
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'trace-1'], 200)]);

        $fromNextjs = Order::query()->create(['customer_name' => 'Next Buyer', 'status' => 'draft', 'source' => Order::SOURCE_STOREFRONT, 'storefront_setting_id' => $nextjs->getKey()]);
        $fromBlade = Order::query()->create(['customer_name' => 'Blade Buyer', 'status' => 'draft', 'source' => Order::SOURCE_STOREFRONT]);

        $this->assertTrue(app(StorefrontMetaConversionsService::class)->sendPurchase($fromNextjs->fresh(), []));
        $this->assertFalse(app(StorefrontMetaConversionsService::class)->sendPurchase($fromBlade->fresh(), []), 'The Blade row has no pixel configured.');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/1122334455667788/events')
            && $request->data()['access_token'] === 'nextjs-token');
    }

    private function store(string $name, string $domain): array
    {
        $company = Company::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => str($name)->substr(0, 3)->upper()->toString(),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        $setting = StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'theme_color' => '#0F766E',
            'meta_title' => $name,
            'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
        ]);

        app(CompanyContext::class)->set($company);

        $product = Product::query()->create([
            'name' => $name.' Product',
            'sku' => str($name)->slug()->upper()->append('-001')->toString(),
            'price' => 1000,
            'sale_price' => 950,
            'cost_price' => 500,
            'stock' => 8,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);

        return [$company, $product, $setting];
    }

    private function checkoutData(): array
    {
        return [
            'name' => 'Meta Buyer',
            'phone' => '+8801700111222',
            'email' => 'buyer@example.test',
            'address' => 'Mirpur, Dhaka',
        ];
    }

    private function draftStorefrontOrder(Product $product): Order
    {
        StockMovement::query()->create([
            'product_id' => $product->getKey(),
            'type' => 'opening',
            'quantity' => 10,
        ]);
        $customer = Customer::query()->create([
            'name' => 'Status Buyer',
            'phone' => '01712345678',
            'email' => 'status-buyer@example.test',
            'customer_source' => 'website',
        ]);
        $order = Order::query()->create([
            'customer_id' => $customer->getKey(),
            'status' => Order::STATUS_DRAFT,
            'source' => Order::SOURCE_STOREFRONT,
        ]);
        OrderItem::query()->create([
            'order_id' => $order->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => 1,
            'unit_price' => 950,
        ]);

        return $order->refresh();
    }
}
