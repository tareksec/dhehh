<?php

namespace Tests\Feature\Concerns;

use App\Models\Company;
use App\Models\Customer;
use App\Models\EmailAttachment;
use App\Models\EmailMailbox;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\Email\Inbox\EmailThreadStats;

/**
 * Builds Email inbox data for tests (owner request 2026-11-02): companies, mailboxes with their mail account, and
 * conversations made of received, sent, draft and note messages, their files, with the counters worked out the way
 * the app does. Expects `$this->company`, `$this->mailbox` and `$this->user` to be set by the test.
 */
trait BuildsEmailInbox
{
    /** A conversation with one received message. */
    protected function received(
        string $subject,
        string $from = 'rahim@x.test',
        ?string $name = 'Rahim',
        string $text = 'Hello',
        ?string $html = null,
        string $folder = EmailThread::FOLDER_INBOX,
        bool $starred = false,
        bool $read = false,
        int $files = 0,
        ?int $assignedTo = null,
        string $status = EmailThread::STATUS_OPEN,
        ?Customer $customer = null,
        array $labels = [],
        ?Company $company = null,
        ?EmailMailbox $mailbox = null,
        ?string $messageId = null,
        ?\DateTimeInterface $when = null,
    ): EmailThread {
        $company ??= $this->company;
        $mailbox ??= $this->mailbox;
        $thread = EmailThread::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'mailbox_id' => $mailbox->getKey(),
            'subject' => $subject,
            'folder' => $folder,
            'is_starred' => $starred,
            'contact_email' => $from,
            'contact_name' => $name,
            'assigned_to' => $assignedTo,
            'status' => $status,
            'customer_id' => $customer?->getKey(),
        ]);

        $message = $this->message($thread, EmailMessage::IN, $text, from: $from, name: $name, read: $read, html: $html, when: $when, messageId: $messageId ?? uniqid('m').'@x.test', company: $company, mailbox: $mailbox);

        for ($i = 0; $i < $files; $i++) {
            $this->attach($message, "file{$i}.pdf", 'PDF');
        }

        foreach ($labels as $label) {
            $thread->labels()->attach($label->getKey());
        }

        return $this->refreshed($thread);
    }

    protected function sentOnly(string $subject): EmailThread
    {
        $thread = EmailThread::query()->create(['mailbox_id' => $this->mailbox->getKey(), 'subject' => $subject, 'folder' => EmailThread::FOLDER_ARCHIVED, 'contact_email' => 'karim@y.test']);
        $this->message($thread, EmailMessage::OUT, 'Hello from us', from: 'info@shop.test', status: EmailMessage::STATUS_SENT, read: true);

        return $this->refreshed($thread);
    }

    protected function outgoing(EmailThread $thread, string $text): EmailMessage
    {
        $message = $this->message($thread, EmailMessage::OUT, $text, from: 'info@shop.test', status: EmailMessage::STATUS_SENT, read: true, when: now()->addMinute());
        $this->refreshed($thread);

        return $message;
    }

    protected function draftOnly(string $subject, User $user): EmailThread
    {
        $thread = EmailThread::query()->create(['mailbox_id' => $this->mailbox->getKey(), 'subject' => $subject, 'folder' => EmailThread::FOLDER_ARCHIVED]);
        $this->message($thread, EmailMessage::OUT, 'Draft text', from: 'info@shop.test', kind: EmailMessage::KIND_DRAFT, status: EmailMessage::STATUS_DRAFT, read: true, userId: $user->getKey());

        return $thread;
    }

    protected function message(
        EmailThread $thread,
        string $direction,
        string $text,
        string $from = 'rahim@x.test',
        ?string $name = null,
        string $kind = EmailMessage::KIND_MESSAGE,
        ?string $status = null,
        bool $read = false,
        ?string $html = null,
        ?\DateTimeInterface $when = null,
        ?string $messageId = null,
        ?string $error = null,
        ?int $userId = null,
        ?Company $company = null,
        ?EmailMailbox $mailbox = null,
    ): EmailMessage {
        $message = EmailMessage::withoutGlobalScopes()->create([
            'company_id' => ($company ?? $this->company)->getKey(),
            'thread_id' => $thread->getKey(),
            'mailbox_id' => ($mailbox ?? $this->mailbox)->getKey(),
            'direction' => $direction,
            'kind' => $kind,
            'status' => $status ?? ($direction === EmailMessage::IN ? EmailMessage::STATUS_RECEIVED : EmailMessage::STATUS_SENT),
            'message_id' => $messageId,
            'from_address' => $from,
            'from_name' => $name,
            'to_addresses' => [['address' => 'info@shop.test', 'name' => null]],
            'subject' => $thread->subject,
            'body_text' => $text,
            'body_html' => $html,
            'is_read' => $read,
            'sent_at' => $when ?? now()->subMinutes(5),
            'error' => $error,
            'user_id' => $userId,
        ]);

        if ($kind === EmailMessage::KIND_MESSAGE) {
            $this->stats($thread);
        }

        return $message;
    }

    protected function refreshed(EmailThread $thread): EmailThread
    {
        return $this->stats($thread);
    }

    /** A conversation's counters, worked out inside its own company. */
    protected function stats(EmailThread $thread): EmailThread
    {
        $thread = EmailThread::withoutGlobalScopes()->findOrFail($thread->getKey());

        return app(CompanyContext::class)->within(Company::query()->findOrFail($thread->company_id), fn () => app(EmailThreadStats::class)->refresh($thread));
    }

    protected function attach(EmailMessage $message, string $name, string $content, string $mime = 'application/pdf', ?string $contentId = null, bool $inline = false): EmailAttachment
    {
        $company = Company::query()->findOrFail($message->company_id);
        $path = app(CompanyStorageService::class)->putPrivate($company, 'email-attachments', $name, $content);

        $file = EmailAttachment::withoutGlobalScopes()->create([
            'company_id' => $message->company_id,
            'message_id' => $message->getKey(),
            'filename' => $name,
            'mime' => $mime,
            'size' => strlen($content),
            'path' => $path,
            'content_id' => $contentId,
            'is_inline' => $inline,
        ]);

        $this->stats(EmailThread::withoutGlobalScopes()->findOrFail($message->thread_id));

        return $file;
    }

    protected function makeCompany(string $name, string $slug): Company
    {
        return Company::query()->create(['name' => $name, 'slug' => $slug, 'invoice_prefix' => strtoupper(substr($slug, 0, 3)), 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true]);
    }

    protected function makeMailbox(Company $company, string $address, ?string $signature = null, ?string $name = null): EmailMailbox
    {
        return EmailMailbox::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'name' => $name ?? $address,
            'address' => $address,
            'receive_mode' => EmailMailbox::RECEIVE_WEBHOOK,
            'signature' => $signature,
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    protected function smtp(string $host = 'smtp.shop.test'): array
    {
        return ['mail_host' => $host, 'mail_port' => 587, 'mail_username' => 'user', 'mail_password' => 'secret', 'mail_encryption' => 'tls', 'mail_from_address' => 'info@shop.test'];
    }

    /** The company's site, with an email account to send through. */
    protected function makeSite(Company $company, string $host = 'smtp.shop.test'): StorefrontSetting
    {
        return StorefrontSetting::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'site_type' => StorefrontSetting::SITE_BLADE,
            'notification_credentials' => $this->smtp($host),
        ]);
    }
}
