<?php

namespace App\Filament\Resources\ConversationChannels\Pages;

use App\Filament\Resources\ConversationChannels\ConversationChannelResource;
use App\Filament\Resources\Pages\TrashedRecords;

/**
 * Deleted chat channels. A channel here receives no webhook and sends nothing, and the conversations and messages it archived stay; Restore turns it back on as it was (owner request 2026-11-01, docs/delete-and-trash-plan.md T4b).
 */
class TrashedConversationChannels extends TrashedRecords
{
    protected static string $resource = ConversationChannelResource::class;
}
