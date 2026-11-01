<?php

namespace App\Filament\Resources\StockPools;

use App\Filament\Clusters\Inventory;
use App\Filament\Resources\StockPools\Pages\BulkLinkStockPools;
use App\Filament\Resources\StockPools\Pages\CreateStockPool;
use App\Filament\Resources\StockPools\Pages\EditStockPool;
use App\Filament\Resources\StockPools\Pages\ListStockPools;
use App\Models\Product;
use App\Models\StockPool;
use App\Services\StockMovementService;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Superadmin-only, cross-company by design: links Product rows that belong
 * to different companies but are physically the same stock in one warehouse
 * (e.g. ZamZam International wholesale + ZamZam Gadget retail selling the
 * same units). See App\Models\StockPool for the full mechanics.
 */
class StockPoolResource extends Resource
{
    protected static ?string $model = StockPool::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $cluster = Inventory::class;

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Shared Stock Pools';

    // A pool has no name of its own: it is known by the product that holds its real stock. The Delete
    // confirmation asks for this name (owner request 2026-11-01).
    public static function hasRecordTitle(): bool
    {
        return true;
    }

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record?->sourceProduct?->name ?? parent::getRecordTitle($record);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Shared Stock')
                ->columnSpanFull()
                ->description('Link products across companies that physically share one warehouse\'s stock. Selling in any linked company updates the same live quantity everywhere — no company ever shows a stale or double-counted number.')
                ->schema([
                    Select::make('source_product_id')
                        ->label('Source product (holds the real stock)')
                        ->options(fn (?StockPool $record): array => self::productOptions(currentPoolId: $record?->getKey()))
                        ->searchable()
                        ->required()
                        ->live()
                        ->helperText('The product whose opening/purchase history represents the real physical inventory — usually the main/wholesale company\'s product. Its own stock movement log carries the true history; every other linked product below is a pass-through.'),
                    Select::make('member_product_ids')
                        ->label('Other linked products (pass-through)')
                        ->options(fn (Get $get, ?StockPool $record): array => self::productOptions(
                            excludeId: $get('source_product_id') ? (int) $get('source_product_id') : null,
                            currentPoolId: $record?->getKey(),
                        ))
                        ->multiple()
                        ->searchable()
                        ->required()
                        ->minItems(1)
                        ->helperText('Other companies\' products that sell the exact same physical units. Simple (non-variant) products only.'),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sourceProduct.name')
                    ->label('Source product')
                    ->state(fn (StockPool $record): string => $record->sourceProduct?->name ?? '—')
                    ->description(fn (StockPool $record): ?string => $record->sourceProduct?->sku)
                    ->searchable(),
                TextColumn::make('source_company')
                    ->label('Source company')
                    ->badge()
                    ->color('primary')
                    ->state(fn (StockPool $record): string => $record->sourceProduct?->company?->name ?? '—'),
                TextColumn::make('linked_companies')
                    ->label('Pools into')
                    ->badge()
                    ->color('info')
                    ->state(fn (StockPool $record): array => self::memberProducts($record)
                        ->reject(fn (Product $p): bool => (int) $p->getKey() === (int) $record->source_product_id)
                        ->map(fn (Product $p): ?string => $p->company?->name)
                        ->filter()
                        ->unique()
                        ->sort()
                        ->values()
                        ->all() ?: ['—']),
                TextColumn::make('linked_products_count')
                    ->label('Linked products')
                    ->badge()
                    ->state(fn (StockPool $record): int => self::memberProducts($record)
                        ->reject(fn (Product $p): bool => (int) $p->getKey() === (int) $record->source_product_id)
                        ->count()),
                TextColumn::make('shared_stock')
                    ->label('Live shared stock')
                    ->state(fn (StockPool $record): int => (int) $record->sourceProduct?->stock)
                    ->badge()
                    ->color('success'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->before(fn (StockPool $record) => self::syncMembers($record, [])),
            ]);
    }

    /** @return Collection<int, Product> */
    public static function memberProducts(StockPool $pool): Collection
    {
        return Product::query()
            ->withoutGlobalScopes()
            ->where('stock_pool_id', $pool->getKey())
            ->with('company')
            ->get();
    }

    /**
     * Sets stock_pool_id on the source + selected member products, clears
     * it from anything previously in this pool that's no longer selected,
     * and resyncs every touched product's displayed stock so removed
     * members immediately revert to their own independent ledger.
     *
     * @param  list<int>  $memberProductIds
     */
    public static function syncMembers(StockPool $pool, array $memberProductIds): void
    {
        $desiredIds = collect($memberProductIds)
            ->push($pool->source_product_id)
            ->filter()
            ->unique()
            ->values();

        $previousIds = Product::query()->withoutGlobalScopes()->where('stock_pool_id', $pool->getKey())->pluck('id');

        if ($desiredIds->isNotEmpty()) {
            Product::query()->withoutGlobalScopes()->whereIn('id', $desiredIds)->update(['stock_pool_id' => $pool->getKey()]);
        }

        $removedIds = $previousIds->diff($desiredIds);

        if ($removedIds->isNotEmpty()) {
            Product::query()->withoutGlobalScopes()->whereIn('id', $removedIds)->update(['stock_pool_id' => null]);
        }

        foreach ($desiredIds->merge($removedIds)->unique() as $id) {
            app(StockMovementService::class)->syncProductStock((int) $id);
        }
    }

    /** @return array<int, string> */
    protected static function productOptions(?int $excludeId = null, ?int $currentPoolId = null): array
    {
        return Product::query()
            ->withoutGlobalScopes()
            ->where('has_variants', false)
            ->where(function ($query) use ($currentPoolId): void {
                $query->whereNull('stock_pool_id');

                if ($currentPoolId) {
                    $query->orWhere('stock_pool_id', $currentPoolId);
                }
            })
            ->when($excludeId, fn ($query) => $query->whereKeyNot($excludeId))
            ->with('company')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Product $product): array => [
                $product->getKey() => "{$product->name} — {$product->company?->name} (SKU: {$product->sku})",
            ])
            ->all();
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function canEdit($record): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function canDelete($record): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockPools::route('/'),
            'create' => CreateStockPool::route('/create'),
            'bulk-link' => BulkLinkStockPools::route('/bulk-link'),
            'edit' => EditStockPool::route('/{record}/edit'),
        ];
    }
}
