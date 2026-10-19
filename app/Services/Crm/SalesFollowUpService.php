<?php

namespace App\Services\Crm;

use App\Models\ChatOrderLink;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\CrmSalesFollowUp;
use App\Models\Order;
use App\Models\Quotation;
use App\Services\CourierAlertService;
use App\Services\Meta\MetaGraphService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reminders for a customer who was sent a checkout link or a quotation and
 * has not ordered (16_AI_INBOX_CONTROL_PLANE_PLAN.md §3.1, owner answer O4).
 *
 * One schedule per offer: the hours in `follow_up_schedule_hours` after the
 * customer's last message, then one a week for `follow_up_weekly_count`
 * weeks. A new message from the customer starts the count again. Nothing
 * goes out in the quiet hours. WhatsApp reminders use the approved template
 * at every step; Messenger and Instagram allow a normal message only inside
 * the 24-hour window, so later steps go to the team's Needs attention list.
 */
class SalesFollowUpService
{
    public function schedule(Conversation $conversation, ?ChatOrderLink $link = null, ?Quotation $quotation = null): ?CrmSalesFollowUp
    {
        if (($link && ((int) $link->company_id !== (int) $conversation->company_id || (int) $link->conversation_id !== (int) $conversation->getKey()))
            || ($quotation && (int) $quotation->company_id !== (int) $conversation->company_id)) {
            throw new \LogicException('Sales follow-up sources must belong to this company and conversation.');
        }
        $settings = app(AiSettingsService::class)->all($conversation->company, AiSettingsService::TOOL_MESSAGING);
        if (! $settings['sales_follow_ups_enabled'] || (! $link && ! $quotation)) {
            return null;
        }

        $eventKey = $link ? 'link:'.$link->getKey() : 'quotation:'.$quotation->getKey();
        $existing = CrmSalesFollowUp::query()->where('event_key', $eventKey)->first();
        if ($existing) {
            return $existing;
        }

        // Count from the customer's last message while the first reminder is
        // still ahead; an offer sent long after the last message starts now.
        $lastInbound = $conversation->messages()->where('direction', 'incoming')->latest('id')->first();
        $base = $lastInbound?->sent_at ?? now();
        if ($base->copy()->addHours($this->offsets($settings)[0])->isPast()) {
            $base = now();
        }

        // One schedule per chat: a newer offer replaces the older one.
        CrmSalesFollowUp::query()->where('conversation_id', $conversation->getKey())->where('status', 'pending')
            ->update(['status' => 'cancelled', 'reason' => 'Replaced by a newer offer.']);

        return CrmSalesFollowUp::query()->create([
            'event_key' => $eventKey,
            'company_id' => $conversation->company_id, 'conversation_id' => $conversation->getKey(),
            'chat_order_link_id' => $link?->getKey(), 'quotation_id' => $quotation?->getKey(),
            'inbound_watermark' => $conversation->messages()->where('direction', 'incoming')->max('id'),
            'step' => 0, 'base_at' => $base,
            'due_at' => $this->dueAt($base, 0, $settings),
        ]);
    }

