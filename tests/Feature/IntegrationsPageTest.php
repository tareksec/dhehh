<?php

namespace Tests\Feature;

use App\Filament\Pages\Integrations;
use App\Jobs\DispatchWebsiteWebhookJob;
use App\Jobs\RetryStorefrontMetaEventJob;
use App\Livewire\MetaEventLogTable;
use App\Livewire\WebsiteApiKeysTable;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\CourierProvider;
use App\Models\MetaAdAccount;
use App\Models\StorefrontMetaEvent;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\Crm\AiSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class IntegrationsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_guidelines_editor_saves_and_reloads_company_specific_text(): void
    {
        $company = $this->makeCompany();
        $this->actingAs($this->makeSuperAdmin($company))
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);
        $guidelines = "## কথা বলার নিয়ম\n\nআপনি বলুন। একবারে একটি প্রশ্ন করুন।";
        Livewire::test(Integrations::class)
            ->assertSee('সেলস এজেন্টের কথাবার্তার নির্দেশনা')
            ->assertSet('data.ai_messaging_sales_guidelines', app(AiSettingsService::class)->defaultSalesGuidelines())
            ->set('data.ai_messaging_sales_guidelines', $guidelines)
            ->call('save')
            ->assertHasNoFormErrors();
        Livewire::test(Integrations::class)->assertSet('data.ai_messaging_sales_guidelines', $guidelines);
        $this->assertSame($guidelines, app(AiSettingsService::class)->all($company->fresh(), 'messaging')['sales_guidelines']);
        $this->assertSame('', app(AiSettingsService::class)->all($company->fresh(), 'ad_assistant')['sales_guidelines']);
    }

    /**
     * The image sub-model credential block only renders (and is only
     * required) once both "Read customer images" and "Use a separate model
     * for images" are on — leaving it off must never block saving the rest
     * of the form, since those fields are hidden and optional by default.
     */
    public function test_vision_alone_saves_without_the_optional_separate_image_model_fields(): void
    {
        $company = $this->makeCompany();
        $this->actingAs($this->makeSuperAdmin($company))
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        Livewire::test(Integrations::class)
            ->set('data.ai_messaging_api_format', 'anthropic')
            ->set('data.ai_messaging_provider', 'Anthropic (Claude)')
            ->set('data.ai_messaging_model', 'claude-haiku-4-5-20251001')
            ->set('data.ai_messaging_api_key', 'messaging-key')
            ->set('data.ai_messaging_vision_enabled', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(AiSettingsService::class)->all($company->fresh(), 'messaging');
        $this->assertTrue($settings['vision_enabled']);
        $this->assertFalse($settings['image_model_enabled']);
    }

    public function test_separate_image_model_and_voice_transcription_settings_save_and_reload(): void
    {
        $company = $this->makeCompany();
        $this->actingAs($this->makeSuperAdmin($company))
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        Livewire::test(Integrations::class)
            ->set('data.ai_messaging_api_format', 'anthropic')
            ->set('data.ai_messaging_provider', 'Anthropic (Claude)')
            ->set('data.ai_messaging_model', 'claude-haiku-4-5-20251001')
            ->set('data.ai_messaging_api_key', 'messaging-key')
            ->set('data.ai_messaging_vision_enabled', true)
            ->set('data.ai_messaging_image_model_enabled', true)
            ->set('data.ai_messaging_image_api_format', 'openai')
            ->set('data.ai_messaging_image_provider', 'OpenRouter')
            ->set('data.ai_messaging_image_model', 'google/gemini-3-flash-preview')
            ->set('data.ai_messaging_image_api_key', 'image-key')
            ->set('data.ai_messaging_voice_enabled', true)
            ->set('data.ai_messaging_voice_provider', 'Groq')
            ->set('data.ai_messaging_voice_base_url', 'https://api.groq.com/openai/v1/audio/transcriptions')
            ->set('data.ai_messaging_voice_model', 'whisper-large-v3')
            ->set('data.ai_messaging_voice_api_key', 'voice-key')
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(Integrations::class)
            ->assertSet('data.ai_messaging_image_model_enabled', true)
            ->assertSet('data.ai_messaging_image_model', 'google/gemini-3-flash-preview')
            ->assertSet('data.ai_messaging_image_has_api_key', true)
            ->assertSet('data.ai_messaging_voice_enabled', true)
            ->assertSet('data.ai_messaging_voice_model', 'whisper-large-v3')
            ->assertSet('data.ai_messaging_voice_has_api_key', true);

        $settings = app(AiSettingsService::class)->all($company->fresh(), 'messaging');
        $this->assertSame('image-key', $settings['image_api_key']);
        $this->assertSame('voice-key', $settings['voice_api_key']);
    }

    public function test_super_admin_can_save_every_tab_in_one_go_for_a_brand_new_company(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeSuperAdmin($company);
        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        // No StorefrontSetting row exists yet for this company — the required
        // "Active gateway" Select must still validate via an explicit
        // mount()-time default, not rely on the field's own ->default().
        Livewire::test(Integrations::class)
            // Any OpenAI-compatible provider (not just OpenAI itself) can be
            // added via the free-text label + base URL, without a code change.
            ->set('data.ai_messaging_api_format', 'openai')
            ->set('data.ai_messaging_provider', 'DeepSeek')
            ->set('data.ai_messaging_base_url', 'https://api.deepseek.com/chat/completions')
            ->set('data.ai_messaging_model', 'deepseek-chat')
            ->set('data.ai_messaging_confidence_threshold', 0.8)
            ->set('data.ai_messaging_max_consecutive_ai_replies', 3)
            ->set('data.ai_messaging_api_key', 'sk-live-test')
            ->set('data.woocommerce_base_url', 'https://shop.example.com')
            ->set('data.woocommerce_credentials.consumer_key', 'ck_1')
            ->set('data.woocommerce_credentials.consumer_secret', 'cs_1')
            ->set('data.online_payment_enabled', true)
            ->set('data.online_payment_gateway', 'zinipay')
            ->set('data.payment_credentials.zinipay_api_key', 'zk_1')
            ->set('data.payment_credentials.zinipay_base_url', 'https://api.zinipay.example/custom')
            ->set('data.meta_tracking_enabled', true)
            ->set('data.meta_pixel_id', '123456789')
            ->set('data.meta_capi_enabled', true)
            ->set('data.meta_tracking_credentials.access_token', 'tok_1')
            ->call('save')
            ->assertHasNoFormErrors();

        $ai = app(AiSettingsService::class)->all($company, AiSettingsService::TOOL_MESSAGING);
        $this->assertSame('deepseek-chat', $ai['model']);
        $this->assertSame('sk-live-test', $ai['api_key']);
        $this->assertSame('openai', $ai['api_format']);
        $this->assertSame('DeepSeek', $ai['provider']);
        $this->assertSame('https://api.deepseek.com/chat/completions', $ai['base_url']);

        $setting = StorefrontSetting::withoutGlobalScopes()->where('company_id', $company->getKey())->firstOrFail();
        $this->assertSame('https://shop.example.com', $setting->woocommerce_base_url);
        $this->assertSame('ck_1', $setting->woocommerce_credentials['consumer_key']);
        $this->assertTrue((bool) $setting->online_payment_enabled);
        $this->assertSame('zk_1', $setting->payment_credentials['zinipay_api_key']);
        $this->assertSame('https://api.zinipay.example/custom', $setting->payment_credentials['zinipay_base_url']);
        $this->assertSame('123456789', $setting->meta_pixel_id);
        $this->assertSame('tok_1', $setting->meta_tracking_credentials['access_token']);
    }

    public function test_each_ai_tool_tab_saves_independently_from_the_others(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeSuperAdmin($company);
        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        Livewire::test(Integrations::class)
            ->set('data.ai_messaging_api_format', 'anthropic')
            ->set('data.ai_messaging_provider', 'Anthropic (Claude)')
            ->set('data.ai_messaging_model', 'claude-haiku-4-5-20251001')
            ->set('data.ai_messaging_api_key', 'messaging-key')
            ->set('data.ai_ad_assistant_api_format', 'openai')
            ->set('data.ai_ad_assistant_provider', 'OpenAI')
            ->set('data.ai_ad_assistant_model', 'gpt-5')
            ->set('data.ai_ad_assistant_api_key', 'ad-assistant-key')
            ->set('data.ai_landing_page_api_format', 'openai')
            ->set('data.ai_landing_page_provider', 'DeepSeek')
            ->set('data.ai_landing_page_base_url', 'https://api.deepseek.com/chat/completions')
            ->set('data.ai_landing_page_model', 'deepseek-chat')
            ->set('data.ai_landing_page_api_key', 'landing-page-key')
            ->call('save')
            ->assertHasNoFormErrors();

        $service = app(AiSettingsService::class);
        $fresh = $company->fresh();

        $messaging = $service->all($fresh, AiSettingsService::TOOL_MESSAGING);
        $adAssistant = $service->all($fresh, AiSettingsService::TOOL_AD_ASSISTANT);
        $landingPage = $service->all($fresh, AiSettingsService::TOOL_LANDING_PAGE);

        $this->assertSame('claude-haiku-4-5-20251001', $messaging['model']);
        $this->assertSame('messaging-key', $messaging['api_key']);
        $this->assertSame('gpt-5', $adAssistant['model']);
        $this->assertSame('ad-assistant-key', $adAssistant['api_key']);
        $this->assertSame('deepseek-chat', $landingPage['model']);
        $this->assertSame('https://api.deepseek.com/chat/completions', $landingPage['base_url']);
        $this->assertSame('landing-page-key', $landingPage['api_key']);
    }

    public function test_picking_a_popular_provider_auto_fills_provider_base_url_and_a_default_model(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeSuperAdmin($company);
        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        Livewire::test(Integrations::class)
            ->set('data.ai_landing_page_provider_preset', 'deepseek')
            ->assertSet('data.ai_landing_page_provider', 'DeepSeek')
            ->assertSet('data.ai_landing_page_base_url', 'https://api.deepseek.com/chat/completions')
            ->assertSet('data.ai_landing_page_api_format', 'openai')
            ->assertSet('data.ai_landing_page_model', 'deepseek-v4-pro')
            // The model picker offers that provider's own models — picking
            // a different one just updates the real Model field, the
            // provider/base_url stay untouched.
            ->set('data.ai_landing_page_model_picker', 'deepseek-v4-flash')
            ->assertSet('data.ai_landing_page_model', 'deepseek-v4-flash')
            ->call('save')
            ->assertHasNoFormErrors();

        $landingPage = app(AiSettingsService::class)->all($company->fresh(), AiSettingsService::TOOL_LANDING_PAGE);
        $this->assertSame('DeepSeek', $landingPage['provider']);
        $this->assertSame('https://api.deepseek.com/chat/completions', $landingPage['base_url']);
        $this->assertSame('openai', $landingPage['api_format']);
        $this->assertSame('deepseek-v4-flash', $landingPage['model']);
    }

    /**
     * Regression test: the model picker's own options legitimately shrink
     * or change whenever provider_preset/api_format change elsewhere on the
     * form. Since Filament validates a Select's submitted state against its
     * own live options(), a stale-but-harmless picker selection (it's
     * dehydrated(false), never persisted) must never block saving.
     */
    public function test_changing_api_format_after_picking_a_model_never_blocks_saving(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeSuperAdmin($company);
        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        Livewire::test(Integrations::class)
            ->set('data.ai_messaging_provider_preset', 'deepseek')
            // Switching away from the preset's own format leaves the model
            // picker's options computed fresh (now empty, since no preset
            // is selected and the format isn't Anthropic either) — this
            // must not raise "the selected ... is invalid" on save.
            ->set('data.ai_messaging_api_format', 'anthropic')
            ->set('data.ai_messaging_api_key', 'messaging-key')
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_saving_ai_settings_for_one_company_never_leaks_into_another(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();
        $userA = $this->makeSuperAdmin($companyA);
        $userA->companies()->attach($companyB, ['role' => 'super_admin', 'is_default' => false]);

        $this->actingAs($userA)
            ->withSession(['current_company_id' => $companyA->getKey(), 'current_company_selection_explicit' => true]);

        // makeCompany() above leaves CompanyContext pointed at whichever
        // company it created last (company B) as a side effect — put it
        // back on company A before driving the Livewire component, since
        // Livewire::test() (unlike a real page load) never re-runs
        // SetCurrentCompany to re-resolve it from the session itself.
        app(CompanyContext::class)->set($companyA);

        Livewire::test(Integrations::class)
            ->set('data.ai_ad_assistant_api_format', 'openai')
            ->set('data.ai_ad_assistant_provider', 'OpenAI')
            ->set('data.ai_ad_assistant_model', 'gpt-5-company-a')
            ->set('data.ai_ad_assistant_api_key', 'company-a-secret')
            ->call('save')
            ->assertHasNoFormErrors();

        // Company A's own saved config is exactly what was submitted.
        $service = app(AiSettingsService::class);
        $ai = $service->all($companyA->fresh(), AiSettingsService::TOOL_AD_ASSISTANT);
        $this->assertSame('gpt-5-company-a', $ai['model']);
        $this->assertSame('company-a-secret', $ai['api_key']);

        // Company B (a completely separate row, never touched by that save)
        // still reads plain defaults straight from the service.
        $untouched = $service->all($companyB->fresh(), AiSettingsService::TOOL_AD_ASSISTANT);
        $this->assertSame(AiSettingsService::DEFAULTS['model'], $untouched['model']);
        $this->assertBlank($untouched['api_key'] ?? null);

        // And a real page load for company B (a fresh HTTP request through
        // SetCurrentCompany, the same as an actual company-switch reload)
        // never renders company A's model/provider text — only its own.
        $response = $this
            ->withSession(['current_company_id' => $companyB->getKey(), 'current_company_selection_explicit' => true])
            ->get('/admin/settings/integrations');

        $response->assertOk()
            ->assertDontSee('gpt-5-company-a')
            ->assertDontSee('company-a-secret');
    }

    public function test_saving_never_touches_unrelated_storefront_settings_on_the_same_row(): void
    {
        $company = $this->makeCompany();
        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'theme_color' => '#123456',
            'meta_title' => 'My Store Title',
            'is_published' => true,
            'cod_enabled' => true,
        ]);

        $user = $this->makeSuperAdmin($company);
        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        Livewire::test(Integrations::class)
            ->set('data.woocommerce_base_url', 'https://shop2.example.com')
            ->call('save')
            ->assertHasNoFormErrors();

        $setting = StorefrontSetting::withoutGlobalScopes()->where('company_id', $company->getKey())->firstOrFail();
        $this->assertSame('#123456', $setting->theme_color);
        $this->assertSame('My Store Title', $setting->meta_title);
        $this->assertTrue((bool) $setting->is_published);
        $this->assertSame('https://shop2.example.com', $setting->woocommerce_base_url);
    }

    public function test_a_non_super_admin_with_settings_permission_can_edit_woocommerce_but_never_ai(): void
    {
        $company = $this->makeCompany();
        UserRole::query()->create(['name' => 'Ops Manager', 'slug' => 'ops_manager', 'permissions' => ['settings.manage'], 'is_active' => true]);
        $user = User::factory()->create(['role' => 'ops_manager', 'is_active' => true]);
        $user->companies()->attach($company, ['role' => 'ops_manager', 'is_default' => true]);

        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        $this->assertTrue(Integrations::canAccess());

        // The AI fields are set anyway (simulating a crafted request bypassing
        // the hidden tab in the UI) — save() must still refuse to persist them.
        Livewire::test(Integrations::class)
            ->set('data.woocommerce_base_url', 'https://staff-shop.example.com')
            ->set('data.ai_messaging_api_format', 'openai')
            ->set('data.ai_messaging_provider', 'OpenAI')
            ->set('data.ai_messaging_model', 'gpt-should-not-save')
            ->set('data.ai_messaging_api_key', 'sk-should-not-save')
            ->call('save')
            ->assertHasNoFormErrors();

        $ai = app(AiSettingsService::class)->all($company, AiSettingsService::TOOL_MESSAGING);
        $this->assertBlank($ai['api_key'] ?? null);

        $setting = StorefrontSetting::withoutGlobalScopes()->where('company_id', $company->getKey())->firstOrFail();
        $this->assertSame('https://staff-shop.example.com', $setting->woocommerce_base_url);
    }

    public function test_staff_without_settings_permission_cannot_access_the_page(): void
    {
        $company = $this->makeCompany();
        $user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $user->companies()->attach($company, ['role' => 'sales_staff', 'is_default' => true]);

        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true])
            ->get('/admin/settings/integrations')
            ->assertForbidden();
    }

    public function test_multi_provider_status_cards_reflect_courier_and_ad_manager_records(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeSuperAdmin($company);

        CourierProvider::query()->create([
            'company_id' => $company->getKey(), 'name' => 'Steadfast', 'driver' => 'steadfast',
            'credentials' => ['api_key' => 'x', 'secret_key' => 'y'], 'is_active' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        $response = $this->get('/admin/settings/integrations');
        $response->assertOk()
            ->assertSee('Courier Providers')
            ->assertSee('Meta Ads (Ad Manager)');

        MetaAdAccount::query()->create([
            'company_id' => $company->getKey(), 'name' => 'Main Ad Account',
            'credentials' => ['access_token' => 'x'], 'is_active' => true,
        ]);

        $this->get('/admin/settings/integrations')
            ->assertOk()
            ->assertSeeText('Connected');
    }

    public function test_test_webhook_action_warns_when_no_secret_is_saved_yet(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeSuperAdmin($company);
        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        Http::fake();

        Livewire::test(Integrations::class)
            ->call('testWoocommerceWebhook')
            ->assertNotified('No webhook secret saved yet');

        Http::assertNothingSent();
    }

    public function test_test_webhook_action_reports_success_for_a_correctly_signed_round_trip(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeSuperAdmin($company);
        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'woocommerce_credentials' => ['webhook_secret' => 'test-secret-123'],
        ]);

        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        Livewire::test(Integrations::class)
            ->call('testWoocommerceWebhook')
            ->assertNotified('Webhook reachable, signature verified');

        // The exact same request a real WooCommerce delivery would send:
        // uses the just-saved secret, not whatever's unsaved in the form.
        Http::assertSent(function ($request) {
            $expectedSignature = base64_encode(hash_hmac('sha256', '{}', 'test-secret-123', true));

            return $request->header('X-WC-Webhook-Signature') === [$expectedSignature]
                && $request->header('X-WC-Webhook-Topic') === ['order.updated'];
        });
    }

    public function test_test_webhook_action_reports_the_failure_status(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeSuperAdmin($company);
        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'woocommerce_credentials' => ['webhook_secret' => 'test-secret-123'],
        ]);

        Http::fake(['*' => Http::response('Forbidden', 403)]);

        Livewire::test(Integrations::class)
            ->call('testWoocommerceWebhook')
            ->assertNotified('Webhook test failed: HTTP 403');
    }

    public function test_website_api_credentials_save_and_reload(): void
    {
        $company = $this->makeCompany();
        $this->actingAs($this->makeSuperAdmin($company))
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        Livewire::test(Integrations::class)
            ->set('data.website_api_credentials.webhook_url', 'https://website.example.com/webhooks/zamzam')
            ->set('data.website_api_credentials.webhook_secret', 'a-generated-secret')
            ->set('data.website_api_credentials.is_enabled', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $setting = StorefrontSetting::withoutGlobalScopes()->where('company_id', $company->getKey())->first();
        $this->assertSame('https://website.example.com/webhooks/zamzam', $setting->website_api_credentials['webhook_url']);
        $this->assertTrue($setting->website_api_credentials['is_enabled']);
    }

    public function test_website_api_test_webhook_warns_when_not_saved_yet(): void
    {
        $company = $this->makeCompany();
        $this->actingAs($this->makeSuperAdmin($company))
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        Queue::fake();

        Livewire::test(Integrations::class)
            ->call('testWebsiteWebhook')
            ->assertNotified('Webhook URL or secret not saved yet');

        Queue::assertNothingPushed();
    }

    public function test_website_api_test_webhook_queues_a_signed_ping(): void
    {
        $company = $this->makeCompany();
        $this->actingAs($this->makeSuperAdmin($company))
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'website_api_credentials' => [
                'webhook_url' => 'https://website.example.com/webhooks/zamzam',
                'webhook_secret' => 'a-generated-secret',
                'is_enabled' => false,
            ],
        ]);

        Queue::fake();

        Livewire::test(Integrations::class)
            ->call('testWebsiteWebhook')
            ->assertNotified('Test webhook queued');

        Queue::assertPushed(DispatchWebsiteWebhookJob::class, fn (DispatchWebsiteWebhookJob $job): bool => $job->event === 'ping'
            && $job->url === 'https://website.example.com/webhooks/zamzam');
    }

    public function test_website_api_keys_table_generates_and_revokes_a_key(): void
    {
        $company = $this->makeCompany();
        $this->actingAs($this->makeSuperAdmin($company))
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        Livewire::test(WebsiteApiKeysTable::class)
            ->mountTableAction('generateApiKey')
            ->setTableActionData(['label' => 'Tasneem Knitting website'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified('API key generated — copy it now');

        $key = CompanyApiKey::withoutGlobalScopes()->where('company_id', $company->getKey())->sole();
        $this->assertSame('Tasneem Knitting website', $key->label);
        $this->assertFalse($key->isRevoked());

        Livewire::test(WebsiteApiKeysTable::class)
            ->mountTableAction('revoke', $key)
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified('API key revoked');

        $this->assertTrue($key->fresh()->isRevoked());
    }

    public function test_sync_order_action_warns_when_site_url_or_api_keys_are_not_saved(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeSuperAdmin($company);
        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        Http::fake();

        Livewire::test(Integrations::class)
            ->callAction('syncWooOrder', data: ['woo_order_id' => 38044])
            ->assertNotified('WooCommerce site URL or API key/secret is not saved yet');

        Http::assertNothingSent();
    }

    public function test_sync_order_action_pulls_a_real_order_from_woocommerce_and_creates_it_in_the_erp(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeSuperAdmin($company);
        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'woocommerce_base_url' => 'https://shop.example.com',
            'woocommerce_credentials' => ['consumer_key' => 'ck_1', 'consumer_secret' => 'cs_1'],
        ]);

        Http::fake([
            'shop.example.com/wp-json/wc/v3/orders/38044' => Http::response([
                'id' => 38044,
                'number' => '38044',
                'status' => 'processing',
                'date_created' => '2026-08-30T10:00:00',
                'discount_total' => '0.00',
                'total_tax' => '0.00',
                'shipping_total' => '60.00',
                'billing' => [
                    'first_name' => 'Backfilled', 'last_name' => 'Customer',
                    'phone' => '01700000123', 'email' => 'backfilled@example.test',
                    'address_1' => '1 Test Road', 'city' => 'Dhaka',
                ],
                'line_items' => [],
            ], 200),
        ]);

        Livewire::test(Integrations::class)
            ->callAction('syncWooOrder', data: ['woo_order_id' => 38044])
            ->assertNotified('Order synced');

        // Fetched with the saved API credentials, not anything else.
        Http::assertSent(fn ($request): bool => $request->url() === 'https://shop.example.com/wp-json/wc/v3/orders/38044'
            && $request->hasHeader('Authorization'));

        $this->assertDatabaseHas('orders', [
            'company_id' => $company->getKey(),
            'external_reference' => 'woo-38044',
        ]);
    }

    public function test_sync_order_action_reports_the_exact_processing_failure_instead_of_a_generic_message(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeSuperAdmin($company);
        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'woocommerce_base_url' => 'https://shop.example.com',
            'woocommerce_credentials' => ['consumer_key' => 'ck_1', 'consumer_secret' => 'cs_1'],
        ]);

        // No "id" in the response WooCommerce sends back — the same real
        // failure shape WooCommerceOrderSyncService::upsertOrder() rejects
        // with a specific message, used here as a stand-in for any real
        // processing bug: the point is that the exact exception surfaces
        // in the notification instead of a generic "something went wrong".
        Http::fake([
            'shop.example.com/wp-json/wc/v3/orders/38044' => Http::response(['number' => '38044'], 200),
        ]);

        Livewire::test(Integrations::class)
            ->callAction('syncWooOrder', data: ['woo_order_id' => 38044])
            ->assertNotified('Sync failed: WooCommerce order payload is missing an order id.');

        $this->assertDatabaseMissing('orders', ['external_reference' => 'woo-38044']);
    }

    public function test_sync_woocommerce_product_import_action_is_available_in_the_woocommerce_tab_when_credentials_are_saved(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeSuperAdmin($company);
        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        // No credentials saved yet — the relocated product-import action
        // (moved off Storefront Settings' now-removed "WooCommerce Import"
        // section) stays hidden, matching its original visibility rule.
        Livewire::test(Integrations::class)->assertActionHidden('syncWooCommerceImport');

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'woocommerce_base_url' => 'https://shop.example.com',
            'woocommerce_credentials' => ['consumer_key' => 'ck_1', 'consumer_secret' => 'cs_1'],
        ]);

        Http::fake([
            'shop.example.com/wp-json/wc/v3/products*' => Http::response([], 200),
        ]);

        Livewire::test(Integrations::class)
            ->assertActionVisible('syncWooCommerceImport')
            ->callAction('syncWooCommerceImport', data: ['download_images' => false])
            ->assertNotified('WooCommerce sync complete');
    }

    public function test_payment_gateway_base_urls_and_the_full_meta_pixel_capi_configuration_save_together(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeSuperAdmin($company);
        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        // online_payment_gateway's own credential fields are only
        // dehydrated while their gateway is selected (same conditional-
        // visibility pattern the merchant-id/password fields already used)
        // — one save covers whichever gateway is active, matching how an
        // admin would actually use this form.
        Livewire::test(Integrations::class)
            ->set('data.online_payment_gateway', 'paystation')
            ->set('data.payment_credentials.paystation_base_url', 'https://api.paystation.example/custom')
            ->set('data.meta_tracking_enabled', true)
            ->set('data.meta_pixel_id', '1122334455667788')
            ->set('data.meta_capi_enabled', true)
            ->set('data.meta_tracking_credentials.access_token', 'capi-token-xyz')
            ->set('data.meta_consent_required', true)
            ->set('data.meta_status_events_enabled', true)
            ->set('data.meta_status_events', ['confirmed', 'delivered'])
            ->call('save')
            ->assertHasNoFormErrors();

        $setting = StorefrontSetting::withoutGlobalScopes()->where('company_id', $company->getKey())->firstOrFail();

        $this->assertSame('https://api.paystation.example/custom', $setting->payment_credentials['paystation_base_url']);
        $this->assertSame('1122334455667788', $setting->meta_pixel_id);
        $this->assertSame('capi-token-xyz', $setting->meta_tracking_credentials['access_token']);
        $this->assertTrue($setting->meta_consent_required);
        $this->assertSame(['confirmed', 'delivered'], $setting->meta_status_events);
    }

    public function test_meta_event_log_table_renders_inside_the_page_and_a_retry_can_be_queued(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeSuperAdmin($company);
        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        $event = StorefrontMetaEvent::query()->create([
            'company_id' => $company->getKey(),
            'pixel_id' => '1234567890123456',
            'event_name' => 'Purchase',
            'event_id' => 'evt-'.uniqid(),
            'status' => StorefrontMetaEvent::STATUS_FAILED,
            'attempts' => 1,
        ]);

        Queue::fake();

        Livewire::test(Integrations::class)
            ->assertSee('Purchase')
            ->assertSee($event->event_id);

        Livewire::test(MetaEventLogTable::class)
            ->callTableAction('retry', $event)
            ->assertNotified('Meta event retry queued');

        Queue::assertPushed(
            RetryStorefrontMetaEventJob::class,
            fn ($job): bool => $job->metaEventId === $event->getKey(),
        );
    }

    protected function assertBlank(mixed $value): void
    {
        $this->assertTrue(blank($value), 'Expected value to be blank but got: '.var_export($value, true));
    }

    public function test_a_company_with_two_sites_edits_each_sites_integration_settings_separately(): void
    {
        $company = $this->makeCompany();
        $blade = StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'is_published' => true,
            'woocommerce_base_url' => 'https://woo.example.com',
            'online_payment_enabled' => true,
            'online_payment_gateway' => 'zinipay',
            'payment_credentials' => ['zinipay_api_key' => 'blade-key'],
        ]);
        $nextjs = StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'site_type' => StorefrontSetting::SITE_NEXTJS,
            'nextjs_site_enabled' => true,
            'nextjs_site_domain' => 'main.example.test',
            'online_payment_enabled' => true,
            'online_payment_gateway' => 'paystation',
            'payment_credentials' => ['paystation_merchant_id' => 'next-mid', 'paystation_password' => 'next-pass'],
        ]);

        $this->actingAs($this->makeSuperAdmin($company))
            ->withSession(['current_company_id' => $company->getKey(), 'current_company_selection_explicit' => true]);

        Livewire::test(Integrations::class)
            ->assertFormFieldExists('site_setting_id')
            ->assertSet('data.site_setting_id', $blade->getKey())
            ->assertSet('data.online_payment_gateway', 'zinipay')
            ->assertFormFieldIsVisible('woocommerce_base_url')
            ->set('data.site_setting_id', $nextjs->getKey())
            ->assertSet('data.online_payment_gateway', 'paystation')
            ->assertFormFieldIsHidden('woocommerce_base_url')
            ->set('data.meta_pixel_id', '5544332211009988')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('5544332211009988', $nextjs->fresh()->meta_pixel_id);
        $this->assertNull($blade->fresh()->meta_pixel_id, 'Saving the Next.js site must not write to the Blade row.');
        $this->assertSame('https://woo.example.com', $blade->fresh()->woocommerce_base_url);
        $this->assertSame(['zinipay_api_key' => 'blade-key'], $blade->fresh()->payment_credentials);
        $this->assertSame('paystation', $nextjs->fresh()->online_payment_gateway);
        $this->assertSame(2, StorefrontSetting::withoutGlobalScopes()->where('company_id', $company->getKey())->count());
    }

    protected function makeCompany(): Company
    {
        $company = Company::query()->create([
            'name' => 'Integrations Co', 'slug' => 'integrations-co-'.uniqid(), 'invoice_prefix' => 'INT'.random_int(100, 999),
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);

        return $company;
    }

    protected function makeSuperAdmin(Company $company): User
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $user->companies()->attach($company, ['role' => 'super_admin', 'is_default' => true]);

        return $user;
    }
}
