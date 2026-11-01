<?php

namespace App\Filament\Resources\SupplierAccountAdjustments;

use App\Filament\Clusters\Purchasing;
use App\Filament\Resources\SupplierAccountAdjustments\Pages\ManageSupplierAccountAdjustments;
use App\Models\SupplierAccountAdjustment;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Cash, shipping and ad cost of a supplier account that live outside the
 * ledgers (the old workbook, or a correction). New money is better recorded as
 * a supplier payment or an expense, which move an account balance.
 */
class SupplierAccountAdjustmentResource extends Resource
{
    protected static ?string $model = SupplierAccountAdjustment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $cluster = Purchasing::class;

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'external_reference';

    public static function getNavigationLabel(): string
    {
        return __('Account Entries');
    }

    public static function getModelLabel(): string
    {
        return __('Account Entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Account Entries');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('supplier_id')
                ->label(__('Supplier'))
                ->relationship('supplier', 'name', fn ($query) => $query->where('is_active', true))
                ->searchable(['name', 'phone', 'company_name'])
                ->preload()
                ->required(),
            DatePicker::make('entry_date')
                ->label(__('Date'))
                ->default(now())
                ->required(),
            Select::make('type')
                ->label(__('Type'))
                ->options(fn (): array => collect(SupplierAccountAdjustment::TYPES)
                    ->map(fn (string $label): string => __($label))
                    ->all())
                ->required(),
            TextInput::make('amount')
                ->label(__('Amount'))
                ->numeric()
                ->prefix('৳')
                ->minValue(0.01)
                ->required(),
            Textarea::make('note')
                ->label(__('Note'))
                ->rows(2)
                ->columnSpanFull()
                ->helperText(__('Figures kept outside the ledgers: old workbook totals or a correction. New cash, shipping or ad cost is better recorded as a supplier payment or an expense.')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('entry_date', 'desc')
            ->columns([
                TextColumn::make('entry_date')->label(__('Date'))->date()->sortable(),
                TextColumn::make('supplier.name')->label(__('Supplier'))->searchable()->sortable(),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __(SupplierAccountAdjustment::TYPES[$state] ?? $state)),
                TextColumn::make('amount')->label(__('Amount'))->moneyWithoutTrailingZeroes('BDT')->alignEnd()->sortable(),
                TextColumn::make('note')->label(__('Note'))->wrap()->limit(80)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label(__('Supplier'))
                    ->relationship('supplier', 'name')
                    ->searchable(),
                SelectFilter::make('type')
                    ->label(__('Type'))
                    ->options(fn (): array => collect(SupplierAccountAdjustment::TYPES)
                        ->map(fn (string $label): string => __($label))
                        ->all()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->headerActions([
                CreateAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSupplierAccountAdjustments::route('/'),
        ];
    }
}
