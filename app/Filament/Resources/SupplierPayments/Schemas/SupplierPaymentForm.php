<?php

namespace App\Filament\Resources\SupplierPayments\Schemas;

use App\Models\Supplier;
use App\Models\SupplierPayment;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class SupplierPaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Payment')
                ->columnSpanFull()
                ->schema([
                    TextInput::make('payment_number')
                        ->label('Payment Number')
                        ->default(fn (): string => SupplierPayment::nextPaymentNumber())
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    Select::make('supplier_id')
                        ->label('Supplier')
                        ->relationship('supplier', 'name', fn ($query) => $query->where('is_active', true))
                        ->searchable()
                        ->live()
                        // A supplier whose goods come through Goods Receipts is paid into his account.
                        ->afterStateUpdated(fn (Set $set, ?int $state) => $set(
                            'for_supplier_account',
                            $state ? Supplier::query()->whereKey($state)->first()?->hasConsignmentAccount() ?? false : false,
                        ))
                        ->required(),
                    Toggle::make('for_supplier_account')
                        ->label(__('Cash into the supplier account'))
                        ->helperText(__('Counts in the supplier account (goods received, goods taken, advance), not against a purchase invoice. It can be paid ahead of the goods.'))
                        ->default(false),
                    Select::make('account_id')
                        ->label('Pay From Account')
                        ->relationship('account', 'name', fn ($query) => $query->manual()->where('is_active', true))
                        ->searchable()
                        ->required(),
                    DatePicker::make('payment_date')->default(now())->required(),
                    TextInput::make('amount')->numeric()->prefix('৳')->minValue(0.01)->required(),
                    Select::make('method')->options(SupplierPayment::METHODS)->default('cash')->required(),
                    TextInput::make('reference')->maxLength(255),
                ])->columns(2),
            Section::make('Note')->columnSpanFull()->schema([
                Textarea::make('note')->rows(3)->columnSpanFull(),
            ]),
        ]);
    }
}
