<?php

namespace Tests\Feature;

use App\Filament\Pages\Inbox;
use App\Filament\Pages\SalesAutomation;
use App\Filament\Widgets\CrmSalesMetrics;
use App\Jobs\AiAutoReplyJob;
use App\Jobs\RefreshMessengerProfileJob;
use App\Jobs\StoreIncomingMessageJob;
use App\Models\Category;
use App\Models\ChatOrderLink;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\ConversationMessage;
use App\Models\CrmAiRun;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\Crm\AiLlmClient;
use App\Services\Crm\AiReplyService;
use App\Services\Crm\AiSettingsService;
use App\Services\Crm\SalesCartService;
use App\Services\Crm\SalesFollowUpService;
use App\Services\Crm\SalesQualificationService;
use App\Services\Crm\SalesReplyRenderer;
use App\Services\Meta\MetaGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CrmSalesAutomationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Conversation $conversation;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->company = Company::query()->create(['name' => 'Sales Co', 'slug' => 'sales-automation', 'invoice_prefix' => 'SA', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($this->company);
        $this->settings();
        $channel = ConversationChannel::query()->create(['provider' => 'whatsapp', 'external_id' => 'sales-channel', 'display_name' => 'Sales', 'access_token' => 'test', 'is_active' => true]);
        $this->conversation = Conversation::query()->create(['channel_id' => $channel->id, 'provider' => 'whatsapp', 'external_contact_id' => '8801700000000', 'contact_phone' => '01700000000', 'contact_name' => 'Customer', 'status' => 'open', 'ai_enabled' => true]);
        $this->incoming('ঢাকায় দুইটি নিতে চাই');
        $this->product = Product::query()->create(['name' => 'Lamp', 'sku' => 'AUTO-LAMP', 'price' => 400, 'sale_price' => 400, 'cost_price' => 100, 'stock' => 10, 'unit' => 'pcs', 'is_active' => true, 'status' => 'available']);
    }

    private function settings(array $extra = []): void
    {
        app(AiSettingsService::class)->save($this->company, 'messaging', [...AiSettingsService::DEFAULTS, 'enabled' => true, 'api_key' => 'test', ...$extra]);
        $this->company->refresh();
        if (isset($this->conversation)) {
            $this->conversation->unsetRelation('company');
        }
    }

    private function incoming(string $body): ConversationMessage
    {
        return $this->conversation->messages()->create(['direction' => 'incoming', 'type' => 'text', 'body' => $body, 'sent_at' => now()]);
    }

    private function response(array $input): array
    {
        return ['content' => [['type' => 'tool_use', 'id' => 'reply-1', 'name' => 'submit_reply', 'input' => ['confidence' => 0.95, 'needs_human' => false, ...$input]]], 'usage' => ['input_tokens' => 20, 'output_tokens' => 20]];
    }

    private function link(): ChatOrderLink
    {
        return ChatOrderLink::query()->create(['conversation_id' => $this->conversation->id, 'prefill' => ['items' => []]]);
    }

    /** createOrderLink() is protected — exercised directly, same as other reflection-based tests in this suite. */
    private function createOrderLink(array $input): array
    {
        return (new \ReflectionMethod(AiReplyService::class, 'createOrderLink'))->invoke(app(AiReplyService::class), $this->conversation, $input);
    }

    public function test_customer_image_reaches_vision_model_without_a_caption(): void
    {
        Storage::fake('local');
        $this->settings(['vision_enabled' => true, 'max_run_tokens' => 30000]);
        $image = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $path = app(CompanyStorageService::class)->putPrivate($this->company, 'conversation-media', 'vision.png', $bytes);
        $this->conversation->messages()->create(['direction' => 'incoming', 'type' => 'image', 'body' => null, 'media_path' => $path, 'media_mime' => 'image/png', 'sent_at' => now()]);
        Http::fake(['api.anthropic.com/*' => Http::response($this->response(['reply_keys' => [], 'prompt_key' => 'variant', 'language' => 'bn'])), 'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'vision-reply']]])]);
        app(AiReplyService::class)->maybeReply($this->conversation);
        $this->assertSame('sent', CrmAiRun::query()->sole()->status, (string) CrmAiRun::query()->sole()->reason);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'anthropic') && data_get(collect($request['messages'])->last(), 'content.0.source.media_type') === 'image/jpeg');
        $this->assertSame('open', $this->conversation->fresh()->status);
    }

    public function test_openai_vision_uses_image_url_blocks(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);
        (new AiLlmClient('openai', 'test', 'vision-model'))->chat('Test', [['role' => 'user', 'content' => [['type' => 'image', 'source' => ['media_type' => 'image/jpeg', 'data' => 'abc']], ['type' => 'text', 'text' => 'What product?']]]], []);
        Http::assertSent(fn ($request) => data_get($request->data(), 'messages.1.content.0.image_url.url') === 'data:image/jpeg;base64,abc');
    }

    /**
     * With a separate image model configured, the customer's photo never
     * reaches the main tool-calling model as raw bytes — a dedicated
     * model/provider describes it first, and that plain-text description
     * (persisted onto the message) is what the main model actually sees.
     */
    public function test_separate_image_model_describes_the_photo_before_it_reaches_the_main_model(): void
    {
        Storage::fake('local');
        $this->settings([
            'vision_enabled' => true,
            'image_model_enabled' => true,
            'image_api_format' => 'anthropic',
            'image_model' => 'claude-vision-test',
            'image_api_key' => 'image-key',
            'max_run_tokens' => 30000,
        ]);
        $image = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $path = app(CompanyStorageService::class)->putPrivate($this->company, 'conversation-media', 'vision.png', $bytes);
        $photo = $this->conversation->messages()->create(['direction' => 'incoming', 'type' => 'image', 'body' => null, 'media_path' => $path, 'media_mime' => 'image/png', 'sent_at' => now()]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['content' => [['type' => 'text', 'text' => 'A blue smart lamp, brand unreadable.']], 'usage' => ['input_tokens' => 5, 'output_tokens' => 5]])
                ->push($this->response(['reply_keys' => [], 'prompt_key' => 'variant', 'language' => 'bn'])),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'vision-reply']]]),
        ]);

        app(AiReplyService::class)->maybeReply($this->conversation);

        $this->assertSame('sent', CrmAiRun::query()->latest('id')->first()->status);
        $this->assertStringContainsString('blue smart lamp', $photo->fresh()->body);
        // The second (main-model) request carries the description as plain
        // text, never the image bytes.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'anthropic')
            && is_string(data_get(collect($request['messages'])->last(), 'content'))
            && str_contains((string) data_get(collect($request['messages'])->last(), 'content'), 'blue smart lamp'));
    }

    /** A voice note is transcribed first, then answered exactly like a typed message. */
    public function test_voice_note_is_transcribed_and_flows_through_the_normal_reply_pipeline(): void
    {
        Storage::fake('local');
        $this->settings(['voice_enabled' => true, 'voice_api_key' => 'voice-key', 'max_run_tokens' => 30000]);
        $path = app(CompanyStorageService::class)->putPrivate($this->company, 'conversation-media', 'note.ogg', 'fake-audio-bytes');
        $note = $this->conversation->messages()->create(['direction' => 'incoming', 'type' => 'audio', 'body' => null, 'media_path' => $path, 'media_mime' => 'audio/ogg', 'sent_at' => now()]);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'ল্যাম্পের দাম কত?']),
            'api.anthropic.com/*' => Http::response($this->response(['reply_keys' => [], 'prompt_key' => 'variant', 'language' => 'bn'])),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'voice-reply']]]),
        ]);

        app(AiReplyService::class)->maybeReply($this->conversation);

        $this->assertSame('ল্যাম্পের দাম কত?', $note->fresh()->body);
        $this->assertSame('sent', CrmAiRun::query()->latest('id')->first()->status);
    }

    /** Voice transcription left off (the default) is a graceful, deterministic fallback — never a silent drop or an LLM call. */
    public function test_voice_note_without_transcription_configured_gets_a_graceful_fallback(): void
    {
        Storage::fake('local');
        $path = app(CompanyStorageService::class)->putPrivate($this->company, 'conversation-media', 'note.ogg', 'fake-audio-bytes');
        $this->conversation->messages()->create(['direction' => 'incoming', 'type' => 'audio', 'body' => null, 'media_path' => $path, 'media_mime' => 'audio/ogg', 'sent_at' => now()]);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'voice-fallback']]])]);

        app(AiReplyService::class)->maybeReply($this->conversation);

        $this->assertSame('ভয়েস মেসেজটি শুনতে পারছি না। লিখে জানান।', $this->conversation->messages()->where('direction', 'outgoing')->sole()->body);
        $this->assertSame('open', $this->conversation->fresh()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'anthropic') || str_contains($request->url(), 'openai'));
    }

    public function test_business_suite_echo_syncs_once_and_pauses_ai_without_creating_an_incoming_message(): void
    {
        Queue::fake();
        $channel = $this->conversation->channel;
        $channel->update(['provider' => 'messenger']);
        $this->conversation->update(['provider' => 'messenger']);
        $payload = ['object' => 'page', 'entry' => [['id' => $channel->external_id, 'messaging' => [[
            'sender' => ['id' => $channel->external_id], 'recipient' => ['id' => $this->conversation->external_contact_id],
            'timestamp' => now()->getTimestampMs(), 'message' => ['is_echo' => true, 'mid' => 'suite-1', 'text' => 'প্রোডাক্টের নাম দিন'],
        ]]]]];
        $job = new StoreIncomingMessageJob($channel->id, $payload);
        $job->handle(app(CompanyContext::class), app(MetaGraphService::class));
        $job->handle(app(CompanyContext::class), app(MetaGraphService::class));
        $reply = $this->conversation->messages()->where('external_message_id', 'suite-1')->sole();
        $this->assertSame('outgoing', $reply->direction);
        $this->assertSame('page', $reply->generated_by);
        $this->assertTrue($this->conversation->fresh()->human_handled_until->isFuture());
        Queue::assertNotPushed(AiAutoReplyJob::class);
    }

    public function test_own_api_echo_preserves_ai_attribution_even_before_send_response(): void
    {
        $channel = $this->conversation->channel;
        $channel->update(['provider' => 'messenger']);
        $this->conversation->update(['provider' => 'messenger']);
        $own = $this->conversation->messages()->create(['direction' => 'outgoing', 'type' => 'text', 'body' => 'Reply', 'generated_by' => 'ai', 'delivery_status' => 'sending', 'sent_at' => now()]);
        $payload = ['object' => 'page', 'entry' => [['id' => $channel->external_id, 'messaging' => [[
            'sender' => ['id' => $channel->external_id], 'recipient' => ['id' => $this->conversation->external_contact_id],
            'message' => ['is_echo' => true, 'mid' => 'own-echo', 'text' => 'Reply', 'metadata' => 'crm-message:'.$own->id],
        ]]]]];
        (new StoreIncomingMessageJob($channel->id, $payload))->handle(app(CompanyContext::class), app(MetaGraphService::class));
        $this->assertSame('ai', $own->fresh()->generated_by);
        $this->assertSame('own-echo', $own->fresh()->external_message_id);
        $this->assertNull($this->conversation->fresh()->human_handled_until);
    }

    public function test_generic_bengali_order_request_asks_for_product_without_llm_or_handoff(): void
    {
        $this->conversation->messages()->where('direction', 'incoming')->update(['body' => 'Hi']);
        $this->incoming('আমি একটি অর্ডার করতে চাই');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'order-clarification']]])]);
        app(AiReplyService::class)->maybeReply($this->conversation);
        $this->assertSame('আপনি কোন প্রোডাক্ট নিতে চান? নাম অথবা ছবি শেয়ার করুন।', $this->conversation->messages()->where('direction', 'outgoing')->sole()->body);
        $this->assertSame('open', $this->conversation->fresh()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'anthropic'));
    }

    public function test_profile_name_replaces_id_placeholder_and_updates_generated_lead_name(): void
    {
        $this->conversation->channel->update(['provider' => 'messenger']);
        $lead = Lead::query()->create(['name' => 'Chat contact 123456', 'phone' => '123456', 'source' => 'facebook', 'status' => 'new']);
        $this->conversation->update(['provider' => 'messenger', 'external_contact_id' => '123456', 'contact_name' => 'Contact 123456', 'lead_id' => $lead->id]);
        $this->assertSame('নাম পাওয়া যায়নি', $this->conversation->profileDisplayName());
        Http::fake(['graph.facebook.com/*' => Http::response(['first_name' => 'Rahim', 'last_name' => 'Ahmed'])]);
        (new RefreshMessengerProfileJob($this->conversation->id))->handle(app(MetaGraphService::class));
        $this->assertSame('Rahim Ahmed', $this->conversation->fresh()->profileDisplayName());
        $this->assertSame('Rahim Ahmed', $lead->fresh()->name);
    }

    public function test_profile_failure_is_visible_and_token_change_allows_immediate_retry(): void
    {
        $this->conversation->channel->update(['provider' => 'messenger']);
        $this->conversation->update(['provider' => 'messenger', 'contact_name' => null]);
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['code' => 200, 'message' => 'Permission denied']], 403)
            ->push(['first_name' => 'Rahim', 'last_name' => 'Ahmed'])]);
        $job = new RefreshMessengerProfileJob($this->conversation->id);
        $job->handle(app(MetaGraphService::class));
        $this->assertSame('profile', $this->conversation->channel->fresh()->last_error_source);
        $this->assertStringContainsString('pages_messaging', $this->conversation->channel->fresh()->last_error);
        $job->handle(app(MetaGraphService::class));
        Http::assertSentCount(1);
        $this->conversation->channel->update(['access_token' => 'replacement-token']);
        $job->handle(app(MetaGraphService::class));
        $this->assertSame('Rahim Ahmed', $this->conversation->fresh()->contact_name);
        $this->assertNull($this->conversation->channel->fresh()->last_error);
        Http::assertSentCount(2);
    }

    public function test_missing_profile_name_never_falls_back_to_phone_or_id(): void
    {
        $this->conversation->update(['contact_name' => null]);
        $this->assertSame('নাম পাওয়া যায়নি', $this->conversation->profileDisplayName());
    }

    public function test_order_request_keeps_existing_product_context_instead_of_asking_again(): void
    {
        $this->conversation->messages()->where('direction', 'incoming')->update(['body' => 'Lamp নিতে চাই']);
        $this->incoming('আমি একটি অর্ডার করতে চাচ্ছি');
        Http::fake(['api.anthropic.com/*' => Http::response($this->response(['reply_keys' => [], 'prompt_key' => 'quantity', 'language' => 'bn'])), 'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'quantity-clarification']]])]);
        app(AiReplyService::class)->maybeReply($this->conversation);
        $this->assertSame('কতটি নিতে চান?', $this->conversation->messages()->where('direction', 'outgoing')->sole()->body);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'anthropic'));
    }

    public function test_banglish_price_reply_has_no_unsolicited_stock_or_question(): void
    {
        $this->incoming('ei lamp er daam koto?');
        $key = 'product:'.$this->product->id;
        $reply = app(SalesReplyRenderer::class)->render($this->conversation, ['reply_keys' => [$key], 'product_detail' => 'price', 'prompt_key' => 'budget'], [['result' => ['reply_options' => [$key]]]]);
        $this->assertSame('Lamp — 400 taka', $reply);
    }

    public function test_native_bengali_stock_reply_does_not_add_price(): void
    {
        $this->incoming('ল্যাম্পটা আছে?');
        $key = 'product:'.$this->product->id;
        $reply = app(SalesReplyRenderer::class)->render($this->conversation, ['reply_keys' => [$key], 'product_detail' => 'stock'], [['result' => ['reply_options' => [$key]]]]);
        $this->assertSame('Lamp — স্টকে আছে।', $reply);
    }

    public function test_banglish_small_talk_is_short_and_language_can_be_requested_explicitly(): void
    {
        $this->incoming('tumi kmn aso?');
        $renderer = app(SalesReplyRenderer::class);
        $this->assertSame('Apnake shahajjo korte prostut achi.', $renderer->render($this->conversation, ['prompt_key' => 'wellbeing'], []));
        $this->assertSame('ধন্যবাদ।', $renderer->render($this->conversation, ['prompt_key' => 'thanks', 'language' => 'bn'], []));
        $this->assertSame('Thank you.', $renderer->render($this->conversation, ['prompt_key' => 'thanks', 'language' => 'en'], []));
    }

    public function test_llm_receives_banglish_rules_and_sent_reply_has_no_introduction(): void
    {
        $this->settings(['sales_guidelines' => 'CUSTOM_GUIDELINE: সহজ বাংলায় সংক্ষিপ্ত উত্তর দিন।']);
        $this->incoming('tumi kmn aso?');
        Http::fake(['api.anthropic.com/*' => Http::response($this->response(['reply_keys' => [], 'prompt_key' => 'wellbeing', 'language' => 'banglish'])), 'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'banglish-1']]])]);
        app(AiReplyService::class)->maybeReply($this->conversation);
        $this->assertSame('Apnake shahajjo korte prostut achi.', $this->conversation->messages()->where('direction', 'outgoing')->sole()->body);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'anthropic') && str_contains(json_encode($request['system']), 'tumi kmn aso') && str_contains(json_encode($request['system']), 'No unsolicited introduction') && str_contains(json_encode($request['system']), 'CUSTOM_GUIDELINE') && str_contains(json_encode($request['system']), 'NEVER catalog facts'));
    }

    public function test_handoff_stays_disabled_for_the_next_customer_message(): void
    {
        Http::fake();
        $service = app(AiReplyService::class);
        $service->escalate($this->conversation, 'Customer requested staff.');
        $this->incoming('Lamp price?');
        $service->maybeReply($this->conversation->fresh());
        Http::assertNothingSent();
        $this->assertFalse($this->conversation->fresh()->ai_enabled);
    }

    public function test_internal_notes_and_failed_outbound_are_not_submitted_to_the_llm(): void
    {
        $this->conversation->messages()->create(['direction' => 'outgoing', 'type' => 'note', 'body' => 'PRIVATE STAFF MARGIN', 'delivery_status' => 'internal', 'sent_at' => now()]);
        $this->conversation->messages()->create(['direction' => 'outgoing', 'type' => 'text', 'body' => 'FAILED PRICE PROMISE', 'delivery_status' => 'failed', 'sent_at' => now()]);
        Http::fake(['api.anthropic.com/*' => Http::response($this->response(['reply_keys' => [], 'prompt_key' => 'product'])), 'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'out-1']]])]);
        app(AiReplyService::class)->maybeReply($this->conversation);
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'anthropic')) {
                return false;
            }
            $context = json_encode($request['messages']);
            $this->assertStringNotContainsString('PRIVATE STAFF', $context);
            $this->assertStringNotContainsString('FAILED PRICE', $context);

            return true;
        });
        $this->assertSame('sent', CrmAiRun::query()->sole()->status);
    }

    public function test_staff_takeover_during_inference_prevents_the_send(): void
    {
        Http::fake(['api.anthropic.com/*' => function () {
            $this->conversation->update(['human_handled_until' => now()->addDay()]);

            return Http::response($this->response(['reply_keys' => [], 'prompt_key' => 'product']));
        }]);
        app(AiReplyService::class)->maybeReply($this->conversation);
        Http::assertSentCount(1);
        $this->assertSame(0, $this->conversation->messages()->where('direction', 'outgoing')->count());
    }

    public function test_older_inbound_job_is_coalesced_without_an_ai_call(): void
    {
        Http::fake();
        $source = $this->conversation->messages()->first();
        $this->incoming('আরেকটি প্রশ্ন');
        (new AiAutoReplyJob($this->conversation->id, $source->id))->handle(app(CompanyContext::class), app(AiReplyService::class));
        Http::assertNothingSent();
        $this->assertNotNull(data_get($source->fresh()->raw_payload, '_local.ai_processed_at'));
    }

    public function test_model_prose_cannot_bypass_price_validation(): void
    {
        foreach (['BDT 999', 'নয়শ টাকা', 'Lamp costs 999', 'https://untrusted.example/checkout'] as $answer) {
            try {
                app(SalesReplyRenderer::class)->render($this->conversation, ['answer' => $answer], []);
                $this->fail('Unsupported model prose was accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_catalog_claims_are_reloaded_before_rendering(): void
    {
        $key = 'product:'.$this->product->id;
        $this->product->update(['sale_price' => 550, 'price' => 550]);
        $answer = app(SalesReplyRenderer::class)->render($this->conversation, ['reply_keys' => [$key]], [['result' => ['reply_options' => [$key]]]]);
        $this->assertStringContainsString('৫৫০', $answer);
        $this->assertStringNotContainsString('৪০০', $answer);
    }

    public function test_unlooked_up_product_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(SalesReplyRenderer::class)->render($this->conversation, ['reply_keys' => ['product:'.$this->product->id]], []);
    }

    public function test_variant_checkout_uses_variant_price_and_requires_a_selection(): void
    {
        $this->product->update(['has_variants' => true]);
        $variant = ProductVariant::query()->create(['product_id' => $this->product->id, 'sku' => 'LAMP-BLUE', 'options' => ['Color' => 'Blue'], 'sale_price' => 650, 'stock' => 5, 'is_active' => true]);
        $item = app(SalesCartService::class)->item($this->conversation, ['product_id' => $this->product->id, 'variant_id' => $variant->id, 'quantity' => 2]);
        $this->assertSame($variant->id, $item['product_variant_id']);
        $this->assertSame(650.0, $item['unit_price']);
        $this->expectException(ValidationException::class);
        app(SalesCartService::class)->item($this->conversation, ['product_id' => $this->product->id]);
    }

    public function test_insufficient_stock_cannot_create_a_cart_item(): void
    {
        $this->expectException(ValidationException::class);
        app(SalesCartService::class)->item($this->conversation, ['product_id' => $this->product->id, 'quantity' => 11]);
    }

    public function test_qualification_retains_customer_evidence_and_rejects_inference(): void
    {
        $source = $this->conversation->messages()->first();
        $service = app(SalesQualificationService::class);
        $saved = $service->update($this->conversation, ['field' => 'delivery_area', 'value' => 'ঢাকা', 'evidence_message_id' => $source->id, 'evidence_quote' => $source->body]);
        $this->assertTrue($saved['saved']);
        $lead = $this->conversation->fresh()->lead;
        $this->assertSame($source->id, data_get($lead->qualification, 'delivery_area.evidence_message_id'));
        $denied = $service->update($this->conversation, ['field' => 'budget', 'value' => '5000', 'evidence_message_id' => $source->id, 'evidence_quote' => $source->body]);
        $this->assertArrayHasKey('error', $denied);
        $this->assertArrayNotHasKey('budget', $lead->fresh()->qualification);
    }

    public function test_qualification_gives_a_chat_a_fresh_lead_when_its_lead_is_in_trash(): void
    {
        $source = $this->conversation->messages()->first();
        $service = app(SalesQualificationService::class);
        $service->update($this->conversation, ['field' => 'delivery_area', 'value' => 'ঢাকা', 'evidence_message_id' => $source->id, 'evidence_quote' => $source->body]);
        $trashed = $this->conversation->fresh()->lead;
        $trashed->delete();

        // Without this the save would stop on the lead in Trash; it behaves as it did when a deleted lead cleared the link.
        $saved = $service->update($this->conversation->fresh(), ['field' => 'delivery_area', 'value' => 'ঢাকা', 'evidence_message_id' => $source->id, 'evidence_quote' => $source->body]);

        $this->assertTrue($saved['saved']);
        $fresh = $this->conversation->fresh()->lead;
        $this->assertNotNull($fresh);
        $this->assertNotSame($trashed->getKey(), $fresh->getKey());
        $this->assertSoftDeleted($trashed);
    }

    public function test_follow_up_starts_over_after_a_new_inbound_message(): void
    {
        $this->travelTo(now('Asia/Dhaka')->setTime(12, 0));
        $this->settings(['sales_follow_ups_enabled' => true, 'follow_up_template' => 'sales_followup']);
        $service = app(SalesFollowUpService::class);
        $followUp = $service->schedule($this->conversation, $this->link());
        $followUp->update(['due_at' => now()->subMinute(), 'step' => 2]);
        $this->incoming('আর লাগবে না');
        $service->process($followUp);
        $followUp = $followUp->fresh();
        $this->assertSame('pending', $followUp->status);
        $this->assertSame(0, $followUp->step);
        $this->assertStringContainsString('wrote again', $followUp->reason);
        $this->assertEqualsWithDelta(now()->addHour()->timestamp, $followUp->due_at->timestamp, 5);
    }

    public function test_follow_up_rechecks_opt_out_using_normalized_phone(): void
    {
        $this->travelTo(now('Asia/Dhaka')->setTime(12, 0));
        $this->settings(['sales_follow_ups_enabled' => true, 'follow_up_template' => 'sales_followup']);
        $service = app(SalesFollowUpService::class);
        $followUp = $service->schedule($this->conversation, $this->link());
        $followUp->update(['due_at' => now()->subMinute()]);
        Lead::query()->create(['name' => 'Opted out', 'phone' => '+8801700000000', 'opted_out_at' => now()]);
        $service->process($followUp);
        $this->assertSame('cancelled', $followUp->fresh()->status);
        $this->assertStringContainsString('opted out', $followUp->fresh()->reason);
    }

    public function test_follow_up_template_is_sent_once_and_archived(): void
    {
        $this->travelTo(now('Asia/Dhaka')->setTime(12, 0));
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'follow-up-1']]])]);
        $this->settings(['sales_follow_ups_enabled' => true, 'follow_up_template' => 'sales_followup']);
        $service = app(SalesFollowUpService::class);
        $link = $this->link();
        $followUp = $service->schedule($this->conversation, $link);
        $this->assertSame($followUp->id, $service->schedule($this->conversation, $link)->id);
        $followUp->update(['due_at' => now()->subMinute()]);
        $service->process($followUp);
        $service->process($followUp);
        Http::assertSentCount(1);
        // The first of the scheduled reminders went; the next one waits for its time.
        $this->assertSame(['pending', 1], [$followUp->fresh()->status, $followUp->fresh()->step]);
        $this->assertSame('follow-up-1', $this->conversation->messages()->where('direction', 'outgoing')->sole()->external_message_id);
    }

    public function test_review_mode_records_a_draft_without_sending(): void
    {
        $this->settings(['review_mode' => true]);
        Http::fake(['api.anthropic.com/*' => Http::response($this->response(['reply_keys' => [], 'prompt_key' => 'quantity']))]);
        app(AiReplyService::class)->maybeReply($this->conversation);
        Http::assertSentCount(1);
        $this->assertSame('suggested', CrmAiRun::query()->sole()->status);
        $this->assertSame(0, $this->conversation->messages()->where('direction', 'outgoing')->count());
    }

    public function test_daily_budget_blocks_before_http_and_hands_off(): void
    {
        $this->settings(['daily_budget_usd' => 0.001, 'input_cost_per_million' => 10, 'output_cost_per_million' => 10]);
        Http::fake();
        app(AiReplyService::class)->maybeReply($this->conversation);
        Http::assertNothingSent();
        $this->assertSame('budget_blocked', CrmAiRun::query()->sole()->status);
        $this->assertFalse($this->conversation->fresh()->ai_enabled);
    }

    public function test_native_sales_automation_page_renders_for_authorized_staff(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        Livewire::test(SalesAutomation::class)->assertSuccessful();
        Livewire::test(CrmSalesMetrics::class)->assertSuccessful()->assertSee('Completed CRM/chat orders');
    }

    public function test_inbox_presence_renews_before_thirty_second_expiry(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        Cache::forget('crm:human-present:'.$this->conversation->id);
        Livewire::test(Inbox::class)->set('selectedConversationId', $this->conversation->id)->set('humanPresentMarkedAt', microtime(true) - 16)->call('refreshInbox')->assertHasNoErrors();
        $this->assertTrue($this->conversation->hasHumanPresent());
    }

    // --- Proactive mode (opt-in) ---------------------------------------

    public function test_prompt_key_is_ignored_when_reply_keys_already_answer_by_default(): void
    {
        $key = 'product:'.$this->product->id;
        $reply = app(SalesReplyRenderer::class)->render(
            $this->conversation,
            ['reply_keys' => [$key], 'product_detail' => 'price', 'prompt_key' => 'delivery_area', 'language' => 'en'],
            [['result' => ['reply_options' => [$key]]]],
        );
        $this->assertSame('Lamp — BDT 400', $reply);
    }

    public function test_proactive_mode_lets_the_renderer_combine_an_answer_and_one_follow_up_question(): void
    {
        $key = 'product:'.$this->product->id;
        $reply = app(SalesReplyRenderer::class)->render(
            $this->conversation,
            ['reply_keys' => [$key], 'product_detail' => 'price', 'prompt_key' => 'delivery_area', 'language' => 'en'],
            [['result' => ['reply_options' => [$key]]]],
            true,
        );
        $this->assertSame("Lamp — BDT 400\nWhich area should we deliver to?", $reply);
    }

    public function test_proactive_mode_setting_flows_through_the_full_ai_reply_pipeline(): void
    {
        $this->settings(['proactive_mode_enabled' => true]);
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['content' => [['type' => 'tool_use', 'id' => 'lookup-1', 'name' => 'lookup_product', 'input' => ['name' => 'Lamp']]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]])
                ->push($this->response(['reply_keys' => ['product:'.$this->product->id], 'prompt_key' => 'delivery_area', 'product_detail' => 'price', 'language' => 'en'])),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'proactive-1']]]),
        ]);
        app(AiReplyService::class)->maybeReply($this->conversation);
        $this->assertSame("Lamp — BDT 400\nWhich area should we deliver to?", $this->conversation->messages()->where('direction', 'outgoing')->sole()->body);
    }

    // --- Objection handling (opt-in) -------------------------------------

    public function test_price_objection_still_hard_escalates_by_default(): void
    {
        Http::fake();
        $this->incoming('দামটা একটু কমানো যাবে?');
        app(AiReplyService::class)->maybeReply($this->conversation);
        Http::assertNothingSent();
        $this->assertSame('pending', $this->conversation->fresh()->status);
        $this->assertFalse($this->conversation->fresh()->ai_enabled);
    }

    public function test_objection_handling_enabled_lets_a_mild_price_objection_reach_the_agent(): void
    {
        $this->settings(['objection_handling_enabled' => true]);
        $this->incoming('দামটা একটু কমানো যাবে?');
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->response(['reply_keys' => [], 'prompt_key' => 'thanks', 'language' => 'bn'])),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'objection-1']]]),
        ]);
        app(AiReplyService::class)->maybeReply($this->conversation);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'anthropic'));
        $this->assertSame('open', $this->conversation->fresh()->status);
    }

    public function test_refund_request_still_hard_escalates_even_with_objection_handling_enabled(): void
    {
        $this->settings(['objection_handling_enabled' => true]);
        Http::fake();
        $this->incoming('আমি রিফান্ড চাই');
        app(AiReplyService::class)->maybeReply($this->conversation);
        Http::assertNothingSent();
        $this->assertSame('pending', $this->conversation->fresh()->status);
    }

    // --- Multi-product cart ---------------------------------------------

    public function test_create_order_link_accepts_multiple_items_in_one_call(): void
    {
        $mug = Product::query()->create(['name' => 'Mug', 'sku' => 'AUTO-MUG', 'price' => 150, 'sale_price' => 150, 'cost_price' => 50, 'stock' => 20, 'unit' => 'pcs', 'is_active' => true, 'status' => 'available']);

        $result = $this->createOrderLink(['items' => [
            ['product_id' => $this->product->id, 'quantity' => 2],
            ['product_id' => $mug->id, 'quantity' => 1],
        ]]);

        $this->assertTrue($result['found']);
        $this->assertSame(['Lamp', 'Mug'], $result['items']);
        $link = ChatOrderLink::query()->findOrFail($result['link_id']);
        $this->assertCount(2, $link->prefill['items']);
        $this->assertSame(2, $link->prefill['items'][0]['quantity']);
    }

    public function test_create_order_link_merges_into_the_same_open_link_across_messages(): void
    {
        $mug = Product::query()->create(['name' => 'Mug', 'sku' => 'AUTO-MUG2', 'price' => 150, 'sale_price' => 150, 'cost_price' => 50, 'stock' => 20, 'unit' => 'pcs', 'is_active' => true, 'status' => 'available']);

        $first = $this->createOrderLink(['items' => [['product_id' => $this->product->id, 'quantity' => 1]]]);
        $second = $this->createOrderLink(['items' => [['product_id' => $mug->id, 'quantity' => 3]]]);

        $this->assertSame($first['link_id'], $second['link_id']);
        $link = ChatOrderLink::query()->findOrFail($second['link_id']);
        $this->assertSame(['Lamp', 'Mug'], array_column($link->prefill['items'], 'name'));

        // Re-stating the same product updates its quantity instead of duplicating the line.
        $this->createOrderLink(['items' => [['product_id' => $this->product->id, 'quantity' => 5]]]);
        $link = $link->fresh();
        $this->assertCount(2, $link->prefill['items']);
        $this->assertSame(5, $link->prefill['items'][0]['quantity']);
    }

    // --- Grounded cross-sell / upsell (opt-in) ---------------------------

    public function test_cross_sell_tool_is_not_offered_by_default(): void
    {
        $tools = (new \ReflectionMethod(AiReplyService::class, 'toolDefinitions'))
            ->invoke(app(AiReplyService::class), app(AiSettingsService::class)->all($this->company, AiSettingsService::TOOL_MESSAGING));
        $this->assertNotContains('lookup_related_products', array_column($tools, 'name'));
    }

    public function test_cross_sell_enabled_lets_the_agent_suggest_one_related_product(): void
    {
        $category = Category::query()->create(['name' => 'Home', 'slug' => 'home', 'is_active' => true]);
        $this->product->update(['category_id' => $category->id]);
        $related = Product::query()->create(['name' => 'Lampshade', 'sku' => 'AUTO-SHADE', 'price' => 120, 'sale_price' => 120, 'cost_price' => 40, 'stock' => 15, 'unit' => 'pcs', 'is_active' => true, 'status' => 'available', 'category_id' => $category->id]);
        $this->settings(['cross_sell_enabled' => true]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['content' => [['type' => 'tool_use', 'id' => 'lookup-1', 'name' => 'lookup_product', 'input' => ['name' => 'Lamp']]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]])
                ->push(['content' => [['type' => 'tool_use', 'id' => 'related-1', 'name' => 'lookup_related_products', 'input' => ['product_id' => $this->product->id]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]])
                ->push($this->response(['reply_keys' => ['product:'.$this->product->id, 'related:'.$related->id], 'product_detail' => 'price', 'language' => 'en'])),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'cross-sell-1']]]),
        ]);

        app(AiReplyService::class)->maybeReply($this->conversation);

        $body = $this->conversation->messages()->where('direction', 'outgoing')->sole()->body;
        $this->assertStringContainsString('Lamp — BDT 400', $body);
        $this->assertStringContainsString('You might also like: Lampshade — BDT 120', $body);
    }

    // --- Personalization by purchase history (opt-in) --------------------

    public function test_qualification_context_includes_personalization_only_when_enabled(): void
    {
        $customer = Customer::query()->create(['name' => 'Rahim', 'phone' => '01700000000']);
        $this->conversation->update(['customer_id' => $customer->id]);
        $order = Order::query()->create(['customer_id' => $customer->id, 'order_date' => today(), 'status' => 'draft']);
        $order->items()->create(['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 400]);

        $service = app(SalesQualificationService::class);

        $default = $service->context($this->conversation->fresh());
        $this->assertArrayNotHasKey('is_returning_customer', $default);
        $this->assertArrayNotHasKey('items', $default['recent_orders'][0]);

        $personalized = $service->context($this->conversation->fresh(), true);
        $this->assertTrue($personalized['is_returning_customer']);
        $this->assertSame(['Lamp'], $personalized['recent_orders'][0]['items']);
    }

    public function test_personalization_enabled_mentions_returning_customer_signal_in_the_system_prompt(): void
    {
        $customer = Customer::query()->create(['name' => 'Rahim', 'phone' => '01700000000']);
        $this->conversation->update(['customer_id' => $customer->id]);
        $order = Order::query()->create(['customer_id' => $customer->id, 'order_date' => today(), 'status' => 'draft']);
        $order->items()->create(['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 400]);
        $this->settings(['personalization_enabled' => true]);

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->response(['reply_keys' => [], 'prompt_key' => 'thanks', 'language' => 'bn'])),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'personalization-1']]]),
        ]);

        app(AiReplyService::class)->maybeReply($this->conversation->fresh());

        Http::assertSent(fn ($request) => str_contains($request->url(), 'anthropic')
            && str_contains((string) $request['system'], 'is_returning_customer":true')
            && str_contains((string) $request['system'], 'PERSONALIZATION IS ON'));
    }
}
