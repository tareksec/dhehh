<?php

namespace Tests\Feature;

use App\Filament\Pages\Inbox;
use App\Filament\Pages\WhatsAppWebLogin;
use App\Jobs\ImportWhatsAppWebMessagesJob;
use App\Jobs\RefreshWhatsAppWebNamesJob;
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
use App\Services\WhatsAppWeb\WhatsAppWebArchiveService;
use App\Services\WhatsAppWeb\WhatsAppWebSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    protected function postMedia(string $messageId, string $bytes, ?string $secret = self::SECRET, string $mime = 'image/jpeg')
    {
        return $this->call('POST', "/webhooks/whatsapp-web/{$this->channel->id}/media", [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_X_MESSAGE_ID' => $messageId,
            'HTTP_X_MEDIA_MIME' => $mime,
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

    public function test_stored_media_is_served_to_staff_through_the_inbox_media_route(): void
    {
        Storage::fake(app(CompanyStorageService::class)->privateDiskName());

        $this->postEvent('messages', ['history' => true, 'messages' => [
            $this->message('IMG2', ['type' => 'image', 'body' => 'photo', 'media' => ['mime' => 'image/jpeg', 'file_name' => null, 'size' => 10]]),
        ]])->assertOk();
        $this->postMedia('IMG2', 'jpeg-bytes')->assertOk();

        $message = ConversationMessage::query()->sole();
        $this->assertNotNull($message->mediaImageUrl());

        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->withSession(['current_company_id' => $this->company->id]);

        $response = $this->get(route('conversation-messages.media', ['message' => $message->getKey()]));

        $response->assertOk();
        $this->assertSame('jpeg-bytes', $response->streamedContent());
        $this->assertStringStartsWith('image/jpeg', (string) $response->headers->get('Content-Type'));
    }

    protected function storedMedia(string $id, string $type, string $declaredMime, string $bytes): ConversationMessage
    {
        Storage::fake(app(CompanyStorageService::class)->privateDiskName());

        $this->postEvent('messages', ['history' => false, 'messages' => [
            $this->message($id, ['type' => $type, 'body' => null, 'media' => ['mime' => $declaredMime, 'file_name' => null, 'size' => 10]]),
        ]])->assertOk();
        $this->postMedia($id, $bytes, mime: $declaredMime)->assertOk();

        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->withSession(['current_company_id' => $this->company->id]);

        return ConversationMessage::query()->where('external_message_id', 'like', '%'.$id)->sole();
    }

    public function test_a_file_lost_from_the_server_is_saved_again_when_the_bridge_sends_it_again(): void
    {
        $image = $this->storedMedia('LOST1', 'image', 'image/jpeg', 'first-copy');
        $disk = Storage::disk(app(CompanyStorageService::class)->privateDiskName());
        $disk->delete($image->media_path);
        $this->assertFalse($disk->exists($image->media_path));

        $this->postMedia('LOST1', 'second-copy')->assertOk();

        $this->assertSame('second-copy', $disk->get($image->fresh()->media_path));
        $this->get(route('conversation-messages.media', ['message' => $image->getKey()]))->assertOk();
    }

    public function test_a_file_that_is_still_on_the_server_is_not_replaced_by_a_repeat_upload(): void
    {
        $image = $this->storedMedia('KEEP1', 'image', 'image/jpeg', 'first-copy');

        $this->postMedia('KEEP1', 'second-copy')->assertOk();

        $this->assertSame('first-copy', Storage::disk(app(CompanyStorageService::class)->privateDiskName())->get($image->fresh()->media_path));
    }

    public function test_a_real_picture_and_voice_note_are_served_with_playable_types(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $image = $this->storedMedia('REALPNG', 'image', 'image/jpeg', $png);

        $response = $this->get(route('conversation-messages.media', ['message' => $image->getKey()]));
        $response->assertOk();
        $this->assertSame($png, $response->streamedContent());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame((string) strlen($png), $response->headers->get('Content-Length'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
    }

    public function test_a_voice_note_keeps_the_audio_type_whatsapp_declared(): void
    {
        $ogg = "OggS\x00\x02".str_repeat("\x00", 8)."\x01\x02\x03\x04".str_repeat("\x00", 8)."\x01\x13OpusHead\x01\x01\x38\x01\x80\xbb".str_repeat("\x00", 40);
        $audio = $this->storedMedia('REALOGG', 'audio', 'audio/ogg; codecs=opus', $ogg);

        $response = $this->get(route('conversation-messages.media', ['message' => $audio->getKey()]));

        $response->assertOk();
        $this->assertStringStartsWith('audio/', (string) $response->headers->get('Content-Type'));
    }

    public function test_media_supports_byte_ranges_so_audio_and_video_can_seek(): void
    {
        $audio = $this->storedMedia('RANGE1', 'audio', 'audio/ogg; codecs=opus', '0123456789');
        $url = route('conversation-messages.media', ['message' => $audio->getKey()]);

        $partial = $this->get($url, ['Range' => 'bytes=2-5']);
        $partial->assertStatus(206);
        $this->assertSame('2345', $partial->streamedContent());
        $this->assertSame('bytes 2-5/10', $partial->headers->get('Content-Range'));
        $this->assertSame('4', $partial->headers->get('Content-Length'));

        $tail = $this->get($url, ['Range' => 'bytes=-3']);
        $tail->assertStatus(206);
        $this->assertSame('789', $tail->streamedContent());

        $open = $this->get($url, ['Range' => 'bytes=8-']);
        $open->assertStatus(206);
        $this->assertSame('89', $open->streamedContent());

        $probe = $this->get($url, ['Range' => 'bytes=0-0']);
        $probe->assertStatus(206);
        $this->assertSame('0', $probe->streamedContent());

        $this->get($url, ['Range' => 'bytes=50-60'])->assertStatus(416);
        $this->assertSame('0123456789', $this->get($url, ['Range' => 'nonsense'])->streamedContent());
    }

    public function test_a_missing_file_tells_staff_why_instead_of_a_blank_404(): void
    {
        $image = $this->storedMedia('GONE1', 'image', 'image/jpeg', 'jpeg-bytes');
        Storage::disk(app(CompanyStorageService::class)->privateDiskName())->delete($image->media_path);

        $response = $this->getJson(route('conversation-messages.media', ['message' => $image->getKey()]));

        $response->assertNotFound();
        $this->assertStringContainsString('missing', (string) $response->json('message'));
    }

    public function test_the_inbox_reports_why_a_stored_file_cannot_be_shown(): void
    {
        $image = $this->storedMedia('SHOW1', 'image', 'image/jpeg', 'jpeg-bytes');
        app(CompanyContext::class)->set($this->company);

        $html = Livewire::test(Inbox::class)
            ->call('selectConversation', Conversation::withoutGlobalScopes()->sole()->id)
            ->html();

        $this->assertStringContainsString('x-on:error.capture', $html);
        $this->assertStringContainsString('The browser could not show this file', $html);
    }

    protected function lidContact(string $lid, string $pushName): array
    {
        return ['jid' => $lid, 'lid' => $lid, 'phone' => null, 'name' => null, 'push_name' => $pushName, 'verified_name' => null];
    }

    public function test_a_name_that_arrives_under_a_lid_reaches_the_chat_when_its_message_arrives_later(): void
    {
        $this->postEvent('contacts', ['contacts' => [$this->lidContact('111@lid', 'Karim Uddin')]])->assertOk();
        $this->postEvent('messages', ['history' => true, 'messages' => [
            $this->message('L1', ['chat_jid' => '111@lid', 'chat_phone' => '8801711000001', 'push_name' => null]),
        ]])->assertOk();

        $conversation = Conversation::withoutGlobalScopes()->where('external_contact_id', '8801711000001')->sole();
        $this->assertSame('Karim Uddin', $conversation->contact_name);
        $this->assertSame('111@lid', WhatsAppContact::withoutGlobalScopes()->where('phone', '8801711000001')->sole()->lid);
    }

    public function test_a_name_that_arrives_under_a_lid_reaches_a_chat_archived_before_it(): void
    {
        $this->postEvent('messages', ['history' => true, 'messages' => [
            $this->message('L2', ['chat_jid' => '222@lid', 'chat_phone' => '8801711000002', 'push_name' => null]),
        ]])->assertOk();

        $conversation = Conversation::withoutGlobalScopes()->where('external_contact_id', '8801711000002')->sole();
        $this->assertNull($conversation->contact_name);

        $this->postEvent('contacts', ['contacts' => [$this->lidContact('222@lid', 'Nasima Begum')]])->assertOk();

        $this->assertSame('Nasima Begum', $conversation->fresh()->contact_name);
    }

    public function test_refreshing_names_fills_chats_archived_before_their_names_were_known(): void
    {
        $conversation = Conversation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'channel_id' => $this->channel->id,
            'provider' => 'whatsapp',
            'external_contact_id' => '8801711000009',
            'contact_phone' => '8801711000009',
            'contact_name' => null,
            'status' => 'open',
            'ai_enabled' => false,
        ]);
        ConversationMessage::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'incoming',
            'type' => 'text',
            'body' => 'দাম কত?',
            'external_message_id' => 'waweb:'.$this->channel->id.':OLD1',
            'delivery_status' => 'received',
            'generated_by' => 'human',
            'raw_payload' => ['source' => 'whatsapp_web', 'chat_jid' => '777@lid', 'chat_phone' => '8801711000009'],
            'sent_at' => now(),
        ]);
        // What an older bridge saved: a name keyed by the LID alone.
        WhatsAppContact::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'channel_id' => $this->channel->id, 'jid' => '777@lid', 'lid' => '777@lid', 'push_name' => 'Salma Akter',
        ]);

        app(CompanyContext::class)->set($this->company);
        $result = app(WhatsAppWebArchiveService::class)->refreshNames($this->channel);

        $this->assertSame(['linked' => 1, 'named' => 0], $result);
        $this->assertSame('Salma Akter', $conversation->fresh()->contact_name);
        $this->assertSame('777@lid', WhatsAppContact::withoutGlobalScopes()->where('phone', '8801711000009')->sole()->lid);

        // A second run changes nothing.
        $this->assertSame(['linked' => 0, 'named' => 0], app(WhatsAppWebArchiveService::class)->refreshNames($this->channel));
    }

    public function test_a_real_chat_name_is_never_overwritten_by_a_refresh(): void
    {
        $conversation = Conversation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'channel_id' => $this->channel->id, 'provider' => 'whatsapp',
            'external_contact_id' => '8801711000010', 'contact_phone' => '8801711000010', 'contact_name' => 'Named by staff', 'status' => 'open', 'ai_enabled' => false,
        ]);
        WhatsAppContact::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'channel_id' => $this->channel->id, 'jid' => '8801711000010@s.whatsapp.net', 'phone' => '8801711000010', 'push_name' => 'Someone Else',
        ]);

        app(CompanyContext::class)->set($this->company);
        app(WhatsAppWebArchiveService::class)->refreshNames($this->channel);

        $this->assertSame('Named by staff', $conversation->fresh()->contact_name);
    }

    public function test_the_first_complete_history_sync_queues_one_name_refresh(): void
    {
        Queue::fake();

        $this->postEvent('history', ['progress' => 100, 'is_latest' => true, 'messages' => 0])->assertOk();
        $this->postEvent('history', ['progress' => 100, 'is_latest' => true, 'messages' => 0])->assertOk();

        Queue::assertPushed(RefreshWhatsAppWebNamesJob::class, 1);
    }

    public function test_the_page_can_start_a_name_refresh(): void
    {
        Queue::fake();
        $this->openPageAsAdminWithBridgeSaved();

        Http::fake(['bridge.test/sessions/*' => Http::response(['id' => 'web-test-session', 'status' => 'connected', 'qr' => null, 'phone' => '8801711000000', 'history_progress' => 100], 200)]);

        Livewire::test(WhatsAppWebLogin::class)
            ->assertSee('Fill missing names')
            ->call('refreshNames', $this->channel->id);

        Queue::assertPushed(RefreshWhatsAppWebNamesJob::class, fn (RefreshWhatsAppWebNamesJob $job): bool => $job->channelId === $this->channel->id);
    }

    public function test_the_inbox_plays_video_and_audio_and_explains_a_file_that_was_not_saved(): void
    {
        Storage::fake(app(CompanyStorageService::class)->privateDiskName());

        $this->postEvent('messages', ['history' => true, 'messages' => [
            $this->message('VID1', ['type' => 'video', 'body' => null, 'media' => ['mime' => 'video/mp4', 'file_name' => null, 'size' => 10]]),
            $this->message('AUD1', ['type' => 'audio', 'body' => null, 'media' => ['mime' => 'audio/ogg; codecs=opus', 'file_name' => null, 'size' => 10]]),
            $this->message('DOC1', ['type' => 'document', 'body' => null, 'media' => ['mime' => 'application/pdf', 'file_name' => 'price.pdf', 'size' => 10]]),
        ]])->assertOk();
        $this->postMedia('VID1', 'video-bytes', self::SECRET, 'video/mp4')->assertOk();
        $this->postMedia('AUD1', 'audio-bytes', self::SECRET, 'audio/ogg; codecs=opus')->assertOk();

        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->withSession(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->set($this->company);

        Livewire::test(Inbox::class)
            ->call('selectConversation', Conversation::withoutGlobalScopes()->sole()->id)
            ->assertSeeHtml('<video')
            ->assertSeeHtml('<audio')
            ->assertSee('The file is not saved (yet).');
    }

    public function test_the_inbox_shows_the_phone_number_for_a_chat_without_a_name(): void
    {
        $this->postEvent('messages', ['history' => true, 'messages' => [
            $this->message('NN1', ['chat_phone' => '8801859963681', 'chat_jid' => '8801859963681@s.whatsapp.net', 'push_name' => null]),
        ]])->assertOk();

        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->withSession(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->set($this->company);

        Livewire::test(Inbox::class)
            ->assertSee('+8801859963681')
            ->assertDontSee('নাম পাওয়া যায়নি');
    }

    public function test_a_live_message_is_saved_while_the_bridge_waits_but_history_is_queued(): void
    {
        // With the queue faked, a job sent through the "sync" connection is the one that runs inline.
        Queue::fake();

        $this->postEvent('messages', ['history' => false, 'messages' => [$this->message('RT1')]])->assertOk();
        $this->postEvent('messages', ['history' => true, 'messages' => [$this->message('H1')]])->assertOk();

        Queue::assertPushed(ImportWhatsAppWebMessagesJob::class, fn (ImportWhatsAppWebMessagesJob $job): bool => $job->connection === 'sync' && ! $job->history);
        Queue::assertPushed(ImportWhatsAppWebMessagesJob::class, fn (ImportWhatsAppWebMessagesJob $job): bool => $job->connection !== 'sync' && $job->history);
    }

    public function test_a_live_message_is_in_the_inbox_as_soon_as_the_bridge_gets_its_answer(): void
    {
        $this->postEvent('messages', ['history' => false, 'messages' => [$this->message('RT3')]])->assertOk();

        $this->assertSame(1, ConversationMessage::query()->count());
    }

    public function test_a_live_message_that_cannot_be_saved_is_reported_so_the_bridge_retries(): void
    {
        $this->mock(WhatsAppWebArchiveService::class, function ($mock): void {
            $mock->shouldReceive('storeMessage')->andThrow(new \RuntimeException('database unavailable'));
        });

        $this->postEvent('messages', ['history' => false, 'messages' => [$this->message('RT2')]])->assertStatus(500);
    }

    public function test_the_inbox_notices_a_new_message_without_a_full_refresh_in_between(): void
    {
        $this->postEvent('messages', ['history' => true, 'messages' => [$this->message('P1', ['body' => 'পুরনো মেসেজ'])]])->assertOk();

        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->withSession(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->set($this->company);

        $component = Livewire::test(Inbox::class)
            ->call('selectConversation', Conversation::withoutGlobalScopes()->sole()->id)
            ->call('checkForChanges');
        $before = $component->get('inboxSignature');

        // Nothing new: same signature, and no full refresh happens yet.
        $component->call('checkForChanges')->assertSet('inboxSignature', $before);

        $this->postEvent('messages', ['history' => false, 'messages' => [$this->message('P2', ['body' => 'এইমাত্র এল', 'timestamp' => now()->timestamp])]])->assertOk();

        $component->call('checkForChanges')
            ->assertNotSet('inboxSignature', $before)
            ->assertSee('এইমাত্র এল');
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

    public function test_the_page_shows_why_the_bridge_connection_keeps_failing(): void
    {
        $this->openPageAsAdminWithBridgeSaved();
        $this->channel->forceFill(['web_status' => 'reconnecting'])->saveQuietly();

        Http::fake([
            'bridge.test/sessions/*' => Http::response(['id' => 'web-test-session', 'status' => 'reconnecting', 'qr' => null, 'phone' => null, 'history_progress' => null, 'last_error' => 'Connection Terminated', 'last_error_code' => 428], 200),
        ]);

        Livewire::test(WhatsAppWebLogin::class)
            ->assertSee('Last error')
            ->assertSee('Connection Terminated')
            ->assertSee('(428)');
    }

    public function test_the_page_hides_the_last_error_once_the_device_is_connected(): void
    {
        $this->openPageAsAdminWithBridgeSaved();

        Http::fake([
            'bridge.test/sessions/*' => Http::response(['id' => 'web-test-session', 'status' => 'connected', 'qr' => null, 'phone' => '8801711000000', 'history_progress' => 100, 'last_error' => 'Connection Terminated', 'last_error_code' => 428], 200),
        ]);

        Livewire::test(WhatsAppWebLogin::class)
            ->assertDontSee('Last error')
            ->assertDontSee('Connection Terminated');
    }

    protected function openPageAsAdminWithBridgeSaved(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->withSession(['current_company_id' => $this->company->id]);
        app(CompanyContext::class)->set($this->company);

        app(WhatsAppWebSettingsService::class)->save($this->company, [
            'bridge_url' => 'https://bridge.test',
            'api_key' => 'bridge-api-key-0123456789abcdef',
        ]);
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
