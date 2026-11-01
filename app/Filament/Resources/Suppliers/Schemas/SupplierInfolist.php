<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use App\Models\Supplier;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupplierInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Supplier')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('company_name')->label('Company'),
                        TextEntry::make('phone'),
                        TextEntry::make('email'),
                        IconEntry::make('is_active')->boolean(),
                    ])
                    ->columns(2),

                Section::make('Balance')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('opening_balance')->moneyWithoutTrailingZeroes('BDT'),
                        TextEntry::make('current_balance')->moneyWithoutTrailingZeroes('BDT'),
                    ])
                    ->columns(2),

                Section::make(__('Supplier account'))
                    ->description(__('What we still owe this supplier, from his goods received, the goods he took and the advance he collected. The invoice balance above is separate.'))
                    ->columnSpanFull()
                    ->visible(fn (Supplier $record): bool => $record->hasConsignmentAccount())
                    ->schema([
                        TextEntry::make('account_balance')
                            ->label(__('Supplier still gets from us'))
                            ->state(fn (Supplier $record): float => $record->accountStatement()->balance())
                            ->moneyWithoutTrailingZeroes('BDT')
                            ->weight('bold'),
                        TextEntry::make('account_received')
                            ->label(__('Value of goods received'))
                            ->state(fn (Supplier $record): float => $record->accountStatement()->valueReceived)
                            ->moneyWithoutTrailingZeroes('BDT'),
                        TextEntry::make('account_taken')
                            ->label(__('Goods taken'))
                            ->state(fn (Supplier $record): float => $record->accountStatement()->goodsTaken)
                            ->moneyWithoutTrailingZeroes('BDT'),
                        TextEntry::make('account_advance')
                            ->label(__('Customer advance'))
                            ->state(fn (Supplier $record): float => $record->accountStatement()->customerAdvance)
                            ->moneyWithoutTrailingZeroes('BDT'),
                        TextEntry::make('account_given')
                            ->label(__('Total we gave'))
                            ->state(fn (Supplier $record): float => $record->accountStatement()->totalGiven())
                            ->moneyWithoutTrailingZeroes('BDT'),
                    ])
                    ->columns(3),

                TextEntry::make('address')->columnSpanFull(),
            ]);
    }
}
