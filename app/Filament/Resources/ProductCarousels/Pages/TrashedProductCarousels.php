<?php

namespace App\Filament\Resources\ProductCarousels\Pages;

use App\Filament\Resources\Pages\TrashedRecords;
use App\Filament\Resources\ProductCarousels\ProductCarouselResource;

/**
 * Deleted product carousels (owner request 2026-11-01, docs/delete-and-trash-plan.md T3).
 */
class TrashedProductCarousels extends TrashedRecords
{
    protected static string $resource = ProductCarouselResource::class;
}
