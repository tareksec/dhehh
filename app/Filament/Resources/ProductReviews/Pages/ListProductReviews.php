<?php

namespace App\Filament\Resources\ProductReviews\Pages;

use App\Filament\Resources\Pages\TrashedRecords;
use App\Filament\Resources\ProductReviews\ProductReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListProductReviews extends ListRecords
{
    protected static string $resource = ProductReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TrashedRecords::linkAction(ProductReviewResource::class),
        ];
    }
}
