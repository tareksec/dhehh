<?php

namespace App\Filament\Resources\ExchangeRates;

use App\Filament\Clusters\Investments;
use App\Filament\Resources\ExchangeRates\Pages\CreateExchangeRate;
use App\Filament\Resources\ExchangeRates\Pages\EditExchangeRate;
use App\Filament\Resources\ExchangeRates\Pages\ListExchangeRates;
use App\Models\ExchangeRate;
use App\Services\CompanyContext;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/**
 * Daily USD and RMB rates, typed by staff (17_IMS_IN_PORTAL_PLAN.md, phase R).
 * A project cost entered in a foreign currency is converted with the newest
 * rate on or before its date.
 */
class ExchangeRateResource extends Resource
{
    protected static ?string $model = ExchangeRate::class;

    protected static ?string $cluster = Investments::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'currency';

    public static function getNavigationLabel(): string
    {
        return __('Exchange Rates');
    }

    public static function getModelLabel(): string
    {
        return __('exchange rate');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Exchange Rates');
    }

    public static function canManage(): bool
    {
        return auth()->user()?->hasPermission('investments.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('currency')
                ->label(__('Currency'))
                ->options(collect(ExchangeRate::CURRENCIES)->map(fn (string $label): string => __($label))->all())
                ->required()
                ->live(),
            DatePicker::make('rate_date')
                ->label(__('Rate date'))
                ->default(now())
                ->required()
                ->unique(
                    table: 'exchange_rates',
                    column: 'rate_date',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                        ->where('company_id', app(CompanyContext::class)->id())
                        ->where('currency', $get('currency')),
                )
                ->validationMessages(['unique' => __('A rate for this currency and day is already on file. Edit it instead.')]),
            TextInput::make('rate')
                ->label(__('Rate (BDT for 1 unit)'))
                ->numeric()->minValue(0.000001)->required(),
            TextInput::make('note')->label(__('Note'))->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('rate_date', 'desc')
            ->columns([
                TextColumn::make('rate_date')->label(__('Rate date'))->date()->sortable(),
                TextColumn::make('currency')->label(__('Currency'))
                    ->formatStateUsing(fn (string $state): string => ExchangeRate::labelFor($state))
                    ->badge()->sortable(),
                TextColumn::make('rate')->label(__('Rate (BDT for 1 unit)'))
                    ->formatStateUsing(fn (string $state): string => rtrim(rtrim(number_format((float) $state, 6), '0'), '.')),
                TextColumn::make('note')->label(__('Note'))->placeholder('-')->limit(40),
                TextColumn::make('creator.name')->label(__('Entered by'))->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('currency')->label(__('Currency'))
                    ->options(collect(ExchangeRate::CURRENCIES)->map(fn (string $label): string => __($label))->all()),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function canCreate(): bool
    {
        return static::canManage();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManage();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManage();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExchangeRates::route('/'),
            'create' => CreateExchangeRate::route('/create'),
            'edit' => EditExchangeRate::route('/{record}/edit'),
        ];
    }
}