    public function process(CrmSalesFollowUp $followUp): void
    {
        $lock = Cache::lock('crm:conversation:'.$followUp->conversation_id, 80);
        if (! $lock->get()) {
            return;
        }
        try {
            $followUp->refresh();
            if ($followUp->status !== 'pending' || $followUp->due_at->isFuture()) {
                return;
            }
            $conversation = $followUp->conversation()->with(['company', 'channel', 'lead'])->first();
            if (! $conversation) {
                return;
            }
            $settings = app(AiSettingsService::class)->all($conversation->company, AiSettingsService::TOOL_MESSAGING);
            $reason = $this->suppressionReason($followUp, $conversation, $settings);
            if ($reason) {
                $followUp->update(['status' => 'cancelled', 'reason' => $reason]);

                return;
            }

            // The customer wrote again: the hours are counted from that message.
            $latest = $conversation->messages()->where('direction', 'incoming')->latest('id')->first();
            if ($latest && (int) $latest->getKey() > (int) ($followUp->inbound_watermark ?? 0)) {
                $base = $latest->sent_at ?? now();
                $step = $this->firstStepAfter($base, $settings);
                $followUp->update([
                    'inbound_watermark' => $latest->getKey(), 'step' => $step, 'base_at' => $base,
                    'due_at' => $this->dueAt($base, $step, $settings)->max(now()),
                    'reason' => 'Customer wrote again; the reminders start over from that message.',
                ]);

                return;
            }

            if ($this->personWroteSince($conversation, $followUp)) {
                $followUp->update(['status' => 'cancelled', 'reason' => 'A person already followed up with this customer.']);

                return;
            }

            $quietUntil = $this->afterQuietHours(now(), $conversation->company, $settings);
            if ($quietUntil->isFuture()) {
                $followUp->update(['due_at' => $quietUntil, 'reason' => 'Quiet hours.']);

                return;
            }
            if ($conversation->hasHumanPresent()) {
                $followUp->update(['due_at' => now()->addMinutes(10), 'reason' => 'Staff viewing this conversation.']);

                return;
            }
            if ($settings['review_mode']) {
                $followUp->update(['status' => 'review', 'reason' => 'Staff review mode enabled.']);
                app(AiReplyService::class)->escalate($conversation, 'Sales follow-up is ready for staff review.', 'other');

                return;
            }

            $outcome = $conversation->provider === 'whatsapp'
                ? $this->sendWhatsAppTemplate($followUp, $conversation, $settings)
                : $this->sendChatMessage($followUp, $conversation, $settings);
            if ($outcome === 'unknown') {
                return;
            }

            $this->advance($followUp, $settings, $outcome === 'sent');
        } finally {
            $lock->release();
        }
    }

    /**
     * Reminder times in hours after the base message: the daily steps, then
     * the weekly ones counted from the last daily step.
     *
     * @return non-empty-list<int>
     */
    public function offsets(array $settings): array
    {
        $hours = AiSettingsService::followUpHours((string) ($settings['follow_up_schedule_hours'] ?? ''));
        $last = end($hours);
        for ($week = 1; $week <= (int) ($settings['follow_up_weekly_count'] ?? 0); $week++) {
            $hours[] = $last + 168 * $week;
        }

        return $hours;
    }

    /** The first reminder that is still ahead when counting from $base (0 when every one has passed). */
    protected function firstStepAfter(Carbon $base, array $settings): int
    {
        foreach ($this->offsets($settings) as $step => $hours) {
            if ($base->copy()->addHours($hours)->isFuture()) {
                return $step;
            }
        }

        return 0;
    }

    /** The moment reminder number $step is due, before the quiet hours are applied. */
    protected function dueAt(Carbon $base, int $step, array $settings): Carbon
    {
        return $base->copy()->addHours($this->offsets($settings)[$step]);
    }

    /** Move a moment that falls into the quiet hours (company time) to their end. */
    protected function afterQuietHours(Carbon $at, ?Company $company, array $settings): Carbon
    {
        $start = (int) ($settings['follow_up_quiet_start_hour'] ?? 22);
        $end = (int) ($settings['follow_up_quiet_end_hour'] ?? 8);
        if ($start === $end) {
            return $at;
        }

        $local = $at->copy()->setTimezone($company?->timezone ?: config('app.timezone'));
        $hour = $local->hour;
        $quiet = $start < $end ? ($hour >= $start && $hour < $end) : ($hour >= $start || $hour < $end);
        if (! $quiet) {
            return $at;
        }

        $resume = $local->copy()->setTime($end, 0);
        if ($resume->lessThanOrEqualTo($local)) {
            $resume->addDay();
        }

        return $resume->setTimezone($at->getTimezone());
    }

