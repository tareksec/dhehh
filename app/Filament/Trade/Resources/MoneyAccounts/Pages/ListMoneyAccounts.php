<?php

namespace App\Filament\Trade\Resources\MoneyAccounts\Pages;

use App\Filament\Trade\Resources\MoneyAccounts\MoneyAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMoneyAccounts extends ListRecords
{
    protected static string $resource = MoneyAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New account'))->icon('heroicon-m-plus'),
        ];
    }
}
