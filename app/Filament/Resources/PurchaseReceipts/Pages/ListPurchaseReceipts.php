<?php

namespace App\Filament\Resources\PurchaseReceipts\Pages;

use App\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use App\Services\SupplierWorkbookImportService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class ListPurchaseReceipts extends ListRecords
{
    protected static string $resource = PurchaseReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importWorkbookAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Copies the old hand-kept supplier workbook in as history. Starts as a
     * preview that saves nothing, so the owner can check the figures against
     * the workbook's own Summary first.
     */
    protected function importWorkbookAction(): Action
    {
        $canImport = fn (): bool => (bool) (auth()->user()?->canPerformModelAbility('create', PurchaseReceipt::class)
            && auth()->user()?->canPerformModelAbility('update', PurchaseReceipt::class));

        return Action::make('importSupplierWorkbook')
            ->label(__('Import supplier workbook'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->visible($canImport)
            ->modalHeading(__('Import supplier workbook'))
            ->modalDescription(__('The old workbook is copied in as history: goods received, goods the supplier took, advance, cash, shipping and ad cost. Stock and account balances are never changed.'))
            ->modalSubmitActionLabel(__('Start'))
            ->schema([
                Select::make('supplier_id')
                    ->label(__('Supplier'))
                    ->options(fn (): array => Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                FileUpload::make('workbook')
                    ->label(__('Supplier workbook (.xlsx)'))
                    ->disk('local')
                    ->directory('imports/supplier-workbooks')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                    ->required(),
                FileUpload::make('product_map')
                    ->label(__('Product map (optional CSV)'))
                    ->disk('local')
                    ->directory('imports/supplier-workbooks')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                    ->helperText(__('Two columns: the product name in the workbook and the ERP SKU. Only needed for products whose names differ.')),
                DatePicker::make('opening_date')
                    ->label(__('Date of the opening receipt'))
                    ->helperText(__('Goods in the workbook without a real receive date come in on this day. Empty: the earliest date in the workbook.')),
                Toggle::make('replace')
                    ->label(__('Replace an earlier import of this supplier'))
                    ->default(false),
                Toggle::make('skip_unmatched')
                    ->label(__('Leave out products that are not matched'))
                    ->default(false),
                Toggle::make('preview_only')
                    ->label(__('Preview only (save nothing)'))
                    ->helperText(__('Turn this off to save the history.'))
                    ->default(true),
            ])
            ->action(function (array $data, SupplierWorkbookImportService $service) use ($canImport): void {
                abort_unless($canImport(), 403);

                $workbook = is_array($data['workbook'] ?? null) ? reset($data['workbook']) : ($data['workbook'] ?? null);
                $map = is_array($data['product_map'] ?? null) ? reset($data['product_map']) : ($data['product_map'] ?? null);
                $supplier = Supplier::query()->find($data['supplier_id'] ?? 0);

                if (! $workbook || ! $supplier) {
                    return;
                }

                $preview = (bool) ($data['preview_only'] ?? true);

                try {
                    $report = $service->run(
                        Storage::disk('local')->path($workbook),
                        $supplier,
                        dryRun: $preview,
                        openingDate: filled($data['opening_date'] ?? null) ? Carbon::parse($data['opening_date']) : null,
                        productMap: $map ? $this->readMap(Storage::disk('local')->path($map)) : [],
                        replace: (bool) ($data['replace'] ?? false),
                        skipUnmatched: (bool) ($data['skip_unmatched'] ?? false),
                    );
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title(__('Workbook import failed'))
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();

                    return;
                } finally {
                    Storage::disk('local')->delete(array_filter([$workbook, $map]));
                }

                $notification = Notification::make()
                    ->title($preview ? __('Workbook import preview') : __('Workbook imported'))
                    ->body(new HtmlString(collect($service->summary($report))->map(fn (string $line): string => e($line))->implode('<br>')))
                    ->persistent();

                if ($preview || $report['errors'] !== [] || $report['unmatched'] !== []) {
                    $notification->warning();
                } else {
                    $notification->success();
                }

                $notification->send();
            });
    }

    /**
     * @return array<string, string>
     */
    private function readMap(string $path): array
    {
        $map = [];
        $handle = fopen($path, 'r');

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 2 && filled($row[0]) && filled($row[1])) {
                $map[trim(ltrim((string) $row[0], "\xEF\xBB\xBF"))] = trim((string) $row[1]);
            }
        }

        fclose($handle);

        return $map;
    }
}
