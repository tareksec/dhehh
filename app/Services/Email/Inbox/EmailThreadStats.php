<?php

namespace App\Services\Email\Inbox;

use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Support\EmailText;

/**
 * Works a conversation's counters out from its messages, so they are right after anything that changes the messages
 * (a send, an undo, a discarded draft, a deleted message) instead of being nudged up and down by hand. Drafts, internal
 * notes and a message that is still waiting out its undo window do not count as messages of the conversation.
 */
class EmailThreadStats
{
    /** The statuses that count: received, sent, and the ones on their way out. */
    protected const COUNTED = [EmailMessage::STATUS_RECEIVED, EmailMessage::STATUS_PENDING, EmailMessage::STATUS_SENDING, EmailMessage::STATUS_SENT, EmailMessage::STATUS_FAILED];

    public function refresh(EmailThread $thread): EmailThread
    {
        $messages = EmailMessage::query()
            ->where('thread_id', $thread->getKey())
            ->where('kind', EmailMessage::KIND_MESSAGE)
            ->whereIn('status', self::COUNTED)
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get(['id', 'direction', 'status', 'is_read', 'sent_at', 'body_text', 'body_html']);

        $last = $messages->last();
        $inbound = $messages->where('direction', EmailMessage::IN);
        $outbound = $messages->where('direction', EmailMessage::OUT)->where('status', '!=', EmailMessage::STATUS_FAILED);

        $thread->forceFill([
            'message_count' => $messages->count(),
            'unread_count' => $inbound->where('is_read', false)->count(),
            'last_message_at' => $last?->sent_at,
            'last_inbound_at' => $inbound->last()?->sent_at,
            'last_outbound_at' => $outbound->last()?->sent_at,
            'snippet' => $last === null ? null : $this->snippet($last),
            'has_attachments' => EmailAttachment::query()
                ->whereIn('message_id', $messages->pluck('id'))
                ->where('is_inline', false)
                ->exists(),
        ])->save();

        return $thread;
    }

    protected function snippet(EmailMessage $message): string
    {
        $text = filled($message->body_text) ? (string) $message->body_text : EmailText::fromHtml((string) $message->body_html);

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, 200);
    }
}
