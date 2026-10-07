<?php

namespace App\Services\Crm;

use App\Jobs\SendBroadcastJob;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\ConversationChannel;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\StorefrontSetting;
use App\Services\Meta\MetaGraphService;
use App\Services\StorefrontNotificationService;
use Illuminate\Support\Collection;
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
        $rows = collect();

        if (in_array($broadcast->audience_type, ['leads', 'both'], true)) {
            $query = Lead::query()->withoutGlobalScopes()
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
