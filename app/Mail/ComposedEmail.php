<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Symfony\Component\Mime\Email;

/**
 * A message a staff member wrote in the Email inbox (owner request 2026-11-02), sent exactly as written: from the
 * mailbox's own address, with the cleaned HTML and its plain-text twin, the files, and the headers that make the
 * reply sit under the customer's message in their mail program (Message-ID, In-Reply-To, References). The recipients
 * are not part of it; StorefrontNotificationService::sendComposed() sets To, Cc and Bcc.
 */
class ComposedEmail extends Mailable
{
    /**
     * @param  list<string>  $references  Message-IDs, oldest first
     * @param  list<array{name: string, mime: string, content: string}>  $files
     */
    public function __construct(
        public string $fromAddress,
        public ?string $fromName,
        public string $mailSubject,
        public string $htmlBody,
        public string $textBody,
        public string $messageId,
        public ?string $inReplyTo = null,
        public array $references = [],
        public array $files = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: new Address($this->fromAddress, $this->fromName), subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->htmlBody);
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->messageId,
            references: $this->references,
            text: $this->inReplyTo === null ? [] : ['In-Reply-To' => '<'.trim($this->inReplyTo, '<>').'>'],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return array_map(
            fn (array $file): Attachment => Attachment::fromData(fn (): string => $file['content'], $file['name'])->withMime($file['mime']),
            $this->files,
        );
    }

    /** Sets the plain-text twin next to the HTML once the message is built. */
    public function build(): static
    {
        return $this->withSymfonyMessage(fn (Email $message) => $message->text($this->textBody));
    }
}
