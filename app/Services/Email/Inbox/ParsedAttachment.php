<?php

namespace App\Services\Email\Inbox;

/** A file found in a received message. The bytes are held in memory; the message is capped at 15 MB. */
final class ParsedAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $mime,
        public readonly string $content,
        public readonly ?string $contentId,
        public readonly bool $inline,
    ) {}

    public function size(): int
    {
        return strlen($this->content);
    }
}
