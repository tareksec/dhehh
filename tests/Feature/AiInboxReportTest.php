<?php

namespace Tests\Feature;

use App\Filament\Pages\AiInboxReport;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Crm\AiInboxReportService;
use App\Services\Crm\AiSettingsService;
use App\Services\Crm\AttentionQueueService;
use App\Services\Crm\ConversationModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** AI inbox control plane, phase 3: the AI inbox report. */
class AiInboxReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private ConversationChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::query()->create(['name' => 'Report Co', 'slug' => 'report-co', 'invoice_prefix' => 'RC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($this->company);
        app(AiSettingsService::class)->save($this->company, 'messaging', [...AiSettingsService::DEFAULTS, 'enabled' => true, 'api_key' => 'test', 'response_target_minutes' => 10]);
        $this->company->refresh();
        $this->channel = ConversationChannel::query()->create(['provider' => 'messenger', 'external_id' => 'report-page', 'display_name' => 'Report Page', 'access_token' => 'test', 'is_active' => true]);
    }

    private function chat(string $psid, ?string $aiReply = 'জ্বি, আছে'): Conversation
    {
        $conversation = Conversation::query()->create(['channel_id' => $this->channel->id, 'provider' => 'messenger', 'external_contact_id' => $psid, 'status' => 'open', 'ai_enabled' => true]);
        $conversation->messages()->create(['direction' => 'incoming', 'type' => 'text', 'body' => 'দাম কত?', 'sent_at' => now()]);
        if ($aiReply !== null) {
            $conversation->messages()->create(['direction' => 'outgoing', 'type' => 'text', 'body' => $aiReply, 'generated_by' => 'ai', 'sent_at' => now()]);
        }

        return $conversation;
    }

    public function test_report_counts_ai_share_handovers_reply_times_and_late_chats(): void
    {
        $modes = app(ConversationModeService::class);
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->chat('a');
        $this->chat('b');
        $fast = $this->chat('c', null);
        $slow = $this->chat('d', null);
        $modes->toHuman($fast, 'ai', null, null, false, 'complaint');
        $modes->toHuman($slow, 'ai', null, null, false, 'refund');
        $this->travel(4)->minutes();
        app(AttentionQueueService::class)->answered($fast, $user);
        $this->travel(16)->minutes();
        app(AttentionQueueService::class)->answered($slow, $user);
        $waiting = $this->chat('e', null);
        $modes->toHuman($waiting, 'ai', null, null, false, 'complaint');
        $this->travel(3)->minutes();

        $report = app(AiInboxReportService::class)->summary('7');

        $this->assertSame(5, $report['chats']);
        $this->assertSame(2, $report['ai_only']);
        $this->assertSame(40, $report['ai_only_percent']);
        $this->assertSame(2, $report['ai_replies']);
        $this->assertSame(3, $report['handoffs']);
        $this->assertSame(2, $report['answered']);
        $this->assertSame(12, $report['avg_response_minutes']);
        $this->assertSame(12, $report['median_response_minutes']);
        $this->assertSame(1, $report['late']);
        $this->assertSame(33, $report['late_percent']);
        $this->assertSame(1, $report['waiting_now']);
        $this->assertSame(3, $report['longest_wait_minutes']);
        $this->assertSame([['reason' => 'complaint', 'total' => 2], ['reason' => 'refund', 'total' => 1]], $report['reasons']->all());
        $this->assertSame(5, $report['channels']->sole()['chats']);
        $this->assertSame(3, $report['channels']->sole()['handoffs']);
    }

    public function test_period_and_company_limit_the_numbers(): void
    {
        $old = $this->chat('old');
        $old->messages()->update(['sent_at' => now()->subDays(10)]);
        $this->chat('new');

        $other = Company::query()->create(['name' => 'Other Report', 'slug' => 'other-report', 'invoice_prefix' => 'OR', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        $otherChannel = ConversationChannel::query()->create(['provider' => 'messenger', 'external_id' => 'other-report-page', 'display_name' => 'Other', 'access_token' => 'test', 'is_active' => true]);
        $otherChat = Conversation::query()->create(['channel_id' => $otherChannel->id, 'provider' => 'messenger', 'external_contact_id' => 'x', 'status' => 'open']);
        $otherChat->messages()->create(['direction' => 'incoming', 'type' => 'text', 'body' => 'hi', 'sent_at' => now()]);
        app(CompanyContext::class)->set($this->company);

        $service = app(AiInboxReportService::class);
        $this->assertSame(1, $service->summary('7')['chats']);
        $this->assertSame(2, $service->summary('30')['chats']);
        $this->assertNull($service->summary('7')['late_percent']);
    }

    public function test_report_page_renders_and_switches_period(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        $chat = $this->chat('p', null);
        app(ConversationModeService::class)->toHuman($chat, 'ai', null, null, false, 'discount_requested');

        Livewire::test(AiInboxReport::class)
            ->assertOk()
            ->assertSee('Asked for a discount')
            ->assertSee('Report Page')
            ->call('setPeriod', '30')
            ->assertSet('period', '30')
            ->call('setPeriod', 'bogus')
            ->assertSet('period', '7');
    }
}
