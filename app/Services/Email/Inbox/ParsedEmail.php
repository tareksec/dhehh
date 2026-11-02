<?php

namespace App\Services\Email\Inbox;

use Carbon\CarbonImmutable;

/**
 * What the MIME parser read from one received message, in plain values: who wrote it and to whom, the ids that
 * tie it into a conversation, the subject, the text and the HTML, and the files.
 */
final class ParsedEmail
{
    /**
     * @param  array<int, array{address: string, name: ?string}>  $to
     * @param  array<int, array{address: string, name: ?string}>  $cc
     * @param  array<int, string>  $references  Message-IDs without the angle brackets, oldest first
     * @param  array<int, ParsedAttachment>  $attachments
     */
    public function __construct(
        public readonly string $messageId,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly string $fromAddress,
        public readonly ?string $fromName,
        public readonly array $to,
        public readonly array $cc,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html,
        public readonly CarbonImmutable $date,
        public readonly array $attachments,
        public readonly int $rawSize,
    ) {}
}
