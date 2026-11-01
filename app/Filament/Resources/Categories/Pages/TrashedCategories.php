<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Pages\TrashedRecords;

/**
 * Deleted categories. Restoring one puts it back on the storefront, with its products (owner request 2026-11-01, docs/delete-and-trash-plan.md T3).
 */
class TrashedCategories extends TrashedRecords
{
    protected static string $resource = CategoryResource::class;
}
