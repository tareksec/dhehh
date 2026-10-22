<?php

namespace Tests\Feature;

use App\Models\AttentionItem;
use App\Models\ChatOrderLink;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\CrmSalesFollowUp;
use App\Services\CompanyContext;
use App\Services\Crm\AiSettingsService;
use App\Services\Crm\SalesFollowUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** AI inbox control plane, owner answer O4: 1, 6, 24, 48 hours, then weekly; quiet hours; Messenger's 24-hour window. */
class SalesFollowUpScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00', 'Asia/Dhaka'));
        $this->company = Company::query()->create(['name' => 'Remind Co', 'slug' => 'remind-co', 'invoice_prefix' => 'RM', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($this->company);
        $this->settings();
    }

    private function settings(array $extra = []): void
    {
        app(AiSettingsService::class)->save($this->company, 'messaging', [
            ...AiSettingsService::DEFAULTS, 'enabled' => true, 'api_key' => 'test',
            'sales_follow_ups_enabled' => true, 'follow_up_template' => 'sales_followup', ...$extra,
        ]);
        $this->company->refresh();
    }

    private function chat(string $provider = 'whatsapp'): Conversation
    {
        $channel = ConversationChannel::query()->create(['provider' => $provider, 'external_id' => $provider.'-ch', 'display_name' => 'Channel', 'access_token' => 'token', 'is_active' => true]);
        $conversation = Conversation::query()->create([
            'channel_id' => $channel->id, 'provider' => $provider, 'external_contact_id' => $provider === 'whatsapp' ? '8801700000000' : 'psid-1',
            'contact_phone' => $provider === 'whatsapp' ? '01700000000' : null, 'contact_name' => 'Rina', 'status' => 'open', 'ai_enabled' => true,
        ]);
        $conversation->messages()->create(['direction' => 'incoming', 'type' => 'text', 'body' => 'নিতে চাই', 'sent_at' => now()]);

        return $conversation->fresh();
    }

    private function link(Conversation $conversation): ChatOrderLink
    {
        return ChatOrderLink::query()->create(['conversation_id' => $conversation->id, 'prefill' => ['items' => []]]);
    }

    private function process(CrmSalesFollowUp $followUp): CrmSalesFollowUp
    {
        app(SalesFollowUpService::class)->process($followUp);

        return $followUp->fresh();
    }

    public function test_the_default_schedule_is_1_6_24_48_hours_then_four_weekly_reminders(): void
    {
        $this->assertSame([1, 6, 24, 48, 216, 384, 552, 720], app(SalesFollowUpService::class)->offsets(app(AiSettingsService::class)->all($this->company, 'messaging')));

        $this->settings(['follow_up_schedule_hours' => '2, 8', 'follow_up_weekly_count' => 0]);
        $this->assertSame([2, 8], app(SalesFollowUpService::class)->offsets(app(AiSettingsService::class)->all($this->company, 'messaging')));
    }

    public function test_the_hours_are_cleaned_and_a_typo_keeps_the_default(): void
    {
        $this->assertSame([1, 6, 24], AiSettingsService::followUpHours('24, 6 ,1, 1, abc, 0, 900'));
        $this->assertSame([1, 6, 24, 48], AiSettingsService::followUpHours('nothing useful'));

        $this->settings(['follow_up_schedule_hours' => 'oops']);
        $this->assertSame('1, 6, 24, 48', app(AiSettingsService::class)->all($this->company, 'messaging')['follow_up_schedule_hours']);
    }

    public function test_whatsapp_reminders_go_out_at_each_step_and_the_last_one_ends_the_schedule(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.r1']]])
            ->push(['messages' => [['id' => 'wamid.r2']]])
            ->push(['messages' => [['id' => 'wamid.r3']]])]);
        $this->settings(['follow_up_schedule_hours' => '1, 2', 'follow_up_weekly_count' => 1]);
        $conversation = $this->chat();
        $followUp = app(SalesFollowUpService::class)->schedule($conversation, $this->link($conversation));
        $this->assertEqualsWithDelta(now()->addHour()->timestamp, $followUp->due_at->timestamp, 5);

        $this->assertSame('pending', $this->process($followUp)->status); // not due yet
        Http::assertNothingSent();

        $this->travelTo(now()->addHour()->addSecond());
        $followUp = $this->process($followUp);
        $this->assertSame(['pending', 1], [$followUp->status, $followUp->step]);
        $this->assertEqualsWithDelta(Carbon::parse('2026-10-19 12:00:00', 'Asia/Dhaka')->addHours(2)->timestamp, $followUp->due_at->timestamp, 5);

        $this->travelTo(now()->addHour());
        $followUp = $this->process($followUp);
        $this->assertSame(['pending', 2], [$followUp->status, $followUp->step]);
        $this->assertEqualsWithDelta(Carbon::parse('2026-10-19 12:00:00', 'Asia/Dhaka')->addHours(170)->timestamp, $followUp->due_at->timestamp, 5);

        $this->travelTo(Carbon::parse('2026-10-26 14:00:05', 'Asia/Dhaka'));
        $followUp = $this->process($followUp);
        $this->assertSame(['sent', 3], [$followUp->status, $followUp->step]);
        Http::assertSentCount(3);
        $this->assertSame(3, $conversation->messages()->where('direction', 'outgoing')->where('generated_by', 'automation')->count());
    }

    public function test_a_reminder_due_days_ago_is_cancelled_not_sent(): void
    {
        // Owner decision 2026-10-21: when the scheduler starts after a long stop, old offers are not all chased at once.
        Http::fake();
        $this->settings(['follow_up_schedule_hours' => '1, 2', 'follow_up_weekly_count' => 1]);
        $conversation = $this->chat();
        $followUp = app(SalesFollowUpService::class)->schedule($conversation, $this->link($conversation));

        $this->travelTo($followUp->due_at->copy()->addDays(SalesFollowUpService::STALE_AFTER_DAYS)->addHour());
        $followUp = $this->process($followUp);

        $this->assertSame('cancelled', $followUp->status);
        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_in_the_quiet_hours(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.q']]])]);
        $this->travelTo(Carbon::parse('2026-10-19 21:30:00', 'Asia/Dhaka'));
        $conversation = $this->chat();
        $followUp = app(SalesFollowUpService::class)->schedule($conversation, $this->link($conversation));

        $this->travelTo(Carbon::parse('2026-10-19 22:31:00', 'Asia/Dhaka'));
        $followUp = $this->process($followUp);
        $this->assertSame('pending', $followUp->status);
        $this->assertSame('Quiet hours.', $followUp->reason);
        $this->assertEquals(Carbon::parse('2026-10-20 08:00:00', 'Asia/Dhaka')->timestamp, $followUp->due_at->timestamp);
        Http::assertNothingSent();

        $this->travelTo(Carbon::parse('2026-10-20 08:00:30', 'Asia/Dhaka'));
        $this->assertSame(1, $this->process($followUp)->step);
        Http::assertSentCount(1);
    }

    public function test_a_new_message_from_the_customer_restarts_the_count(): void
    {
        Http::fake();
        $conversation = $this->chat();
        $followUp = app(SalesFollowUpService::class)->schedule($conversation, $this->link($conversation));
        $followUp->update(['step' => 3, 'due_at' => now()->addHour()]);

        $this->travelTo(now()->addHours(2));
        $conversation->messages()->create(['direction' => 'incoming', 'type' => 'text', 'body' => 'এখনো ভাবছি', 'sent_at' => now()]);
        $followUp->update(['due_at' => now()->subMinute()]);
        $followUp = $this->process($followUp);

        $this->assertSame(['pending', 0], [$followUp->status, $followUp->step]);
        $this->assertEqualsWithDelta(now()->addHour()->timestamp, $followUp->due_at->timestamp, 5);
        Http::assertNothingSent();
    }

    public function test_a_person_writing_to_the_customer_ends_the_reminders(): void
    {
        Http::fake();
        $conversation = $this->chat();
        $followUp = app(SalesFollowUpService::class)->schedule($conversation, $this->link($conversation));
        $this->travelTo(now()->addMinutes(20));
        $conversation->messages()->create(['direction' => 'outgoing', 'type' => 'text', 'body' => 'ফোন করেছিলাম', 'generated_by' => 'human', 'delivery_status' => 'sent', 'sent_at' => now()]);

        $this->travelTo(now()->addHour());
        $followUp = $this->process($followUp);

        $this->assertSame('cancelled', $followUp->status);
        $this->assertStringContainsString('person already followed up', $followUp->reason);
        Http::assertNothingSent();
    }

    public function test_a_newer_offer_replaces_the_older_schedule(): void
    {
        $conversation = $this->chat();
        $first = app(SalesFollowUpService::class)->schedule($conversation, $this->link($conversation));
        $second = app(SalesFollowUpService::class)->schedule($conversation, $this->link($conversation));

        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame(1, CrmSalesFollowUp::query()->where('status', 'pending')->count());
    }

    public function test_messenger_gets_the_reminder_text_inside_the_24_hour_window(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm.1', 'recipient_id' => 'psid-1'])]);
        $this->settings(['follow_up_message' => 'অর্ডারটি শেষ করতে: {link}']);
        $conversation = $this->chat('messenger');
        $link = $this->link($conversation);
        $followUp = app(SalesFollowUpService::class)->schedule($conversation, $link);

        $this->travelTo(now()->addHour()->addSecond());
        $followUp = $this->process($followUp);

        $this->assertSame(1, $followUp->step);
        $message = $conversation->messages()->where('direction', 'outgoing')->sole();
        $this->assertSame('অর্ডারটি শেষ করতে: '.$link->publicUrl(), $message->body);
        $this->assertSame('automation', $message->generated_by);
        $this->assertSame(0, AttentionItem::query()->count());
    }

    public function test_messenger_steps_after_24_hours_go_to_the_team_not_the_customer(): void
    {
        Http::fake();
        $this->settings(['follow_up_message' => 'অর্ডারটি শেষ করতে: {link}', 'follow_up_schedule_hours' => '24, 48', 'follow_up_weekly_count' => 0]);
        $conversation = $this->chat('messenger');
        $followUp = app(SalesFollowUpService::class)->schedule($conversation, $this->link($conversation));
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $followUp->due_at->timestamp, 5);

        $this->travelTo(now()->addHours(24)->addSecond());
        $followUp = $this->process($followUp);
        $this->assertSame(1, $followUp->step);
        $item = AttentionItem::query()->sole();
        $this->assertSame(['handover', 'follow_up_due', $conversation->id], [$item->kind, $item->reason, $item->conversation_id]);
        $this->assertStringContainsString('Reminder 1 of 2', $item->note);
        $this->assertSame('ai', $conversation->fresh()->automation_mode);

        // The next step finds the item still open: it is refreshed, not duplicated.
        $this->travelTo(now()->addDay());
        $followUp = $this->process($followUp);
        $this->assertSame(['completed', 2], [$followUp->status, $followUp->step]);
        $this->assertSame(1, AttentionItem::query()->count());
        $this->assertStringContainsString('Reminder 2 of 2', AttentionItem::query()->sole()->note);
        Http::assertNothingSent();
    }

    public function test_messenger_with_no_reminder_text_goes_to_the_team(): void
    {
        Http::fake();
        $conversation = $this->chat('messenger');
        $followUp = app(SalesFollowUpService::class)->schedule($conversation, $this->link($conversation));

        $this->travelTo(now()->addHour()->addSecond());
        $this->process($followUp);

        $this->assertStringContainsString('No reminder text is saved', AttentionItem::query()->sole()->note);
        Http::assertNothingSent();
    }

    public function test_another_companys_follow_ups_are_not_touched(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.o']]])]);
        $conversation = $this->chat();
        $mine = app(SalesFollowUpService::class)->schedule($conversation, $this->link($conversation));

        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        $this->assertSame(0, CrmSalesFollowUp::query()->count());
        app(CompanyContext::class)->set($this->company);
        $this->assertSame(1, CrmSalesFollowUp::query()->whereKey($mine->getKey())->count());
    }
}
