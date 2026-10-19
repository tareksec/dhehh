<?php

namespace Tests\Feature;

use App\Models\ChatOrderLink;
use App\Models\Company;
use App\Models\CompanyFaq;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\Product;
use App\Services\CompanyContext;
use App\Services\Crm\AiInboxReportService;
use App\Services\Crm\AiReplyService;
use App\Services\Crm\AiSettingsService;
use App\Services\Crm\ConversationMessengerService;
use App\Services\Crm\ProductCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** "Seen" and "typing..." while the AI works, and the Messenger product carousel. */
class AiPresenceAndCardsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://erp.example.com']);
        Cache::flush();
        $this->company = Company::query()->create(['name' => 'Cards Co', 'slug' => 'cards-co', 'invoice_prefix' => 'CC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'domain' => 'shop.example.com', 'is_active' => true]);
        app(CompanyContext::class)->set($this->company);
        $this->save();
        $this->product = Product::query()->create(['name' => 'Smart Bulb', 'sku' => 'CD-1', 'price' => 420, 'sale_price' => 420, 'cost_price' => 250, 'stock' => 15, 'unit' => 'pcs', 'vat_rate' => 0, 'is_active' => true, 'status' => Product::STATUS_AVAILABLE, 'image' => 'https://cdn.example.com/bulb.jpg']);
    }

    private function save(array $extra = []): void
    {
        app(AiSettingsService::class)->save($this->company, 'messaging', [...AiSettingsService::DEFAULTS, 'enabled' => true, 'api_key' => 'key', 'model' => 'claude-test', 'product_cards_enabled' => true, ...$extra]);
        $this->company->refresh();
    }

    private function chat(string $provider = 'messenger', string $body = 'Smart Bulb এর দাম কত?'): Conversation
    {
        $channel = ConversationChannel::query()->firstOrCreate(
            ['provider' => $provider, 'external_id' => $provider.'-ch'],
            ['display_name' => ucfirst($provider), 'access_token' => 'token', 'is_active' => true],
        );
        $conversation = Conversation::query()->create([
            'channel_id' => $channel->id, 'provider' => $provider, 'external_contact_id' => $provider === 'whatsapp' ? '8801700'.random_int(100000, 999999) : 'psid-'.uniqid(),
            'contact_phone' => $provider === 'whatsapp' ? '8801700000000' : null, 'contact_name' => 'Rina', 'status' => 'open', 'ai_enabled' => true,
        ]);
        $conversation->messages()->create(['direction' => 'incoming', 'type' => 'text', 'body' => $body, 'external_message_id' => 'mid.'.uniqid(), 'sent_at' => now()]);

        return $conversation->fresh();
    }

    private function tool(string $name, array $input): array
    {
        return ['content' => [['type' => 'tool_use', 'id' => 'tu_'.uniqid(), 'name' => $name, 'input' => $input]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]];
    }

    /** The agent looks the bulb up and answers with it. Graph answers every call unless $graph says otherwise. */
    private function fakeAgent(?callable $graph = null, array $replyKeys = []): void
    {
        $sequence = Http::sequence()
            ->push($this->tool('lookup_product', ['name' => 'Smart Bulb']))
            ->push($this->tool('submit_reply', ['answer' => 'ok', 'reply_keys' => $replyKeys ?: ['product:'.$this->product->id], 'confidence' => 0.95, 'needs_human' => false]));
        Http::fake([
            'api.anthropic.com/*' => $sequence,
            'graph.facebook.com/*' => $graph ?? fn (Request $request) => Http::response(['message_id' => 'm.'.uniqid(), 'recipient_id' => 'psid-1', 'messages' => [['id' => 'wamid.'.uniqid()]]]),
        ]);
    }

    /** @return list<string> a label for each request, in the order they were made */
    private function timeline(): array
    {
        return Http::recorded()->map(function (array $pair): string {
            /** @var Request $request */
            $request = $pair[0];
            if (str_contains($request->url(), 'anthropic')) {
                return 'llm';
            }
            $data = $request->data();
            if (isset($data['sender_action'])) {
                return $data['sender_action'];
            }
            if (isset($data['typing_indicator'])) {
                return 'wa_typing';
            }
            if (isset($data['message']['attachment'])) {
                return 'cards';
            }

            return 'send';
        })->values()->all();
    }

    public function test_a_messenger_customer_sees_seen_then_typing_before_the_model_is_called(): void
    {
        $this->fakeAgent();
        $conversation = $this->chat();

        app(AiReplyService::class)->maybeReply($conversation);

        // Two model rounds (lookup, then answer): "seen" once, "typing" once.
        $this->assertSame(['mark_seen', 'typing_on', 'llm', 'llm', 'send', 'cards'], $this->timeline());
        $this->assertNotNull(data_get($conversation->messages()->where('direction', 'incoming')->first()->raw_payload, '_local.seen_at'));
    }

    public function test_a_whatsapp_customer_gets_read_and_typing_in_one_call(): void
    {
        $this->fakeAgent();
        $conversation = $this->chat('whatsapp');
        $incoming = $conversation->messages()->where('direction', 'incoming')->first();

        app(AiReplyService::class)->maybeReply($conversation);

        $this->assertSame(['wa_typing', 'llm', 'llm', 'send'], $this->timeline());
        Http::assertSent(fn (Request $request): bool => ($request->data()['typing_indicator']['type'] ?? null) === 'text'
            && $request->data()['status'] === 'read'
            && $request->data()['message_id'] === $incoming->external_message_id
            && str_contains($request->url(), 'whatsapp-ch/messages'));
        $this->assertNotNull(data_get($incoming->fresh()->raw_payload, '_local.marked_read_at'));
    }

    public function test_the_signs_can_be_switched_off(): void
    {
        $this->save(['typing_indicator_enabled' => false, 'product_cards_enabled' => false]);
        $this->fakeAgent();

        app(AiReplyService::class)->maybeReply($this->chat());

        $this->assertSame(['llm', 'llm', 'send'], $this->timeline());
    }

    public function test_a_failing_sign_never_stops_the_answer(): void
    {
        $this->fakeAgent(fn (Request $request) => isset($request->data()['sender_action'])
            ? Http::response(['error' => ['message' => 'nope', 'code' => 100]], 400)
            : Http::response(['message_id' => 'm.'.uniqid()]));
        $conversation = $this->chat();

        app(AiReplyService::class)->maybeReply($conversation);

        $reply = $conversation->messages()->where('direction', 'outgoing')->where('generated_by', 'ai')->orderBy('id')->first();
        $this->assertSame('sent', $reply->delivery_status);
        $this->assertNull($conversation->channel->fresh()->last_error);
    }

    public function test_an_instant_saved_answer_shows_no_typing(): void
    {
        CompanyFaq::query()->create(['question' => 'Delivery?', 'answer' => '১-২ দিন।', 'keywords' => 'ডেলিভারি', 'is_active' => true]);
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm.faq'])]);

        app(AiReplyService::class)->maybeReply($this->chat('messenger', 'ডেলিভারি কত দিন?'));

        $this->assertSame(['send'], $this->timeline());
    }

    public function test_typing_is_taken_away_when_the_ai_hands_the_chat_over(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->tool('escalate_to_human', ['reason' => 'Bulk price', 'reason_code' => 'other']),
            'graph.facebook.com/*' => Http::response(['recipient_id' => 'psid-1']),
        ]);

        app(AiReplyService::class)->maybeReply($this->chat('messenger', '১০০ পিস নিলে স্পেশাল রেট হবে?'));

        $this->assertSame(['mark_seen', 'typing_on', 'llm', 'typing_off'], $this->timeline());
    }

    public function test_the_cards_follow_the_written_answer_with_photo_price_and_a_button(): void
    {
        $this->fakeAgent();
        $conversation = $this->chat();

        app(AiReplyService::class)->maybeReply($conversation);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data()['message']['attachment']['payload'] ?? null;
            if ($payload === null) {
                return false;
            }
            $card = $payload['elements'][0];

            return $payload['template_type'] === 'generic'
                && count($payload['elements']) === 1
                && $card['title'] === 'Smart Bulb'
                && $card['subtitle'] === '৪২০ টাকা · স্টকে আছে'
                && $card['image_url'] === 'https://cdn.example.com/bulb.jpg'
                && $card['buttons'][0] === ['type' => 'web_url', 'url' => 'https://shop.example.com/product/'.$this->product->slug, 'title' => 'দেখুন']
                && $card['default_action']['url'] === 'https://shop.example.com/product/'.$this->product->slug;
        });
        $messages = $conversation->messages()->where('direction', 'outgoing')->orderBy('id')->get();
        $this->assertCount(2, $messages);
        $this->assertSame('🛍 Smart Bulb', $messages[1]->body);
        $this->assertSame(['product_cards', 'sent'], [data_get($messages[1]->ai_meta, 'kind'), $messages[1]->delivery_status]);
        $this->assertNull(data_get($messages[0]->ai_meta, 'kind'));
    }

    public function test_the_button_opens_the_order_link_when_the_answer_has_one(): void
    {
        $conversation = $this->chat();
        $link = ChatOrderLink::query()->create(['conversation_id' => $conversation->id, 'prefill' => ['items' => []]]);

        $elements = app(ProductCardService::class)->elements($conversation, ['language' => 'en', 'reply_keys' => ['product:'.$this->product->id, 'link:'.$link->id]]);

        $this->assertSame([['type' => 'web_url', 'url' => $link->publicUrl(), 'title' => 'Order now']], $elements[0]['buttons']);
        $this->assertSame('BDT 420 · In stock', $elements[0]['subtitle']);
    }

    public function test_a_product_with_options_shows_its_price_range_and_variants_give_their_product(): void
    {
        $variantProduct = Product::query()->create(['name' => 'Tee', 'sku' => 'CD-2', 'price' => 300, 'sale_price' => 300, 'cost_price' => 100, 'stock' => 0, 'unit' => 'pcs', 'vat_rate' => 0, 'is_active' => true, 'status' => Product::STATUS_AVAILABLE, 'has_variants' => true, 'image' => 'https://cdn.example.com/tee.jpg']);
        $variantProduct->variants()->create(['sku' => 'CD-2-M', 'options' => ['Size' => 'M'], 'sale_price' => 300, 'cost_price' => 100, 'stock' => 4, 'is_active' => true]);
        $variantProduct->variants()->create(['sku' => 'CD-2-L', 'options' => ['Size' => 'L'], 'sale_price' => 350, 'cost_price' => 100, 'stock' => 4, 'is_active' => true]);
        $variant = $variantProduct->variants()->first();
        $conversation = $this->chat();

        $elements = app(ProductCardService::class)->elements($conversation, ['language' => 'en', 'reply_keys' => ['variant:'.$variant->id, 'product:'.$variantProduct->id]]);

        $this->assertCount(1, $elements); // the same product only once
        $this->assertSame('BDT 300 – BDT 350 · In stock', $elements[0]['subtitle']);
    }

    public function test_a_product_without_a_photo_gets_no_card_and_an_answer_cites_at_most_eight_facts(): void
    {
        $keys = [];
        for ($i = 1; $i <= 12; $i++) {
            $product = Product::query()->create(['name' => 'Item '.$i, 'sku' => 'IT-'.$i, 'price' => 100, 'sale_price' => 100, 'cost_price' => 50, 'stock' => 5, 'unit' => 'pcs', 'vat_rate' => 0, 'is_active' => true, 'status' => Product::STATUS_AVAILABLE, 'image' => $i === 1 ? null : 'https://cdn.example.com/i'.$i.'.jpg']);
            $keys[] = 'product:'.$product->id;
        }
        $conversation = $this->chat();

        // The agent can cite at most eight facts in one answer.
        $elements = app(ProductCardService::class)->elements($conversation, ['language' => 'en', 'reply_keys' => $keys]);
        $this->assertCount(7, $elements);
        $this->assertNotContains('Item 1', array_column($elements, 'title'));
    }

    public function test_no_cards_when_off_on_whatsapp_or_without_a_photo(): void
    {
        $this->save(['product_cards_enabled' => false]);
        $this->fakeAgent();
        app(AiReplyService::class)->maybeReply($this->chat());
        $this->assertNotContains('cards', $this->timeline());

        $this->save(['product_cards_enabled' => true]);
        $this->fakeAgent();
        app(AiReplyService::class)->maybeReply($this->chat('whatsapp'));
        $this->assertNotContains('cards', $this->timeline());

        Product::query()->whereKey($this->product->id)->update(['image' => null]);
        $this->fakeAgent();
        app(AiReplyService::class)->maybeReply($this->chat());
        $this->assertNotContains('cards', $this->timeline());
    }

    public function test_a_failed_carousel_leaves_the_answer_and_no_failed_bubble(): void
    {
        $this->fakeAgent(fn (Request $request) => isset($request->data()['message']['attachment'])
            ? Http::response(['error' => ['message' => 'bad button url', 'code' => 100]], 400)
            : Http::response(['message_id' => 'm.'.uniqid(), 'recipient_id' => 'psid-1']));
        $conversation = $this->chat();

        app(AiReplyService::class)->maybeReply($conversation);

        $outgoing = $conversation->messages()->where('direction', 'outgoing')->get();
        $this->assertCount(1, $outgoing);
        $this->assertSame('sent', $outgoing->first()->delivery_status);
        $this->assertNull($conversation->channel->fresh()->last_error);
    }

    public function test_the_carousel_is_not_another_ai_answer_for_the_streak_or_the_report(): void
    {
        $this->save(['max_consecutive_ai_replies' => 2]);
        $conversation = $this->chat();
        $text = $conversation->messages()->create(['direction' => 'outgoing', 'type' => 'text', 'body' => 'First answer', 'delivery_status' => 'sent', 'generated_by' => 'ai', 'sent_at' => now()->addSecond()]);
        $conversation->messages()->create(['direction' => 'outgoing', 'type' => 'text', 'body' => '🛍 Smart Bulb', 'delivery_status' => 'sent', 'generated_by' => 'ai', 'ai_meta' => ['kind' => ConversationMessengerService::CARDS_KIND, 'source_message_id' => $text->id], 'sent_at' => now()->addSeconds(2)]);
        $conversation->messages()->create(['direction' => 'incoming', 'type' => 'text', 'body' => 'Smart Bulb এর দাম কত?', 'external_message_id' => 'mid.next', 'sent_at' => now()->addSeconds(3)]);
        $this->fakeAgent();
        $this->travel(10)->seconds();

        app(AiReplyService::class)->maybeReply($conversation->fresh());

        // One AI answer so far, limit two: the AI still answers (a counted carousel would have handed the chat over).
        $this->assertSame('open', $conversation->fresh()->status);
        $this->assertSame(2, app(AiInboxReportService::class)->summary('7')['ai_replies']);
    }
}
