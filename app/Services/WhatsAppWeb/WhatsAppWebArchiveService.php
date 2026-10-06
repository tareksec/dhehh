<?php

namespace App\Services\WhatsAppWeb;

use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\WhatsAppContact;
use App\Services\CompanyStorageService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes what the whatsapp-bridge syncs into the ERP: contacts, 1:1
 * conversations and every message (history and live, sent and received).
 *
 * Archive rules: nothing here ever deletes. A "delete for everyone" only
 * sets revoked_at; contacts and conversations outlive their channel. All
 * writes are idempotent on the bridge's message/contact ids, so the
 * bridge's retrying outbox can safely deliver the same batch twice.
 *
 * Callers must have set CompanyContext to the channel's company.
 */
class WhatsAppWebArchiveService
{
    public static function externalMessageId(ConversationChannel $channel, string $bridgeMessageId): string
    {
        return 'waweb:'.$channel->getKey().':'.$bridgeMessageId;
    }

    /** @param array<string, mixed> $contact Normalized bridge contact (jid, lid, phone, name, push_name, verified_name). */
    public function upsertContact(ConversationChannel $channel, array $contact, ?Carbon $seenAt = null): ?WhatsAppContact
    {
        $phone = $this->digits($contact['phone'] ?? null);
        $jid = $phone !== null ? $phone.'@s.whatsapp.net' : trim((string) ($contact['jid'] ?? ''));

        if ($jid === '') {
            return null;
        }

        $record = WhatsAppContact::query()->createOrFirst(
            ['company_id' => $channel->company_id, 'jid' => $jid],
            ['channel_id' => $channel->getKey(), 'phone' => $phone],
        );

        $updates = array_filter([
            'channel_id' => $channel->getKey(),
            'lid' => $this->clean($contact['lid'] ?? null),
            'phone' => $phone,
            'name' => $this->clean($contact['name'] ?? null),
            'push_name' => $this->clean($contact['push_name'] ?? null),
            'verified_name' => $this->clean($contact['verified_name'] ?? null),
        ], fn (mixed $value): bool => $value !== null);

        // first/last_seen_at track messages only; an address-book sync has no $seenAt.
        if ($seenAt && (! $record->first_seen_at || $seenAt->lessThan($record->first_seen_at))) {
            $updates['first_seen_at'] = $seenAt;
        }

        if ($seenAt && (! $record->last_seen_at || $seenAt->greaterThan($record->last_seen_at))) {
            $updates['last_seen_at'] = $seenAt;
        }

        if (! $record->customer_id && ! $record->lead_id && $phone !== null) {
            [$customerId, $leadId] = $this->matchCustomerOrLead($phone);
            $updates['customer_id'] = $customerId;
            $updates['lead_id'] = $customerId ? null : $leadId;
        }

        $record->forceFill(array_filter($updates, fn (mixed $value): bool => $value !== null))->save();

        if ($phone !== null && ($name = $record->displayName())) {
            Conversation::query()
                ->where('channel_id', $channel->getKey())
                ->where('external_contact_id', $phone)
                ->where(fn ($query) => $query->whereNull('contact_name')->orWhere('contact_name', '')->orWhere('contact_name', $phone))
                ->update(['contact_name' => $name]);
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $item  Normalized bridge message (kind=message|revoke).
     * @return bool True when a new message row was stored.
     */
    public function storeMessage(ConversationChannel $channel, array $item, bool $history): bool
    {
        $bridgeId = trim((string) ($item['id'] ?? ''));

        if ($bridgeId === '') {
            return false;
        }

        $externalId = self::externalMessageId($channel, $bridgeId);

        if (($item['kind'] ?? 'message') === 'revoke') {
            ConversationMessage::query()
                ->where('external_message_id', $externalId)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => isset($item['timestamp']) ? Carbon::createFromTimestamp((int) $item['timestamp']) : now()]);

            return false;
        }

        $phone = $this->digits($item['chat_phone'] ?? null);
        $contactId = $phone ?? trim((string) ($item['chat_jid'] ?? ''));

        if ($contactId === '' || ConversationMessage::query()->where('external_message_id', $externalId)->exists()) {
            return false;
        }

        $fromMe = (bool) ($item['from_me'] ?? false);
        $sentAt = filled($item['timestamp'] ?? null) ? Carbon::createFromTimestamp((int) $item['timestamp']) : now();
        $type = (string) ($item['type'] ?? 'text');
        $pushName = $fromMe ? null : $this->clean($item['push_name'] ?? null);

        $contact = $this->upsertContact($channel, [
            'jid' => $item['chat_jid'] ?? null,
            'phone' => $phone,
            'push_name' => $pushName,
        ], $sentAt);

        return DB::transaction(function () use ($channel, $item, $history, $externalId, $contactId, $phone, $fromMe, $sentAt, $type, $contact): bool {
            $conversation = Conversation::query()->firstOrCreate(
                ['channel_id' => $channel->getKey(), 'external_contact_id' => $contactId],
                [
                    'company_id' => $channel->company_id,
                    'provider' => 'whatsapp',
                    'contact_phone' => $phone,
                    'contact_name' => $contact?->displayName(),
                    'customer_id' => $contact?->customer_id,
                    'lead_id' => $contact?->lead_id,
                    'status' => 'open',
                    // Archive chats are answered from the phone - never by the AI.
                    'ai_enabled' => false,
                ],
            );

            $message = ConversationMessage::query()->firstOrCreate(
                ['external_message_id' => $externalId],
                [
                    'conversation_id' => $conversation->getKey(),
                    'direction' => $fromMe ? 'outgoing' : 'incoming',
                    'type' => array_key_exists($type, ConversationMessage::TYPES) ? $type : 'text',
                    'body' => $item['body'] ?? null,
                    'media_mime' => data_get($item, 'media.mime'),
                    'delivery_status' => $fromMe ? 'sent' : 'received',
                    'generated_by' => $fromMe ? 'phone_app' : 'human',
                    'raw_payload' => ['source' => 'whatsapp_web'] + $item,
                    'sent_at' => $sentAt,
                ],
            );

            if (! $message->wasRecentlyCreated) {
                return false;
            }

            $locked = Conversation::withoutGlobalScopes()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();
            $updates = [];

            if (! $locked->last_message_at || $sentAt->greaterThan($locked->last_message_at)) {
                $updates['last_message_at'] = $sentAt;
            }

            if (! $history && ! $fromMe) {
                $updates['unread_count'] = (int) $locked->unread_count + 1;
                $updates['status'] = $locked->status === 'closed' ? 'open' : $locked->status;
            }

            if (blank($locked->contact_name) && $contact?->displayName()) {
                $updates['contact_name'] = $contact->displayName();
            }

            if (! $locked->customer_id && ! $locked->lead_id) {
                $updates += $this->linkFor($channel, $locked, $contact, createLead: ! $history && ! $fromMe);
            }

            if ($updates !== []) {
                $locked->forceFill($updates)->saveQuietly();
            }

            return true;
        });
    }

