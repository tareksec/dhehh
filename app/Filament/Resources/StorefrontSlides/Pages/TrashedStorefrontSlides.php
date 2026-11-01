<?php

namespace App\Filament\Resources\StorefrontSlides\Pages;

use App\Filament\Resources\Pages\TrashedRecords;
use App\Filament\Resources\StorefrontSlides\StorefrontSlideResource;

/**
 * Deleted hero slides (owner request 2026-11-01, docs/delete-and-trash-plan.md T3).
 */
class TrashedStorefrontSlides extends TrashedRecords
{
    protected static string $resource = StorefrontSlideResource::class;
}
