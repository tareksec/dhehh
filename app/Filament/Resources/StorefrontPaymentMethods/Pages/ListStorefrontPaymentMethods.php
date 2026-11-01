<?php

namespace App\Filament\Resources\StorefrontPaymentMethods\Pages;

use App\Filament\Resources\Pages\TrashedRecords;
use App\Filament\Resources\StorefrontPaymentMethods\StorefrontPaymentMethodResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStorefrontPaymentMethods extends ListRecords
{
    protected static string $resource = StorefrontPaymentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TrashedRecords::linkAction(StorefrontPaymentMethodResource::class),
            CreateAction::make(),
        ];
    }
}
