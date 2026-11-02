<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Pages\TrashedRecords;

/**
 * Deleted leads. A lead here gets no broadcast and no follow-up reminder and is not on the Needs attention list; its activities and quotations stay, and Restore brings it back as it was (owner request 2026-11-01, docs/delete-and-trash-plan.md T4b).
 */
class TrashedLeads extends TrashedRecords
{
    protected static string $resource = LeadResource::class;
}
