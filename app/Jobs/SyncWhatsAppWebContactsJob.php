<?php

namespace App\Jobs;

use App\Models\ConversationChannel;
use App\Services\CompanyContext;
use App\Services\WhatsAppWeb\WhatsAppWebArchiveService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Saves the WhatsApp Business App address book pushed by the bridge into
 * whatsapp_contacts, linking each number to an existing Customer/Lead.
 */
class SyncWhatsAppWebContactsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 30, 120, 300];

    /** @param array<int, array<string, mixed>> $contacts */
    public function __construct(public int $channelId, public array $contacts) {}

    public function handle(CompanyContext $context, WhatsAppWebArchiveService $archive): void
    {
        $channel = ConversationChannel::withoutGlobalScopes()->with('company')->findOrFail($this->channelId);
        $context->set($channel->company);

        try {
            foreach ($this->contacts as $contact) {
                if (is_array($contact)) {
                    $archive->upsertContact($channel, $contact);
                }
            }
        } finally {
            $context->clear();
        }
    }
}
