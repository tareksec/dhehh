<?php

namespace App\Filament\Trade\Resources\MoneyAccounts;

use App\Filament\Trade\Resources\MoneyAccounts\Pages\CreateMoneyAccount;
use App\Filament\Trade\Resources\MoneyAccounts\Pages\EditMoneyAccount;
use App\Filament\Trade\Resources\MoneyAccounts\Pages\ListMoneyAccounts;
use App\Models\Account;
use App\Support\ImsNumbers;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The cash box and bank accounts in the Trade Portal: the ERP's own money
 * accounts (cash, bank, mobile banking; never the system accounts), each with
 * the money in it now. Adding one sets the money in it today as its opening
 * balance. Needs "trade.money_accounts".
 */
class MoneyAccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static ?string $slug = 'accounts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('account');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Cash and bank accounts');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('trade.money_accounts') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canEdit($record): bool
    {
        return static::canAccess() && $record instanceof Account && ! $record->isSystem();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->manual();
    }

    /** @return array<string, string> */
    public static function typeOptions(): array
    {
        return collect(Account::MANUAL_TYPES)->map(fn (string $label): string => __($label))->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('Account'))
                ->schema([
                    TextInput::make('name')->label(__('Name'))->placeholder(__('e.g. Cash box, DBBL Tongi, bKash'))->required()->maxLength(255),
                    Select::make('type')->label(__('Kind'))->options(static::typeOptions())->default('cash')->required()->disabledOn('edit'),
                    TextInput::make('opening_balance')
                        ->label(__('Money in it when you started'))
                        ->helperText(__('For a new account: the money in it today. Everything after is added by the cash book.'))
                        ->numeric()
                        ->default(0)
                        ->required(),
                    Toggle::make('is_active')->label(__('In use'))->default(true),
                ])
                ->columns(['default' => 1, 'md' => 2]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Account'))->searchable()->weight('bold'),
                TextColumn::make('type')->label(__('Kind'))->formatStateUsing(fn (string $state): string => static::typeOptions()[$state] ?? $state),
                TextColumn::make('current_balance')->label(__('Money in it now'))->formatStateUsing(fn ($state): string => ImsNumbers::money($state)),
            ])
            ->recordActions([EditAction::make()])
            ->emptyStateHeading(__('No account yet'))
            ->emptyStateDescription(__('Add your cash box and bank accounts first, with the money in each today.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMoneyAccounts::route('/'),
            'create' => CreateMoneyAccount::route('/create'),
            'edit' => EditMoneyAccount::route('/{record}/edit'),
        ];
    }
}
