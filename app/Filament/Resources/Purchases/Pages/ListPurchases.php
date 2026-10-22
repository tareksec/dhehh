<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\PurchaseCsvImportService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class ListPurchases extends ListRecords
{
    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importPurchasesAction(),

            Action::make('downloadPurchaseSampleCsv')
                ->label(__('Sample CSV'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(route('purchases.import.sample'))
                ->openUrlInNewTab()
                ->visible(fn (): bool => (bool) auth()->user()?->canPerformModelAbility('create', Purchase::class)),

            CreateAction::make(),
        ];
    }

    /**
     * Creates purchases from a CSV: one purchase per date for the supplier
     * chosen here (see PurchaseCsvImportService for the rules).
     */
    protected function importPurchasesAction(): Action
    {
        $canImport = fn (): bool => (bool) auth()->user()?->canPerformModelAbility('create', Purchase::class);

        return Action::make('importPurchases')
            ->label(__('Import purchases from CSV'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->visible($canImport)
            ->modalHeading(__('Import purchases from CSV'))
            ->modalDescription(__('Every date in the file becomes one purchase for the supplier you choose. Columns: date, product (name) or sku, quantity, and optionally unit_cost, short_qty and damaged_qty.'))
            ->modalSubmitActionLabel(__('Import'))
            // fillForm() replaces the fields' own defaults, so they are repeated here.
            ->fillForm(fn (): array => [
                'status' => 'draft',
                'preview_only' => false,
            ])
            ->schema([
                FileUpload::make('csv')
                    ->label(__('Purchases CSV'))
                    ->disk('local')
                    ->directory('imports/purchases')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                    ->required()
                    ->helperText(__('A product is found by SKU, then by its exact name. A blank date takes the date of the row above. A blank unit_cost uses the product cost price. A row that cannot be used is left out and listed.')),
                Select::make('supplier_id')
                    ->label(__('Supplier'))
                    ->options(fn (): array => Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                Select::make('status')
                    ->label(__('Status'))
                    ->options([
                        'draft' => __(Purchase::STATUSES['draft']),
                        'received' => __(Purchase::STATUSES['received']),
                    ])
                    ->required()
                    ->helperText(__('Draft changes no stock and no supplier balance. Received adds the quantity to stock now, so do not use it for goods that are already in stock.')),
                Toggle::make('preview_only')
                    ->label(__('Preview only (save nothing)'))
                    ->helperText(__('Shows what would be created without saving it.')),
            ])
            ->action(function (array $data, PurchaseCsvImportService $service) use ($canImport): void {
                abort_unless($canImport(), 403);

                $file = is_array($data['csv'] ?? null) ? reset($data['csv']) : ($data['csv'] ?? null);
                $supplier = Supplier::query()->find($data['supplier_id'] ?? null);

                if (! $file || ! $supplier) {
                    return;
                }

                $preview = (bool) ($data['preview_only'] ?? false);

                try {
                    $report = $service->run(Storage::disk('local')->path($file), $supplier, (string) ($data['status'] ?? 'draft'), $preview);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title(__('Purchase import failed'))
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();

                    return;
                } finally {
                    Storage::disk('local')->delete($file);
                }

                $notification = Notification::make()
                    ->title($preview ? __('Purchase import preview') : __('Purchases imported'))
                    ->body(new HtmlString(collect($service->summary($report))->map(fn (string $line): string => e($line))->implode('<br>')))
                    ->persistent();

                if ($preview || $report['skipped'] !== []) {
                    $notification->warning();
                } else {
                    $notification->success();
                }

                $notification->send();
            });
    }
}
