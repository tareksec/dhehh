<?php

namespace App\Filament\Trade\Resources\LcFiles\Pages;

use App\Filament\Trade\Resources\LcFiles\LcFileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLcFiles extends ListRecords
{
    protected static string $resource = LcFileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New LC file'))->icon('heroicon-m-plus'),
        ];
    }
}
