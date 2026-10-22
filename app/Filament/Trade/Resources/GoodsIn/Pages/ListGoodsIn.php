<?php

namespace App\Filament\Trade\Resources\GoodsIn\Pages;

use App\Filament\Trade\Resources\GoodsIn\GoodsInResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGoodsIn extends ListRecords
{
    protected static string $resource = GoodsInResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New purchase'))->icon('heroicon-m-plus'),
        ];
    }
}
