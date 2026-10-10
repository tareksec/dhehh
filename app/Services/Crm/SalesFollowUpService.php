<?php

namespace App\Services\Crm;

use App\Models\ChatOrderLink;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\CrmSalesFollowUp;
use App\Models\Order;
use App\Models\Quotation;
use App\Services\Meta\MetaGraphService;
use Illuminate\Support\Facades\Cache;

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

        return CrmSalesFollowUp::query()->firstOrCreate(['event_key' => $link ? 'link:'.$link->getKey() : 'quotation:'.$quotation->getKey()], [
            'company_id' => $conversation->company_id, 'conversation_id' => $conversation->getKey(),
            'chat_order_link_id' => $link?->getKey(), 'quotation_id' => $quotation?->getKey(),
            'inbound_watermark' => $conversation->messages()->where('direction', 'incoming')->max('id'),
            'due_at' => now()->addHours((int) $settings['follow_up_delay_hours']),
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
            if ($conversation->hasHumanPresent()) {
                $followUp->update(['due_at' => now()->addMinutes(10), 'reason' => 'Staff viewing this conversation.']);

                return;
            }
            if ($settings['review_mode']) {
                $followUp->update(['status' => 'review', 'reason' => 'Staff review mode enabled.']);
                app(AiReplyService::class)->escalate($conversation, 'Sales follow-up is ready for staff review.', 'other');

                return;
            }
            if (CrmSalesFollowUp::query()->whereKey($followUp->getKey())->where('status', 'pending')->update(['status' => 'sending', 'attempts' => 1]) !== 1) {
                return;
            }
            $message = ConversationMessage::query()->create(['conversation_id' => $conversation->getKey(), 'direction' => 'outgoing',
                'type' => 'template', 'body' => 'WhatsApp template: '.$settings['follow_up_template'], 'generated_by' => 'automation',
                'delivery_status' => 'sending', 'sent_at' => now(), 'ai_meta' => ['follow_up_id' => $followUp->getKey()]]);
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

                return;
            }
            $message->update(['delivery_status' => 'sent', 'external_message_id' => $externalId]);
            $followUp->update(['status' => 'sent', 'sent_at' => now(), 'reason' => null]);
            $conversation->update(['last_message_at' => now()]);
        } finally {
            $lock->release();
        }
    }

    private function suppressionReason(CrmSalesFollowUp $followUp, Conversation $conversation, array $settings): ?string
    {
        if (! $settings['sales_follow_ups_enabled'] || ! $conversation->ai_enabled || $conversation->status !== 'open' || $conversation->human_handled_until?->isFuture()) {
            return 'Automation disabled or staff owns the conversation.';
        }
        if (app(ContactSuppressionService::class)->suppressed((int) $conversation->company_id, (string) $conversation->contact_phone)) {
            return 'Contact opted out or has no deliverable phone.';
        }
        if ($conversation->messages()->where('direction', 'incoming')->where('id', '>', $followUp->inbound_watermark ?? 0)->exists()) {
            return 'Customer replied after the follow-up was scheduled.';
        }
        if ($followUp->chat_order_link_id) {
            $link = ChatOrderLink::query()->find($followUp->chat_order_link_id);
            if (! $link?->isUsable()) {
                return 'Checkout completed or link expired.';
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
        if (CrmSalesFollowUp::query()->where('conversation_id', $conversation->getKey())->where('id', '!=', $followUp->getKey())->where('sent_at', '>', now()->subDay())->exists()) {
            return 'One sales follow-up per conversation per day.';
        }
        if ($conversation->provider !== 'whatsapp' || ! $conversation->channel?->is_active
            || (int) $conversation->channel->company_id !== (int) $conversation->company_id
            || blank($conversation->channel->access_token) || blank($settings['follow_up_template'])) {
            return 'An active company WhatsApp channel and approved template are required.';
        }

        return null;
    }
}
