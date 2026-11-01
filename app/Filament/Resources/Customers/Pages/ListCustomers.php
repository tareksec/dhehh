<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Pages\TrashedRecords;
use App\Models\Customer;
use App\Services\CustomerCsvService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importCsv')
                ->label('Import CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => auth()->user()?->canPerformModelAbility('create', Customer::class)
                    && auth()->user()?->canPerformModelAbility('update', Customer::class))
                ->schema([
                    FileUpload::make('csv')
                        ->label('Customer CSV')
                        ->disk('local')
                        ->directory('imports/customers')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                        ->required()
                        ->helperText('Existing customers are matched by email first, then phone.'),
                ])
                ->action(function (array $data, CustomerCsvService $customers): void {
                    abort_unless(
                        auth()->user()?->canPerformModelAbility('create', Customer::class)
                            && auth()->user()?->canPerformModelAbility('update', Customer::class),
                        403,
                    );

                    $file = is_array($data['csv'] ?? null)
                        ? reset($data['csv'])
                        : ($data['csv'] ?? null);

                    $path = $file ? Storage::disk('local')->path($file) : null;

                    if (! $path) {
                        return;
                    }

                    $result = $customers->import($path);

                    Storage::disk('local')->delete($file);

                    Notification::make()
                        ->title('Customers imported')
                        ->body("Created {$result['created']} and updated {$result['updated']} customers.")
                        ->success()
                        ->send();
                }),

            Action::make('downloadSampleCsv')
                ->label('Sample CSV')
                ->icon('heroicon-o-document-arrow-down')
                ->url(route('customers.import.sample'))
                ->openUrlInNewTab()
                ->visible(fn (): bool => auth()->user()?->canPerformModelAbility('viewAny', Customer::class)),

            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(route('customers.export.csv'))
                ->openUrlInNewTab()
                ->visible(fn (): bool => auth()->user()?->canPerformModelAbility('viewAny', Customer::class)),

            TrashedRecords::linkAction(CustomerResource::class),

            CreateAction::make(),
        ];
    }
}
