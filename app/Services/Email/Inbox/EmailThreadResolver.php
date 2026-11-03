<?php

namespace App\Services\Email\Inbox;

use App\Models\EmailMailbox;
use App\Models\EmailMessage;
use App\Models\EmailThread;

/**
 * Decides which conversation a received message belongs to. First by the ids the sender's mail program put in
 * In-Reply-To / References (they name messages we already hold, sent or received); failing that, by the same
 * subject (without Re: / Fwd:) from the same person within two weeks; otherwise it starts a new conversation.
 */
class EmailThreadResolver
{
    /** How long a same-subject message from the same person still joins the earlier conversation. */
    public const SUBJECT_WINDOW_DAYS = 14;

    public function resolve(EmailMailbox $mailbox, ParsedEmail $email): ?EmailThread
    {
        $ids = array_values(array_filter([$email->inReplyTo, ...array_reverse($email->references)]));

        if ($ids !== []) {
            $threadId = EmailMessage::query()
                ->where('mailbox_id', $mailbox->getKey())
                ->whereIn('message_id', $ids)
                ->orderByDesc('id')
                ->value('thread_id');

            if ($threadId !== null) {
                return EmailThread::query()->where('mailbox_id', $mailbox->getKey())->find($threadId);
            }
        }

        $subject = self::normalizeSubject($email->subject);

        if ($subject === '') {
            return null;
        }

        return EmailThread::query()
            ->where('mailbox_id', $mailbox->getKey())
            ->where('contact_email', $email->fromAddress)
            ->where('folder', '!=', EmailThread::FOLDER_TRASH)
            ->where('last_message_at', '>=', now()->subDays(self::SUBJECT_WINDOW_DAYS))
            ->orderByDesc('last_message_at')
            ->limit(20)
            ->get()
            ->first(fn (EmailThread $thread): bool => self::normalizeSubject((string) $thread->subject) === $subject);
    }

    /** The subject without any run of "Re:", "Fwd:" and the like, lower case, single spaces: what two replies share. */
    public static function normalizeSubject(string $subject): string
    {
        $subject = mb_strtolower(trim($subject));

        do {
            $before = $subject;
            $subject = trim((string) preg_replace('/^(re|fw|fwd|aw|sv|vs|wg)(\[\d+\])?\s*:\s*/iu', '', $subject));
        } while ($subject !== $before);

        return trim((string) preg_replace('/\s+/u', ' ', $subject));
    }
}