    /** This step is done (sent, or given to the team): plan the next one or finish. */
    protected function advance(CrmSalesFollowUp $followUp, array $settings, bool $sent): void
    {
        // The WhatsApp send marked the row "sending" behind this model's back.
        $followUp->refresh();
        $next = (int) $followUp->step + 1;
        $updates = ['step' => $next, 'reason' => null];
        if ($sent) {
            $updates['sent_at'] = now();
        }

        if ($next >= count($this->offsets($settings))) {
            $updates['status'] = ($sent || $followUp->sent_at) ? 'sent' : 'completed';
        } else {
            $updates['status'] = 'pending';
            $updates['due_at'] = $this->dueAt($followUp->base_at ?? $followUp->created_at, $next, $settings)->max(now()->addMinutes(5));
        }

        $followUp->update($updates);
    }

    /** @return 'sent'|'team'|'unknown' */
    protected function sendWhatsAppTemplate(CrmSalesFollowUp $followUp, Conversation $conversation, array $settings): string
    {
        if (CrmSalesFollowUp::query()->whereKey($followUp->getKey())->where('status', 'pending')->update(['status' => 'sending', 'attempts' => (int) $followUp->attempts + 1]) !== 1) {
            return 'unknown';
        }
        $message = ConversationMessage::query()->create(['conversation_id' => $conversation->getKey(), 'direction' => 'outgoing',
            'type' => 'template', 'body' => 'WhatsApp template: '.$settings['follow_up_template'], 'generated_by' => 'automation',
            'delivery_status' => 'sending', 'sent_at' => now(), 'ai_meta' => ['follow_up_id' => $followUp->getKey(), 'step' => $followUp->step]]);
        try {
            $externalId = app(MetaGraphService::class)->sendWhatsAppTemplate(
                $conversation->channel->external_id, $conversation->channel->access_token,
                app(ContactSuppressionService::class)->normalize((string) $conversation->external_contact_id),
                $settings['follow_up_template'], $settings['follow_up_template_language'], [$conversation->contact_name ?: 'Customer'],
            );
        } catch (\Throwable $exception) {
            // A transport timeout can occur after provider acceptance: never retry blindly.
            $message->update(['delivery_status' => 'unknown']);
            $followUp->update(['status' => 'unknown', 'reason' => 'Provider result uncertain; review before resending.']);
            app(AiReplyService::class)->escalate($conversation, 'Sales follow-up delivery needs manual reconciliation.', 'other', false);

            return 'unknown';
        }
        $message->update(['delivery_status' => 'sent', 'external_message_id' => $externalId]);
        $conversation->update(['last_message_at' => now()]);

        return 'sent';
    }

    /**
     * Messenger and Instagram: the company's reminder text while the 24-hour
     * window is open, otherwise (or with no text saved) the team's list.
     *
     * @return 'sent'|'team'
     */
    protected function sendChatMessage(CrmSalesFollowUp $followUp, Conversation $conversation, array $settings): string
    {
        $text = trim((string) ($settings['follow_up_message'] ?? ''));
        $window = $conversation->withinReplyWindow();
        if ($text === '' || ! $window) {
            $this->handToTeam($followUp, $conversation, $settings, $window
                ? 'No reminder text is saved for this channel.'
                : 'The 24-hour window is closed, so only a person can write to this customer now.');

            return 'team';
        }

        $link = $followUp->chat_order_link_id ? ChatOrderLink::query()->find($followUp->chat_order_link_id) : null;
        $body = strtr($text, ['{link}' => $link?->publicUrl() ?? '', '{name}' => (string) ($conversation->contact_name ?: '')]);
        $message = app(ConversationMessengerService::class)->send($conversation, trim($body), null, 'text', null, [
            'confidence' => 1.0,
            'meta' => ['source' => 'sales_follow_up', 'follow_up_id' => $followUp->getKey(), 'step' => $followUp->step],
        ]);
        // Not an AI answer: it must not count towards the AI's reply streak.
        $message->forceFill(['generated_by' => 'automation'])->save();

        if ($message->delivery_status !== 'sent') {
            $this->handToTeam($followUp, $conversation, $settings, 'The reminder could not be delivered.');

            return 'team';
        }

        return 'sent';
    }

