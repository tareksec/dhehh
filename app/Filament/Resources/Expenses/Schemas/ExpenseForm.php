<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Expense')
                ->columnSpanFull()
                ->schema([
                    Select::make('expense_category_id')
                        ->label('Category')
                        ->relationship('category', 'name', fn ($query) => $query->where('is_active', true))
                        ->searchable()
                        ->createOptionForm([
                            TextInput::make('name')
                                ->label('Category Name')
                                ->required()
                                ->maxLength(255),
                            Textarea::make('description')
                                ->rows(3)
                                ->columnSpanFull(),
                        ])
                        ->createOptionUsing(fn (array $data): int => ExpenseCategory::createWithUniqueSlug(
                            $data['name'],
                            $data['description'] ?? null,
                        )->getKey())
                        ->required(),
                    Select::make('account_id')
                        ->label('Pay From Account')
                        ->relationship('account', 'name', fn ($query) => $query->manual()->where('is_active', true))
                        ->searchable()
                        ->required(),
                    DatePicker::make('expense_date')->default(now())->required(),
                    TextInput::make('amount')->numeric()->prefix('৳')->minValue(0.01)->required(),
                    TextInput::make('reference')->maxLength(255),
                ])->columns(2),
            Section::make(__('For a supplier account'))
                ->description(__('Shipping or ad cost ZamZam paid on a supplier\'s behalf. It is added to what we have given that supplier.'))
                ->columnSpanFull()
                ->collapsible()
                ->collapsed(fn (?Expense $record): bool => blank($record?->supplier_id))
                ->schema([
                    Select::make('supplier_id')
                        ->label(__('Supplier'))
                        ->relationship('supplier', 'name', fn ($query) => $query->where('is_active', true))
                        ->searchable(['name', 'phone', 'company_name'])
                        ->preload()
                        ->live(),
                    Select::make('supplier_cost_type')
                        ->label(__('Cost for the supplier'))
                        ->options(fn (): array => collect(Expense::SUPPLIER_COST_TYPES)
                            ->map(fn (string $label): string => __($label))
                            ->all())
                        ->visible(fn (Get $get): bool => filled($get('supplier_id')))
                        ->required(fn (Get $get): bool => filled($get('supplier_id'))),
                ])->columns(2),
            Section::make('Note')->columnSpanFull()->schema([
                Textarea::make('note')->rows(3)->columnSpanFull(),
            ]),
        ]);
    }
}
