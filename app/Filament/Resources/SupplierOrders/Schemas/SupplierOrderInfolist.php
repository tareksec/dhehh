<?php

namespace App\Filament\Resources\SupplierOrders\Schemas;

use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupplierOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Order'))
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('order_number')->label(__('Order Number')),
                        TextEntry::make('supplier.name')->label(__('Supplier')),
                        TextEntry::make('order_date')->label(__('Order Date'))->date(),
                        TextEntry::make('delivery_date')->label(__('Delivery Date'))->date()->placeholder(__('Not set')),
                        TextEntry::make('customer_name')->label(__('Customer Name'))->placeholder(__('Not set')),
                        TextEntry::make('customer_phone')->label(__('Customer Phone'))->placeholder(__('Not set')),
                        TextEntry::make('status')
                            ->label(__('Status'))
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => __(SupplierOrder::STATUSES[$state] ?? $state)),
                        TextEntry::make('note')->label(__('Note'))->placeholder(__('Not set'))->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make(__('Goods'))
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('product.name')->label(__('Product')),
                                TextEntry::make('variant_label')
                                    ->label(__('Variant'))
                                    ->state(fn (SupplierOrderItem $record): ?string => $record->variant?->label())
                                    ->placeholder('-'),
                                TextEntry::make('quantity')->label(__('Pieces'))->numeric(),
                                TextEntry::make('unit_price')->label(__('Customer price (per pc)'))->moneyWithoutTrailingZeroes('BDT'),
                                TextEntry::make('supplier_unit_cost')->label(__('Supplier buy price (per pc)'))->moneyWithoutTrailingZeroes('BDT'),
                                TextEntry::make('line_amount')
                                    ->label(__('Customer pays'))
                                    ->state(fn (SupplierOrderItem $record): float => $record->amount())
                                    ->moneyWithoutTrailingZeroes('BDT'),
                                TextEntry::make('line_cost')
                                    ->label(__('Off supplier account'))
                                    ->state(fn (SupplierOrderItem $record): float => $record->cost())
                                    ->moneyWithoutTrailingZeroes('BDT'),
                            ])
                            ->columns(4),
                    ]),

                Section::make(__('Totals'))
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('total_pieces')
                            ->label(__('Total pieces'))
                            ->state(fn (SupplierOrder $record): int => $record->totalPieces())
                            ->numeric(),
                        TextEntry::make('total_amount')
                            ->label(__('Customer pays'))
                            ->state(fn (SupplierOrder $record): float => $record->totalAmount())
                            ->moneyWithoutTrailingZeroes('BDT'),
                        TextEntry::make('advance_amount')
                            ->label(__('Advance paid by customer'))
                            ->moneyWithoutTrailingZeroes('BDT'),
                        TextEntry::make('advance_collected_by')
                            ->label(__('Money is with'))
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => __(SupplierOrder::COLLECTED_BY[$state] ?? $state)),
                        TextEntry::make('due_amount')
                            ->label(__('Still to collect'))
                            ->state(fn (SupplierOrder $record): float => $record->dueAmount())
                            ->moneyWithoutTrailingZeroes('BDT'),
                        TextEntry::make('taken_value')
                            ->label(__('Off supplier account'))
                            ->state(fn (SupplierOrder $record): float => $record->isTaken() ? $record->takenValue() : 0.0)
                            ->moneyWithoutTrailingZeroes('BDT'),
                    ])
                    ->columns(3),
            ]);
    }
}
