<?php

namespace Tests\Feature;

use App\Filament\Pages\ConversationActivity;
use App\Filament\Pages\Inbox;
use App\Jobs\AiAutoReplyJob;
use App\Jobs\StoreIncomingMessageJob;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\ConversationMessage;
use App\Models\ConversationModeEvent;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Crm\AiReplyService;
use App\Services\Crm\AiSettingsService;
use App\Services\Crm\ConversationMessengerService;
use App\Services\Crm\ConversationModeService;
use App\Services\Crm\SalesReplyRenderer;
use App\Services\Meta\MetaGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** AI inbox control plane, phase 1: AI/team mode, return to the AI, activity log, reply style. */
class ConversationModeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->company = Company::query()->create(['name' => 'Mode Co', 'slug' => 'mode-co', 'invoice_prefix' => 'MC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($this->company);
        $this->settings();
        $channel = ConversationChannel::query()->create(['provider' => 'messenger', 'external_id' => 'mode-page', 'display_name' => 'Mode Page', 'access_token' => 'test', 'is_active' => true]);
        $this->conversation = Conversation::query()->create(['channel_id' => $channel->id, 'provider' => 'messenger', 'external_contact_id' => 'psid-1', 'contact_name' => 'Rafi Ahmed', 'status' => 'open', 'ai_enabled' => true]);
        $this->incoming('দাম কত?');
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

    private function toolUse(string $name, array $input): array
    {
        return ['content' => [['type' => 'tool_use', 'id' => 'tu_'.uniqid(), 'name' => $name, 'input' => $input]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]];
    }

    private function echo(string $text, string $mid): void
    {
        $channel = $this->conversation->channel;
        $payload = ['object' => 'page', 'entry' => [['id' => $channel->external_id, 'messaging' => [[
            'sender' => ['id' => $channel->external_id], 'recipient' => ['id' => $this->conversation->external_contact_id],
            'timestamp' => now()->getTimestampMs(), 'message' => ['is_echo' => true, 'mid' => $mid, 'text' => $text],
        ]]]]];
        (new StoreIncomingMessageJob($channel->id, $payload))->handle(app(CompanyContext::class), app(MetaGraphService::class));
    }

    public function test_ai_handover_gives_the_chat_to_the_team_with_a_reason_and_a_return_time(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->toolUse('escalate_to_human', ['reason_code' => 'complaint', 'reason' => 'Parcel arrived damaged']))]);

        app(AiReplyService::class)->maybeReply($this->conversation);

        $conversation = $this->conversation->fresh();
        $this->assertSame(Conversation::MODE_HUMAN, $conversation->automation_mode);
        $this->assertFalse($conversation->ai_enabled);
        $this->assertSame('pending', $conversation->status);
        $this->assertSame('complaint', $conversation->handover_reason);
        $this->assertEqualsWithDelta(now()->addMinutes(360)->timestamp, $conversation->human_handled_until->timestamp, 5);
        $event = ConversationModeEvent::query()->sole();
        $this->assertSame(['ai', 'human', 'ai', 'complaint'], [$event->from_mode, $event->to_mode, $event->source, $event->reason]);
        $this->assertSame('Parcel arrived damaged', $event->note);
        $this->assertSame(0, $this->conversation->messages()->where('direction', 'outgoing')->count());
    }

    public function test_handover_notice_is_sent_once_when_the_owner_turns_it_on(): void
    {
        $this->settings(['handover_notice_enabled' => true]);
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'notice-1'])]);

        $service = app(AiReplyService::class);
        $service->escalate($this->conversation, 'Customer asked for a person.', 'human_requested');
        $service->escalate($this->conversation->fresh(), 'Again.', 'human_requested');

        $notice = $this->conversation->messages()->where('direction', 'outgoing')->sole();
        $this->assertSame('বিষয়টা আমাদের একজন প্রতিনিধি এখনই দেখছেন, একটু অপেক্ষা করুন।', $notice->body);
        $this->assertSame('ai', $notice->generated_by);
        $this->assertTrue($this->conversation->fresh()->isHumanMode());
    }

    public function test_keyword_handover_records_the_matching_reason(): void
    {
        Http::fake();
        $this->incoming('আমি রিফান্ড চাই');

        app(AiReplyService::class)->maybeReply($this->conversation);

        $this->assertSame('refund', $this->conversation->fresh()->handover_reason);
        Http::assertNothingSent();
    }

    public function test_due_chats_return_to_the_ai_and_others_stay_with_the_team(): void
    {
        $modes = app(ConversationModeService::class);
        $modes->toHuman($this->conversation, 'ai', null, 30, false, 'complaint');
        $timed = $this->conversation->fresh();
        $permanent = Conversation::query()->create(['channel_id' => $timed->channel_id, 'provider' => 'messenger', 'external_contact_id' => 'psid-2', 'status' => 'open']);
        $modes->toHuman($permanent, 'dashboard', null, null, true);
        $later = Conversation::query()->create(['channel_id' => $timed->channel_id, 'provider' => 'messenger', 'external_contact_id' => 'psid-3', 'status' => 'open']);
        $modes->toHuman($later, 'dashboard', null, 120);

        $this->travel(31)->minutes();
        $this->artisan('crm:revert-conversations')->assertSuccessful();

        $timed->refresh();
        $this->assertSame(Conversation::MODE_AI, $timed->automation_mode);
        $this->assertTrue($timed->ai_enabled);
        $this->assertNull($timed->human_handled_until);
        $this->assertNull($timed->handover_reason);
        $this->assertSame('open', $timed->status);
        $this->assertTrue($permanent->fresh()->isHumanMode());
        $this->assertTrue($later->fresh()->isHumanMode());
        $this->assertSame(1, ConversationModeEvent::query()->where('source', 'auto_revert')->count());
    }

    public function test_return_to_ai_runs_per_company(): void
    {
        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-mode-co', 'invoice_prefix' => 'OC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        $otherChannel = ConversationChannel::query()->create(['provider' => 'messenger', 'external_id' => 'other-page', 'display_name' => 'Other', 'access_token' => 'test', 'is_active' => true]);
        $otherChat = Conversation::query()->create(['channel_id' => $otherChannel->id, 'provider' => 'messenger', 'external_contact_id' => 'psid-x', 'status' => 'open']);
        app(ConversationModeService::class)->toHuman($otherChat, 'dashboard', null, 30);
        app(CompanyContext::class)->set($this->company);
        app(ConversationModeService::class)->toHuman($this->conversation, 'dashboard', null, 30);
        app(CompanyContext::class)->clear();

        $this->travel(31)->minutes();
        $this->artisan('crm:revert-conversations')->assertSuccessful();

        $this->assertSame('ai', Conversation::withoutGlobalScopes()->find($otherChat->id)->automation_mode);
        $this->assertSame('ai', Conversation::withoutGlobalScopes()->find($this->conversation->id)->automation_mode);
        $this->assertSame($other->id, ConversationModeEvent::withoutGlobalScopes()->where('conversation_id', $otherChat->id)->where('source', 'auto_revert')->sole()->company_id);
    }

    public function test_staff_reply_keeps_the_chat_with_the_team_and_restarts_its_timer(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['message_id' => 'staff-1'])->push(['message_id' => 'staff-2'])->push(['message_id' => 'staff-3'])]);
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $messenger = app(ConversationMessengerService::class);

        $messenger->send($this->conversation, 'আমি দেখছি', $user);
        $first = $this->conversation->fresh();
        $this->assertTrue($first->isHumanMode());
        $this->assertSame('staff_reply', ConversationModeEvent::query()->sole()->source);

        $firstUntil = $first->human_handled_until->copy();
        $this->travel(10)->minutes();
        $messenger->send($first, 'আরেকটা উত্তর', $user);
        $this->assertTrue($this->conversation->fresh()->human_handled_until->greaterThan($firstUntil));
        $this->assertSame(1, ConversationModeEvent::query()->count());

        app(ConversationModeService::class)->toHuman($this->conversation->fresh(), 'dashboard', $user, null, true);
        $messenger->send($this->conversation->fresh(), 'স্থায়ী', $user);
        $this->assertNull($this->conversation->fresh()->human_handled_until);
        $this->assertTrue($this->conversation->fresh()->isHumanMode());
    }

    public function test_a_dot_from_the_page_inbox_switches_the_chat_between_team_and_ai(): void
    {
        Queue::fake();

        $this->echo('.', 'dot-1');
        $this->assertTrue($this->conversation->fresh()->isHumanMode());

        $this->echo('.', 'dot-2');
        $this->assertSame(Conversation::MODE_AI, $this->conversation->fresh()->automation_mode);
        $this->assertSame(['dot_command', 'dot_command'], ConversationModeEvent::query()->orderBy('id')->pluck('source')->all());
    }

    public function test_inbox_take_over_timer_and_back_to_ai(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($user);

        $page = Livewire::test(Inbox::class)->set('selectedConversationId', $this->conversation->id)->call('takeOver', 30)->assertHasNoErrors();
        $conversation = $this->conversation->fresh();
        $this->assertTrue($conversation->isHumanMode());
        $this->assertEqualsWithDelta(now()->addMinutes(30)->timestamp, $conversation->human_handled_until->timestamp, 5);

        $page->call('takeOver', 999)->assertHasNoErrors();
        $this->assertEqualsWithDelta(now()->addMinutes(360)->timestamp, $this->conversation->fresh()->human_handled_until->timestamp, 5);

        $page->call('claimConversation')->call('backToAi')->assertHasNoErrors();
        $conversation = $this->conversation->fresh();
        $this->assertSame(Conversation::MODE_AI, $conversation->automation_mode);
        $this->assertSame($user->id, $conversation->assigned_to);
        $this->assertSame(['dashboard'], ConversationModeEvent::query()->distinct()->pluck('source')->all());
        $this->assertSame($user->id, ConversationModeEvent::query()->latest('id')->first()->user_id);
    }

    public function test_reply_waits_for_the_configured_seconds(): void
    {
        Queue::fake();
        $this->settings(['reply_delay_seconds' => 12]);
        $source = $this->conversation->messages()->sole();

        AiAutoReplyJob::dispatchAfterWait($this->conversation->id, $source->id);

        Queue::assertPushed(AiAutoReplyJob::class, fn (AiAutoReplyJob $job): bool => abs(now()->addSeconds(12)->diffInSeconds($job->delay, true)) <= 2);
    }

    public function test_rich_reply_puts_each_fact_on_its_own_labelled_line(): void
    {
        $product = Product::query()->create(['name' => 'Ivory Panjabi', 'sku' => 'PJ-1', 'price' => 2450, 'sale_price' => 2450, 'cost_price' => 1000, 'stock' => 5, 'unit' => 'pcs', 'is_active' => true, 'status' => 'available']);
        $key = 'product:'.$product->id;

        $reply = app(SalesReplyRenderer::class)->render($this->conversation, ['reply_keys' => [$key], 'product_detail' => 'both', 'language' => 'bn', 'salutation' => 'sir'], [['result' => ['reply_options' => [$key]]]], false, ['rich' => true, 'address_style' => 'sir_maam']);

        $this->assertSame("জ্বি স্যার, Ivory Panjabi\n💰 দাম: ২৪৫০ টাকা\n✅ স্টকে আছে", $reply);
    }

    public function test_rich_reply_lists_options_in_stock_and_never_a_count(): void
    {
        $product = Product::query()->create(['name' => 'Kurti', 'sku' => 'KT-1', 'price' => 1350, 'sale_price' => 1350, 'cost_price' => 500, 'stock' => 9, 'unit' => 'pcs', 'is_active' => true, 'status' => 'available', 'has_variants' => true]);
        foreach (['M' => 4, 'L' => 0, 'XL' => 5] as $size => $stock) {
            ProductVariant::query()->create(['product_id' => $product->id, 'sku' => 'KT-1-'.$size, 'options' => ['Size' => $size], 'sale_price' => 1350, 'stock' => $stock, 'is_active' => true]);
        }
        $key = 'product:'.$product->id;

        $reply = app(SalesReplyRenderer::class)->render($this->conversation, ['reply_keys' => [$key], 'language' => 'bn', 'salutation' => 'none'], [['result' => ['reply_options' => [$key]]]], false, ['rich' => true, 'address_style' => 'sir_maam']);

        $this->assertStringStartsWith('Kurti', $reply);
        $this->assertStringContainsString('💰 দাম: ১৩৫০ টাকা', $reply);
        $this->assertStringContainsString('📏 স্টকে আছে: Size: M, XL', $reply);
        $this->assertStringNotContainsString('৪', $reply);
        $this->assertStringEndsWith('কোনটি নেবেন?', $reply);
    }

    public function test_compact_reply_is_unchanged_when_rich_replies_are_off(): void
    {
        $product = Product::query()->create(['name' => 'Lamp', 'sku' => 'LAMP-1', 'price' => 400, 'sale_price' => 400, 'cost_price' => 100, 'stock' => 3, 'unit' => 'pcs', 'is_active' => true, 'status' => 'available']);
        $key = 'product:'.$product->id;

        $reply = app(SalesReplyRenderer::class)->render($this->conversation, ['reply_keys' => [$key], 'language' => 'bn', 'salutation' => 'sir'], [['result' => ['reply_options' => [$key]]]]);

        $this->assertSame('Lamp — ৪০০ টাকা', $reply);
    }

    public function test_activity_page_lists_switches_and_exports_csv(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true, 'name' => 'Nusrat']);
        $this->actingAs($user);
        app(ConversationModeService::class)->toHuman($this->conversation, 'dashboard', $user, 60);

        Livewire::test(ConversationActivity::class)->assertOk()->assertCanSeeTableRecords(ConversationModeEvent::query()->get());

        $response = Livewire::test(ConversationActivity::class)->instance()->exportCsv();
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString('Rafi Ahmed', $csv);
        $this->assertStringContainsString('Nusrat', $csv);
    }

    public function test_migration_backfill_keeps_paused_chats_with_the_team(): void
    {
        $migration = require database_path('migrations/2026_10_09_000100_add_automation_mode_to_conversations_table.php');
        $paused = Conversation::query()->create(['channel_id' => $this->conversation->channel_id, 'provider' => 'messenger', 'external_contact_id' => 'psid-p', 'status' => 'pending', 'ai_enabled' => false]);
        $held = Conversation::query()->create(['channel_id' => $this->conversation->channel_id, 'provider' => 'messenger', 'external_contact_id' => 'psid-h', 'status' => 'open', 'ai_enabled' => true, 'human_handled_until' => now()->addHour()]);

        $migration->down();
        $migration->up();

        $this->assertSame('human', $paused->fresh()->automation_mode);
        $this->assertNull($paused->fresh()->human_handled_until);
        $this->assertSame('human', $held->fresh()->automation_mode);
        $this->assertFalse($held->fresh()->ai_enabled);
        $this->assertSame('ai', $this->conversation->fresh()->automation_mode);
    }
}
