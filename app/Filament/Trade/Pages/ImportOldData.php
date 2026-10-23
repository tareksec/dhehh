<?php

namespace App\Filament\Trade\Pages;

use App\Services\TradeImportService;
use App\Support\SpreadsheetCsv;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * "Bring in old data": buyers with their old due, and items with their stock,
 * from a CSV or Excel file (TradeImportService). Each has a sample file to
 * fill in. Needs "trade.import".
 */
class ImportOldData extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'import';

    protected string $view = 'filament.trade.pages.import-old-data';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('trade.import') ?? false;
    }

    public function getTitle(): string
    {
        return __('Bring in old data');
    }

    public static function getNavigationLabel(): string
    {
        return __('Bring in old data');
    }

    public function importBuyersAction(): Action
    {
        return $this->importAction('importBuyers', __('Bring in buyers and old dues'), 'importBuyers');
    }

    public function importItemsAction(): Action
    {
        return $this->importAction('importItems', __('Bring in items and stock'), 'importItems');
    }

    public function buyerSampleAction(): Action
    {
        return Action::make('buyerSample')->label(__('Sample file'))->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->action(fn () => app(TradeImportService::class)->buyerSample());
    }

    public function itemSampleAction(): Action
    {
        return Action::make('itemSample')->label(__('Sample file'))->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->action(fn () => app(TradeImportService::class)->itemSample());
    }

    protected function importAction(string $name, string $label, string $method): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-arrow-up-tray')
            ->schema([
                FileUpload::make('file')
                    ->label(__('CSV or Excel file'))
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.oasis.opendocument.spreadsheet'])
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data) use ($method): void {
                $file = Arr::first(Arr::wrap($data['file'] ?? null));
                abort_unless($file instanceof TemporaryUploadedFile, 422);

                $extension = strtolower($file->getClientOriginalExtension());

                if (! in_array($extension, SpreadsheetCsv::EXTENSIONS, true)) {
                    Notification::make()->title(__('Use a .csv or .xlsx file.'))->danger()->send();

                    return;
                }

                try {
                    $result = app(TradeImportService::class)->{$method}($file->getRealPath(), $extension);
                } catch (ValidationException $exception) {
                    $messages = collect($exception->errors())->flatten();

                    Notification::make()
                        ->title(__('Nothing was brought in. Fix these rows and try again:'))
                        ->body($messages->take(8)->implode("\n").($messages->count() > 8 ? "\n…" : ''))
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Done: :created new, :updated updated.', ['created' => $result['created'], 'updated' => $result['updated']]))
                    ->success()
                    ->send();
            });
    }
}
