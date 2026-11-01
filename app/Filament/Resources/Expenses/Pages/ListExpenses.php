<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Widgets\ExpenseCategorySummaryWidget;
use App\Filament\Resources\ExpenseScans\ExpenseScanResource;
use App\Filament\Resources\Pages\TrashedRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('scanExpenses')
                ->label('Scan with AI')
                ->icon(Heroicon::OutlinedCamera)
                ->color('gray')
                ->url(fn (): string => ExpenseScanResource::getUrl('create'))
                ->visible(fn (): bool => ExpenseScanResource::canCreate()),
            TrashedRecords::linkAction(ExpenseResource::class),
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ExpenseCategorySummaryWidget::class,
        ];
    }
}
