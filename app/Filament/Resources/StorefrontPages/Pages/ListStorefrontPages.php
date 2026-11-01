<?php

namespace App\Filament\Resources\StorefrontPages\Pages;

use App\Filament\Resources\Pages\TrashedRecords;
use App\Filament\Resources\StorefrontPages\StorefrontPageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStorefrontPages extends ListRecords
{
    protected static string $resource = StorefrontPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TrashedRecords::linkAction(StorefrontPageResource::class),
            CreateAction::make(),
        ];
    }
}
