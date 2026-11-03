<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Concerns\PersistsProductFormData;
use App\Models\Product;
use App\Services\ProductTrashService;
use App\Support\CompanyMedia;
use App\Support\TypeToConfirmDelete;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProductsTable
{
    use PersistsProductFormData;

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'category',
                // Pool members live in other companies, so the eager load
                // must drop CompanyScope or it would only ever see members
                // of the currently-active company.
                'stockPool.products' => fn ($query) => $query->withoutGlobalScopes()->with('company'),
            ]))
            ->columns([
                ImageColumn::make('image')
                    ->label('Image')
                    ->state(fn (Product $record): ?string => CompanyMedia::filamentPublicUrl($record->image, $record))
                    ->height(48)
                    ->square()
                    ->toggleable(),

                TextColumn::make('name')
                    ->label('Product Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),

                TextColumn::make('barcode')
                    ->label('Barcode')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('category.name')
                    ->label('Category')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('brand')
                    ->label('Brand')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('sale_price')
                    ->label('Sale Price')
                    ->getStateUsing(fn ($record) => $record->selling_price)
                    ->moneyWithoutTrailingZeroes('BDT'),

                TextColumn::make('cost_price')
                    ->label('Cost Price')
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('stock')
                    ->label('Stock')
                    ->badge()
                    ->color(fn (Product $record): string => $record->isLowStock() ? 'danger' : 'success')
                    ->description(fn (Product $record): ?string => $record->isLowStock() ? 'Low stock' : null),

                TextColumn::make('stock_pool')
                    ->label('Shared with')
                    ->badge()
                    ->color('info')
                    ->icon('heroicon-o-squares-2x2')
                    ->placeholder('—')
                    ->toggleable()
                    ->state(fn (Product $record): ?string => self::poolCompaniesLabel($record))
                    ->tooltip('Stock is shared with these companies (Inventory → Shared Stock Pools)'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Product::STATUSES[$state] ?? 'Available')
                    ->color(fn (?string $state): string => $state === Product::STATUS_COMING_SOON ? 'warning' : 'success'),

                TextColumn::make('reorder_level')
                    ->label('Reorder')
                    ->badge()
                    ->toggleable(),

                TextColumn::make('unit')
                    ->label('Unit')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('vat_rate')
                    ->label('VAT')
                    ->suffix('%')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Category')
                    ->relationship('category', 'name')
                    ->searchable(),

                TernaryFilter::make('is_active')
                    ->label('Active status')
                    ->trueLabel('Active products')
                    ->falseLabel('Inactive products'),

                SelectFilter::make('status')
                    ->label('Product status')
                    ->options(Product::STATUSES),

                Filter::make('low_stock')
                    ->label('Low stock')
                    ->query(fn (Builder $query): Builder => $query->whereColumn('stock', '<=', 'reorder_level'))
                    ->toggle(),

                TernaryFilter::make('pooled')
                    ->label('Shared stock pool')
                    ->trueLabel('In a shared pool')
                    ->falseLabel('Not pooled')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('stock_pool_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('stock_pool_id'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                SelectFilter::make('brand')
                    ->label('Brand')
                    ->options(fn (): array => Product::query()
                        ->whereNotNull('brand')
                        ->where('brand', '!=', '')
                        ->orderBy('brand')
                        ->pluck('brand', 'brand')
                        ->all())
                    ->searchable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('View')
                        ->color('info')
                        ->icon('heroicon-o-eye'),

                    // Full product form in a slide-over, right on the list —
                    // ListProducts::getDefaultActionUrl() keeps this named
                    // action from redirecting to the edit page like the plain
                    // EditAction below does.
                    EditAction::make('quickEdit')
                        ->label('Quick Edit')
                        ->icon('heroicon-o-bolt')
                        ->slideOver()
                        ->modalWidth(Width::FiveExtraLarge)
                        ->mutateRecordDataUsing(fn (array $data): array => self::mutateProductDataBeforeFill($data))
                        ->using(fn (Product $record, array $data): Product => self::saveProductFromForm($record, $data)),

                    EditAction::make()
                        ->label('Edit (full page)'),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Move to trash')
                        ->schema(fn (): array => [
                            ...TypeToConfirmDelete::countFields(),
                            ...self::trashReasonFields(),
                        ])
                        ->using(fn (Collection $records, array $data) => self::moveToTrash($records, $data)),
                ]),
            ]);
    }

    /**
     * The reason asked when products go to Trash (owner request 2026-11-03): it is saved with the product,
     * and "Test product" lets one that was ordered often, or already paid or shipped, go to Trash too.
     *
     * @return list<Select|Textarea>
     */
    public static function trashReasonFields(): array
    {
        return [
            Select::make('trash_reason')
                ->label('Reason')
                ->options(fn (): array => collect(ProductTrashService::REASONS)->map(fn (string $label): string => __($label))->all())
                ->required()
                ->native(false)
                ->infoTip(__('Why is this product being deleted? A product with orders or purchases goes to trash together with them. Only a "Test product" may go when it was ordered more than twice, or when money was received or the goods were shipped.')),
            Textarea::make('trash_note')
                ->label('Note')
                ->rows(2)
                ->maxLength(500),
        ];
    }

    /**
     * Move the chosen products to Trash one by one, so a refused one (see ProductTrashService) does not stop the rest.
     *
     * @param  Collection<int, Product>  $records
     * @param  array<string, mixed>  $data
     */
    public static function moveToTrash(Collection $records, array $data): void
    {
        $service = app(ProductTrashService::class);
        $moved = 0;
        $refused = [];

        foreach ($records as $product) {
            $why = $service->tryMoveToTrash($product, $data['trash_reason'] ?? null, $data['trash_note'] ?? null);

            if ($why === null) {
                $moved++;
            } else {
                $refused[] = $product->name.': '.$why;
            }
        }

        if ($moved > 0) {
            Notification::make()->title(__(':count products moved to trash', ['count' => $moved]))->success()->send();
        }

        if ($refused !== []) {
            Notification::make()->title(__('Not moved to trash'))->body(implode("\n", $refused))->danger()->persistent()->send();
        }
    }

    /**
     * The other companies a product's stock is pooled with (see StockPool),
     * or null when it isn't pooled. Relies on the `stockPool.products` eager
     * load in configure() so it never fires a per-row query.
     */
    protected static function poolCompaniesLabel(Product $record): ?string
    {
        if (! $record->stock_pool_id || ! $record->relationLoaded('stockPool') || ! $record->stockPool) {
            return $record->stock_pool_id ? 'Pooled' : null;
        }

        $others = $record->stockPool->products
            ->reject(fn (Product $member): bool => (int) $member->getKey() === (int) $record->getKey())
            ->map(fn (Product $member): ?string => $member->company?->name)
            ->filter()
            ->unique()
            ->sort()
            ->implode(', ');

        $prefix = $record->isPoolSource() ? 'Source · ' : '';

        return $others !== '' ? $prefix.$others : ($record->isPoolSource() ? 'Pool source' : 'Pooled');
    }
}
