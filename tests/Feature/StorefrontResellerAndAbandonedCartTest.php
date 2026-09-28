<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ConversationChannel;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StorefrontCartRecord;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\StorefrontNotificationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StorefrontResellerAndAbandonedCartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_reseller_application_creates_pending_customer(): void
    {
        $company = $this->createStore('reseller.example.test');

        $this->get('http://reseller.example.test/reseller')
            ->assertOk()
            ->assertSee('Become a')
            ->assertSee('reseller');

        $this->post('http://reseller.example.test/reseller', [
            'name' => 'Reseller Rahim',
            'phone' => '01811111111',
            'business_name' => 'Rahim Traders',
            'note' => 'Wholesale electronics buyer',
        ])->assertRedirect();

        $customer = Customer::withoutGlobalScopes()->where('phone', '01811111111')->first();
        $this->assertNotNull($customer);
        $this->assertSame('pending', $customer->reseller_status);
        $this->assertSame('Rahim Traders', $customer->business_name);
        $this->assertSame($company->getKey(), $customer->company_id);
    }

    public function test_approved_reseller_stays_approved_on_reapplication(): void
    {
        $company = $this->createStore('reseller2.example.test');

        app(CompanyContext::class)->set($company);

        Customer::query()->create([
            'name' => 'Approved Reseller',
            'phone' => '01822222222',
            'reseller_status' => 'approved',
            'opening_balance' => 0,
            'is_active' => true,
        ]);

        $this->post('http://reseller2.example.test/reseller', [
            'name' => 'Approved Reseller',
            'phone' => '01822222222',
            'business_name' => 'Approved Traders',
        ])->assertRedirect();

        $this->assertSame('approved', Customer::withoutGlobalScopes()->where('phone', '01822222222')->first()->reseller_status);
    }

    public function test_reseller_preview_is_limited_to_the_staff_members_own_companies(): void
    {
        $own = $this->createStore('own-reseller.example.test');
        $other = $this->createStore('other-reseller.example.test', invoicePrefix: 'OTH');

        $user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $user->companies()->detach();
        $user->companies()->attach($own, ['role' => 'sales_staff', 'is_default' => true]);

        $this->actingAs($user)
            ->get(route('storefront.preview.reseller.show', $own->slug))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('storefront.preview.reseller.show', $other->slug))
            ->assertNotFound();

        $this->actingAs($user)
            ->post(route('storefront.preview.reseller.store', $other->slug), [
                'name' => 'Cross Company',
                'phone' => '01833333333',
                'business_name' => 'Cross Shop',
            ])
            ->assertNotFound();

        $this->assertFalse(Customer::withoutGlobalScopes()->where('phone', '01833333333')->exists());
    }

    public function test_cart_activity_persists_record_and_checkout_contact_is_remembered(): void
    {
        $company = $this->createStore('abandon.example.test');

        app(CompanyContext::class)->set($company);

        $product = Product::query()->create([
            'name' => 'Abandoned Product',
            'sku' => 'ABANDON-01',
            'price' => 500,
            'sale_price' => 450,
            'cost_price' => 200,
            'stock' => 10,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);

        $this->post('http://abandon.example.test/cart/items/'.$product->slug, ['quantity' => 2]);

        $record = StorefrontCartRecord::withoutGlobalScopes()->first();
        $this->assertNotNull($record);
        $this->assertSame(StorefrontCartRecord::STATUS_ACTIVE, $record->status);
        $this->assertNull($record->phone);

        // Completing checkout marks the record converted and stores contact.
        $this->post('http://abandon.example.test/checkout', [
            'name' => 'Cart Buyer',
            'phone' => '01833333333',
            'address' => 'Dhaka',
        ]);

        $record->refresh();
        $this->assertSame('01833333333', $record->phone);
        $this->assertSame(StorefrontCartRecord::STATUS_CONVERTED, $record->status);
    }

    public function test_reminder_command_sends_and_marks_stale_carts(): void
    {
        $company = $this->createStore('remind.example.test', reminders: true);

        Http::fake([
            'sms.example.test/*' => Http::response('ok'),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]]),
        ]);

        $stale = StorefrontCartRecord::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'session_id' => 'sess-stale',
            'phone' => '01844444444',
            'customer_name' => 'Stale Buyer',
            'items' => [['product_id' => 1, 'variant_id' => null, 'quantity' => 2]],
            'status' => StorefrontCartRecord::STATUS_ACTIVE,
        ]);
        StorefrontCartRecord::withoutGlobalScopes()->whereKey($stale->getKey())->update(['updated_at' => now()->subHours(10)]);

        // Fresh cart and phone-less cart must not be reminded.
        StorefrontCartRecord::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'session_id' => 'sess-fresh',
            'phone' => '01855555555',
            'items' => [['product_id' => 1, 'variant_id' => null, 'quantity' => 1]],
            'status' => StorefrontCartRecord::STATUS_ACTIVE,
        ]);

        $this->artisan('storefront:send-abandoned-cart-reminders')
            ->expectsOutputToContain('reminders sent: 1')
            ->assertSuccessful();

        $this->assertSame(StorefrontCartRecord::STATUS_REMINDED, $stale->fresh()->status);
        $this->assertNotNull($stale->fresh()->reminded_at);
    }

    public function test_whatsapp_reminder_prefers_the_selected_company_chat_channel_over_legacy_credentials(): void
    {
        $company = $this->createStore('central-channel.example.test', reminders: true);
        app(CompanyContext::class)->set($company);
        $channel = ConversationChannel::query()->create([
            'provider' => 'whatsapp',
            'external_id' => 'central-phone-id',
            'waba_id' => 'central-waba-id',
            'display_name' => 'Central WhatsApp',
            'access_token' => 'central-channel-token',
            'app_secret' => 'central-app-secret',
            'verify_token' => 'central-verify-token',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->clear();

        $setting = StorefrontSetting::withoutGlobalScopes()->where('company_id', $company->getKey())->firstOrFail();
        $credentials = $setting->notification_credentials;
        $credentials['whatsapp_channel_id'] = $channel->getKey();
        $setting->forceFill(['notification_credentials' => $credentials])->save();

        Http::fake([
            'graph.facebook.com/*' => Http::sequence()
                ->push(['messages' => [['id' => 'wamid.central']]])
                ->push(['messages' => []]),
        ]);

        $sent = app(StorefrontNotificationService::class)->sendWhatsAppTemplate(
            $setting->fresh(),
            '01844444444',
            ['Customer', $company->name],
        );

        $this->assertTrue($sent);
        $this->assertNotNull($channel->fresh()->last_outbound_at);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v25.0/central-phone-id/messages'
            && $request->hasHeader('Authorization', 'Bearer central-channel-token')
            && ! str_contains($request->url(), 'wa_token'));

        $this->assertFalse(app(StorefrontNotificationService::class)->sendWhatsAppTemplate(
            $setting->fresh(),
            '01844444444',
            ['Customer', $company->name],
        ));
        $this->assertStringContainsString(
            'returned no message ID',
            (string) $channel->fresh()->last_error,
        );

        $channel->forceFill(['access_token' => null])->save();
        $this->assertFalse(app(StorefrontNotificationService::class)->sendWhatsAppTemplate(
            $setting->fresh(),
            '01844444444',
            ['Customer', $company->name],
        ));
        Http::assertSentCount(2);
        $this->assertStringContainsString(
            'missing its access token',
            (string) $channel->fresh()->last_error,
        );
    }

    private function createStore(string $domain, bool $reminders = false, string $invoicePrefix = 'RSL'): Company
    {
        $company = Company::query()->create([
            'name' => 'Store '.$domain,
            'slug' => str($domain)->slug()->toString(),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => $invoicePrefix,
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'theme_color' => '#0F766E',
            'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
            'abandoned_cart_reminders_enabled' => $reminders,
            'abandoned_cart_delay_hours' => 6,
            'notification_credentials' => $reminders ? [
                'sms_api_url' => 'http://sms.example.test/send?key={api_key}&to={phone}&msg={message}&from={sender_id}',
                'sms_api_key' => 'sms_key',
                'sms_sender_id' => 'ZAMZAM',
                'whatsapp_token' => 'wa_token',
                'whatsapp_phone_number_id' => '1234567890',
                'whatsapp_template_name' => 'abandoned_cart',
                'whatsapp_template_language' => 'bn',
            ] : null,
        ]);

        return $company;
    }
}
