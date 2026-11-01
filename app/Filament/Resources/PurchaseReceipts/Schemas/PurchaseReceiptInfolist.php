<?php

namespace App\Filament\Resources\PurchaseReceipts\Schemas;

use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PurchaseReceiptInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Receipt'))
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('receipt_number')->label(__('Receipt Number')),
                        TextEntry::make('supplier.name')->label(__('Supplier')),
                        TextEntry::make('received_on')->label(__('Received On'))->date(),
                        TextEntry::make('reference')->label(__('Supplier Challan / Reference'))->placeholder(__('Not set')),
                        TextEntry::make('is_opening')
                            ->label(__('Opening receipt'))
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? __('Yes') : __('No')),
                        TextEntry::make('note')->label(__('Note'))->placeholder(__('Not set'))->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make(__('Goods Received'))
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('product.name')->label(__('Product')),
                                TextEntry::make('variant_label')
                                    ->label(__('Variant'))
                                    ->state(fn (PurchaseReceiptItem $record): ?string => $record->variant?->label())
                                    ->placeholder('-'),
                                TextEntry::make('cartons')->label(__('Cartons'))->placeholder('-'),
                                TextEntry::make('pcs_per_carton')->label(__('Pcs per carton'))->placeholder('-'),
                                TextEntry::make('quantity')->label(__('Received (pcs)'))->numeric(),
                                TextEntry::make('short_quantity')->label(__('Short (pcs)'))->numeric(),
                                TextEntry::make('damage_quantity')->label(__('Damage (pcs)'))->numeric(),
                                TextEntry::make('unit_cost')->label(__('Buy price (per pc)'))->moneyWithoutTrailingZeroes('BDT'),
                                TextEntry::make('line_value')
                                    ->label(__('Value'))
                                    ->state(fn (PurchaseReceiptItem $record): float => $record->value())
                                    ->moneyWithoutTrailingZeroes('BDT'),
                            ])
                            ->columns(5),
                    ]),

                Section::make(__('Totals'))
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('total_pieces')
                            ->label(__('Total received (pcs)'))
                            ->state(fn (PurchaseReceipt $record): int => $record->totalPieces())
                            ->numeric(),
                        TextEntry::make('total_value')
                            ->label(__('Total value'))
                            ->state(fn (PurchaseReceipt $record): float => $record->totalValue())
                            ->moneyWithoutTrailingZeroes('BDT'),
                    ])
                    ->columns(2),
            ]);
    }
}
