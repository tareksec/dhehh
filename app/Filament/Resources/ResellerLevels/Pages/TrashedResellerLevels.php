<?php

namespace App\Filament\Resources\ResellerLevels\Pages;

use App\Filament\Resources\Pages\TrashedRecords;
use App\Filament\Resources\ResellerLevels\ResellerLevelResource;

/**
 * Deleted reseller levels. While a level is here, the resellers who had it use the company amounts; Restore makes the level apply to them again (owner request 2026-11-01, docs/delete-and-trash-plan.md T4b).
 */
class TrashedResellerLevels extends TrashedRecords
{
    protected static string $resource = ResellerLevelResource::class;
}
