<?php

namespace App\Filament\Resources\ResellerVariationPrices\Pages;

use App\Filament\Resources\ResellerVariationPrices\ResellerVariationPriceResource;
use Filament\Resources\Pages\ListRecords;

class ListResellerVariationPrices extends ListRecords
{
    protected static string $resource = ResellerVariationPriceResource::class;

    public function getSubheading(): ?string
    {
        return __('The selling price you suggest to resellers for each variation. Resellers see it and can set their own price; a variation with no suggested price is not sold in a reseller\'s store until they do.');
    }
}
