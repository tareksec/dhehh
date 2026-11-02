<?php

namespace App\Services\Crm;

use App\Jobs\SendBroadcastJob;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\StorefrontSetting;
use App\Services\Meta\MetaGraphService;
use App\Services\StorefrontNotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Builds a Broadcast's recipient list from its stored Lead/Customer filters
 * and sends it, WhatsApp-first with an SMS fallback -- mirrors the
 * fallback shape in ConversationMessengerService, but for a cold,
 * business-initiated send (so WhatsApp always goes out as an approved
 * template, never a free-form message).
 */
class BroadcastService
{
    public function __construct(
        protected MetaGraphService $meta,
        protected StorefrontNotificationService $notifications,
    ) {}

    /** Rebuilds the recipient list from the broadcast's stored filters. */
    public function buildRecipients(Broadcast $broadcast): int
    {
        if ($broadcast->isMessenger()) {
            return $this->storeRecipients($broadcast, $this->messengerAudience($broadcast)->get(['id', 'contact_name'])->map(fn (Conversation $conversation): array => [
                'recipient_type' => 'conversation',
                'recipient_id' => $conversation->getKey(),
                'name' => $conversation->contact_name,
                'phone' => null,
            ]));
        }

        $rows = collect();

        if (in_array($broadcast->audience_type, ['leads', 'both'], true)) {
            $query = Lead::query()->withoutGlobalScopes()->withoutTrashed()
                ->where('company_id', $broadcast->company_id)
                ->whereNull('opted_out_at')
                ->whereNotNull('phone')
                ->where('phone', '!=', '');

            if (filled($broadcast->lead_status_filter)) {
                $query->whereIn('status', $broadcast->lead_status_filter);
            }

            if (filled($broadcast->lead_source_filter)) {
                $query->whereIn('source', $broadcast->lead_source_filter);
            }

            $query->get(['id', 'name', 'phone'])->each(function (Lead $lead) use ($rows): void {
                $rows->push([
                    'recipient_type' => 'lead',
                    'recipient_id' => $lead->getKey(),
                    'name' => $lead->name,
                    'phone' => $lead->phone,
                ]);
            });
        }

        if (in_array($broadcast->audience_type, ['customers', 'both'], true)) {
            Customer::query()->withoutGlobalScopes()
                ->withoutTrashed()
                ->where('company_id', $broadcast->company_id)
                ->whereNull('opted_out_at')
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->get(['id', 'name', 'phone'])
                ->each(function (Customer $customer) use ($rows): void {
                    $rows->push([
                        'recipient_type' => 'customer',
                        'recipient_id' => $customer->getKey(),
                        'name' => $customer->name,
                        'phone' => $customer->phone,
                    ]);
                });
        }

        // Never message the same phone number twice within one broadcast,
        // even when it appears as both a lead and a customer.
        $rows = $rows->unique(fn (array $row): string => preg_replace('/\D+/', '', $row['phone']) ?: $row['phone'])
            ->values();

        return $this->storeRecipients($broadcast, $rows);
    }

