<?php

namespace App\Filament\Resources\StockMovements\Tables;

use App\Models\StockMovement;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('product'))
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StockMovement::TYPES[$state] ?? ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'sale', 'damage' => 'danger',
                        'adjustment' => 'warning',
                        default => 'success',
                    }),

                TextColumn::make('quantity')
                    ->label('Quantity')
                    ->sortable(),

                TextColumn::make('signed_quantity')
                    ->label('Stock Impact')
                    ->badge()
                    ->color(fn (int $state): string => $state < 0 ? 'danger' : 'success'),

                TextColumn::make('damage_source')
                    ->label(__('Damage source'))
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn (?string $state): ?string => StockMovement::damageSources()[$state] ?? $state)
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('reason')
                    ->label('Reason')
                    ->limit(40)
                    ->placeholder('-')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('reference_type')
                    ->label('Reference')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reference_id')
                    ->label('Reference ID')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('product_id')
                    ->label('Product')
                    ->relationship('product', 'name')
                    ->searchable(),

                SelectFilter::make('type')
                    ->label('Type')
                    ->options(StockMovement::TYPES),

                SelectFilter::make('damage_source')
                    ->label(__('Damage source'))
                    ->options(fn (): array => StockMovement::damageSources()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            // No bulk delete (owner decision 2026-11-03, plan question 6): a stock movement is a row of
            // the stock ledger, and deleting one rewrites the stock history. A movement is corrected
            // with another movement (an adjustment), or goes with the order / purchase that made it.
            ->defaultSort('created_at', 'desc');
    }
}
