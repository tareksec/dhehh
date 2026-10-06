<?php

namespace Tests\Feature;

use App\Filament\Pages\WhatsAppWebLogin;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\Crm\ConversationMessengerService;
use App\Services\WhatsAppWeb\WhatsAppWebSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class WhatsAppWebArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected const SECRET = 'bridge-webhook-secret-0123456789abcdef';

    protected Company $company;

    protected ConversationChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = Str::random(6);
        $this->company = Company::query()->create([
            'name' => 'Web Co '.$suffix, 'slug' => 'web-co-'.Str::lower($suffix), 'invoice_prefix' => 'W'.Str::upper(substr($suffix, 0, 2)),
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);

        $this->channel = ConversationChannel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'provider' => 'whatsapp',
            'connection_type' => 'web',
            'web_status' => 'connected',
            'external_id' => 'web-test-session',
            'display_name' => 'Sales WhatsApp',
            'app_secret' => self::SECRET,
            'auto_create_leads' => true,
            'is_active' => true,
        ]);
    }

    protected function postEvent(string $type, array $data, ?string $secret = self::SECRET)
    {
        $body = json_encode(['type' => $type, 'session_id' => 'web-test-session', 'data' => $data]);

        return $this->call('POST', "/webhooks/whatsapp-web/{$this->channel->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BRIDGE_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, (string) $secret),
        ], $body);
    }

    protected function postMedia(string $messageId, string $bytes, ?string $secret = self::SECRET)
    {
        return $this->call('POST', "/webhooks/whatsapp-web/{$this->channel->id}/media", [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_X_MESSAGE_ID' => $messageId,
            'HTTP_X_MEDIA_MIME' => 'image/jpeg',
            'HTTP_X_FILE_NAME' => '',
            'HTTP_X_BRIDGE_SIGNATURE' => 'sha256='.hash_hmac('sha256', $messageId."\n".$bytes, (string) $secret),
        ], $bytes);
    }

    protected function message(string $id, array $overrides = []): array
    {
        return array_merge([
            'kind' => 'message',
            'id' => $id,
            'chat_jid' => '8801812345678@s.whatsapp.net',
            'chat_phone' => '8801812345678',
            'from_me' => false,
            'push_name' => 'Rahim',
            'timestamp' => 1700000000,
            'type' => 'text',
            'body' => 'দাম কত?',
            'media' => null,
            'quoted_id' => null,
        ], $overrides);
    }

    public function test_unsigned_or_wrongly_signed_events_are_rejected(): void
    {
        $this->postEvent('messages', ['history' => true, 'messages' => [$this->message('A1')]], 'wrong-secret')->assertStatus(401);

        $this->assertSame(0, ConversationMessage::query()->count());
    }

    public function test_cloud_api_channels_cannot_receive_bridge_events(): void
    {
        $this->channel->forceFill(['connection_type' => 'cloud_api'])->saveQuietly();

        $this->postEvent('messages', ['history' => true, 'messages' => [$this->message('A1')]])->assertNotFound();
    }

    public function test_history_is_archived_idempotently_without_unread_counts_or_new_leads(): void
    {
        $payload = ['history' => true, 'messages' => [
            $this->message('IN1'),
            $this->message('OUT1', ['from_me' => true, 'push_name' => null, 'body' => '১২০০ টাকা', 'timestamp' => 1700000100]),
        ]];

        $this->postEvent('messages', $payload)->assertOk();
        $this->postEvent('messages', $payload)->assertOk(); // bridge outbox retry

        $conversation = Conversation::withoutGlobalScopes()->sole();
        $this->assertSame($this->channel->id, $conversation->channel_id);
        $this->assertSame('8801812345678', $conversation->external_contact_id);
        $this->assertSame('Rahim', $conversation->contact_name);
        $this->assertSame(0, $conversation->unread_count);
        $this->assertFalse($conversation->ai_enabled);
        $this->assertNull($conversation->lead_id);
        $this->assertSame(0, Lead::withoutGlobalScopes()->count());

        $messages = ConversationMessage::query()->orderBy('sent_at')->get();
        $this->assertCount(2, $messages);
        $this->assertSame(['incoming', 'outgoing'], $messages->pluck('direction')->all());
        $this->assertSame('phone_app', $messages[1]->generated_by);
        $this->assertSame("waweb:{$this->channel->id}:IN1", $messages[0]->external_message_id);

        $contact = WhatsAppContact::withoutGlobalScopes()->sole();
        $this->assertSame('8801812345678', $contact->phone);
        $this->assertSame('Rahim', $contact->push_name);
        $this->assertSame($this->company->id, $contact->company_id);
    }

    public function test_live_incoming_message_bumps_unread_and_links_an_existing_customer(): void
    {
        app(CompanyContext::class)->set($this->company);
        $customer = Customer::query()->create(['name' => 'Karim', 'phone' => '01812345678', 'opening_balance' => 0, 'is_active' => true]);
        app(CompanyContext::class)->clear();

        $this->postEvent('messages', ['history' => false, 'messages' => [$this->message('LIVE1')]])->assertOk();

        $conversation = Conversation::withoutGlobalScopes()->sole();
        $this->assertSame(1, $conversation->unread_count);
        $this->assertSame($customer->id, $conversation->customer_id);
        $this->assertSame($customer->id, WhatsAppContact::withoutGlobalScopes()->sole()->customer_id);
        $this->assertSame(0, Lead::withoutGlobalScopes()->count());
    }

    public function test_live_message_from_an_unknown_number_creates_a_lead(): void
    {
        $this->postEvent('messages', ['history' => false, 'messages' => [$this->message('LIVE2')]])->assertOk();

        $lead = Lead::withoutGlobalScopes()->sole();
        $this->assertSame('Rahim', $lead->name);
        $this->assertSame('whatsapp', $lead->source);
        $this->assertSame($lead->id, Conversation::withoutGlobalScopes()->sole()->lead_id);
    }

    public function test_contacts_sync_saves_the_address_book_and_names_existing_chats(): void
    {
        $this->postEvent('messages', ['history' => true, 'messages' => [$this->message('C1', ['push_name' => null])]])->assertOk();

        $this->postEvent('contacts', ['contacts' => [
            ['jid' => '8801812345678@s.whatsapp.net', 'lid' => '55@lid', 'phone' => '8801812345678', 'name' => 'Rahim Uddin', 'push_name' => 'Rahim', 'verified_name' => null],
            ['jid' => '8801999999999@s.whatsapp.net', 'lid' => null, 'phone' => '8801999999999', 'name' => 'Address Book Only', 'push_name' => null, 'verified_name' => null],
        ]])->assertOk();

        $this->assertSame(2, WhatsAppContact::withoutGlobalScopes()->count());
        $this->assertSame('Rahim Uddin', Conversation::withoutGlobalScopes()->sole()->contact_name);
        $this->assertSame('55@lid', WhatsAppContact::withoutGlobalScopes()->where('phone', '8801812345678')->value('lid'));
    }

    public function test_delete_for_everyone_keeps_the_original_message(): void
    {
        $this->postEvent('messages', ['history' => false, 'messages' => [$this->message('R1', ['body' => 'Original text'])]])->assertOk();
        $this->postEvent('messages', ['history' => false, 'messages' => [
            ['kind' => 'revoke', 'id' => 'R1', 'chat_jid' => '8801812345678@s.whatsapp.net', 'chat_phone' => '8801812345678', 'timestamp' => 1700000500],
        ]])->assertOk();

        $message = ConversationMessage::query()->sole();
        $this->assertSame('Original text', $message->body);
        $this->assertNotNull($message->revoked_at);
    }

    public function test_media_waits_for_its_message_then_is_stored_privately(): void
    {
        Storage::fake(app(CompanyStorageService::class)->privateDiskName());

        $this->postMedia('IMG1', 'jpeg-bytes')->assertStatus(409);

        $this->postEvent('messages', ['history' => true, 'messages' => [
            $this->message('IMG1', ['type' => 'image', 'body' => 'photo', 'media' => ['mime' => 'image/jpeg', 'file_name' => null, 'size' => 10]]),
        ]])->assertOk();

        $this->postMedia('IMG1', 'jpeg-bytes', 'wrong-secret')->assertStatus(401);
        $this->postMedia('IMG1', 'jpeg-bytes')->assertOk();

        $message = ConversationMessage::query()->sole();
        $this->assertSame('image', $message->type);
        $this->assertStringStartsWith($this->company->storageRoot().'/private/', $message->media_path);
        $this->assertSame('jpeg-bytes', app(CompanyStorageService::class)->readPrivate($message->media_path, $this->company));
    }

    public function test_ban_and_channel_deletion_never_delete_archived_customer_data(): void
    {
        $this->postEvent('messages', ['history' => true, 'messages' => [$this->message('K1'), $this->message('K2', ['from_me' => true])]])->assertOk();

        $this->postEvent('connection', ['status' => 'banned', 'phone' => '8801700000000'])->assertOk();

        $channel = $this->channel->fresh();
        $this->assertSame('banned', $channel->web_status);
        $this->assertNotNull($channel->web_disconnected_at);
        $this->assertNotNull($channel->last_error);

        $channel->delete();

        $this->assertSame(1, Conversation::withoutGlobalScopes()->count());
        $this->assertNull(Conversation::withoutGlobalScopes()->sole()->channel_id);
        $this->assertSame(2, ConversationMessage::query()->count());
        $this->assertSame(1, WhatsAppContact::withoutGlobalScopes()->count());
        $this->assertNull(WhatsAppContact::withoutGlobalScopes()->sole()->channel_id);
    }

    public function test_archive_conversations_cannot_send_from_the_erp(): void
    {
        $this->postEvent('messages', ['history' => true, 'messages' => [$this->message('S1')]])->assertOk();
        app(CompanyContext::class)->set($this->company);
        $conversation = Conversation::query()->sole();

        try {
            app(ConversationMessengerService::class)->send($conversation, 'Hello');
            $this->fail('Sending through a WhatsApp Web archive channel must be refused.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(1, ConversationMessage::query()->count());
    }

    public function test_only_super_admin_can_open_the_login_page(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'is_active' => true]);
        $this->actingAs($manager)->withSession(['current_company_id' => $this->company->id])
            ->get('/admin/crm/whats-app-web-login')
            ->assertForbidden();

        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->withSession(['current_company_id' => $this->company->id])
            ->get('/admin/crm/whats-app-web-login')
            ->assertOk();
    }

    public function test_linking_a_device_creates_a_web_channel_and_shows_the_bridge_qr(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->withSession(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->set($this->company);

        Http::fake([
            'bridge.test/sessions/*' => Http::response(['id' => 'x', 'status' => 'qr', 'qr' => 'data:image/png;base64,AAAA', 'phone' => null, 'history_progress' => null], 200),
        ]);

        Livewire::test(WhatsAppWebLogin::class)
            ->set('settings.bridge_url', 'https://bridge.test')
            ->set('settings.api_key', 'bridge-api-key-0123456789abcdef')
            ->call('saveSettings')
            ->assertSet('settings.api_key', '')
            ->assertSet('settings.ready', true)
            ->set('deviceName', 'Support WhatsApp')
            ->call('linkDevice')
            ->assertHasNoErrors()
            ->assertSee('data:image/png;base64,AAAA', false);

        $stored = (array) $this->company->fresh()->settings['whatsapp_web'];
        $this->assertNotSame('bridge-api-key-0123456789abcdef', $stored['api_key']);
        $this->assertSame('bridge-api-key-0123456789abcdef', app(WhatsAppWebSettingsService::class)->all($this->company->fresh())['api_key']);

        $channel = ConversationChannel::withoutGlobalScopes()->where('display_name', 'Support WhatsApp')->sole();
        $this->assertTrue($channel->isWeb());
        $this->assertSame('qr', $channel->web_status);
        $this->assertSame($this->company->id, $channel->company_id);

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/sessions/'.$channel->external_id)
            && $request->hasHeader('Authorization', 'Bearer bridge-api-key-0123456789abcdef')
            && $request['webhook_url'] === route('webhooks.whatsapp-web.handle', ['channel' => $channel->id])
            && hash_equals((string) $channel->app_secret, (string) $request['webhook_secret']));
    }
}
