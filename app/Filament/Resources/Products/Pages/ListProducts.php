<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Pages\TrashedRecords;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Widgets\ProductStatsOverview;
use App\Models\Product;
use App\Services\ProductCsvService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderWidgets(): array
    {
        return [ProductStatsOverview::class];
    }

    /**
     * The "Quick Edit" row action is an EditAction, and Filament would
     * otherwise hand every EditAction the full edit-page URL (so it would
     * navigate away instead of opening its slide-over). Exempt only that
     * named action; the plain "Edit (full page)" action keeps its redirect.
     */
    public function getDefaultActionUrl(Action $action): ?string
    {
        if ($action->getName() === 'quickEdit') {
            return null;
        }

        return parent::getDefaultActionUrl($action);
    }

    protected function getHeaderActions(): array
    {
        return [
            TrashedRecords::linkAction(ProductResource::class),
            ...$this->productHeaderActions(),

            ActionGroup::make($this->productHeaderActions(mobile: true))
                ->label('Product actions')
                ->icon('heroicon-o-ellipsis-vertical')
                ->iconButton()
                ->tooltip('Product actions')
                ->dropdownPlacement('bottom-end')
                ->extraAttributes([
                    'class' => 'zz-products-mobile-actions lg:!hidden',
                ]),
        ];
    }

    /** @return array<int, Action> */
    protected function productHeaderActions(bool $mobile = false): array
    {
        $actionName = fn (string $name): string => $mobile
            ? 'mobile'.ucfirst($name)
            : $name;

        $actions = [
            Action::make($actionName('importCsv'))
                ->label('Import CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => auth()->user()?->canPerformModelAbility('create', Product::class)
                    && auth()->user()?->canPerformModelAbility('update', Product::class))
                ->schema([
                    FileUpload::make('csv')
                        ->label('Product CSV')
                        ->disk('local')
                        ->directory('imports/products')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                        ->required()
                        ->helperText('Use SKU as the unique key. Existing SKUs will be updated.'),
                ])
                ->action(function (array $data, ProductCsvService $products): void {
                    abort_unless(
                        auth()->user()?->canPerformModelAbility('create', Product::class)
                            && auth()->user()?->canPerformModelAbility('update', Product::class),
                        403,
                    );

                    $file = is_array($data['csv'] ?? null)
                        ? reset($data['csv'])
                        : ($data['csv'] ?? null);

                    $path = $file ? Storage::disk('local')->path($file) : null;

                    if (! $path) {
                        return;
                    }

                    $result = $products->import($path);

                    Storage::disk('local')->delete($file);

                    Notification::make()
                        ->title('Products imported')
                        ->body("Created {$result['created']} and updated {$result['updated']} products.")
                        ->success()
                        ->send();
                }),

            Action::make($actionName('downloadSampleCsv'))
                ->label('Sample CSV')
                ->icon('heroicon-o-document-arrow-down')
                ->url(route('products.import.sample'))
                ->openUrlInNewTab()
                ->visible(fn (): bool => auth()->user()?->canPerformModelAbility('viewAny', Product::class)),

            // Open the dedicated searchable, filterable stock-editing page.
            Action::make($actionName('bulkUpdateStock'))
                ->label('Bulk Update Stock')
                ->icon('heroicon-o-pencil-square')
                ->url(fn (): string => BulkUpdateStock::getUrl())
                ->visible(fn (): bool => auth()->user()?->canPerformModelAbility('update', Product::class)),

            Action::make($actionName('exportCsv'))
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(route('products.export.csv'))
                ->openUrlInNewTab()
                ->visible(fn (): bool => auth()->user()?->canPerformModelAbility('viewAny', Product::class)),

            CreateAction::make($actionName('create')),
        ];

        if (! $mobile) {
            foreach ($actions as $action) {
                $action->extraAttributes([
                    'class' => '!hidden lg:!inline-grid',
                ]);
            }
        }

        return $actions;
    }
}
