<?php

namespace App\Services\Crm;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Meta\MetaGraphService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What the customer sees while the AI works on an answer: "Seen", then
 * "typing...". Messenger and WhatsApp only. Everything here is best effort:
 * a failed call must never stop or delay the answer, and it is not recorded as
 * a channel fault.
 */
class ConversationPresenceService
{
    /** Messenger shows "typing..." for about 20 seconds, so it is renewed a little earlier. */
    protected const TYPING_RENEW_SECONDS = 12;

    public function __construct(protected MetaGraphService $meta) {}

    /** Marks the customer's message as seen (Messenger; WhatsApp is marked read by the typing call). */
    public function seen(Conversation $conversation, ConversationMessage $source, array $settings): void
    {
        if ($conversation->provider !== 'messenger' || ! $this->allowed($conversation, $source, $settings)) {
            return;
        }
        if (data_get($source->raw_payload, '_local.seen_at')) {
            return;
        }

        $this->attempt(function () use ($conversation, $source): void {
            $this->meta->sendMessengerAction($conversation->channel, (string) $conversation->external_contact_id, 'mark_seen');
            $this->remember($source, '_local.seen_at');
        });
    }

    /** Shows "typing..." (and marks the message read on WhatsApp). Renewed at most every few seconds. */
    public function typing(Conversation $conversation, ConversationMessage $source, array $settings): void
    {
        if (! $this->allowed($conversation, $source, $settings)) {
            return;
        }
        if (! Cache::add('crm:typing:'.$conversation->getKey(), 1, self::TYPING_RENEW_SECONDS)) {
            return;
        }

        $this->attempt(function () use ($conversation, $source): void {
            if ($conversation->provider === 'messenger') {
                $this->meta->sendMessengerAction($conversation->channel, (string) $conversation->external_contact_id, 'typing_on');

                return;
            }
            $this->meta->sendWhatsAppTyping($conversation->channel, (string) $source->external_message_id);
            $this->remember($source, '_local.marked_read_at');
        });
    }

    /** Takes "typing..." away when the AI ends without sending anything (Messenger). */
    public function stopped(Conversation $conversation, array $settings): void
    {
        if ($conversation->provider !== 'messenger' || ! ($settings['typing_indicator_enabled'] ?? false)
            || ! Cache::pull('crm:typing:'.$conversation->getKey())) {
            return;
        }

        $this->attempt(function () use ($conversation): void {
            $conversation->loadMissing('channel');
            if ($this->usable($conversation)) {
                $this->meta->sendMessengerAction($conversation->channel, (string) $conversation->external_contact_id, 'typing_off');
            }
        });
    }

    protected function allowed(Conversation $conversation, ConversationMessage $source, array $settings): bool
    {
        if (! ($settings['typing_indicator_enabled'] ?? false) || ! $conversation->exists
            || ! in_array($conversation->provider, ['whatsapp', 'messenger'], true)
            || $source->direction !== 'incoming'
            || (int) $source->conversation_id !== (int) $conversation->getKey()) {
            return false;
        }
        if ($conversation->provider === 'whatsapp' && blank($source->external_message_id)) {
            return false;
        }
        $conversation->loadMissing('channel');

        return $this->usable($conversation) && $conversation->withinReplyWindow();
    }

    protected function usable(Conversation $conversation): bool
    {
        $channel = $conversation->channel;

        return $channel !== null
            && $channel->is_active
            && (int) $channel->company_id === (int) $conversation->company_id
            && $channel->provider === $conversation->provider
            && filled($channel->access_token)
            && filled($conversation->external_contact_id);
    }

    protected function remember(ConversationMessage $message, string $key): void
    {
        $raw = is_array($message->raw_payload) ? $message->raw_payload : [];
        data_set($raw, $key, now()->toIso8601String());
        $message->forceFill(['raw_payload' => $raw])->saveQuietly();
    }

    protected function attempt(callable $call): void
    {
        try {
            $call();
        } catch (Throwable $exception) {
            Log::info('Seen/typing indicator was not sent.', ['exception' => $exception::class]);
        }
    }
}
