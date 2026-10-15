<?php

namespace App\Filament\Resources\ResellerVariationPrices;

use App\Filament\Clusters\Resellers;
use App\Filament\Resources\Resellers\ResellerResource;
use App\Filament\Resources\ResellerVariationPrices\Pages\ListResellerVariationPrices;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * One list of every active variation with the selling (market) price the admin
 * suggests to resellers, so the prices can be filled in quickly: type the price
 * in the row, or tick several rows and give them the same price. A variation
 * with no suggested price is not sold through a reseller's store until the
 * reseller sets a price of their own (owner decision 2026-10-14), so the filter
 * "No suggested price yet" shows what is still missing. The wholesale price is
 * shown for reference only and is never changed here.
 */
class ResellerVariationPriceResource extends Resource
{
    protected static ?string $model = ProductVariant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $cluster = Resellers::class;

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'reseller-variation-prices';

    public static function getNavigationLabel(): string
    {
        return __('Variation selling prices');
    }

    public static function getModelLabel(): string
    {
        return __('Variation');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Variations');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('product')
                ->where('is_active', true)
                ->whereHas('product', fn (Builder $product): Builder => $product->where('has_variants', true)))
            ->columns([
                TextColumn::make('product.name')
                    ->label(__('Product'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('options')
                    ->label(__('Variation'))
                    ->formatStateUsing(fn ($state, ProductVariant $record): string => $record->label() ?: '-')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('options', 'like', "%{$search}%")),
                TextColumn::make('sku')
                    ->label(__('SKU'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('sale_price')
                    ->label(__('Wholesale price'))
                    ->formatStateUsing(fn ($state, ProductVariant $record): string => MoneyFormatter::currency($record->baseSalePrice()))
                    ->sortable(),
                TextInputColumn::make('reseller_suggested_price')
                    ->label(__('Suggested selling price'))
                    ->type('number')
                    ->step('0.01')
                    ->placeholder(__('Not set'))
                    ->rules(['nullable', 'numeric', 'min:0', 'max:9999999999'])
                    ->disabled(fn (): bool => ! static::canEditPrices())
                    ->updateStateUsing(function (ProductVariant $record, mixed $state): void {
                        abort_unless(static::canEditPrices(), 403);

                        $price = is_numeric($state) && (float) $state > 0 ? round((float) $state, 2) : null;
                        $record->update(['reseller_suggested_price' => $price]);
                    }),
            ])
            ->filters([
                Filter::make('no_suggested_price')
                    ->label(__('No suggested price yet'))
                    ->query(fn (Builder $query): Builder => $query->where(
                        fn (Builder $inner): Builder => $inner->whereNull('reseller_suggested_price')->orWhere('reseller_suggested_price', '<=', 0)
                    )),
                SelectFilter::make('product_id')
                    ->label(__('Product'))
                    ->relationship('product', 'name', fn (Builder $query): Builder => $query->where('has_variants', true))
                    ->searchable()
                    ->preload(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('setSamePrice')
                        ->label(__('Set the same price'))
                        ->icon(Heroicon::OutlinedTag)
                        ->visible(fn (): bool => static::canEditPrices())
                        ->modalDescription(__('Every selected variation gets this suggested selling price. Resellers can still set their own price.'))
                        ->schema([
                            TextInput::make('price')
                                ->label(__('Suggested selling price'))
                                ->numeric()
                                ->minValue(0.01)
                                ->maxValue(9999999999)
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            abort_unless(static::canEditPrices(), 403);

                            $price = round((float) $data['price'], 2);
                            $records->each(fn (ProductVariant $variant): bool => $variant->update(['reseller_suggested_price' => $price]));

                            Notification::make()
                                ->title(__(':count variations now have this suggested price.', ['count' => $records->count()]))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('clearPrice')
                        ->label(__('Clear the suggested price'))
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('danger')
                        ->visible(fn (): bool => static::canEditPrices())
                        ->requiresConfirmation()
                        ->modalDescription(__('The selected variations will not be sold in resellers\' stores until a reseller sets a price for them.'))
                        ->action(function (Collection $records): void {
                            abort_unless(static::canEditPrices(), 403);

                            $records->each(fn (ProductVariant $variant): bool => $variant->update(['reseller_suggested_price' => null]));

                            Notification::make()
                                ->title(__(':count variations no longer have a suggested price.', ['count' => $records->count()]))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('product_id')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListResellerVariationPrices::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (Auth::user()?->canPerformModelAbility('viewAny', Product::class) ?? false)
            && ResellerResource::resellerModuleEnabledForCurrentContext();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /** Prices are edited from the table itself, which needs the right to change products. */
    protected static function canEditPrices(): bool
    {
        return Auth::user()?->canPerformModelAbility('update', Product::class) ?? false;
    }
}
