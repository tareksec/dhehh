<?php

namespace App\Filament\Trade\Resources\StockItems;

use App\Filament\Trade\Resources\StockItems\Pages\CreateStockItem;
use App\Filament\Trade\Resources\StockItems\Pages\EditStockItem;
use App\Filament\Trade\Resources\StockItems\Pages\ListStockItems;
use App\Models\Product;
use App\Support\ImsNumbers;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Stock in the Trade Portal: every item (machine or part) with how many are
 * left, the low ones marked, adding a new item with its opening stock, and
 * correcting a count after checking the shelf. Stock moves only through the
 * ERP's stock ledger (Product::setStockFromProductForm(), purchases, challans).
 */
class StockItemResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $slug = 'stock';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('item');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Stock');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('trade.stock') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canEdit($record): bool
    {
        return static::canAccess();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('Item'))
                ->schema([
                    TextInput::make('name')->label(__('Item name'))->required()->maxLength(255)->columnSpanFull(),
                    TextInput::make('unit')->label(__('Unit'))->placeholder(__('set, pcs, ctn'))->default('pcs')->required()->maxLength(30),
                    TextInput::make('sale_price')->label(__('Sale price'))->numeric()->minValue(0)->default(0)->required(),
                    TextInput::make('cost_price')->label(__('Buying price'))->numeric()->minValue(0)->default(0),
                    TextInput::make('reorder_level')->label(__('Warn when stock is at or below'))->numeric()->integer()->minValue(0)->default(0),
                    TextInput::make('opening_stock')
                        ->label(__('How many are in the shop now'))
                        ->numeric()->integer()->minValue(0)->default(0)
                        ->visibleOn('create')
                        ->dehydrated(false),
                    Textarea::make('description')->label(__('Details'))->rows(2)->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'md' => 2]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            // The ERP's "coming soon" purchase-cost placeholders (C&F, Duty…) are not goods.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', '!=', Product::STATUS_COMING_SOON))
            ->columns([
                TextColumn::make('name')->label(__('Item'))->searchable()->sortable()->wrap()->weight('bold'),
                TextColumn::make('stock')
                    ->label(__('In stock'))
                    ->sortable()
                    ->formatStateUsing(fn ($state, Product $record): string => ImsNumbers::digits((string) (int) $state).' '.($record->unit ?: 'pcs'))
                    ->color(fn (Product $record): string => $record->isLowStock() ? 'danger' : 'success')
                    ->description(fn (Product $record): ?string => $record->isLowStock() ? __('Low') : null),
                TextColumn::make('sale_price')->label(__('Sale price'))->formatStateUsing(fn ($state): string => ImsNumbers::money($state)),
            ])
            ->filters([
                Filter::make('low')->label(__('Only low stock'))->query(fn (Builder $query): Builder => $query->whereColumn('stock', '<=', 'reorder_level')),
            ])
            ->recordActions([
                static::correctStockAction(),
                EditAction::make(),
            ])
            ->emptyStateHeading(__('No item yet'))
            ->emptyStateDescription(__('Add your machines and parts, or bring them in from a file (Menu → Bring in old data).'));
    }

    public static function correctStockAction(): Action
    {
        return Action::make('correctStock')
            ->label(__('Correct count'))
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (Product $record): bool => ! $record->has_variants)
            ->schema(fn (Product $record): array => [
                TextInput::make('stock')->label(__('How many are in the shop now'))->numeric()->integer()->minValue(0)->default((int) $record->stock)->required(),
                TextInput::make('reason')->label(__('Why'))->placeholder(__('e.g. counted the shelf'))->required()->maxLength(255),
            ])
            ->action(function (Product $record, array $data): void {
                $record->setStockFromProductForm((int) $data['stock'], reason: (string) $data['reason'], note: __('Stock count corrected in the Trade Portal'));
                Notification::make()->title(__('Stock corrected'))->success()->send();
            });
    }

    /** A SKU for an item added in the portal: from its name, made unique. */
    public static function skuFor(string $name): string
    {
        $base = 'TRD-'.(Str::upper(Str::limit(Str::slug($name), 40, '')) ?: 'ITEM');
        $sku = $base;
        $suffix = 2;

        while (Product::query()->withoutGlobalScopes()->where('sku', $sku)->exists()) {
            $sku = "{$base}-{$suffix}";
            $suffix++;
        }

        return $sku;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockItems::route('/'),
            'create' => CreateStockItem::route('/create'),
            'edit' => EditStockItem::route('/{record}/edit'),
        ];
    }
}
