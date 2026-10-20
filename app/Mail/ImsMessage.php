<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One IMS v2 e-mail to an investor (IMS-V2/TRD.md §8): a subject, a few
 * lines, an optional link and an optional PDF (the monthly statement, a
 * project report). Already written in the investor's language.
 */
class ImsMessage extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public string $companyName,
        public string $heading,
        public array $lines,
        public ?string $link = null,
        public ?string $pdfName = null,
        public ?string $pdfBase64 = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->companyName} — {$this->heading}");
    }

    public function content(): Content
    {
        return new Content(text: 'emails.ims-message');
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
