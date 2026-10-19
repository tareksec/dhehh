<?php

namespace Tests\Feature;

use App\Filament\Resources\Broadcasts\BroadcastResource;
use App\Filament\Resources\Broadcasts\Pages\CreateBroadcast;
use App\Filament\Resources\Broadcasts\Pages\ListBroadcasts;
use App\Jobs\SendBroadcastJob;
use App\Models\Broadcast;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Crm\BroadcastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** AI inbox control plane, owner answer O5: Messenger broadcast, 23-hour audience, test to your own chat first. */
class MessengerBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private ConversationChannel $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::query()->create(['name' => 'Cast Co', 'slug' => 'cast-co', 'invoice_prefix' => 'CC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($this->company);
        $this->page = $this->page('cast-page');
    }

    private function page(string $id, string $provider = 'messenger'): ConversationChannel
    {
        return ConversationChannel::query()->create(['provider' => $provider, 'external_id' => $id, 'display_name' => $id, 'access_token' => 'token', 'is_active' => true]);
    }

    private function chat(string $name, ?ConversationChannel $page = null, int $hoursSinceLastMessage = 1, string $provider = 'messenger'): Conversation
    {
        $page ??= $this->page;
        $conversation = Conversation::query()->create(['channel_id' => $page->id, 'provider' => $provider, 'external_contact_id' => 'psid-'.uniqid(), 'contact_name' => $name, 'status' => 'open', 'ai_enabled' => true]);
        $conversation->messages()->create(['direction' => 'incoming', 'type' => 'text', 'body' => 'hi', 'sent_at' => now()->subHours($hoursSinceLastMessage)]);

        return $conversation;
    }

    private function broadcast(array $extra = []): Broadcast
    {
        return Broadcast::query()->create([
            'name' => 'Eid offer', 'channel' => 'messenger', 'messenger_channel_id' => $this->page->id,
            'messenger_text' => 'Hi {{name}}, Eid offer is on!', 'status' => 'draft', ...$extra,
        ]);
    }

    private function fakeMeta(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['message_id' => 'm.1', 'recipient_id' => 'x'])->push(['message_id' => 'm.2', 'recipient_id' => 'x'])
            ->push(['message_id' => 'm.3', 'recipient_id' => 'x'])->push(['message_id' => 'm.4', 'recipient_id' => 'x'])]);
    }

    public function test_the_audience_is_this_pages_chats_from_the_last_23_hours(): void
    {
        $recent = $this->chat('Recent', hoursSinceLastMessage: 22);
        $this->chat('Too old', hoursSinceLastMessage: 24);
        $this->chat('Other page', $this->page('other-page'));
        $this->chat('WhatsApp chat', $this->page('wa-phone', 'whatsapp'), provider: 'whatsapp');
        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        $this->chat('Other company', $this->page('their-page'));
        app(CompanyContext::class)->set($this->company);

        $broadcast = $this->broadcast();
        $count = app(BroadcastService::class)->buildRecipients($broadcast);

        $this->assertSame(1, $count);
        $recipient = $broadcast->recipients()->sole();
        $this->assertSame(['conversation', $recent->id, 'Recent', null], [$recipient->recipient_type, $recipient->recipient_id, $recipient->name, $recipient->phone]);
        $this->assertSame('conversations', $broadcast->fresh()->audience_type);
    }

    public function test_it_cannot_be_queued_until_a_test_of_the_current_text_succeeded(): void
    {
        Bus::fake([SendBroadcastJob::class]);
        $this->fakeMeta();
        $mine = $this->chat('My own chat');
        $broadcast = $this->broadcast();

        try {
            app(BroadcastService::class)->queue($broadcast);
            $this->fail('A broadcast with no test was queued.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('test', collect($exception->errors())->flatten()->first());
        }
        $this->assertSame('draft', $broadcast->fresh()->status);
        Bus::assertNotDispatched(SendBroadcastJob::class);

        app(BroadcastService::class)->sendTest($broadcast, $mine);
        $this->assertFalse($broadcast->fresh()->needsTest());
        $this->assertSame('Hi My own chat, Eid offer is on!', $mine->messages()->where('direction', 'outgoing')->sole()->body);

        // Editing the text makes the test stale.
        $broadcast->update(['messenger_text' => 'A different offer']);
        $this->assertTrue($broadcast->fresh()->needsTest());
        $this->expectException(ValidationException::class);
        app(BroadcastService::class)->queue($broadcast->fresh());
    }

    public function test_a_tested_broadcast_is_queued(): void
    {
        Bus::fake([SendBroadcastJob::class]);
        $this->fakeMeta();
        $mine = $this->chat('My own chat');
        $broadcast = $this->broadcast();
        app(BroadcastService::class)->sendTest($broadcast, $mine);

        app(BroadcastService::class)->queue($broadcast->fresh());

        $this->assertSame('queued', $broadcast->fresh()->status);
        Bus::assertDispatched(SendBroadcastJob::class);
    }

    public function test_a_test_to_a_chat_outside_the_window_or_on_another_page_is_refused(): void
    {
        Http::fake();
        $broadcast = $this->broadcast();

        foreach ([$this->chat('Old chat', hoursSinceLastMessage: 30), $this->chat('Other page chat', $this->page('another-page'))] as $conversation) {
            try {
                app(BroadcastService::class)->sendTest($broadcast, $conversation);
                $this->fail('The test went to a chat it must not reach.');
            } catch (ValidationException) {
                $this->assertTrue($broadcast->fresh()->needsTest());
            }
        }
        Http::assertNothingSent();
    }

    public function test_a_failed_test_does_not_unlock_the_broadcast(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'nope', 'code' => 10]], 400)]);
        $mine = $this->chat('My own chat');
        $broadcast = $this->broadcast();

        $this->expectException(ValidationException::class);
        try {
            app(BroadcastService::class)->sendTest($broadcast, $mine);
        } finally {
            $this->assertTrue($broadcast->fresh()->needsTest());
        }
    }

    public function test_sending_rechecks_the_window_for_every_recipient(): void
    {
        $this->fakeMeta();
        $fresh = $this->chat('Fresh', hoursSinceLastMessage: 1);
        $closing = $this->chat('Closing soon', hoursSinceLastMessage: 22);
        $broadcast = $this->broadcast(['tested_hash' => null]);
        $broadcast->forceFill(['tested_hash' => $broadcast->contentHash()])->save();
        app(BroadcastService::class)->buildRecipients($broadcast);
        $this->assertSame(2, $broadcast->fresh()->recipients_count);
        $broadcast->forceFill(['status' => 'queued'])->save();

        // Two hours pass before the job runs: "Closing soon" is out of the window now.
        $this->travel(2)->hours();
        app(BroadcastService::class)->send($broadcast->fresh());

        $broadcast = $broadcast->fresh();
        $this->assertSame([1, 1, 'completed'], [$broadcast->sent_count, $broadcast->failed_count, $broadcast->status]);
        $this->assertSame(1, $fresh->messages()->where('direction', 'outgoing')->count());
        $this->assertSame(0, $closing->messages()->where('direction', 'outgoing')->count());
        $this->assertStringContainsString('has not written in the last 23 hours', $broadcast->recipients()->where('status', 'failed')->sole()->error);
        $sent = $fresh->messages()->where('direction', 'outgoing')->sole();
        $this->assertSame(['automation', $broadcast->id], [$sent->generated_by, $sent->ai_meta['broadcast_id']]);
    }

    public function test_the_form_saves_a_messenger_broadcast_without_an_audience(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        Livewire::test(CreateBroadcast::class)
            ->fillForm(['name' => 'From the form', 'channel' => 'messenger', 'messenger_channel_id' => $this->page->id, 'messenger_text' => 'Hi {{name}}'])
            ->call('create')
            ->assertHasNoFormErrors();

        $broadcast = Broadcast::query()->where('name', 'From the form')->sole();
        $this->assertSame(['conversations', $this->page->id, 'draft'], [$broadcast->audience_type, $broadcast->messenger_channel_id, $broadcast->status]);
    }

    public function test_the_list_sends_a_test_and_then_the_broadcast(): void
    {
        Bus::fake([SendBroadcastJob::class]);
        $this->fakeMeta();
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        $mine = $this->chat('My own chat');
        $broadcast = $this->broadcast();
        app(BroadcastService::class)->buildRecipients($broadcast);

        $list = Livewire::test(ListBroadcasts::class);
        $list->assertTableActionDisabled('send', $broadcast);

        $list->callTableAction('sendTest', $broadcast, ['conversation_id' => $mine->id]);
        $this->assertFalse($broadcast->fresh()->needsTest());

        $list->assertTableActionEnabled('send', $broadcast)->callTableAction('send', $broadcast);
        $this->assertSame('queued', $broadcast->fresh()->status);
    }

    public function test_a_person_without_crm_manage_cannot_open_broadcasts(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'inventory_staff', 'is_active' => true]));

        $this->assertFalse(BroadcastResource::canAccess());
    }
}
