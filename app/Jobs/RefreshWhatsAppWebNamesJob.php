<?php

namespace App\Jobs;

use App\Models\ConversationChannel;
use App\Services\CompanyContext;
use App\Services\WhatsAppWeb\WhatsAppWebArchiveService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Gives archived WhatsApp Web chats that have no name yet the name WhatsApp
 * shared for the contact. Idempotent; safe to run again at any time.
 */
class RefreshWhatsAppWebNamesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public int $channelId) {}

    public function handle(CompanyContext $context, WhatsAppWebArchiveService $archive): void
    {
        $channel = ConversationChannel::withoutGlobalScopes()->withoutTrashed()->with('company')->where('connection_type', 'web')->find($this->channelId);

        if (! $channel) {
            return;
        }

        $context->set($channel->company);

        try {
            $archive->refreshNames($channel);
        } finally {
            $context->clear();
        }
    }
}
