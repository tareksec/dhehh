<?php

namespace App\Filament\Ims\Office\Resources\BankStatementImports\Pages;

use App\Filament\Ims\Office\Resources\BankStatementImports\BankStatementImportResource;
use App\Ims\Reconciliation\StatementImporter;
use App\Models\BankStatementImport;
use App\Services\CompanyContext;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListBankStatementImports extends ListRecords
{
    protected static string $resource = BankStatementImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('read')
                ->label(__('Read a statement'))
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => auth()->user()?->hasPermission('ims.reconcile') ?? false)
                ->schema([
                    Select::make('source')->label(__('From'))->options(BankStatementImport::SOURCES)->required(),
                    FileUpload::make('file')->label(__('CSV or Excel file'))->storeFiles(false)->required()
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->maxSize(5120),
                ])
                ->action(function (array $data): void {
                    $import = app(StatementImporter::class)->import(app(CompanyContext::class)->company(), (string) $data['source'], $data['file'], auth()->user());
                    Notification::make()->title(__(':lines lines read, :matched possible matches', ['lines' => $import->lines_count, 'matched' => $import->matched_count]))->success()->send();
                    $this->redirect(BankStatementImportResource::getUrl('view', ['record' => $import]));
                }),
        ];
    }
}
