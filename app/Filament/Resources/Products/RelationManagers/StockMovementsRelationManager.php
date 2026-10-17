<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\StockMovement;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StockMovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'stockMovements';

    protected static ?string $title = 'Stock Movement History';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
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
                    ->label('Entered Quantity')
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
                    ->placeholder('Manual')
                    ->toggleable(),

                TextColumn::make('reference_id')
                    ->label('Reference ID')
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('note')
                    ->label('Note')
                    ->limit(50)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
