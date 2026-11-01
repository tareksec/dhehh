<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Pages\TrashedRecords;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Supplier;
use App\Services\SupplierCsvService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListSuppliers extends ListRecords
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importCsv')
                ->label('Import CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => auth()->user()?->canPerformModelAbility('create', Supplier::class)
                    && auth()->user()?->canPerformModelAbility('update', Supplier::class))
                ->schema([
                    FileUpload::make('csv')
                        ->label('Supplier CSV')
                        ->disk('local')
                        ->directory('imports/suppliers')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                        ->required()
                        ->helperText('Existing suppliers are matched by email first, then phone, then company name.'),
                ])
                ->action(function (array $data, SupplierCsvService $suppliers): void {
                    abort_unless(
                        auth()->user()?->canPerformModelAbility('create', Supplier::class)
                            && auth()->user()?->canPerformModelAbility('update', Supplier::class),
                        403,
                    );

                    $file = is_array($data['csv'] ?? null)
                        ? reset($data['csv'])
                        : ($data['csv'] ?? null);

                    $path = $file ? Storage::disk('local')->path($file) : null;

                    if (! $path) {
                        return;
                    }

                    $result = $suppliers->import($path);

                    Storage::disk('local')->delete($file);

                    Notification::make()
                        ->title('Suppliers imported')
                        ->body("Created {$result['created']} and updated {$result['updated']} suppliers.")
                        ->success()
                        ->send();
                }),

            Action::make('downloadSampleCsv')
                ->label('Sample CSV')
                ->icon('heroicon-o-document-arrow-down')
                ->url(route('suppliers.import.sample'))
                ->openUrlInNewTab()
                ->visible(fn (): bool => auth()->user()?->canPerformModelAbility('viewAny', Supplier::class)),

            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(route('suppliers.export.csv'))
                ->openUrlInNewTab()
                ->visible(fn (): bool => auth()->user()?->canPerformModelAbility('viewAny', Supplier::class)),

            TrashedRecords::linkAction(SupplierResource::class),

            CreateAction::make(),
        ];
    }
}
