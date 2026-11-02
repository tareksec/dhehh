<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\ConversationMessage;
use App\Services\CompanyContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ingests one 'history' webhook chunk from a Meta Embedded Signup Coexistence
 * onboarding - up to ~180 days of the owner's past 1:1 WhatsApp Business App
 * messages, delivered across several webhook deliveries over a few minutes.
 * Queued rather than run inline in the webhook request, since a full backfill
 * can be large. Idempotent: reuses the same external_message_id dedupe as
 * live messages, so repeated/overlapping chunks are safe to re-process.
 *
 * Deliberately does not bump unread_count, reopen a closed conversation, or
 * trigger AiAutoReplyJob - these are already-handled historical messages,
 * not something needing a fresh live response.
 */
class ImportConversationHistoryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 30, 120, 300];

    /** @param array<string, mixed> $value The 'history' field's webhook value payload. */
    public function __construct(public int $channelId, public array $value) {}

    public function handle(CompanyContext $context): void
    {
        $channel = ConversationChannel::withoutGlobalScopes()->withoutTrashed()->with('company')->findOrFail($this->channelId);
        $context->set($channel->company);

        try {
            foreach ((array) ($this->value['history'] ?? []) as $chunk) {
                foreach ((array) data_get($chunk, 'threads', []) as $thread) {
                    $contactId = (string) ($thread['id'] ?? '');

                    if ($contactId === '') {
                        continue;
                    }

                    foreach ((array) data_get($thread, 'messages', []) as $message) {
                        if (is_array($message)) {
                            $this->importMessage($channel, $contactId, $message);
                        }
                    }
                }
            }
        } finally {
            $context->clear();
        }
    }

    protected function importMessage(ConversationChannel $channel, string $contactId, array $message): void
    {
        $externalId = (string) ($message['id'] ?? '');

        if ($externalId === '') {
            return;
        }

        $businessNumber = substr(preg_replace('/\D/', '', (string) $channel->external_id), -10);
        $from = substr(preg_replace('/\D/', '', (string) ($message['from'] ?? '')), -10);
        $direction = ($from !== '' && $from === $businessNumber) ? 'outgoing' : 'incoming';
        $type = $this->normalizeType((string) ($message['type'] ?? 'text'));
        $body = data_get($message, 'text.body')
            ?? data_get($message, 'image.caption')
            ?? data_get($message, 'video.caption')
            ?? data_get($message, 'document.caption');
        $sentAt = isset($message['timestamp']) ? Carbon::createFromTimestamp((int) $message['timestamp']) : now();

        DB::transaction(function () use ($channel, $contactId, $externalId, $direction, $type, $body, $message, $sentAt): void {
            $conversation = Conversation::query()->firstOrCreate(
                ['channel_id' => $channel->getKey(), 'external_contact_id' => $contactId],
                ['company_id' => $channel->company_id, 'provider' => $channel->provider, 'status' => 'open'],
            );

            if (! $conversation->last_message_at || $sentAt->greaterThan($conversation->last_message_at)) {
                $conversation->forceFill(['last_message_at' => $sentAt])->saveQuietly();
            }

            if (ConversationMessage::query()->where('external_message_id', $externalId)->exists()) {
                return;
            }

            ConversationMessage::query()->firstOrCreate(
                ['external_message_id' => $externalId],
                [
                    'conversation_id' => $conversation->getKey(),
                    'direction' => $direction,
                    'type' => $type,
                    'body' => $body,
                    'delivery_status' => $direction === 'outgoing' ? 'sent' : 'received',
                    'generated_by' => $direction === 'outgoing' ? 'phone_app' : 'human',
                    'raw_payload' => $message,
                    'sent_at' => $sentAt,
                ],
            );
        });
    }

    protected function normalizeType(string $type): string
    {
        return array_key_exists($type, ConversationMessage::TYPES) ? $type : 'text';
    }
}
