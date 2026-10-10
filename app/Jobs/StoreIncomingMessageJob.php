<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Lead;
use App\Services\CompanyContext;
use App\Services\Crm\ConversationModeService;
use App\Services\Meta\MetaGraphService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class StoreIncomingMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 30, 120, 300];

    public function __construct(public int $channelId, public array $payload) {}

    public function handle(CompanyContext $context, MetaGraphService $metaGraph): void
    {
        $channel = ConversationChannel::withoutGlobalScopes()->with('company')->findOrFail($this->channelId);
        $context->set($channel->company);

        try {
            if (($this->payload['object'] ?? null) === 'whatsapp_business_account') {
                $this->handleWhatsApp($channel, $metaGraph);
            } elseif (($this->payload['object'] ?? null) === 'page') {
                $this->handleMessenger($channel, $metaGraph);
            }
        } catch (Throwable $exception) {
            $channel->recordDiagnosticError('Incoming webhook processing failed. Check the application logs and retry the Meta event.', 'webhook');

            throw $exception;
        } finally {
            $context->clear();
        }
    }

    protected function handleWhatsApp(ConversationChannel $channel, MetaGraphService $metaGraph): void
    {
        foreach ($this->payload['entry'] ?? [] as $entry) {
            if (filled($channel->waba_id) && ! hash_equals((string) $channel->waba_id, (string) ($entry['id'] ?? ''))) {
                continue;
            }

            foreach ($entry['changes'] ?? [] as $change) {
                $field = $change['field'] ?? null;
                $value = (array) ($change['value'] ?? []);

                if (! is_array($change)
                    || (string) data_get($change, 'value.metadata.phone_number_id', '') !== (string) $channel->external_id) {
                    continue;
                }

                if ($field === 'smb_app_state_sync') {
                    $this->handleContactsSync($channel, $value);

                    continue;
                }

                if ($field === 'history') {
                    // Queued, not processed inline: a full ~180-day backfill
                    // can be large and arrives across several webhook
                    // deliveries over a few minutes.
                    ImportConversationHistoryJob::dispatch($channel->getKey(), $value);

                    continue;
                }

                if ($field === 'smb_message_echoes') {
                    $this->handleMessageEchoes($channel, $value);

                    continue;
                }

                if ($field !== 'messages') {
                    continue;
                }

                $contacts = collect($value['contacts'] ?? [])->keyBy('wa_id');

                foreach ($value['messages'] ?? [] as $message) {
                    $waId = (string) ($message['from'] ?? '');
                    $externalMessageId = (string) ($message['id'] ?? '');

                    if ($waId === '' || $externalMessageId === '') {
                        continue;
                    }

                    $contact = $contacts->get($waId, []);

                    $this->ingestMessage(
                        $channel,
                        $waId,
                        data_get($contact, 'profile.name'),
                        $waId, // WhatsApp contact id IS the phone number
                        $message['referral'] ?? null,
                        [
                            'external_message_id' => $externalMessageId,
                            'type' => $this->normalizeType((string) ($message['type'] ?? 'text')),
                            'body' => data_get($message, 'text.body')
                                ?? data_get($message, 'button.text')
                                ?? data_get($message, 'interactive.button_reply.title')
                                ?? data_get($message, 'interactive.list_reply.title')
                                ?? data_get($message, 'image.caption')
                                ?? data_get($message, 'video.caption')
                                ?? data_get($message, 'document.caption'),
                            'media_id' => data_get($message, 'image.id')
                                ?? data_get($message, 'audio.id')
                                ?? data_get($message, 'video.id')
                                ?? data_get($message, 'document.id')
                                ?? data_get($message, 'sticker.id'),
                            'media_mime' => data_get($message, 'image.mime_type')
                                ?? data_get($message, 'audio.mime_type')
                                ?? data_get($message, 'video.mime_type')
                                ?? data_get($message, 'document.mime_type')
                                ?? data_get($message, 'sticker.mime_type'),
                            'sent_at' => isset($message['timestamp'])
                                ? Carbon::createFromTimestamp((int) $message['timestamp'])
                                : now(),
                            'raw' => $message,
                        ],
                    );
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->applyDeliveryStatus($channel, $status, $metaGraph);
                }
            }
        }
    }

    /**
     * Coexistence contacts sync ('smb_app_state_sync'): the Business App's
     * current contacts, delivered once shortly after onboarding. Links each
     * to an existing Customer/Lead by phone, or creates a Lead - the same
     * matching rule as autoLink(), but standalone since there is no message
     * or Conversation attached to a contact-sync entry.
     */
    protected function handleContactsSync(ConversationChannel $channel, array $value): void
    {
        foreach ((array) ($value['state_sync'] ?? []) as $entry) {
            if (! is_array($entry) || ($entry['type'] ?? null) !== 'contact' || ($entry['action'] ?? 'add') === 'remove') {
                continue;
            }

            $phone = trim((string) data_get($entry, 'contact.phone_number', ''));
            $name = data_get($entry, 'contact.full_name') ?? data_get($entry, 'contact.first_name');

            if ($phone === '') {
                continue;
            }

            $this->linkSyncedContact($channel, $phone, is_string($name) ? $name : null);
        }
    }

    protected function linkSyncedContact(ConversationChannel $channel, string $phone, ?string $name): void
    {
        $normalized = substr(preg_replace('/\D/', '', $phone), -10);

        if ($normalized === '') {
            return;
        }

        $alreadyKnown = Customer::query()->where(fn ($query) => $query
            ->where('phone', 'like', "%{$normalized}")
            ->orWhere('phone', $phone))->exists()
            || Lead::query()->where(fn ($query) => $query
                ->where('phone', 'like', "%{$normalized}")
                ->orWhere('phone', $phone))->exists();

        if ($alreadyKnown || ! $channel->auto_create_leads) {
            return;
        }

        Lead::query()->create([
            'company_id' => $channel->company_id,
            'name' => $name ?: 'WhatsApp contact '.$phone,
            'phone' => $phone,
            'source' => 'whatsapp',
            'status' => 'new',
        ]);
    }

    /**
     * Coexistence phone-app echoes ('smb_message_echoes'): messages the
     * owner sent from the phone app after connecting, mirrored to us live.
     * Archived as outgoing messages with generated_by = 'phone_app' so the
     * Inbox can tell them apart from replies sent through the ERP itself.
     * Messages the ERP itself sent are skipped via the same
     * external_message_id dedupe used everywhere else in this pipeline.
     */
    protected function handleMessageEchoes(ConversationChannel $channel, array $value): void
    {
        foreach ((array) ($value['message_echoes'] ?? []) as $echo) {
            if (! is_array($echo)) {
                continue;
            }

            $externalMessageId = (string) ($echo['id'] ?? '');
            $contactId = (string) ($echo['to'] ?? '');

            if ($externalMessageId === '' || $contactId === ''
                || ConversationMessage::query()->where('external_message_id', $externalMessageId)->exists()) {
                continue;
            }

            $sentAt = isset($echo['timestamp']) ? Carbon::createFromTimestamp((int) $echo['timestamp']) : now();

            DB::transaction(function () use ($channel, $contactId, $externalMessageId, $echo, $sentAt): void {
                $conversation = Conversation::query()->firstOrCreate(
                    ['channel_id' => $channel->getKey(), 'external_contact_id' => $contactId],
                    ['company_id' => $channel->company_id, 'provider' => $channel->provider, 'status' => 'open'],
                );

                ConversationMessage::query()->firstOrCreate(
                    ['external_message_id' => $externalMessageId],
                    [
                        'conversation_id' => $conversation->getKey(),
                        'direction' => 'outgoing',
                        'type' => $this->normalizeType((string) ($echo['type'] ?? 'text')),
                        'body' => data_get($echo, 'text.body')
                            ?? data_get($echo, 'image.caption')
                            ?? data_get($echo, 'video.caption')
                            ?? data_get($echo, 'document.caption'),
                        'delivery_status' => 'sent',
                        'generated_by' => 'phone_app',
                        'raw_payload' => $echo,
                        'sent_at' => $sentAt,
                    ],
                );

                if (! $conversation->last_message_at || $sentAt->greaterThan($conversation->last_message_at)) {
                    $conversation->forceFill(['last_message_at' => $sentAt])->saveQuietly();
                }
            });
        }
    }

    protected function handleMessenger(ConversationChannel $channel, MetaGraphService $metaGraph): void
    {
        foreach ($this->payload['entry'] ?? [] as $entry) {
            if ((string) ($entry['id'] ?? '') !== (string) $channel->external_id) {
                continue;
            }
            foreach ($entry['messaging'] ?? [] as $event) {
                $psid = (string) data_get($event, 'sender.id', '');

                if ($psid === (string) $channel->external_id && data_get($event, 'message.is_echo') === true) {
                    $this->ingestMessengerEcho($channel, $event);

                    continue;
                }

                if ($psid === '' || $psid === $channel->external_id) {
                    continue; // echoes of our own page messages
                }

                if (isset($event['message'])) {
                    $message = $event['message'];
                    $externalMessageId = (string) ($message['mid'] ?? '');

                    if ($externalMessageId === '') {
                        continue;
                    }

                    $attachment = data_get($message, 'attachments.0');

                    $this->ingestMessage(
                        $channel,
                        $psid,
                        null,
                        null,
                        null,
                        [
                            'external_message_id' => $externalMessageId,
                            'type' => $attachment ? $this->normalizeType((string) ($attachment['type'] ?? 'text')) : 'text',
                            'body' => $message['text'] ?? null,
                            'media_url' => data_get($attachment, 'payload.url'),
                            'media_mime' => null,
                            'sent_at' => isset($event['timestamp'])
                                ? Carbon::createFromTimestampMs((int) $event['timestamp'])
                                : now(),
                            'raw' => $event,
                        ],
                    );
                }

                if (isset($event['delivery']['mids'])) {
                    foreach ((array) $event['delivery']['mids'] as $mid) {
                        $this->applyDeliveryStatus($channel, [
                            'id' => (string) $mid,
                            'status' => 'delivered',
                        ], $metaGraph);
                    }
                }

                if (isset($event['read']['watermark'])) {
                    $readAt = Carbon::createFromTimestampMs((int) $event['read']['watermark']);

                    ConversationMessage::query()
                        ->where('direction', 'outgoing')
                        ->whereNotNull('external_message_id')
                        ->whereIn('delivery_status', ['sending', 'sent', 'delivered'])
                        ->where('sent_at', '<=', $readAt)
                        ->whereHas('conversation', fn ($query) => $query
                            ->withoutGlobalScopes()
                            ->where('company_id', $channel->company_id)
                            ->where('channel_id', $channel->getKey())
                            ->where('external_contact_id', $psid))
                        ->update(['delivery_status' => 'read']);
                }
            }
        }
    }

    protected function ingestMessengerEcho(ConversationChannel $channel, array $event): void
    {
        $contactId = (string) data_get($event, 'recipient.id', '');
        $mid = (string) data_get($event, 'message.mid', '');
        if ($contactId === '' || $mid === '') {
            return;
        }
        $message = DB::transaction(function () use ($channel, $event, $contactId, $mid) {
            $conversation = Conversation::query()->firstOrCreate(
                ['channel_id' => $channel->id, 'external_contact_id' => $contactId],
                ['company_id' => $channel->company_id, 'provider' => 'messenger', 'status' => 'open'],
            );
            $conversation = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $metadata = (string) data_get($event, 'message.metadata', '');
            if (preg_match('/^crm-message:(\d+)$/', $metadata, $match)) {
                $own = $conversation->messages()->whereKey($match[1])->where('direction', 'outgoing')->first();
                if ($own && (! $own->external_message_id || $own->external_message_id === $mid)) {
                    $own->update(['external_message_id' => $mid, 'delivery_status' => 'sent']);

                    return null;
                }
            }
            // Our own API replies already have this ID. Never overwrite their AI/staff attribution.
            if (ConversationMessage::query()->where('external_message_id', $mid)->exists()) {
                return null;
            }
            $attachment = data_get($event, 'message.attachments.0');
            $sentAt = isset($event['timestamp']) ? Carbon::createFromTimestampMs((int) $event['timestamp']) : now();
            $message = $conversation->messages()->create([
                'direction' => 'outgoing', 'external_message_id' => $mid,
                'type' => $attachment ? $this->normalizeType((string) ($attachment['type'] ?? 'document')) : 'text',
                'body' => data_get($event, 'message.text'), 'delivery_status' => 'sent',
                'generated_by' => 'page', 'raw_payload' => $event, 'sent_at' => $sentAt,
            ]);
            if (! $conversation->last_message_at || $sentAt->greaterThan($conversation->last_message_at)) {
                $conversation->forceFill(['last_message_at' => $sentAt])->saveQuietly();
            }
            // A reply typed in the page's own inbox keeps the chat with the
            // team for the takeover time. A lone "." switches the chat: to
            // the team, or back to the AI when the team already has it.
            $modes = app(ConversationModeService::class);
            if (trim((string) data_get($event, 'message.text')) === '.' && $sentAt->greaterThan(now()->subMinutes(10))) {
                $conversation->isHumanMode()
                    ? $modes->toAi($conversation, 'dot_command')
                    : $modes->toHuman($conversation, 'dot_command');
            } else {
                $modes->staffAnswered($conversation, 'page_reply', null, $sentAt);
            }

            return $message;
        });
        if ($message && ($url = data_get($event, 'message.attachments.0.payload.url'))) {
            DownloadConversationMediaJob::dispatch($message->id, $channel->id, null, $url)->afterCommit();
        }
    }

    protected function ingestMessage(
        ConversationChannel $channel,
        string $externalContactId,
        ?string $contactName,
        ?string $contactPhone,
        ?array $referral = null,
        array $data = [],
    ): ?ConversationMessage {
        if ((string) ($data['external_message_id'] ?? '') === '') {
            return null;
        }

        [$message, $conversation, $created] = $this->storeMessage(
            $channel,
            $externalContactId,
            $data,
        );

        if (! $message || ! $conversation || ! $this->conversationMatches($conversation, $channel, $externalContactId)) {
            return null;
        }

        // Contact/lead mutations happen only after the platform message has
        // been deduplicated and proven to belong to this channel. The row lock
        // prevents concurrent webhook retries from creating duplicate leads.
        $conversation = $this->enrichConversation(
            $conversation,
            $channel,
            $contactName,
            $contactPhone,
            $referral,
        );

        if ($created || ! $channel->last_inbound_at) {
            $channel->markInboundReceived();
        }

        $this->dispatchFollowUps($message, $conversation, $channel, $data);

        if ($conversation->provider === 'messenger' && $conversation->profileDisplayName() === 'নাম পাওয়া যায়নি') {
            RefreshMessengerProfileJob::dispatch((int) $conversation->getKey())->afterCommit();
        }

        return $message;
    }

    protected function enrichConversation(
        Conversation $conversation,
        ConversationChannel $channel,
        ?string $contactName,
        ?string $contactPhone,
        ?array $referral,
    ): Conversation {
        return DB::transaction(function () use ($conversation, $channel, $contactName, $contactPhone, $referral): Conversation {
            $locked = Conversation::withoutGlobalScopes()
                ->whereKey($conversation->getKey())
                ->where('company_id', $channel->company_id)
                ->where('channel_id', $channel->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $updates = [];
            $fromAd = ($referral['source_type'] ?? null) === 'ad';

            if ($fromAd && $locked->entry_point !== 'ctwa_ad') {
                $updates['entry_point'] = 'ctwa_ad';
                $updates['ad_referral_id'] = $referral['source_id'] ?? null;
            }

            if ($contactName && $locked->contact_name !== $contactName) {
                $updates['contact_name'] = $contactName;
            }

            if ($contactPhone && $locked->contact_phone !== $contactPhone) {
                $updates['contact_phone'] = $contactPhone;
            }

            if ($updates !== []) {
                $locked->forceFill($updates)->saveQuietly();
            }

            if (! $locked->customer_id && ! $locked->lead_id) {
                $this->autoLink($locked, $channel);
            }

            return $locked;
        });
    }

    protected function autoLink(Conversation $conversation, ConversationChannel $channel): void
    {
        $phone = $conversation->contact_phone;

        if ($phone) {
            // Match on the local significant digits so '8801812345678'
            // (WhatsApp) finds a customer saved as '01812345678'.
            $normalized = substr(preg_replace('/\D/', '', $phone), -10);

            $customer = Customer::query()
                ->where(fn ($query) => $query
                    ->where('phone', 'like', "%{$normalized}")
                    ->orWhere('phone', $phone))
                ->first();

            if ($customer) {
                $conversation->forceFill(['customer_id' => $customer->getKey()])->saveQuietly();

                return;
            }

            $lead = Lead::query()
                ->where(fn ($query) => $query
                    ->where('phone', 'like', "%{$normalized}")
                    ->orWhere('phone', $phone))
                ->first();

            if ($lead) {
                $conversation->forceFill(['lead_id' => $lead->getKey()])->saveQuietly();

                return;
            }
        }

        if ($channel->auto_create_leads) {
            $lead = Lead::query()->create([
                'company_id' => $channel->company_id,
                'name' => $conversation->contact_name ?: 'Chat contact '.$conversation->external_contact_id,
                'phone' => $phone ?: ($conversation->external_contact_id ?? 'unknown'),
                'source' => $channel->provider === 'whatsapp' ? 'whatsapp' : 'facebook',
                'status' => 'new',
            ]);

            $conversation->forceFill(['lead_id' => $lead->getKey()])->saveQuietly();
        }
    }

    /**
     * @return array{0: ConversationMessage|null, 1: Conversation|null, 2: bool}
     */
    protected function storeMessage(
        ConversationChannel $channel,
        string $externalContactId,
        array $data,
    ): array {
        $externalId = (string) ($data['external_message_id'] ?? '');

        if ($externalId === '') {
            return [null, null, false];
        }

        // Conversation creation, platform-ID dedupe, and counters share one
        // transaction. Duplicate webhook retries return the original pair so
        // any missing post-commit follow-up can be scheduled again safely.
        return DB::transaction(function () use ($channel, $externalContactId, $data, $externalId): array {
            $existing = ConversationMessage::query()
                ->where('external_message_id', $externalId)
                ->first();

            if ($existing) {
                return [
                    $existing,
                    Conversation::withoutGlobalScopes()->find($existing->conversation_id),
                    false,
                ];
            }

            $conversation = Conversation::query()->firstOrCreate(
                [
                    'channel_id' => $channel->getKey(),
                    'external_contact_id' => $externalContactId,
                ],
                [
                    'company_id' => $channel->company_id,
                    'provider' => $channel->provider,
                    'status' => 'open',
                ],
            );

            // firstOrCreate uses createOrFirst internally, so concurrent Meta
            // retries also collapse safely on the unique platform message ID.
            $message = ConversationMessage::query()->firstOrCreate(
                ['external_message_id' => $externalId],
                [
                    'conversation_id' => $conversation->getKey(),
                    'direction' => 'incoming',
                    'type' => $data['type'],
                    'body' => $data['body'],
                    'media_mime' => $data['media_mime'] ?? null,
                    'delivery_status' => 'received',
                    'raw_payload' => $data['raw'],
                    'sent_at' => $data['sent_at'],
                ],
            );

            if (! $message->wasRecentlyCreated) {
                return [
                    $message,
                    Conversation::withoutGlobalScopes()->find($message->conversation_id),
                    false,
                ];
            }

            $locked = Conversation::withoutGlobalScopes()
                ->whereKey($conversation->getKey())
                ->where('company_id', $conversation->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            $updates = [
                'unread_count' => (int) $locked->unread_count + 1,
                'status' => $locked->status === 'closed' ? 'open' : $locked->status,
            ];

            if (! $locked->last_message_at || $data['sent_at']->greaterThan($locked->last_message_at)) {
                $updates['last_message_at'] = $data['sent_at'];
            }

            $locked->forceFill($updates)->saveQuietly();

            return [$message, $locked, true];
        });
    }

    protected function conversationMatches(
        Conversation $conversation,
        ConversationChannel $channel,
        string $externalContactId,
    ): bool {
        return (int) $conversation->company_id === (int) $channel->company_id
            && (int) $conversation->channel_id === (int) $channel->getKey()
            && (string) $conversation->provider === (string) $channel->provider
            && (string) $conversation->external_contact_id === $externalContactId;
    }

    protected function dispatchFollowUps(
        ConversationMessage $message,
        Conversation $conversation,
        ConversationChannel $channel,
        array $data,
    ): void {
        $message->refresh();

        if ((! empty($data['media_id']) || ! empty($data['media_url'])) && blank($message->media_path)) {
            DownloadConversationMediaJob::dispatch(
                $message->getKey(),
                $channel->getKey(),
                $data['media_id'] ?? null,
                $data['media_url'] ?? null,
            );
        }

        if ($message->type === 'text'
            && filled($message->body)
            && ! data_get($message->raw_payload, '_local.ai_processed_at')) {
            AiAutoReplyJob::dispatchAfterWait($conversation->getKey(), $message->getKey());
        }
    }

    protected function applyDeliveryStatus(ConversationChannel $channel, array $statusPayload, MetaGraphService $metaGraph): void
    {
        $externalMessageId = (string) ($statusPayload['id'] ?? '');
        $status = (string) ($statusPayload['status'] ?? '');

        if ($externalMessageId === '' || ! in_array($status, ['sent', 'delivered', 'read', 'played', 'failed'], true)) {
            return;
        }

        $message = ConversationMessage::query()
            ->where('external_message_id', $externalMessageId)
            ->where('direction', 'outgoing')
            ->whereHas('conversation', fn ($query) => $query
                ->withoutGlobalScopes()
                ->where('company_id', $channel->company_id)
                ->where('channel_id', $channel->getKey()))
            ->first();

        if (! $message) {
            return;
        }

        $statusRanks = [
            'sending' => 0,
            'sent' => 1,
            'delivered' => 2,
            'read' => 3,
            'played' => 4,
        ];
        $currentStatus = (string) $message->delivery_status;

        if ($currentStatus === 'failed'
            || ($status !== 'failed'
                && isset($statusRanks[$currentStatus], $statusRanks[$status])
                && $statusRanks[$status] < $statusRanks[$currentStatus])) {
            return;
        }

        $raw = is_array($message->raw_payload) ? $message->raw_payload : [];
        $raw['_delivery'] = $metaGraph->sanitizedStatusMetadata($statusPayload);
        $message->forceFill([
            'delivery_status' => $status,
            'raw_payload' => $raw,
        ])->saveQuietly();

        if ($status === 'failed') {
            $errorMessage = $metaGraph->statusErrorMessage($statusPayload)
                ?? 'Meta reported that an outgoing message failed. Open the failed message for details.';
            $raw['error'] = array_filter([
                'message' => $errorMessage,
                'code' => data_get($statusPayload, 'errors.0.code'),
            ], fn (mixed $value): bool => $value !== null);
            $message->forceFill(['raw_payload' => $raw])->saveQuietly();
            $channel->recordDiagnosticError($errorMessage, 'outbound');
        }
    }

    protected function normalizeType(string $type): string
    {
        return array_key_exists($type, ConversationMessage::TYPES) ? $type : 'text';
    }
}
