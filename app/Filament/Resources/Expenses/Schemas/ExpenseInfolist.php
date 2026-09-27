<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Models\Expense;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ExpenseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Expense')->columnSpanFull()->schema([
                TextEntry::make('expense_number')->label('Expense Number'),
                TextEntry::make('category.name')->label('Category'),
                TextEntry::make('account.name')->label('Account'),
                TextEntry::make('expense_date')->date(),
                TextEntry::make('amount')->moneyWithoutTrailingZeroes('BDT'),
                TextEntry::make('reference'),
            ])->columns(2),
            TextEntry::make('note')->columnSpanFull(),
            Section::make(__('Scanned source'))
                ->description(__('This expense was read by Smart Scan from the photo or pasted text below.'))
                ->columnSpanFull()
                ->collapsible()
                ->visible(fn (Expense $record): bool => $record->expense_scan_id !== null)
                ->schema([
                    View::make('filament.expense-scans.images')
                        ->viewData(fn (Expense $record): array => ['scan' => $record->scan]),
                ]),
        ]);
    }
}