    /** A person has to do this reminder: open (or refresh) the chat's Needs attention item. */
    protected function handToTeam(CrmSalesFollowUp $followUp, Conversation $conversation, array $settings, string $why): void
    {
        $note = 'Reminder '.($followUp->step + 1).' of '.count($this->offsets($settings)).' is due. '.$why;
        $item = app(AttentionQueueService::class)->opened($conversation, 'handover', 'follow_up_due', $note);
        if (! $item->wasRecentlyCreated) {
            return;
        }

        try {
            app(CourierAlertService::class)->alert(
                (int) $conversation->company_id,
                'ai-handoff',
                "conversation-{$conversation->getKey()}",
                'Customer reminder is due',
                Str::limit(($conversation->contact_name ?: 'A customer').': '.$note, 200),
            );
        } catch (\Throwable $exception) {
            Log::warning('Follow-up reminder notification failed.', ['error' => $exception->getMessage()]);
        }
    }

    /** A person (not the AI or an automation) wrote to the customer after their last message. */
    protected function personWroteSince(Conversation $conversation, CrmSalesFollowUp $followUp): bool
    {
        // After the schedule began, or after the last reminder: a quotation a
        // person sent just before it began must not cancel it.
        $since = $followUp->sent_at && $followUp->sent_at->greaterThan($followUp->created_at) ? $followUp->sent_at : $followUp->created_at;

        return $conversation->messages()->where('direction', 'outgoing')
            ->whereIn('generated_by', ['human', 'phone_app', 'page'])
            ->where('type', '!=', 'note')
            ->where('sent_at', '>', $since)
            ->exists();
    }

    private function suppressionReason(CrmSalesFollowUp $followUp, Conversation $conversation, array $settings): ?string
    {
        if (! $settings['sales_follow_ups_enabled'] || ! $conversation->ai_enabled || $conversation->status !== 'open' || $conversation->human_handled_until?->isFuture()) {
            return 'Automation disabled or staff owns the conversation.';
        }
        // A phone number is the only thing an opt-out is recorded against.
        if (($conversation->provider === 'whatsapp' || filled($conversation->contact_phone))
            && app(ContactSuppressionService::class)->suppressed((int) $conversation->company_id, (string) $conversation->contact_phone)) {
            return 'Contact opted out or has no deliverable phone.';
        }
        if ($followUp->chat_order_link_id) {
            // An old link is only 7 days valid, but the weekly reminders run
            // for weeks: only a finished checkout ends them.
            $link = ChatOrderLink::query()->find($followUp->chat_order_link_id);
            if (! $link || $link->converted_order_id !== null) {
                return 'Checkout completed.';
            }
        }
        if ($followUp->quotation_id && ! Quotation::query()->whereKey($followUp->quotation_id)->where('status', 'sent')->whereNull('converted_order_id')->exists()) {
            return 'Quotation is no longer awaiting a response.';
        }
        $customerId = $conversation->customer_id ?: $conversation->lead?->converted_customer_id;
        if (($conversation->lead && in_array($conversation->lead->status, ['won', 'lost'], true))
            || ($customerId && Order::query()->where('customer_id', $customerId)->where('created_at', '>=', $followUp->created_at)->whereNotIn('status', ['cancelled', 'returned', 'refunded'])->exists())) {
            return 'Lead closed or customer placed an order.';
        }
        if (! $conversation->channel?->is_active || (int) $conversation->channel->company_id !== (int) $conversation->company_id || blank($conversation->channel->access_token)) {
            return 'An active company channel is required.';
        }
        if ($conversation->provider === 'whatsapp' && blank($settings['follow_up_template'])) {
            return 'An approved WhatsApp template is required.';
        }

        return null;
    }
}
