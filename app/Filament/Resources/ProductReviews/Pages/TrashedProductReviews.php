<?php

namespace App\Filament\Resources\ProductReviews\Pages;

use App\Filament\Resources\Pages\TrashedRecords;
use App\Filament\Resources\ProductReviews\ProductReviewResource;

/**
 * Deleted product reviews. A restored review shows again if it was approved (owner request 2026-11-01, docs/delete-and-trash-plan.md T3).
 */
class TrashedProductReviews extends TrashedRecords
{
    protected static string $resource = ProductReviewResource::class;
}
