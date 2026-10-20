<?php

namespace Tests\Feature;

use App\Filament\Pages\AiSetupChecklist;
use App\Filament\Pages\Inbox;
use App\Filament\Pages\Integrations;
use App\Models\ChatOrderLink;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\ConversationMessage;
use App\Models\CrmAiRun;
use App\Models\Product;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Crm\AiReplyService;
use App\Services\Crm\AiSettingsService;
use App\Services\Crm\AiSetupChecklistService;
use App\Services\Crm\SalesFollowUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Practice mode (owner request 2026-10-19): until the AI is live it answers
 * only the chats staff marked as test chats.
 */
class PracticeModeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private ConversationChannel $channel;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::query()->create(['name' => 'Practice Co', 'slug' => 'practice-co', 'invoice_prefix' => 'PC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($this->company);
        $this->channel = ConversationChannel::query()->create(['provider' => 'whatsapp', 'external_id' => 'phone-1', 'display_name' => 'WA', 'access_token' => 'wa-token', 'is_active' => true]);
        $this->product = Product::query()->create(['name' => 'Smart Bulb', 'sku' => 'PM-1', 'price' => 420, 'sale_price' => 420, 'cost_price' => 250, 'stock' => 15, 'unit' => 'pcs', 'vat_rate' => 0, 'is_active' => true, 'status' => Product::STATUS_AVAILABLE]);
    }

    private function save(array $extra = []): void
    {
        app(AiSettingsService::class)->save($this->company, 'messaging', [...AiSettingsService::DEFAULTS, 'enabled' => true, 'api_key' => 'key', 'model' => 'claude-test', ...$extra]);
        $this->company->refresh();
    }

    private function chat(array $attributes = []): Conversation
    {
        $conversation = Conversation::query()->create([
            'provider' => 'whatsapp', 'channel_id' => $this->channel->id, 'external_contact_id' => '8801700'.random_int(100000, 999999),
            'contact_name' => 'Customer', 'contact_phone' => '8801700000000', 'status' => 'open', ...$attributes,
        ]);
        ConversationMessage::query()->create(['conversation_id' => $conversation->id, 'direction' => 'incoming', 'type' => 'text', 'body' => 'Smart Bulb এর দাম কত?', 'external_message_id' => 'wamid.'.uniqid(), 'sent_at' => now()]);

        return $conversation->fresh();
    }

    private function fakeAgent(): void
    {
        $reply = [
            ['content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'lookup_product', 'input' => ['name' => 'Smart Bulb']]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]],
            ['content' => [['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'submit_reply', 'input' => ['answer' => 'ok', 'reply_keys' => ['product:'.$this->product->id], 'confidence' => 0.95, 'needs_human' => false]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]],
        ];
        $sequence = Http::sequence();
        foreach ($reply as $response) {
            $sequence->push($response);
        }
        Http::fake(['api.anthropic.com/*' => $sequence, 'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]])]);
    }

    public function test_a_company_that_never_saved_it_is_live_exactly_when_the_ai_is_on(): void
    {
        $this->assertFalse(app(AiSettingsService::class)->isLive($this->company));

        $this->save();
        $this->assertTrue(app(AiSettingsService::class)->isLive($this->company));

        // Settings saved before practice mode existed carry no ai_live key at all.
        $settings = (array) $this->company->settings;
        unset($settings['ai_tools']['messaging']['ai_live']);
        $this->company->forceFill(['settings' => $settings])->save();
        $this->assertTrue(app(AiSettingsService::class)->isLive($this->company->fresh()));
        $this->assertTrue(app(AiSettingsService::class)->all($this->company->fresh(), 'messaging')['ai_live']);
    }

    public function test_in_practice_mode_a_real_customer_gets_no_ai_reply(): void
    {
        $this->save(['ai_live' => false]);
        Http::fake();
        $conversation = $this->chat();

        app(AiReplyService::class)->maybeReply($conversation);

        Http::assertNothingSent();
        $this->assertSame(0, $conversation->messages()->where('direction', 'outgoing')->count());
        $run = CrmAiRun::query()->where('conversation_id', $conversation->id)->sole();
        $this->assertSame('skipped', $run->status);
        $this->assertStringContainsString('Practice mode', $run->reason);
        $this->assertSame('open', $conversation->fresh()->status);
    }

    public function test_in_practice_mode_a_test_chat_is_answered(): void
    {
        $this->save(['ai_live' => false]);
        $this->fakeAgent();
        $conversation = $this->chat(['is_staff_test' => true]);

        app(AiReplyService::class)->maybeReply($conversation);

        $reply = $conversation->messages()->where('direction', 'outgoing')->sole();
        $this->assertSame('ai', $reply->generated_by);
        $this->assertStringContainsString('৪২০', $reply->body);
    }

    public function test_once_live_every_customer_is_answered(): void
    {
        $this->save(['ai_live' => false]);
        app(AiSettingsService::class)->setLive($this->company, true);
        $this->company->refresh();
        $this->fakeAgent();
        $conversation = $this->chat();

        app(AiReplyService::class)->maybeReply($conversation);

        $this->assertSame(1, $conversation->messages()->where('direction', 'outgoing')->count());
    }

    public function test_going_live_and_back_keeps_the_other_settings(): void
    {
        $this->save(['ai_live' => false, 'takeover_minutes' => 90]);
        $service = app(AiSettingsService::class);

        $service->setLive($this->company, true);
        $settings = $service->all($this->company->fresh(), 'messaging');
        $this->assertTrue($settings['ai_live']);
        $this->assertSame(90, $settings['takeover_minutes']);
        $this->assertSame('key', $settings['api_key']);

        $service->setLive($this->company->fresh(), false);
        $this->assertFalse($service->isLive($this->company->fresh()));
        $this->assertTrue($service->enabled($this->company->fresh(), 'messaging'));
    }

    public function test_saving_other_settings_keeps_the_live_choice(): void
    {
        $this->save(['ai_live' => false]);
        app(AiSettingsService::class)->save($this->company->fresh(), 'messaging', ['enabled' => true, 'takeover_minutes' => 60]);

        $this->assertFalse(app(AiSettingsService::class)->isLive($this->company->fresh()));
    }

    public function test_reminders_wait_in_practice_mode_and_go_out_once_live(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00', 'Asia/Dhaka'));
        $this->save(['ai_live' => true, 'sales_follow_ups_enabled' => true, 'follow_up_template' => 'sales_followup']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.template']]])]);
        $conversation = $this->chat(['ai_enabled' => true]);
        $link = ChatOrderLink::query()->create(['conversation_id' => $conversation->id, 'prefill' => ['items' => []]]);
        $followUp = app(SalesFollowUpService::class)->schedule($conversation, $link);

        app(AiSettingsService::class)->setLive($this->company->fresh(), false);
        $this->travelTo(now()->addHour()->addSecond());
        app(SalesFollowUpService::class)->process($followUp);

        Http::assertNothingSent();
        $this->assertSame(['pending', 0], [$followUp->fresh()->status, $followUp->fresh()->step]);

        app(AiSettingsService::class)->setLive($this->company->fresh(), true);
        app(SalesFollowUpService::class)->process($followUp->fresh());

        Http::assertSentCount(1);
        $this->assertSame(1, $followUp->fresh()->step);
    }

    public function test_a_company_that_turns_the_ai_on_in_the_form_starts_in_practice_mode(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $this->company->getKey(), 'current_company_selection_explicit' => true]);

        $form = Livewire::test(Integrations::class)
            ->assertSet('data.ai_messaging_ai_live', false)
            ->assertSet('data.ai_messaging_typing_indicator_enabled', true)
            ->assertSet('data.ai_messaging_product_cards_enabled', false)
            ->set('data.ai_messaging_api_format', 'anthropic')
            ->set('data.ai_messaging_provider', 'Anthropic (Claude)')
            ->set('data.ai_messaging_model', 'claude-haiku-4-5-20251001')
            ->set('data.ai_messaging_api_key', 'messaging-key')
            ->set('data.ai_messaging_enabled', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(AiSettingsService::class)->all($this->company->fresh(), 'messaging');
        $this->assertTrue($settings['enabled']);
        $this->assertFalse($settings['ai_live']);

        $form->set('data.ai_messaging_ai_live', true)
            ->set('data.ai_messaging_typing_indicator_enabled', false)
            ->set('data.ai_messaging_product_cards_enabled', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(AiSettingsService::class)->all($this->company->fresh(), 'messaging');
        $this->assertSame([true, false, true], [$settings['ai_live'], $settings['typing_indicator_enabled'], $settings['product_cards_enabled']]);
    }

    public function test_staff_can_mark_a_chat_as_a_test_chat_in_the_inbox(): void
    {
        $this->save(['ai_live' => false]);
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        $conversation = $this->chat();

        Livewire::test(Inbox::class, ['selectedConversationId' => $conversation->id])
            ->assertActionVisible('markTestChat')
            ->callAction('markTestChat');
        $this->assertTrue($conversation->fresh()->is_staff_test);

        Livewire::test(Inbox::class, ['selectedConversationId' => $conversation->id])->callAction('markTestChat');
        $this->assertFalse($conversation->fresh()->is_staff_test);
    }

    public function test_the_test_chat_button_is_hidden_once_live_unless_the_chat_is_marked(): void
    {
        $this->save(['ai_live' => true]);
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        $plain = $this->chat();
        $marked = $this->chat(['is_staff_test' => true]);

        Livewire::test(Inbox::class, ['selectedConversationId' => $plain->id])->assertActionHidden('markTestChat');
        Livewire::test(Inbox::class, ['selectedConversationId' => $marked->id])->assertActionVisible('markTestChat');
    }

    public function test_the_checklist_page_goes_live_and_back_for_people_who_manage_settings(): void
    {
        $this->save(['ai_live' => false]);
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        Livewire::test(AiSetupChecklist::class)->assertActionVisible('goLive')->assertActionHidden('practiceMode')->callAction('goLive');
        $this->assertTrue(app(AiSettingsService::class)->isLive($this->company->fresh()));

        Livewire::test(AiSetupChecklist::class)->assertActionHidden('goLive')->assertActionVisible('practiceMode')->callAction('practiceMode');
        $this->assertFalse(app(AiSettingsService::class)->isLive($this->company->fresh()));
    }

    public function test_the_live_step_is_done_only_when_the_ai_is_on_and_live(): void
    {
        $steps = fn (): array => collect(app(AiSetupChecklistService::class)->steps($this->company->fresh()))->keyBy('key')->all();

        $this->assertFalse($steps()['live']['done']);

        $this->save(['ai_live' => false]);
        $this->assertFalse($steps()['live']['done']);

        app(AiSettingsService::class)->setLive($this->company->fresh(), true);
        $this->assertTrue($steps()['live']['done']);
    }
}
