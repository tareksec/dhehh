<?php

namespace App\Filament\Resources\StockMovements\Schemas;

use App\Models\StockMovement;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class StockMovementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Movement Details')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('product_id')
                            ->label('Product')
                            ->relationship('product', 'name')
                            ->searchable()
                            ->required(),

                        Select::make('type')
                            ->label('Type')
                            ->options(StockMovement::userSelectableTypes())
                            ->live()
                            ->required(),

                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->integer()
                            ->required()
                            ->minValue(fn (Get $get): ?int => $get('type') === 'adjustment' ? null : 1)
                            ->helperText(fn (Get $get): string => match ($get('type')) {
                                'adjustment' => 'Use a signed non-zero value: positive adds stock, negative removes stock.',
                                'damage' => 'Use a positive quantity — the pieces damaged/written off.',
                                default => 'Use a positive quantity. Sales are automatically counted as outgoing stock.',
                            }),

                        TextInput::make('reason')
                            ->label('Reason')
                            ->maxLength(255)
                            ->required(fn (Get $get): bool => in_array($get('type'), ['adjustment', 'damage'], true))
                            ->helperText(fn (Get $get): string => match ($get('type')) {
                                'adjustment' => 'Required when stock is manually adjusted.',
                                'damage' => 'Required — explain how the stock was damaged.',
                                default => 'Optional context for this stock movement.',
                            }),

                        Select::make('damage_source')
                            ->label(__('Damage source'))
                            ->options(fn (): array => StockMovement::damageSources())
                            ->native(false)
                            ->visible(fn (Get $get): bool => $get('type') === 'damage')
                            ->required(fn (Get $get): bool => $get('type') === 'damage')
                            ->helperText(__('Pick "Customer return damage" for pieces a customer sent back damaged and "Shipment damage" for pieces found damaged when a shipment was opened.')),

                        TextInput::make('reference_type')
                            ->label('Reference Type')
                            ->maxLength(255),

                        TextInput::make('reference_id')
                            ->label('Reference ID')
                            ->numeric()
                            ->minValue(1),

                        Textarea::make('note')
                            ->label('Note')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
