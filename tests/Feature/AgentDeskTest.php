<?php

namespace Tests\Feature;

use App\Filament\Pages\AgentDesk;
use App\Filament\Pages\UnansweredQuestions;
use App\Models\AiInstructionVersion;
use App\Models\ChatOrderLink;
use App\Models\Company;
use App\Models\CompanyFaq;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\ConversationMessage;
use App\Models\ConversationModeEvent;
use App\Models\CrmAiRun;
use App\Models\Product;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Crm\AiInstructionService;
use App\Services\Crm\AiReplyService;
use App\Services\Crm\AiRunService;
use App\Services\Crm\AiSettingsService;
use App\Services\Crm\ConversationMessengerService;
use App\Services\Crm\ConversationModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** AI inbox control plane, phase 4: Agent Desk (versions, test chat, unanswered questions). */
class AgentDeskTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->company = Company::query()->create(['name' => 'Desk Co', 'slug' => 'desk-co', 'invoice_prefix' => 'DC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($this->company);
        $this->settings(['brand_voice' => 'Friendly', 'sales_guidelines' => 'Be brief.']);
        AiInstructionVersion::query()->delete(); // each test starts with no history
    }

    private function settings(array $extra = []): void
    {
        app(AiSettingsService::class)->save($this->company, 'messaging', [...AiSettingsService::DEFAULTS, 'enabled' => true, 'api_key' => 'test', ...$extra]);
        $this->company->refresh();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function toolUse(string $name, array $input): array
    {
        return ['content' => [['type' => 'tool_use', 'id' => 'tu_'.uniqid(), 'name' => $name, 'input' => $input]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 20]];
    }

    private function product(): Product
    {
        return Product::query()->create(['name' => 'Lamp', 'sku' => 'LAMP-1', 'price' => 400, 'sale_price' => 400, 'cost_price' => 100, 'stock' => 3, 'unit' => 'pcs', 'is_active' => true, 'status' => 'available']);
    }

    public function test_first_view_makes_the_current_text_version_one_and_publishing_replaces_it(): void
    {
        $service = app(AiInstructionService::class);
        $user = $this->admin();

        $first = $service->published($this->company);
        $this->assertSame('Friendly', $first->normalizedBlocks()['voice']);
        $this->assertSame($first->id, $service->published($this->company)->id);

        $service->saveDraft($this->company, ['about' => 'We sell lamps.', 'voice' => 'Warm', 'guidelines' => 'Ask the size.'], $user);
        $service->saveDraft($this->company, ['about' => 'We sell lamps.', 'voice' => 'Warm', 'guidelines' => 'Ask the colour.'], $user);
        $this->assertSame(1, AiInstructionVersion::query()->where('status', 'draft')->count());
        $this->assertSame('Friendly', app(AiSettingsService::class)->all($this->company, 'messaging')['brand_voice'], 'a draft changes nothing live');

        $published = $service->publishDraft($this->company, $user, 'Colour question');

        $live = app(AiSettingsService::class)->all($this->company->fresh(), 'messaging');
        $this->assertSame(['We sell lamps.', 'Warm', 'Ask the colour.'], [$live['business_about'], $live['brand_voice'], $live['sales_guidelines']]);
        $this->assertSame('published', $published->fresh()->status);
        $this->assertSame('Colour question', $published->change_note);
        $this->assertSame($user->id, $published->published_by);
        $this->assertSame('superseded', $first->fresh()->status);
        $this->assertSame(1, AiInstructionVersion::query()->where('status', 'published')->count());
        $this->assertNull($service->draft($this->company));
    }

    public function test_restoring_an_older_version_makes_it_live_as_a_new_version(): void
    {
        $service = app(AiInstructionService::class);
        $user = $this->admin();
        $first = $service->published($this->company);
        $service->saveDraft($this->company, ['about' => '', 'voice' => 'Formal', 'guidelines' => 'New rules'], $user);
        $service->publishDraft($this->company, $user, null);

        $restored = $service->restore($this->company, $first, $user);

        $this->assertSame('Friendly', app(AiSettingsService::class)->all($this->company->fresh(), 'messaging')['brand_voice']);
        $this->assertSame('restore', $restored->source);
        $this->assertSame('published', $restored->status);
        $this->assertSame(1, AiInstructionVersion::query()->where('status', 'published')->count());
        $this->assertSame(3, AiInstructionVersion::query()->count());
    }

    public function test_saving_the_same_texts_on_integrations_is_recorded_as_a_version(): void
    {
        $this->settings(['brand_voice' => 'Friendly', 'sales_guidelines' => 'Be brief.']);
        $this->assertSame(0, AiInstructionVersion::query()->count(), 'no change, no version');

        $this->settings(['brand_voice' => 'Playful', 'sales_guidelines' => 'Be brief.']);

        $versions = AiInstructionVersion::query()->orderBy('id')->get();
        $this->assertCount(2, $versions);
        $this->assertSame('Friendly', $versions[0]->normalizedBlocks()['voice']);
        $this->assertSame('superseded', $versions[0]->status);
        $this->assertSame('Playful', $versions[1]->normalizedBlocks()['voice']);
        $this->assertSame('published', $versions[1]->status);
        $this->assertSame('settings', $versions[1]->source);
    }

    public function test_test_chat_answers_from_the_real_catalog_and_sends_nothing(): void
    {
        $product = $this->product();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse('lookup_product', ['name' => 'Lamp']))
            ->push($this->toolUse('create_order_link', ['items' => [['product_id' => $product->id, 'quantity' => 1]]]))
            ->push($this->toolUse('submit_reply', ['reply_keys' => ['product:'.$product->id], 'product_detail' => 'price', 'language' => 'bn', 'confidence' => 0.95, 'needs_human' => false]))]);
        $conversations = Conversation::query()->count();
        $messages = ConversationMessage::query()->count();

        $result = app(AiReplyService::class)->dryRun($this->company, [], 'ল্যাম্পের দাম কত?', ['about' => 'We sell lamps and lights.', 'voice' => 'Warm', 'guidelines' => 'Be brief.']);

        $this->assertSame('reply', $result['outcome']);
        $this->assertSame('Lamp — ৪০০ টাকা', $result['reply']);
        $this->assertSame(['lookup_product', 'create_order_link'], array_column($result['tools'], 'tool'));
        $this->assertStringContainsString('test_mode', $result['tools'][1]['summary']);
        $this->assertSame(0, ChatOrderLink::query()->count());
        $this->assertSame($conversations, Conversation::query()->count());
        $this->assertSame($messages, ConversationMessage::query()->count());
        $run = CrmAiRun::query()->sole();
        $this->assertTrue($run->is_test);
        $this->assertNull($run->conversation_id);
        $this->assertSame(300, $run->input_tokens);
        Http::assertSent(function ($request): bool {
            $system = (string) ($request['system'] ?? '');

            return str_contains($system, 'We sell lamps and lights.') && str_contains($system, 'Warm');
        });
        Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'graph.facebook.com'));
    }

    public function test_test_chat_hands_over_a_keyword_message_and_uses_saved_faqs_without_the_ai(): void
    {
        CompanyFaq::query()->create(['company_id' => $this->company->id, 'question' => 'Return policy', 'answer' => '৭ দিনের মধ্যে ফেরত।', 'keywords' => 'return policy', 'is_active' => true]);
        $service = app(AiReplyService::class);

        $handover = $service->dryRun($this->company, [], 'আমি রিফান্ড চাই');
        $faq = $service->dryRun($this->company, [], 'what is your return policy?');

        $this->assertSame(['handover', 'refund', 'keyword'], [$handover['outcome'], $handover['reason_code'], $handover['source']]);
        $this->assertSame(['reply', '৭ দিনের মধ্যে ফেরত।', 'faq'], [$faq['outcome'], $faq['reply'], $faq['source']]);
        Http::assertNothingSent();
    }

    public function test_test_chat_reports_a_handover_when_the_ai_has_no_verified_answer(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->toolUse('escalate_to_human', ['reason_code' => 'out_of_scope', 'reason' => 'No grounded answer for the warranty question']))]);

        $result = app(AiReplyService::class)->dryRun($this->company, [], 'warranty koto din?');

        $this->assertSame(['handover', 'out_of_scope'], [$result['outcome'], $result['reason_code']]);
        $this->assertSame('No grounded answer for the warranty question', $result['reason']);
        $this->assertSame(0, ConversationModeEvent::query()->count(), 'a test never switches a real chat');
    }

    public function test_test_chat_needs_an_ai_key(): void
    {
        $this->settings(['api_key' => '']);
        $company = $this->company->fresh();
        $stored = (array) $company->settings;
        unset($stored['ai_tools']['messaging']['api_key']);
        $company->forceFill(['settings' => $stored])->save();

        $result = app(AiReplyService::class)->dryRun($company->fresh(), [], 'hello');

        $this->assertSame('unavailable', $result['outcome']);
        Http::assertNothingSent();
    }

    public function test_nothing_can_be_sent_in_an_unsaved_conversation(): void
    {
        $this->expectException(\LogicException::class);

        app(ConversationMessengerService::class)->send(new Conversation(['company_id' => $this->company->id]), 'hello');
    }

    public function test_test_runs_cost_money_but_do_not_use_up_the_daily_run_limit(): void
    {
        $this->settings(['daily_run_limit' => 1]);
        $channel = ConversationChannel::query()->create(['provider' => 'messenger', 'external_id' => 'desk-page', 'display_name' => 'Desk Page', 'access_token' => 'test', 'is_active' => true]);
        $conversation = Conversation::query()->create(['channel_id' => $channel->id, 'provider' => 'messenger', 'external_contact_id' => 'psid-d', 'status' => 'open']);
        CrmAiRun::query()->create(['company_id' => $this->company->id, 'conversation_id' => null, 'is_test' => true, 'status' => 'tested', 'estimated_cost_usd' => 0.01]);

        $run = app(AiRunService::class)->start($conversation, null, app(AiSettingsService::class)->all($this->company->fresh(), 'messaging'));

        $this->assertSame('running', $run->status);
    }

    public function test_agent_desk_saves_a_draft_publishes_and_tests_the_editor_text(): void
    {
        $this->actingAs($this->admin());
        Http::fake(['api.anthropic.com/*' => Http::response($this->toolUse('escalate_to_human', ['reason_code' => 'other', 'reason' => 'Test']))]);

        $page = Livewire::test(AgentDesk::class)->assertOk()
            ->assertSet('blocks.voice', 'Friendly')
            ->set('blocks.voice', 'Calm and kind')
            ->set('blocks.about', 'A lamp shop.')
            ->call('saveDraft')->assertHasNoErrors()->assertSet('hasDraft', true)
            ->set('testMessage', 'hello')->call('sendTest')->assertHasNoErrors();

        $this->assertSame('handover', $page->get('transcript')[1]['kind']);
        Http::assertSent(fn ($request): bool => str_contains((string) ($request['system'] ?? ''), 'Calm and kind'));
        $this->assertSame('Friendly', app(AiSettingsService::class)->all($this->company->fresh(), 'messaging')['brand_voice']);

        $page->callAction('publish', ['note' => 'Softer tone'])->assertHasNoActionErrors();

        $this->assertSame('Calm and kind', app(AiSettingsService::class)->all($this->company->fresh(), 'messaging')['brand_voice']);
        $this->assertSame('Softer tone', AiInstructionVersion::query()->where('status', 'published')->sole()->change_note);
        $page->assertSet('hasDraft', false)->assertSee('Softer tone');
    }

    public function test_only_people_who_manage_settings_can_change_or_test_the_instructions(): void
    {
        $staff = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $this->actingAs($staff);

        Livewire::test(AgentDesk::class)->assertOk()
            ->assertDontSee('Send test message')
            ->set('blocks.voice', 'Hacked')
            ->call('saveDraft')->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, AiInstructionVersion::query()->where('status', 'draft')->count());
    }

    public function test_unanswered_questions_list_out_of_scope_handovers_and_add_a_faq_answer(): void
    {
        $user = $this->admin();
        $this->actingAs($user);
        $channel = ConversationChannel::query()->create(['provider' => 'messenger', 'external_id' => 'desk-page-2', 'display_name' => 'Desk Page', 'access_token' => 'test', 'is_active' => true]);
        $conversation = Conversation::query()->create(['channel_id' => $channel->id, 'provider' => 'messenger', 'external_contact_id' => 'psid-u', 'contact_name' => 'Tania', 'status' => 'open', 'ai_enabled' => true]);
        $conversation->messages()->create(['direction' => 'incoming', 'type' => 'text', 'body' => 'warranty koto din?', 'sent_at' => now()]);
        app(ConversationModeService::class)->toHuman($conversation, 'ai', null, null, false, 'out_of_scope', 'No grounded answer');
        $other = Conversation::query()->create(['channel_id' => $channel->id, 'provider' => 'messenger', 'external_contact_id' => 'psid-v', 'status' => 'open', 'ai_enabled' => true]);
        app(ConversationModeService::class)->toHuman($other, 'ai', null, null, false, 'complaint');
        Cache::put("crm:faq:{$this->company->id}", collect(), 300);
        $events = ConversationModeEvent::query()->where('reason', 'out_of_scope')->get();

        Livewire::test(UnansweredQuestions::class)->assertOk()
            ->assertCanSeeTableRecords($events)
            ->assertCountTableRecords(1)
            ->assertSee('warranty koto din?')
            ->callTableAction('addFaq', $events->first(), ['question' => 'Warranty', 'answer' => '১ বছরের ওয়ারেন্টি।', 'keywords' => 'warranty, ওয়ারেন্টি'])
            ->assertHasNoTableActionErrors();

        $faq = CompanyFaq::query()->sole();
        $this->assertSame(['Warranty', true], [$faq->question, $faq->is_active]);
        $this->assertTrue($faq->matches('Warranty koto din?'));
        $this->assertFalse(Cache::has("crm:faq:{$this->company->id}"));
    }
}
