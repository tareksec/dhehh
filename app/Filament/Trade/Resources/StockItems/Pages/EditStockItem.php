<?php

namespace App\Filament\Trade\Resources\StockItems\Pages;

use App\Filament\Trade\Resources\StockItems\StockItemResource;
use Filament\Resources\Pages\EditRecord;

class EditStockItem extends EditRecord
{
    protected static string $resource = StockItemResource::class;

    protected function getHeaderActions(): array
    {
        return [StockItemResource::correctStockAction()->record($this->getRecord())];
    }

    protected function getRedirectUrl(): string
    {
        return StockItemResource::getUrl('index');
    }
}
