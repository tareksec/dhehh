<?php

namespace App\Filament\Trade\Resources\DeliveryChallans\Pages;

use App\Filament\Trade\Resources\DeliveryChallans\DeliveryChallanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDeliveryChallans extends ListRecords
{
    protected static string $resource = DeliveryChallanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New challan'))->icon('heroicon-m-plus'),
        ];
    }
}
