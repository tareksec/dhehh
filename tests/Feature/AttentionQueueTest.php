<?php

namespace Tests\Feature;

use App\Filament\Pages\NeedsAttention;
use App\Models\AttentionItem;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Crm\AiReplyService;
use App\Services\Crm\AiSettingsService;
use App\Services\Crm\ConversationMessengerService;
use App\Services\Crm\ConversationModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** AI inbox control plane, phase 2: the Needs attention queue and the reply target. */
class AttentionQueueTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->company = Company::query()->create(['name' => 'Queue Co', 'slug' => 'queue-co', 'invoice_prefix' => 'QC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($this->company);
        $this->settings();
        $channel = ConversationChannel::query()->create(['provider' => 'messenger', 'external_id' => 'queue-page', 'display_name' => 'Queue Page', 'access_token' => 'test', 'is_active' => true]);
        $this->conversation = Conversation::query()->create(['channel_id' => $channel->id, 'provider' => 'messenger', 'external_contact_id' => 'psid-q', 'contact_name' => 'Mitu Akter', 'status' => 'open', 'ai_enabled' => true]);
        $this->conversation->messages()->create(['direction' => 'incoming', 'type' => 'text', 'body' => 'প্যাকেট ভাঙা এসেছে', 'sent_at' => now()]);
    }

    private function settings(array $extra = []): void
    {
        app(AiSettingsService::class)->save($this->company, 'messaging', [...AiSettingsService::DEFAULTS, 'enabled' => true, 'api_key' => 'test', ...$extra]);
        $this->company->refresh();
        if (isset($this->conversation)) {
            $this->conversation->unsetRelation('company');
        }
    }

    private function handOver(string $reason = 'complaint'): void
    {
        app(ConversationModeService::class)->toHuman($this->conversation, 'ai', null, null, false, $reason, 'Parcel arrived damaged');
    }

    public function test_ai_handover_opens_one_item_with_the_reply_target(): void
    {
        $this->settings(['response_target_minutes' => 15]);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'escalate_to_human', 'input' => ['reason_code' => 'complaint', 'reason' => 'Parcel arrived damaged']]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]])]);

        app(AiReplyService::class)->maybeReply($this->conversation);
        $this->handOver('refund'); // a second handover while open updates the same item

        $item = AttentionItem::query()->sole();
        $this->assertSame(['handover', 'refund'], [$item->kind, $item->reason]);
        $this->assertSame($this->conversation->id, $item->conversation_id);
        $this->assertEqualsWithDelta(now()->addMinutes(15)->timestamp, $item->due_at->timestamp, 5);
        $this->assertNull($item->resolved_at);
    }

    public function test_staff_takeover_from_the_dashboard_opens_nothing_and_no_target_means_never_late(): void
    {
        $this->settings(['response_target_minutes' => 0]);
        app(ConversationModeService::class)->toHuman($this->conversation, 'dashboard');
        $this->assertSame(0, AttentionItem::query()->count());

        $this->handOver();
        $item = AttentionItem::query()->sole();
        $this->assertNull($item->due_at);
        $this->travel(5)->hours();
        $this->assertFalse($item->fresh()->isOverdue());
    }

    public function test_first_staff_reply_stops_the_timer_and_return_to_ai_closes_as_answered(): void
    {
        $this->settings(['response_target_minutes' => 10]);
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['message_id' => 'q-1'])->push(['message_id' => 'q-2'])]);
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->handOver();

        $this->travel(4)->minutes();
        app(ConversationMessengerService::class)->send($this->conversation->fresh(), 'দুঃখিত, দেখছি', $user);
        $this->travel(20)->minutes();
        app(ConversationMessengerService::class)->send($this->conversation->fresh(), 'নতুন পাঠাচ্ছি', $user);

        $item = AttentionItem::query()->sole();
        $this->assertSame($user->id, $item->first_response_by);
        $this->assertSame(4, $item->waitMinutes());
        $this->assertFalse($item->isOverdue());

        app(ConversationModeService::class)->toAi($this->conversation->fresh(), 'dashboard', $user);
        $item->refresh();
        $this->assertSame('answered', $item->outcome);
        $this->assertSame($user->id, $item->resolved_by);
    }

    public function test_auto_return_without_a_reply_is_recorded_as_never_answered(): void
    {
        $this->settings(['response_target_minutes' => 10, 'takeover_minutes' => 30]);
        $this->handOver();

        $this->travel(11)->minutes();
        $this->assertTrue(AttentionItem::query()->sole()->isOverdue());

        $this->travel(20)->minutes();
        $this->artisan('crm:revert-conversations')->assertSuccessful();

        $item = AttentionItem::query()->sole();
        $this->assertSame('never_answered', $item->outcome);
        $this->assertNotNull($item->resolved_at);
        $this->assertNull($item->resolved_by);
    }

    public function test_back_to_ai_without_a_reply_is_recorded_as_given_back(): void
    {
        $this->handOver();
        app(ConversationModeService::class)->toAi($this->conversation->fresh(), 'dot_command');

        $this->assertSame('returned_to_ai', AttentionItem::query()->sole()->outcome);
    }

    public function test_needs_attention_page_lists_open_items_and_marks_done(): void
    {
        $this->settings(['response_target_minutes' => 10]);
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($user);
        $this->handOver();
        $item = AttentionItem::query()->sole();
        $this->travel(15)->minutes();

        $this->assertSame('1', NeedsAttention::getNavigationBadge());
        $this->assertSame('danger', NeedsAttention::getNavigationBadgeColor());

        Livewire::test(NeedsAttention::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$item])
            ->filterTable('late', true)
            ->assertCanSeeTableRecords([$item])
            ->callTableAction('claim', $item)
            ->callTableAction('done', $item)
            ->assertHasNoErrors();

        $item->refresh();
        $this->assertSame('dismissed', $item->outcome);
        $this->assertSame($user->id, $item->resolved_by);
        $this->assertSame($user->id, $this->conversation->fresh()->assigned_to);
        $this->assertNull(NeedsAttention::getNavigationBadge());
        Livewire::test(NeedsAttention::class)->assertCanNotSeeTableRecords([$item]);
    }

    public function test_items_stay_inside_their_company(): void
    {
        $this->handOver();
        $other = Company::query()->create(['name' => 'Other Queue', 'slug' => 'other-queue', 'invoice_prefix' => 'OQ', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($other);

        $this->assertSame(0, AttentionItem::query()->count());
        $this->assertSame($this->company->id, AttentionItem::withoutGlobalScopes()->sole()->company_id);
    }
}
