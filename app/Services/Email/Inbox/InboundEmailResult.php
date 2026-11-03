<?php

namespace App\Services\Email\Inbox;

use App\Models\EmailMessage;

/** What receiving one message did: the stored message, and whether it was one we already held. */
final class InboundEmailResult
{
    public function __construct(
        public readonly EmailMessage $message,
        public readonly bool $duplicate,
        public readonly bool $newThread,
    ) {}
}
