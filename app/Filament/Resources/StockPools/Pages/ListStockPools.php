<?php

namespace App\Filament\Resources\StockPools\Pages;

use App\Filament\Resources\Pages\TrashedRecords;
use App\Filament\Resources\StockPools\StockPoolResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListStockPools extends ListRecords
{
    protected static string $resource = StockPoolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TrashedRecords::linkAction(StockPoolResource::class),
            Action::make('bulkLink')
                ->label('Bulk link products')
                ->icon(Heroicon::OutlinedSquares2x2)
                ->color('gray')
                ->url(BulkLinkStockPools::getUrl()),
            CreateAction::make()
                ->label('Link products'),
        ];
    }
}
