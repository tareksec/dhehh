<?php

namespace App\Services\Email\Inbox;

use App\Filament\Pages\EmailInbox;
use App\Models\Company;
use App\Models\EmailAttachment;
use App\Models\EmailMailbox;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use App\Services\BusinessNotificationService;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Takes in one received message for a mailbox (owner request 2026-11-02): reads it, drops it when it is a message
 * the mailbox already holds (a mail delivered twice, or our own sent mail coming back by copy), joins it to its
 * conversation or starts one, keeps its cleaned text and HTML, stores its files on the company's private storage,
 * matches the sender to a customer or lead, and brings the conversation back to the Inbox when it had been put
 * away. Everything happens inside the mailbox's own company.
 */
class InboundEmailService
{
    /** The largest message taken in, in bytes (the web server's request limit is 16 MB). */
    public const MAX_BYTES = 15 * 1024 * 1024;

    public function __construct(
        protected MimeEmailParser $parser,
        protected EmailSanitizer $sanitizer,
        protected EmailThreadResolver $threads,
        protected EmailContactMatcher $contacts,
        protected CompanyStorageService $storage,
        protected CompanyContext $context,
        protected EmailRuleEngine $rules,
    ) {}

    /**
     * @throws \InvalidArgumentException when the text is not a readable message
     */
    public function receive(EmailMailbox $mailbox, string $raw, ?string $envelopeFrom = null): InboundEmailResult
    {
        $email = $this->parser->parse($raw, $envelopeFrom);
        $company = Company::query()->findOrFail($mailbox->company_id);

        return $this->context->within($company, function () use ($mailbox, $company, $email): InboundEmailResult {
            $held = EmailMessage::query()->where('mailbox_id', $mailbox->getKey())->where('message_id', $email->messageId)->first();

            if ($held !== null) {
                return new InboundEmailResult($held, true, false);
            }

            $stored = [];

            try {
                // The files first, so a message row never points at a file that was not written.
                foreach ($email->attachments as $attachment) {
                    $stored[] = [$attachment, $this->storage->putPrivate($company, 'email-attachments', Str::uuid()->toString().'.bin', $attachment->content)];
                }

                $result = DB::transaction(fn (): InboundEmailResult => $this->store($mailbox, $email, $stored));
                $this->applyRules($mailbox, $result);
                $this->alert($mailbox, $company, $result);

                return $result;
            } catch (UniqueConstraintViolationException) {
                // The same message arrived twice at the same moment: the first one won.
                $this->discardFiles($company, $stored);

                return new InboundEmailResult(
                    EmailMessage::query()->where('mailbox_id', $mailbox->getKey())->where('message_id', $email->messageId)->firstOrFail(),
                    true,
                    false,
                );
            } catch (Throwable $exception) {
                $this->discardFiles($company, $stored);

                throw $exception;
            }
        });
    }

    /** The mailbox's rules (label, assign, archive) on the message just kept; they can never fail the delivery. */
    protected function applyRules(EmailMailbox $mailbox, InboundEmailResult $result): void
    {
        $thread = EmailThread::query()->find($result->message->thread_id);

        if ($thread !== null) {
            $this->rules->apply($mailbox, $thread, (string) $result->message->from_address, $result->message->from_name, (string) $result->message->subject);
        }
    }

