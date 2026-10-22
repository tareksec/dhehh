<?php

namespace App\Services\Crm;

use App\Jobs\MarkConversationReadJob;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\ConversationMessage;
use App\Models\CrmAiRun;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Meta\MetaGraphException;
use App\Services\Meta\MetaGraphService;
use App\Services\StorefrontNotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sends replies through the conversation channel and always archives the
 * outgoing attempt before contacting Meta, including a safe failed state.
 */
class ConversationMessengerService
{
    /** ai_meta['kind'] of the message that records a sent carousel. It is not an AI answer of its own. */
    public const CARDS_KIND = 'product_cards';

    public function __construct(
        protected MetaGraphService $meta,
        protected StorefrontNotificationService $notifications,
    ) {}

    public function send(
        Conversation $conversation,
        string $body,
        ?User $sender = null,
        string $type = 'text',
        ?string $mediaUrl = null,
        array $automation = [],
    ): ConversationMessage {
        // The Agent Desk test chat uses an unsaved conversation; nothing it
        // produces may ever reach a customer.
        if (! $conversation->exists) {
            throw new \LogicException('A message can only be sent in a saved conversation.');
        }

        // WhatsApp Web (QR) channels are a read-only archive: the bridge
        // never sends, so the linked number is not put at extra ban risk.
        if ($conversation->channel()->withoutGlobalScopes()->first()?->isWeb()) {
            throw ValidationException::withMessages([
                'body' => __('WhatsApp Web archive chats are read-only. Reply from the WhatsApp Business app on your phone, or add an internal note.'),
            ]);
        }

        $message = ConversationMessage::query()->create([
            'conversation_id' => $conversation->getKey(),
            'direction' => 'outgoing',
            'type' => $type,
            'body' => $body,
            'media_path' => $mediaUrl,
            'media_mime' => $mediaUrl ? 'image/*' : null,
            'delivery_status' => 'sending',
            'sent_by' => $sender?->getKey(),
            'generated_by' => $automation === [] ? 'human' : 'ai',
            'ai_confidence' => $automation['confidence'] ?? null,
            'ai_meta' => $automation['meta'] ?? null,
            'sent_at' => now(),
        ]);

        $this->updateConversationAfterAttempt($conversation, $sender);

        $delivered = $this->deliver($message, $conversation);
        if ($sender && $delivered->delivery_status === 'sent') {
            CrmAiRun::query()->where('conversation_id', $conversation->getKey())->whereIn('status', ['handed_off', 'budget_blocked'])
                ->whereNull('resolved_at')->update(['resolved_at' => now()]);
        }

        return $delivered;
    }

    /**
     * A Messenger carousel of product cards, sent right after the written
     * answer. Best effort: the answer already told the customer everything, so
     * a failed carousel is only logged -- it leaves no failed bubble behind
     * (a retry would have nothing to resend) and no channel fault.
     *
     * @param  array<int, array<string, mixed>>  $elements
     */
    public function sendCards(Conversation $conversation, array $elements, string $summary, array $meta = []): ?ConversationMessage
    {
        if (! $conversation->exists || $conversation->provider !== 'messenger' || $elements === []) {
            return null;
        }

        try {
            $conversation->loadMissing('channel');
            $channel = $conversation->channel;
            $this->validateDelivery($conversation, $channel);

            $message = ConversationMessage::query()->create([
                'conversation_id' => $conversation->getKey(),
                'direction' => 'outgoing',
                'type' => 'text',
                'body' => $summary,
                'delivery_status' => 'sending',
                'generated_by' => 'ai',
                'ai_meta' => [...$meta, 'kind' => self::CARDS_KIND],
                'sent_at' => now(),
            ]);

            try {
                $externalId = $this->meta->sendMessengerCards($channel, trim((string) $conversation->external_contact_id), $elements, 'crm-message:'.$message->getKey());
            } catch (Throwable $exception) {
                $message->delete();

                throw $exception;
            }

            $message->forceFill(['external_message_id' => $externalId, 'delivery_status' => 'sent'])->save();
            $conversation->forceFill(['last_message_at' => now()])->saveQuietly();
            $channel->markOutboundSent();

            return $message->refresh();
        } catch (Throwable $exception) {
            Log::info('Product cards were not sent.', ['conversation_id' => $conversation->getKey(), 'error' => $this->safeMessage($exception)]);

            return null;
        }
    }

