<?php

namespace App\Filament\Trade\Resources\StockItems\Pages;

use App\Filament\Trade\Resources\StockItems\StockItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockItems extends ListRecords
{
    protected static string $resource = StockItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New item'))->icon('heroicon-m-plus'),
        ];
    }
}