    /**
     * Stores downloaded media for an archived message in the company's
     * private storage. Returns false when the message has not arrived yet
     * (the bridge then retries the upload later).
     */
    public function attachMedia(ConversationChannel $channel, string $bridgeMessageId, string $contents, ?string $mime, ?string $fileName): bool
    {
        $message = ConversationMessage::query()
            ->where('external_message_id', self::externalMessageId($channel, $bridgeMessageId))
            ->first();

        if (! $message) {
            return false;
        }

        if (filled($message->media_path)) {
            return true;
        }

        $mime = $this->clean($mime) ?? $message->media_mime ?? 'application/octet-stream';
        $extension = strtolower((string) pathinfo((string) $fileName, PATHINFO_EXTENSION))
            ?: (preg_replace('/[^a-z0-9]+/', '', strtolower(explode(';', explode('/', $mime)[1] ?? 'bin')[0])) ?: 'bin');
        $name = 'waweb-'.$channel->getKey().'-'.(preg_replace('/[^A-Za-z0-9_-]+/', '-', $bridgeMessageId) ?: 'media').'.'.substr($extension, 0, 12);

        $path = app(CompanyStorageService::class)->putPrivate($channel->company, 'conversation-media', $name, $contents);

        $message->forceFill(['media_path' => $path, 'media_mime' => $mime])->save();

        return true;
    }

    /** @return array<string, int> */
    protected function linkFor(ConversationChannel $channel, Conversation $conversation, ?WhatsAppContact $contact, bool $createLead): array
    {
        if ($contact?->customer_id) {
            return ['customer_id' => $contact->customer_id];
        }

        if ($contact?->lead_id) {
            return ['lead_id' => $contact->lead_id];
        }

        if (! $createLead || ! $channel->auto_create_leads) {
            return [];
        }

        $phone = $conversation->contact_phone ?: $conversation->external_contact_id;
        $lead = Lead::query()->create([
            'company_id' => $channel->company_id,
            'name' => $contact?->displayName() ?: 'WhatsApp contact '.$phone,
            'phone' => $phone,
            'source' => 'whatsapp',
            'status' => 'new',
        ]);

        $contact?->forceFill(['lead_id' => $lead->getKey()])->save();

        return ['lead_id' => $lead->getKey()];
    }

    /** @return array{0: int|null, 1: int|null} Same last-10-digit phone match as StoreIncomingMessageJob::autoLink(). */
    protected function matchCustomerOrLead(string $phone): array
    {
        $normalized = substr($phone, -10);
        $matches = fn ($query) => $query->where('phone', 'like', "%{$normalized}")->orWhere('phone', $phone);

        $customerId = Customer::query()->where($matches)->value('id');

        return [$customerId, $customerId ? null : Lead::query()->where($matches)->value('id')];
    }

    protected function digits(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits === '' || $digits === null ? null : $digits;
    }

    protected function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
