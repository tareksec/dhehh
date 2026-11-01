<?php

namespace App\Filament\Resources\QuickReplies\Pages;

use App\Filament\Resources\Pages\TrashedRecords;
use App\Filament\Resources\QuickReplies\QuickReplyResource;

/**
 * Deleted quick replies (owner request 2026-11-01, docs/delete-and-trash-plan.md T3).
 */
class TrashedQuickReplies extends TrashedRecords
{
    protected static string $resource = QuickReplyResource::class;
}
