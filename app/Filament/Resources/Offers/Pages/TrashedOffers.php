<?php

namespace App\Filament\Resources\Offers\Pages;

use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Resources\Pages\TrashedRecords;

/**
 * Deleted offers and collections. A restored collection applies its deal prices again (owner request 2026-11-01, docs/delete-and-trash-plan.md T3).
 */
class TrashedOffers extends TrashedRecords
{
    protected static string $resource = OfferResource::class;
}
