<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The link staff send a reseller so they can choose a new portal password. */
class ResellerPasswordResetLink extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $companyName,
        public string $url,
        public int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':company reseller password reset', ['company' => $this->companyName]));
    }

    public function content(): Content
    {
        return new Content(text: 'emails.reseller-password-reset');
    }
}