    /** Replaces the broadcast's recipient list with these rows. */
    protected function storeRecipients(Broadcast $broadcast, Collection $rows): int
    {
        BroadcastRecipient::query()->where('broadcast_id', $broadcast->getKey())->delete();

        $now = now();

        $rows->chunk(500)->each(function (Collection $chunk) use ($broadcast, $now): void {
            BroadcastRecipient::query()->insert(
                $chunk->map(fn (array $row): array => [
                    ...$row,
                    'broadcast_id' => $broadcast->getKey(),
                    'status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()
            );
        });

        $broadcast->forceFill(['recipients_count' => $rows->count()])->save();

        return $rows->count();
    }

    /** Queues a draft/failed/cancelled broadcast for sending. */
    public function queue(Broadcast $broadcast): void
    {
        if (! $broadcast->isEditable()) {
            return;
        }

        // Owner answer O5: a Messenger broadcast waits for a successful test
        // of its current text, sent to the sender's own chat.
        if ($broadcast->needsTest()) {
            throw ValidationException::withMessages([
                'broadcast' => __('Send a test to your own chat first. The test must match the text you are about to send.'),
            ]);
        }

        if ($broadcast->recipients_count === 0) {
            $this->buildRecipients($broadcast);
        }

        $broadcast->forceFill(['status' => 'queued', 'queued_at' => now()])->save();

        SendBroadcastJob::dispatch($broadcast->getKey());
    }

    /** Sends every still-pending recipient. Safe to re-run after a timeout. */
    public function send(Broadcast $broadcast): void
    {
        if (! in_array($broadcast->status, ['queued', 'sending'], true)) {
            return;
        }

        $broadcast->forceFill(['status' => 'sending'])->save();

        $setting = StorefrontSetting::primaryFor((int) $broadcast->company_id);
        $channel = $broadcast->whatsappChannel;
        $deadline = microtime(true) + 25;

        $broadcast->recipients()
            ->where('status', 'pending')
            ->orderBy('id')
            ->chunkById(50, function (Collection $chunk) use ($broadcast, $setting, $channel, $deadline) {
                foreach ($chunk as $recipient) {
                    if (microtime(true) >= $deadline || $broadcast->fresh()->status === 'cancelled') {
                        return false;
                    }
                    $this->sendToRecipient($broadcast, $recipient, $setting, $channel);
                    // Gentle stagger -- friendly to both Meta's and the SMS
                    // gateway's rate limits, not a hard requirement of either.
                    usleep(150_000);
                }
            });

        $broadcast->refresh();
        if ($broadcast->status === 'cancelled' || $broadcast->recipients()->where('status', 'pending')->exists()) {
            return;
        }
        $broadcast->forceFill([
            'status' => ($broadcast->recipients()->where('status', 'sending')->exists() || ($broadcast->recipients_count > 0 && $broadcast->sent_count === 0))
                ? 'failed'
                : 'completed',
            'completed_at' => now(),
        ])->save();
    }

    protected function sendToRecipient(
        Broadcast $broadcast,
        BroadcastRecipient $recipient,
        ?StorefrontSetting $setting,
        ?ConversationChannel $channel,
    ): void {
        if ($recipient->status !== 'pending') {
            return;
        }

        if (BroadcastRecipient::query()->whereKey($recipient->getKey())->where('status', 'pending')->update(['status' => 'sending']) !== 1) {
            return;
        }

        if ($broadcast->isMessenger()) {
            $this->sendToConversation($broadcast, $recipient);

            return;
        }

        if (app(ContactSuppressionService::class)->suppressed((int) $broadcast->company_id, $recipient->phone)) {
            $recipient->update(['status' => 'failed', 'error' => 'Contact opted out before delivery.']);
            $broadcast->increment('failed_count');

            return;
        }

        if (in_array($broadcast->channel, ['whatsapp', 'both'], true)
            && $this->sendWhatsApp($broadcast, $recipient, $channel)) {
            $recipient->forceFill(['status' => 'sent', 'channel_used' => 'whatsapp', 'sent_at' => now(), 'error' => null])->save();
            $broadcast->increment('sent_count');

            return;
        }

        if (in_array($broadcast->channel, ['sms', 'both'], true)
            && $setting
            && $this->sendSms($broadcast, $recipient, $setting)) {
            $recipient->forceFill(['status' => 'sent', 'channel_used' => 'sms', 'sent_at' => now(), 'error' => null])->save();
            $broadcast->increment('sent_count');

            return;
        }

        $recipient->forceFill([
            'status' => 'failed',
            'error' => 'Delivery failed on every channel configured for this broadcast.',
        ])->save();
        $broadcast->increment('failed_count');
    }

    /**
     * The Page's chats that wrote inside the window. Checked again for each
     * recipient at send time: the window keeps closing while a list is sent.
     */
    public function messengerAudience(Broadcast $broadcast): Builder
    {
        $cutoff = now()->subHours(Broadcast::MESSENGER_WINDOW_HOURS);

        return Conversation::query()->withoutGlobalScopes()
            ->where('company_id', $broadcast->company_id)
            ->where('provider', 'messenger')
            ->where('channel_id', $broadcast->messenger_channel_id)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('conversation_messages')
                ->whereColumn('conversation_messages.conversation_id', 'conversations.id')
                ->where('conversation_messages.direction', 'incoming')
                ->where('conversation_messages.sent_at', '>=', $cutoff));
    }

    /**
     * Test send to one chat (the sender's own). Only a delivered test
     * unlocks the broadcast, and only for the text it carried.
     *
     * @throws ValidationException
     */
    public function sendTest(Broadcast $broadcast, Conversation $conversation): void
    {
        if (! $broadcast->isMessenger()) {
            throw ValidationException::withMessages(['conversation' => __('Only a Messenger broadcast needs a test.')]);
        }

        if ($blocker = $this->messengerBlocker($broadcast, $conversation)) {
            throw ValidationException::withMessages(['conversation' => $blocker]);
        }

        if (! $this->deliverToConversation($broadcast, $conversation)) {
            throw ValidationException::withMessages(['conversation' => __('Meta did not accept the test message. Check the Page connection and try again.')]);
        }

        $broadcast->forceFill(['tested_hash' => $broadcast->contentHash(), 'tested_at' => now()])->save();
    }

    protected function sendToConversation(Broadcast $broadcast, BroadcastRecipient $recipient): void
    {
        $conversation = Conversation::query()->withoutGlobalScopes()
            ->where('company_id', $broadcast->company_id)
            ->find($recipient->recipient_id);

        $blocker = $this->messengerBlocker($broadcast, $conversation);
        if ($blocker === null && $this->deliverToConversation($broadcast, $conversation)) {
            $recipient->forceFill(['status' => 'sent', 'channel_used' => 'messenger', 'sent_at' => now(), 'error' => null])->save();
            $broadcast->increment('sent_count');

            return;
        }

        $recipient->forceFill(['status' => 'failed', 'error' => $blocker ?? __('Meta did not accept the message.')])->save();
        $broadcast->increment('failed_count');
    }

    /** Why this chat may not get the broadcast now, or null when it may. */
    protected function messengerBlocker(Broadcast $broadcast, ?Conversation $conversation): ?string
    {
        if (! $conversation || (int) $conversation->company_id !== (int) $broadcast->company_id
            || $conversation->provider !== 'messenger' || (int) $conversation->channel_id !== (int) $broadcast->messenger_channel_id) {
            return __('This chat is not on the Page chosen for the broadcast.');
        }

        $channel = ConversationChannel::withoutGlobalScopes()->withoutTrashed()->find($broadcast->messenger_channel_id);
        if (! $channel || (int) $channel->company_id !== (int) $broadcast->company_id || ! $channel->is_active || blank($channel->access_token)) {
            return __('The Messenger channel is not active.');
        }

        $inWindow = $conversation->messages()->withoutGlobalScopes()
            ->where('direction', 'incoming')
            ->where('sent_at', '>=', now()->subHours(Broadcast::MESSENGER_WINDOW_HOURS))
            ->exists();

        return $inWindow ? null : __('This chat has not written in the last :hours hours, so Messenger does not allow the message.', ['hours' => Broadcast::MESSENGER_WINDOW_HOURS]);
    }

    protected function deliverToConversation(Broadcast $broadcast, Conversation $conversation): bool
    {
        $text = trim(strtr((string) $broadcast->messenger_text, ['{{name}}' => (string) ($conversation->contact_name ?: '')]));
        if ($text === '') {
            return false;
        }

        $message = app(ConversationMessengerService::class)->send($conversation, $text, null, 'text', null, [
            'confidence' => 1.0,
            'meta' => ['source' => 'broadcast', 'broadcast_id' => $broadcast->getKey()],
        ]);
        // Written by the business on purpose, not by the AI.
        $message->forceFill(['generated_by' => 'automation'])->save();

        return $message->delivery_status === 'sent';
    }

    protected function sendWhatsApp(Broadcast $broadcast, BroadcastRecipient $recipient, ?ConversationChannel $channel): bool
    {
        if (! $channel || (int) $channel->company_id !== (int) $broadcast->company_id || ! $channel->is_active
            || blank($channel->access_token) || blank($channel->external_id)
            || blank($broadcast->whatsapp_template_name)) {
            return false;
        }

        try {
            $this->meta->sendWhatsAppTemplate(
                (string) $channel->external_id,
                (string) $channel->access_token,
                preg_replace('/\D+/', '', $recipient->phone) ?: $recipient->phone,
                (string) $broadcast->whatsapp_template_name,
                (string) ($broadcast->whatsapp_template_language ?: 'bn'),
                [$recipient->name ?: 'Customer'],
            );
            $channel->markOutboundSent();

            return true;
        } catch (Throwable $exception) {
            $channel->recordDiagnosticError($exception->getMessage(), 'outbound');

            return false;
        }
    }

    protected function sendSms(Broadcast $broadcast, BroadcastRecipient $recipient, StorefrontSetting $setting): bool
    {
        $body = trim((string) $broadcast->sms_body);

        if ($body === '' || ! $this->notifications->smsConfigured($setting)) {
            return false;
        }

        return $this->notifications->sendSms(
            $setting,
            $recipient->phone,
            strtr($body, ['{{name}}' => $recipient->name ?: 'Customer']),
        );
    }
}
