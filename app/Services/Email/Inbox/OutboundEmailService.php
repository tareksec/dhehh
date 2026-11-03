<?php

namespace App\Services\Email\Inbox;

use App\Filament\Pages\EmailInbox;
use App\Mail\ComposedEmail;
use App\Models\Company;
use App\Models\EmailAttachment;
use App\Models\EmailMailbox;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\BusinessNotificationService;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\StorefrontNotificationService;
use App\Support\EmailAddressList;
use App\Support\EmailText;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything a staff member does to write and send email in the Email inbox (owner request 2026-11-02): start a new
 * message, a reply, a reply to all or a forward; keep the draft (the text is cleaned, the files are stored on the
 * company's private storage); send it. A sent message waits out the mailbox's undo window (10 seconds by default)
 * first and can be taken back until then; it goes out through the site's own SMTP account
 * (StorefrontNotificationService::sendComposed), one Email Log row per recipient. Whoever asks first claims a due
 * message (one conditional update), so the page's timer and the scheduled `email:send-due` never send it twice.
 * Internal notes are kept here too: they are never sent. Everything works inside the current company only.
 */
class OutboundEmailService
{
    /** The longest a message may wait in "sending" before it is treated as interrupted, in minutes. */
    public const SENDING_TIMEOUT_MINUTES = 10;

    /** The longest undo window a mailbox may set, in seconds. */
    public const MAX_UNDO_SECONDS = 60;

    public function __construct(
        protected EmailSanitizer $sanitizer,
        protected EmailThreadStats $stats,
        protected CompanyStorageService $storage,
        protected CompanyContext $context,
        protected StorefrontNotificationService $notifications,
        protected EmailFiles $files,
    ) {}

    // --- Starting a message ---------------------------------------------------------------------------

    /** A blank draft (with the mailbox's signature) in a conversation of its own. */
    public function startNew(EmailMailbox $mailbox, User $user): EmailMessage
    {
        $this->guard($mailbox);

        $thread = EmailThread::query()->create([
            'company_id' => $mailbox->company_id,
            'mailbox_id' => $mailbox->getKey(),
            'subject' => '',
            'folder' => EmailThread::FOLDER_ARCHIVED,
            'status' => EmailThread::STATUS_OPEN,
        ]);

        return $this->makeDraft($mailbox, $thread, $user, [
            'body_html' => $this->signatureBlock($mailbox),
        ]);
    }

    /** A reply (or a reply to all) to one message of a conversation. The draft already exists when the person comes back to it. */
    public function startReply(EmailMessage $original, User $user, bool $all = false): EmailMessage
    {
        $this->guard($original);
        $mailbox = $original->mailbox()->firstOrFail();

        $existing = EmailMessage::query()
            ->where('thread_id', $original->thread_id)
            ->where('kind', EmailMessage::KIND_DRAFT)
            ->where('user_id', $user->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $own = mb_strtolower($mailbox->address);
        $notMine = fn (array $list): array => array_values(array_filter($list, fn (array $entry): bool => $entry['address'] !== $own));

        if ($original->isInbound()) {
            $to = $original->reply_to_addresses ?: [['address' => (string) $original->from_address, 'name' => $original->from_name]];
            $cc = [];

            if ($all) {
                $to = [...$to, ...$notMine((array) $original->to_addresses)];
                $cc = $notMine((array) $original->cc_addresses);
            }
        } else {
            // Answering one of our own messages goes to the people it was sent to.
            $to = (array) $original->to_addresses;
            $cc = $all ? (array) $original->cc_addresses : [];
        }

        $to = EmailAddressList::parse($to)['valid'];
        $cc = array_values(array_filter(EmailAddressList::parse($cc)['valid'], fn (array $entry): bool => ! in_array($entry['address'], EmailAddressList::addresses($to), true)));

        return $this->makeDraft($mailbox, $original->thread()->firstOrFail(), $user, [
            'to_addresses' => $to,
            'cc_addresses' => $cc === [] ? null : $cc,
            'subject' => $this->prefixed('Re', (string) $original->subject),
            'body_html' => ($mailbox->setting('signature_on_replies') ? $this->signatureBlock($mailbox) : '<p></p>').$this->quote($original),
            'in_reply_to' => $original->message_id,
            'references' => trim(implode(' ', array_slice(array_filter([...preg_split('/\s+/', (string) $original->references, -1, PREG_SPLIT_NO_EMPTY), $original->message_id]), -20))) ?: null,
        ]);
    }

    /** A forward: a conversation of its own, the original's text below a header block, its files copied. */
    public function startForward(EmailMessage $original, User $user): EmailMessage
    {
        $this->guard($original);
        $mailbox = $original->mailbox()->firstOrFail();

        $thread = EmailThread::query()->create([
            'company_id' => $mailbox->company_id,
            'mailbox_id' => $mailbox->getKey(),
            'subject' => '',
            'folder' => EmailThread::FOLDER_ARCHIVED,
            'status' => EmailThread::STATUS_OPEN,
        ]);

        $draft = $this->makeDraft($mailbox, $thread, $user, [
            'subject' => $this->prefixed('Fwd', (string) $original->subject),
            'body_html' => $this->signatureBlock($mailbox).$this->forwardedBlock($original),
        ]);

        foreach ($original->attachments()->where('is_inline', false)->get() as $file) {
            $content = $this->storage->readPrivate($file->path, $this->company());

            if ($content !== null && strlen($content) + $this->attachedBytes($draft) <= EmailAttachment::MAX_TOTAL_BYTES) {
                $this->addAttachment($draft, $file->filename, $file->mime, $content);
            }
        }

        return $this->syncDraftThread($draft->fresh());
    }

    // --- The draft ------------------------------------------------------------------------------------

    /**
     * Saves what is typed in the compose window: To / Cc / Bcc (text or lists), the subject and the body. The body is
     * cleaned; an address that is not valid is not saved and is returned, so the person can fix it.
     *
     * @param  array{to?: string|array<int, mixed>|null, cc?: string|array<int, mixed>|null, bcc?: string|array<int, mixed>|null, subject?: ?string, body_html?: ?string}  $fields
     * @return array{draft: EmailMessage, invalid: array<int, string>}
     */
    public function saveDraft(EmailMessage $draft, array $fields): array
    {
        $this->guardDraft($draft);

        $invalid = [];
        $values = [];

        foreach (['to' => 'to_addresses', 'cc' => 'cc_addresses', 'bcc' => 'bcc_addresses'] as $key => $column) {
            if (! array_key_exists($key, $fields)) {
                continue;
            }

            $parsed = EmailAddressList::parse($fields[$key]);
            $invalid = [...$invalid, ...$parsed['invalid']];
            $values[$column] = $parsed['valid'] === [] ? null : $parsed['valid'];
        }

        if (array_key_exists('subject', $fields)) {
            $values['subject'] = mb_substr(trim((string) preg_replace('/[\r\n\t ]+/u', ' ', (string) $fields['subject'])), 0, 255);
        }

        if (array_key_exists('body_html', $fields)) {
            $html = $this->sanitizer->clean((string) $fields['body_html']);
            $values['body_html'] = $html;
            $values['body_text'] = EmailText::fromHtml($html);
        }

        $draft->forceFill($values)->save();

        return ['draft' => $this->syncDraftThread($draft), 'invalid' => $invalid];
    }

    public function addAttachment(EmailMessage $draft, string $filename, string $mime, string $content): EmailAttachment
    {
        $this->guardDraft($draft);

        if ($this->attachedBytes($draft) + strlen($content) > EmailAttachment::MAX_TOTAL_BYTES) {
            throw new EmailSendException(__('The files are too big: one message can carry :size MB in all.', ['size' => (int) (EmailAttachment::MAX_TOTAL_BYTES / 1024 / 1024)]));
        }

        $name = mb_substr(trim(basename(str_replace('\\', '/', $filename))), 0, 255) ?: 'attachment';
        $path = $this->storage->putPrivate($this->company(), 'email-attachments', Str::uuid()->toString().'.bin', $content);

        return EmailAttachment::query()->create([
            'company_id' => $draft->company_id,
            'message_id' => $draft->getKey(),
            'filename' => $name,
            'mime' => mb_strtolower(mb_substr($mime !== '' ? $mime : 'application/octet-stream', 0, 120)),
            'size' => strlen($content),
            'path' => $path,
            'is_inline' => false,
        ]);
    }

    public function removeAttachment(EmailAttachment $attachment): void
    {
        $this->guard($attachment);
        $draft = $attachment->message()->firstOrFail();
        $this->guardDraft($draft);

        $this->deleteFile($attachment->path);
        $attachment->delete();
    }

    /** Throws the draft away with its files; a conversation that was only this draft goes too. */
    public function discardDraft(EmailMessage $draft): void
    {
        $this->guardDraft($draft);
        $thread = $draft->thread()->firstOrFail();

        $this->deleteFiles($draft);
        $draft->delete();

        if (! EmailMessage::query()->where('thread_id', $thread->getKey())->exists()) {
            $thread->delete();
        } else {
            $this->stats->refresh($thread);
        }
    }

    // --- Sending --------------------------------------------------------------------------------------

    /**
     * Sends the draft: it becomes a message that waits out the mailbox's undo window (none when that is 0, then it goes
     * at once). Refused, with the reason, when nobody is addressed, too many are, or there is nothing to send.
     */
    public function queue(EmailMessage $draft, User $user, ?CarbonInterface $at = null): EmailMessage
    {
        $this->guardDraft($draft);
        $mailbox = $draft->mailbox()->firstOrFail();

        if (! $mailbox->is_active) {
            throw new EmailSendException(__('This mailbox is switched off.'));
        }

        $recipients = [...(array) $draft->to_addresses, ...(array) $draft->cc_addresses, ...(array) $draft->bcc_addresses];

        if ($recipients === []) {
            throw new EmailSendException(__('Add at least one person to send to.'));
        }

        if (count(array_unique(EmailAddressList::addresses($recipients))) > EmailAddressList::MAX_RECIPIENTS) {
            throw new EmailSendException(__('One message can go to :count people at most.', ['count' => EmailAddressList::MAX_RECIPIENTS]));
        }

        if (trim((string) $draft->body_text) === '' && ! $draft->attachments()->exists()) {
            throw new EmailSendException(__('Write something or attach a file before sending.'));
        }

        // Stored in the app's time zone, like every other time in the database.
        $at = $at?->copy()->timezone(config('app.timezone'));

        if ($at !== null && ($at->lt(now()->addMinute()) || $at->gt(now()->addYear()))) {
            throw new EmailSendException(__('Pick a time that is still ahead (within a year).'));
        }

        $undo = $at !== null ? 0 : max(0, min(self::MAX_UNDO_SECONDS, (int) $mailbox->setting('undo_send_seconds')));

        $draft->forceFill([
            'kind' => EmailMessage::KIND_MESSAGE,
            'status' => $at !== null ? EmailMessage::STATUS_SCHEDULED : EmailMessage::STATUS_PENDING,
            'direction' => EmailMessage::OUT,
            'message_id' => Str::uuid()->toString().'@'.$this->domainOf($mailbox->address),
            'user_id' => $user->getKey(),
            'is_read' => true,
            'sent_at' => $at ?? now(),
            'send_after' => $at ?? now()->addSeconds($undo),
            'error' => null,
        ])->save();

        $thread = $draft->thread()->firstOrFail();

        if ($thread->contact_email === null) {
            $first = ((array) $draft->to_addresses)[0] ?? null;
            $thread->forceFill(['contact_email' => $first['address'] ?? null, 'contact_name' => $first['name'] ?? null]);
        }

        if (trim((string) $thread->subject) === '') {
            $thread->subject = (string) $draft->subject;
        }

        $this->stats->refresh($thread);

        if ($at === null && $undo === 0) {
            $this->deliver($draft);
        }

        return $draft->fresh();
    }

    /** Takes a scheduled message back before its time: it becomes a draft again, for its author. */
    public function cancelScheduled(EmailMessage $message): bool
    {
        $this->guard($message);

        $taken = EmailMessage::query()
            ->whereKey($message->getKey())
            ->where('status', EmailMessage::STATUS_SCHEDULED)
            ->where('send_after', '>', now())
            ->update(['kind' => EmailMessage::KIND_DRAFT, 'status' => EmailMessage::STATUS_DRAFT, 'send_after' => null, 'message_id' => null]);

        if ($taken === 0) {
            return false;
        }

        $this->stats->refresh($message->thread()->firstOrFail());
        $this->syncDraftThread($message->fresh());

        return true;
    }

    /** Sends a scheduled message now instead of at its time. */
    public function sendScheduledNow(EmailMessage $message): bool
    {
        $this->guard($message);

        $moved = EmailMessage::query()
            ->whereKey($message->getKey())
            ->where('status', EmailMessage::STATUS_SCHEDULED)
            ->update(['status' => EmailMessage::STATUS_PENDING, 'send_after' => now(), 'sent_at' => now()]);

        return $moved > 0 && $this->deliver($message->fresh());
    }

    /** Takes a message back while it still waits out the undo window: it becomes a draft again. */
    public function undo(EmailMessage $message): bool
    {
        $this->guard($message);

        $taken = EmailMessage::query()
            ->whereKey($message->getKey())
            ->where('status', EmailMessage::STATUS_PENDING)
            ->where('send_after', '>', now())
            ->update(['kind' => EmailMessage::KIND_DRAFT, 'status' => EmailMessage::STATUS_DRAFT, 'send_after' => null, 'message_id' => null]);

        if ($taken === 0) {
            return false;
        }

        $this->stats->refresh($message->thread()->firstOrFail());
        $this->syncDraftThread($message->fresh());

        return true;
    }

    /** A message that failed goes again. */
    public function retry(EmailMessage $message): bool
    {
        $this->guard($message);

        $moved = EmailMessage::query()
            ->whereKey($message->getKey())
            ->where('status', EmailMessage::STATUS_FAILED)
            ->update(['status' => EmailMessage::STATUS_PENDING, 'send_after' => now(), 'error' => null]);

        return $moved > 0 && $this->deliver($message->fresh());
    }

    /**
     * Hands one due message to the mail server. Returns whether it went. A message someone else already claimed, or
     * one that is not due yet, is left alone.
     */
    public function deliver(EmailMessage $message): bool
    {
        $this->guard($message);

        $claimed = EmailMessage::query()
            ->whereKey($message->getKey())
            ->whereIn('status', [EmailMessage::STATUS_PENDING, EmailMessage::STATUS_SCHEDULED])
            ->where('send_after', '<=', now())
            ->update(['status' => EmailMessage::STATUS_SENDING, 'updated_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        $message = $message->fresh();
        $mailbox = $message->mailbox()->firstOrFail();
        $setting = $this->settingFor($mailbox);

        if (! $mailbox->is_active) {
            return $this->finish($message, false, __('This mailbox is switched off.'), null);
        }

        if ($setting === null) {
            return $this->finish($message, false, __('This company has no site yet, so there is no email account to send through.'), null);
        }

        $company = $this->company();
        $files = $message->attachments()->get()->map(fn (EmailAttachment $file): ?array => ($content = $this->storage->readPrivate($file->path, $company)) === null
            ? null
            : ['name' => $file->filename, 'mime' => $file->mime, 'content' => $content])->filter()->values()->all();

        $mail = new ComposedEmail(
            fromAddress: $mailbox->address,
            fromName: $mailbox->name,
            mailSubject: (string) $message->subject,
            htmlBody: (string) $message->body_html,
            textBody: (string) $message->body_text,
            messageId: (string) $message->message_id,
            inReplyTo: $message->in_reply_to,
            references: array_values(array_filter(preg_split('/\s+/', (string) $message->references, -1, PREG_SPLIT_NO_EMPTY) ?: [])),
            files: $files,
        );

        $result = $this->notifications->sendComposed(
            $setting,
            $mail,
            EmailAddressList::addresses((array) $message->to_addresses),
            EmailAddressList::addresses((array) $message->cc_addresses),
            EmailAddressList::addresses((array) $message->bcc_addresses),
        );

        return $this->finish($message, $result['ok'], $result['error'], $result['provider_message_id']);
    }

    /**
     * Sends every message of the current company whose undo window is over (the page's timer and `email:send-due`
     * both call this), and marks the ones stuck in "sending" as failed. Returns how many went.
     */
    public function deliverDue(int $limit = 50): int
    {
        EmailMessage::query()
            ->where('status', EmailMessage::STATUS_SENDING)
            ->where('updated_at', '<', now()->subMinutes(self::SENDING_TIMEOUT_MINUTES))
            ->get()
            ->each(fn (EmailMessage $stuck) => $this->finish($stuck, false, __('The sending was interrupted. Check the Email Log, then send it again.'), null));

        $sent = 0;

        foreach (EmailMessage::query()
            ->whereIn('status', [EmailMessage::STATUS_PENDING, EmailMessage::STATUS_SCHEDULED])
            ->where('send_after', '<=', now())
            ->orderBy('send_after')
            ->limit($limit)
            ->get() as $message) {
            $wasScheduled = $message->status === EmailMessage::STATUS_SCHEDULED;

            if ($this->deliver($message)) {
                $sent++;
            } elseif ($wasScheduled && $message->fresh()?->status === EmailMessage::STATUS_FAILED) {
                $this->tellAuthorItFailed($message->fresh());
            }
        }

        return $sent;
    }

    /** A message set to go later failed with nobody watching: the person who wrote it gets a bell message. */
    protected function tellAuthorItFailed(EmailMessage $message): void
    {
        try {
            $author = $message->user_id === null ? null : User::query()->find($message->user_id);

            if ($author === null) {
                return;
            }

            $url = EmailInbox::getUrl(['mailbox' => $message->mailbox_id, 'thread' => $message->thread_id], panel: 'admin');
            app(BusinessNotificationService::class)->notifyUser(
                $author,
                'email.scheduled_failed',
                __('A scheduled email was not sent'),
                trim((string) $message->subject) !== '' ? (string) $message->subject.' — '.(string) $message->error : (string) $message->error,
                ['thread_id' => (string) $message->thread_id],
                $url,
                __('Open email'),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    // --- Notes ----------------------------------------------------------------------------------------

    /** An internal note on a conversation: staff see it, it is never sent, and it does not count as a message. */
    public function addNote(EmailThread $thread, User $user, string $text): EmailMessage
    {
        $this->guard($thread);

        $text = trim($text);

        if ($text === '') {
            throw new EmailSendException(__('Write the note first.'));
        }

        $note = EmailMessage::query()->create([
            'company_id' => $thread->company_id,
            'thread_id' => $thread->getKey(),
            'mailbox_id' => $thread->mailbox_id,
            'direction' => EmailMessage::OUT,
            'kind' => EmailMessage::KIND_NOTE,
            'status' => EmailMessage::STATUS_NOTE,
            'from_address' => $user->email,
            'from_name' => $user->name,
            'subject' => (string) $thread->subject,
            'body_text' => mb_substr($text, 0, 5000),
            'is_read' => true,
            'sent_at' => now(),
            'user_id' => $user->getKey(),
        ]);

        $thread->touch();

        return $note;
    }

    // --- Helpers --------------------------------------------------------------------------------------

    protected function finish(EmailMessage $message, bool $ok, ?string $error, ?string $providerId): bool
    {
        $message->forceFill([
            'status' => $ok ? EmailMessage::STATUS_SENT : EmailMessage::STATUS_FAILED,
            'sent_at' => $ok ? now() : $message->sent_at,
            'send_after' => null,
            'error' => $ok ? null : mb_substr((string) $error, 0, 500),
            'provider_message_id' => $providerId,
        ])->save();

        $this->stats->refresh($message->thread()->firstOrFail());

        return $ok;
    }

    /** @param  array<string, mixed>  $values */
    protected function makeDraft(EmailMailbox $mailbox, EmailThread $thread, User $user, array $values): EmailMessage
    {
        if (isset($values['body_html'])) {
            $values['body_html'] = $this->sanitizer->clean((string) $values['body_html']);
            $values['body_text'] = EmailText::fromHtml($values['body_html']);
        }

        $draft = EmailMessage::query()->create([
            'company_id' => $mailbox->company_id,
            'thread_id' => $thread->getKey(),
            'mailbox_id' => $mailbox->getKey(),
            'direction' => EmailMessage::OUT,
            'kind' => EmailMessage::KIND_DRAFT,
            'status' => EmailMessage::STATUS_DRAFT,
            'from_address' => $mailbox->address,
            'from_name' => $mailbox->name,
            'subject' => '',
            'is_read' => true,
            'user_id' => $user->getKey(),
            ...$values,
        ]);

        return $this->syncDraftThread($draft);
    }

    /** A conversation that holds only a draft shows the draft's subject and text in the Drafts list. */
    protected function syncDraftThread(EmailMessage $draft): EmailMessage
    {
        $thread = $draft->thread()->firstOrFail();

        if ($thread->message_count === 0) {
            $thread->forceFill([
                'subject' => (string) $draft->subject,
                'snippet' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $draft->body_text)), 0, 200) ?: null,
                'contact_email' => ((array) $draft->to_addresses)[0]['address'] ?? null,
                'contact_name' => ((array) $draft->to_addresses)[0]['name'] ?? null,
            ])->save();
        }

        return $draft;
    }

    protected function signatureBlock(EmailMailbox $mailbox): string
    {
        $signature = trim((string) $mailbox->signature);

        return '<p></p>'.($signature === '' ? '' : '<div class="signature">-- <br>'.EmailText::toHtml($signature).'</div>');
    }

    /** "On Mon, Nov 2, 2026 at 10:42 AM, Rahim <r@x.test> wrote:" and the original in a quote. */
    protected function quote(EmailMessage $original): string
    {
        $who = EmailAddressList::label(['address' => (string) $original->from_address, 'name' => $original->from_name]);
        $date = ($original->sent_at ?? $original->created_at)?->translatedFormat('D, M j, Y \a\t g:i A');
        $body = filled($original->body_html) ? (string) $original->body_html : EmailText::toHtml((string) $original->body_text);

        return '<p></p><div class="quote"><p>'.e(__('On :date, :who wrote:', ['date' => $date, 'who' => $who])).'</p><blockquote>'.$body.'</blockquote></div>';
    }

    protected function forwardedBlock(EmailMessage $original): string
    {
        $line = fn (string $label, string $value): string => '<br>'.e($label).': '.e($value);
        $to = implode(', ', array_map(fn (array $entry): string => EmailAddressList::label($entry), (array) $original->to_addresses));
        $date = ($original->sent_at ?? $original->created_at)?->translatedFormat('D, M j, Y \a\t g:i A');
        $body = filled($original->body_html) ? (string) $original->body_html : EmailText::toHtml((string) $original->body_text);

        return '<p></p><div class="forwarded"><p>---------- '.e(__('Forwarded message')).' ---------'
            .$line(__('From'), EmailAddressList::label(['address' => (string) $original->from_address, 'name' => $original->from_name]))
            .$line(__('Date'), (string) $date)
            .$line(__('Subject'), (string) $original->subject)
            .$line(__('To'), $to).'</p>'.$body.'</div>';
    }

    /** "Re: Subject" once, however many Re: / Fwd: the subject already starts with. */
    protected function prefixed(string $prefix, string $subject): string
    {
        $subject = trim($subject);

        return preg_match('/^'.preg_quote($prefix, '/').'\s*:/iu', $subject) === 1 ? $subject : $prefix.': '.$subject;
    }

    protected function attachedBytes(EmailMessage $draft): int
    {
        return (int) $draft->attachments()->sum('size');
    }

    protected function domainOf(string $address): string
    {
        return Str::after($address, '@') ?: 'localhost';
    }

    /** The site whose SMTP account sends this mailbox's mail: its own site, else the company's main one. */
    protected function settingFor(EmailMailbox $mailbox): ?StorefrontSetting
    {
        return $mailbox->storefront_setting_id
            ? StorefrontSetting::withoutGlobalScopes()->where('company_id', $mailbox->company_id)->find($mailbox->storefront_setting_id)
            : StorefrontSetting::primaryFor((int) $mailbox->company_id);
    }

    protected function deleteFiles(EmailMessage $message): void
    {
        $this->files->deleteAll($message->attachments()->get(), $this->company());
    }

    protected function deleteFile(string $path): void
    {
        $this->files->delete($path, $this->company());
    }

    protected function company(): Company
    {
        $company = $this->context->company();

        if ($company === null) {
            throw new AuthorizationException('No company is selected.');
        }

        return $company;
    }

    /** Refuses a record of another company, whatever the caller thinks it holds. */
    protected function guard(Model $model): void
    {
        if ((int) $model->getAttribute('company_id') !== (int) $this->company()->getKey()) {
            throw new AuthorizationException('That record belongs to another company.');
        }
    }

    protected function guardDraft(EmailMessage $draft): void
    {
        $this->guard($draft);

        if ($draft->kind !== EmailMessage::KIND_DRAFT || $draft->status !== EmailMessage::STATUS_DRAFT) {
            throw new EmailSendException(__('This message is not a draft any more.'));
        }
    }
}
