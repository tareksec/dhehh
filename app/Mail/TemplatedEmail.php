<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One email written on Settings → Email Templates (see
 * App\Services\EmailTemplateService): the subject and body the owner wrote,
 * already filled with the order's or the person's values, in the company's
 * branded layout, with a plain-text version. A link becomes a button; an IMS
 * statement or report rides along as a PDF.
 */
class TemplatedEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $htmlBody  escaped HTML paragraphs (EmailTemplateService::toHtml)
     * @param  array{name: string, logo: ?string, color: string, foreground: string, lines: list<string>}  $brand
     */
    public function __construct(
        public string $mailSubject,
        public string $htmlBody,
        public string $textBody,
        public array $brand,
        public ?string $link = null,
        public ?string $pdfName = null,
        public ?string $pdfBase64 = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(html: 'emails.branded', text: 'emails.templated-text');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        if ($this->pdfName === null || $this->pdfBase64 === null) {
            return [];
        }

        return [Attachment::fromData(fn (): string => (string) base64_decode($this->pdfBase64, true), $this->pdfName)->withMime('application/pdf')];
    }
}
