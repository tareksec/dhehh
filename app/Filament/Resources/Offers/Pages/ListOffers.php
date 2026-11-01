<?php

namespace App\Filament\Resources\Offers\Pages;

use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Resources\Pages\TrashedRecords;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOffers extends ListRecords
{
    protected static string $resource = OfferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TrashedRecords::linkAction(OfferResource::class),
            CreateAction::make(),
        ];
    }
}