    /**
     * Tells staff a new message came in (the ERP bell and push), when the mailbox's "Alert staff" setting is on: the one it
     * is assigned to, else everyone who may answer CRM mail. Not for a duplicate, and not for spam or the Trash. The message
     * is already kept, so nothing here may fail the delivery.
     */
    protected function alert(EmailMailbox $mailbox, Company $company, InboundEmailResult $result): void
    {
        if ($result->duplicate || ! $mailbox->setting('notify')) {
            return;
        }

        try {
            $thread = EmailThread::query()->find($result->message->thread_id);

            if ($thread === null || $thread->folder !== EmailThread::FOLDER_INBOX) {
                return;
            }

            $message = $result->message;
            $title = __('New email from :name', ['name' => $message->from_name ?: $message->from_address]);
            $body = filled($message->subject) ? (string) $message->subject : __('(no subject)');
            $url = EmailInbox::getUrl(['mailbox' => $mailbox->getKey(), 'thread' => $thread->getKey()], panel: 'admin');
            $notifications = app(BusinessNotificationService::class);
            $assignee = $thread->assigned_to ? User::query()->find($thread->assigned_to) : null;

            if ($assignee !== null) {
                $notifications->notifyUser($assignee, 'email.received', $title, $body, ['thread_id' => (string) $thread->getKey()], $url, __('Open email'));
            } else {
                $notifications->notifyCompany($company, 'email.received', 'crm.manage', $title, $body, ['thread_id' => (string) $thread->getKey()], $url, __('Open email'));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @param  array<int, array{0: ParsedAttachment, 1: string}>  $stored */
    protected function store(EmailMailbox $mailbox, ParsedEmail $email, array $stored): InboundEmailResult
    {
        $thread = $this->threads->resolve($mailbox, $email);
        $newThread = $thread === null;
        $text = mb_substr($email->text, 0, 1_000_000);
        $snippet = $this->snippet($text);

        if ($thread === null) {
            $thread = EmailThread::query()->create([
                'company_id' => $mailbox->company_id,
                'mailbox_id' => $mailbox->getKey(),
                'subject' => $email->subject,
                'folder' => EmailThread::FOLDER_INBOX,
                'contact_email' => $email->fromAddress,
                'contact_name' => $email->fromName,
                'status' => EmailThread::STATUS_OPEN,
            ]);
        }

        $message = EmailMessage::query()->create([
            'company_id' => $mailbox->company_id,
            'thread_id' => $thread->getKey(),
            'mailbox_id' => $mailbox->getKey(),
            'direction' => EmailMessage::IN,
            'kind' => EmailMessage::KIND_MESSAGE,
            'status' => EmailMessage::STATUS_RECEIVED,
            'message_id' => $email->messageId,
            'in_reply_to' => $email->inReplyTo,
            'references' => $email->references === [] ? null : implode(' ', $email->references),
            'from_address' => $email->fromAddress,
            'from_name' => $email->fromName,
            'reply_to_addresses' => $email->replyTo === [] ? null : $email->replyTo,
            'to_addresses' => $email->to,
            'cc_addresses' => $email->cc === [] ? null : $email->cc,
            'subject' => $email->subject,
            'body_text' => $text,
            'body_html' => $email->html === null ? null : $this->sanitizer->clean($email->html),
            'is_read' => false,
            'sent_at' => $email->date,
            'raw_size' => $email->rawSize,
        ]);

        foreach ($stored as [$attachment, $path]) {
            EmailAttachment::query()->create([
                'company_id' => $mailbox->company_id,
                'message_id' => $message->getKey(),
                'filename' => $attachment->filename,
                'mime' => $attachment->mime,
                'size' => $attachment->size(),
                'path' => $path,
                'content_id' => $attachment->contentId,
                'is_inline' => $attachment->inline,
            ]);
        }

        $this->updateThread($mailbox, $thread, $email, $snippet, $stored !== []);

        $mailbox->forceFill(['last_received_at' => now()])->save();

        return new InboundEmailResult($message, false, $newThread);
    }

    protected function updateThread(EmailMailbox $mailbox, EmailThread $thread, ParsedEmail $email, string $snippet, bool $hasFiles): void
    {
        $thread->message_count = $thread->message_count + 1;
        $thread->unread_count = $thread->unread_count + 1;
        $thread->snippet = $snippet;
        $thread->last_message_at = $email->date->gt($thread->last_message_at ?? $email->date) ? $email->date : ($thread->last_message_at ?? $email->date);
        $thread->last_inbound_at = $email->date;
        $thread->has_attachments = $thread->has_attachments || $hasFiles;

        // A reply to a conversation that was archived or deleted brings it back; one marked as spam stays there.
        if (in_array($thread->folder, [EmailThread::FOLDER_ARCHIVED, EmailThread::FOLDER_TRASH], true)) {
            $thread->folder = EmailThread::FOLDER_INBOX;
            $thread->trashed_at = null;
        }

        // A snoozed conversation that gets a new message is back in the Inbox at once, as in Gmail.
        $thread->snoozed_until = null;
        $thread->snoozed_by = null;

        // A customer who writes again after the staff closed the conversation reopens it.
        if ($thread->status === EmailThread::STATUS_CLOSED) {
            $thread->status = EmailThread::STATUS_OPEN;
        }

        if ($thread->customer_id === null && $thread->lead_id === null && $mailbox->setting('match_customers')) {
            $match = $this->contacts->match((int) $mailbox->company_id, (string) $thread->contact_email);
            $thread->customer_id = $match['customer_id'];
            $thread->lead_id = $match['lead_id'];
        }

        $thread->save();
    }

    protected function snippet(string $text): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, 200);
    }

    /** @param  array<int, array{0: ParsedAttachment, 1: string}>  $stored */
    protected function discardFiles(Company $company, array $stored): void
    {
        foreach ($stored as [, $path]) {
            try {
                $location = $this->storage->locatePrivate($path, $company);

                if ($location !== null) {
                    Storage::disk($location['disk'])->delete($location['path']);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
