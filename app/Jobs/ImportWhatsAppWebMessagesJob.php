<?php

namespace App\Jobs;

use App\Models\ConversationChannel;
use App\Services\CompanyContext;
use App\Services\WhatsAppWeb\WhatsAppWebArchiveService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Archives one batch of messages (history backfill or live) pushed by the
 * whatsapp-bridge. Queued because a full-history sync can be tens of
 * thousands of messages; idempotent, so retries and duplicate deliveries
 * are harmless. Like ImportConversationHistoryJob it never triggers the AI.
 */
class ImportWhatsAppWebMessagesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 30, 120, 300];

    /** @param array<int, array<string, mixed>> $messages */
    public function __construct(public int $channelId, public array $messages, public bool $history = false) {}

    public function handle(CompanyContext $context, WhatsAppWebArchiveService $archive): void
    {
        $channel = ConversationChannel::withoutGlobalScopes()->with('company')->findOrFail($this->channelId);
        $context->set($channel->company);

        try {
            $stored = 0;

            foreach ($this->messages as $item) {
                if (is_array($item) && $archive->storeMessage($channel, $item, $this->history)) {
                    $stored++;
                }
            }

            if ($stored > 0 && ! $this->history) {
                $channel->markInboundReceived();
            }
        } finally {
            $context->clear();
        }
    }
}
