<?php

namespace App\Filament\Resources\StorefrontPages\Pages;

use App\Filament\Resources\Pages\TrashedRecords;
use App\Filament\Resources\StorefrontPages\StorefrontPageResource;

/**
 * Deleted custom pages (owner request 2026-11-01, docs/delete-and-trash-plan.md T3).
 */
class TrashedStorefrontPages extends TrashedRecords
{
    protected static string $resource = StorefrontPageResource::class;
}
