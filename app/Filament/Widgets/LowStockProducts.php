<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Services\LowStockAlertService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class LowStockProducts extends TableWidget
{
    use GuardsWidgetRendering;

    protected int | string | array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return Auth::user()?->hasPermission('inventory.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Low Stock Notifications')
            ->description(fn (): string => app(LowStockAlertService::class)->message())
            ->query(fn (): Builder => app(LowStockAlertService::class)
                ->query()
                ->orderBy('stock')
                ->orderBy('name'))
            ->columns([
                TextColumn::make('name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),

                TextColumn::make('category.name')
                    ->label('Category')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('stock')
                    ->label('Stock')
                    ->badge()
                    ->color('danger')
                    ->sortable(),

                TextColumn::make('reorder_level')
                    ->label('Reorder Level')
                    ->badge()
                    ->color('warning')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('viewProduct')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (Product $record): string => ProductResource::getUrl('view', ['record' => $record])),

                Action::make('editProduct')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (Product $record): string => ProductResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (): bool => Auth::user()?->canPerformModelAbility('update', Product::class) ?? false),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }
}