    public function retry(ConversationMessage $message, ?User $sender = null): ConversationMessage
    {
        $message->loadMissing('conversation.channel');
        $conversation = $message->conversation;

        if (! $conversation || $message->direction !== 'outgoing' || $message->delivery_status !== 'failed') {
            throw ValidationException::withMessages([
                'message' => 'Only a failed outgoing message can be retried.',
            ]);
        }

        $claimed = ConversationMessage::query()
            ->whereKey($message->getKey())
            ->where('direction', 'outgoing')
            ->where('delivery_status', 'failed')
            ->update([
                'delivery_status' => 'sending',
                'external_message_id' => null,
                'raw_payload' => null,
                'sent_by' => $sender?->getKey() ?? $message->sent_by,
                'sent_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            throw ValidationException::withMessages([
                'message' => 'This message is already being retried or is no longer failed.',
            ]);
        }

        $message->refresh();

        $this->updateConversationAfterAttempt($conversation, $sender);

        return $this->deliver($message, $conversation);
    }

    public function dispatchLatestIncomingRead(Conversation $conversation): void
    {
        if ($conversation->provider !== 'whatsapp') {
            return;
        }

        MarkConversationReadJob::dispatch($conversation->getKey());
    }

    public function markLatestIncomingRead(Conversation $conversation): bool
    {
        if ($conversation->provider !== 'whatsapp') {
            return false;
        }

        $conversation->loadMissing('channel');
        $channel = $conversation->channel;

        if ($channel && (int) $channel->company_id !== (int) $conversation->company_id) {
            return false;
        }

        // No Cloud API read receipts for the WhatsApp Web archive.
        if ($channel?->isWeb()) {
            return false;
        }

        $message = $conversation->messages()
            ->where('direction', 'incoming')
            ->whereNotNull('external_message_id')
            ->latest('sent_at')
            ->latest('id')
            ->first();

        if (! $channel?->is_active || ! $message) {
            return false;
        }

        if (data_get($message->raw_payload, '_local.marked_read_at')) {
            return true;
        }

        try {
            $this->meta->markWhatsAppRead($channel, (string) $message->external_message_id);
            $raw = is_array($message->raw_payload) ? $message->raw_payload : [];
            data_set($raw, '_local.marked_read_at', now()->toIso8601String());
            $message->forceFill(['raw_payload' => $raw])->saveQuietly();
            $channel->clearDiagnosticError('read_receipt');

            return true;
        } catch (Throwable $exception) {
            // Best-effort: a stray read-receipt failure (e.g. a stale message
            // ID) must never leave the channel permanently flagged "Needs
            // attention" — nothing else clears the 'read_receipt' source, so
            // without the clearDiagnosticError() call above, one failed
            // receipt would stick forever even while sending/receiving kept
            // working fine.
            $channel->recordDiagnosticError($this->safeMessage($exception), 'read_receipt');

            return false;
        }
    }

    protected function deliver(ConversationMessage $message, Conversation $conversation): ConversationMessage
    {
        if (! in_array($conversation->provider, ['whatsapp', 'messenger'], true)) {
            $message->forceFill(['delivery_status' => 'internal'])->save();

            return $message->refresh();
        }

        $channel = null;

        try {
            $conversation->loadMissing('channel');
            $channel = $conversation->channel;
            $this->validateDelivery($conversation, $channel);

            $contactId = trim((string) $conversation->external_contact_id);
            $externalId = $conversation->provider === 'whatsapp'
                ? $this->meta->sendWhatsApp(
                    $channel,
                    preg_replace('/\D+/', '', $contactId) ?: $contactId,
                    (string) $message->body,
                    $message->media_path,
                )
                : $this->meta->sendMessenger(
                    $channel,
                    $contactId,
                    (string) $message->body,
                    $message->media_path,
                    'crm-message:'.$message->getKey(),
                );

        } catch (Throwable $exception) {
            $safeMessage = $this->safeMessage($exception);
            $message->forceFill([
                'delivery_status' => 'failed',
                'raw_payload' => [
                    'error' => array_filter([
                        'message' => $safeMessage,
                        'code' => $exception instanceof MetaGraphException ? $exception->graphCode : null,
                    ], fn (mixed $value): bool => $value !== null),
                    'failed_at' => now()->toIso8601String(),
                ],
            ])->save();
            if ($channel && (int) $channel->company_id === (int) $conversation->company_id) {
                $channel->recordDiagnosticError($safeMessage, 'outbound');
            }

            $this->attemptSmsFallback($message, $conversation);

            return $message->refresh();
        }

        // Once Meta returns a message ID the customer may already have the
        // message. Never turn later local bookkeeping failures into a
        // retryable bubble that could send a duplicate.
        $message->forceFill([
            'external_message_id' => $externalId,
            'delivery_status' => 'sent',
            'raw_payload' => null,
        ])->save();

        try {
            $channel->markOutboundSent();
        } catch (Throwable $exception) {
            Log::warning('Meta message was accepted but channel diagnostics could not be updated.', [
                'conversation_id' => $conversation->getKey(),
                'message_id' => $message->getKey(),
                'exception' => $exception::class,
            ]);
        }

        return $message->refresh();
    }

    /**
     * Bangladesh's standard bulk SMS routes are one-way, so this can never
     * become a reply thread -- it only exists to make sure the customer
     * still hears from us when WhatsApp fails for any reason (session
     * window closed, invalid token, Meta outage, etc). Runs best-effort:
     * a fallback failure never turns an already-recorded WhatsApp failure
     * into an exception.
     */
    protected function attemptSmsFallback(ConversationMessage $failedMessage, Conversation $conversation): void
    {
        if ($conversation->provider !== 'whatsapp') {
            return;
        }

        $phone = trim((string) $conversation->contact_phone);

        if ($phone === '' || blank($failedMessage->body)) {
            return;
        }

        try {
            $setting = StorefrontSetting::primaryFor((int) $conversation->company_id);

            if (! $setting || ! $this->notifications->smsConfigured($setting)) {
                return;
            }

            if (! $this->notifications->sendSms($setting, $phone, (string) $failedMessage->body)) {
                return;
            }
        } catch (Throwable $exception) {
            Log::warning('WhatsApp SMS fallback failed.', [
                'conversation_id' => $conversation->getKey(),
                'exception' => $exception::class,
            ]);

            return;
        }

        ConversationMessage::query()->create([
            'conversation_id' => $conversation->getKey(),
            'direction' => 'outgoing',
            'type' => $failedMessage->type,
            'body' => $failedMessage->body,
            'delivery_status' => 'sent',
            'delivery_channel' => 'sms',
            'sent_by' => $failedMessage->sent_by,
            'generated_by' => $failedMessage->generated_by,
            'sent_at' => now(),
        ]);

        $conversation->forceFill(['last_message_at' => now()])->saveQuietly();
    }

    protected function validateDelivery(Conversation $conversation, ?ConversationChannel $channel): void
    {
        if (! $channel || ! $channel->is_active) {
            throw ValidationException::withMessages([
                'body' => 'This conversation has no active Meta channel. Activate and test the channel before sending.',
            ]);
        }

        if ((int) $channel->company_id !== (int) $conversation->company_id) {
            throw ValidationException::withMessages([
                'body' => 'This conversation is linked to a channel owned by another company. Correct the channel before sending.',
            ]);
        }

        if ($channel->provider !== $conversation->provider) {
            throw ValidationException::withMessages([
                'body' => 'The conversation provider does not match its configured channel.',
            ]);
        }

        if (blank($channel->external_id)) {
            throw ValidationException::withMessages([
                'body' => 'The channel Phone Number ID or Page ID is missing.',
            ]);
        }

        if (blank($channel->access_token)) {
            throw ValidationException::withMessages([
                'body' => 'The Meta access token is missing. Add a permanent token, then run Test & Subscribe.',
            ]);
        }

        if (blank($conversation->external_contact_id)) {
            throw ValidationException::withMessages([
                'body' => 'This conversation has no Meta contact ID, so the message cannot be delivered.',
            ]);
        }

        if (! $conversation->withinReplyWindow()) {
            throw ValidationException::withMessages([
                'body' => 'The customer-service reply window is closed. Send an approved template or wait for the customer to message again.',
            ]);
        }
    }

    protected function updateConversationAfterAttempt(Conversation $conversation, ?User $sender): void
    {
        $updates = ['last_message_at' => now()];

        if ($sender !== null && $conversation->status === 'pending') {
            $updates['status'] = 'open';
        }

        $conversation->forceFill($updates)->saveQuietly();

        // A staff reply keeps the chat with the team for the takeover time.
        if ($sender !== null) {
            app(ConversationModeService::class)->staffAnswered($conversation, 'staff_reply', $sender);
        }
    }

    protected function safeMessage(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return (string) (collect($exception->errors())->flatten()->first() ?: 'Message validation failed.');
        }

        if ($exception instanceof MetaGraphException) {
            return $exception->getMessage();
        }

        return Str::limit('The message could not be delivered to Meta. Check the channel diagnostics and retry.', 500, '');
    }
}
