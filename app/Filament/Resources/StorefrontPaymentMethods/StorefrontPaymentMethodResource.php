<?php

namespace App\Filament\Resources\StorefrontPaymentMethods;

use App\Filament\Clusters\Storefront;
use App\Filament\Concerns\TrashFollowsDelete;
use App\Filament\Resources\StorefrontPaymentMethods\Pages\CreateStorefrontPaymentMethod;
use App\Filament\Resources\StorefrontPaymentMethods\Pages\EditStorefrontPaymentMethod;
use App\Filament\Resources\StorefrontPaymentMethods\Pages\ListStorefrontPaymentMethods;
use App\Filament\Resources\StorefrontPaymentMethods\Pages\TrashedStorefrontPaymentMethods;
use App\Models\StorefrontPaymentMethod;
use App\Services\CompanyContext;
use App\Support\CompanyScopedUnique;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Unique;

class StorefrontPaymentMethodResource extends Resource
{
    use TrashFollowsDelete;

    protected static ?string $model = StorefrontPaymentMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $cluster = Storefront::class;

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Payment Methods';

    protected static ?string $modelLabel = 'Payment Method';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Payment Method')
                ->columnSpanFull()
                ->description('Only active methods below appear on the storefront checkout page, in this order.')
                ->schema([
                    Select::make('company_id')
                        ->relationship('company', 'name')
                        ->required(fn (): bool => app(CompanyContext::class)->isAllCompanies())
                        ->visible(fn (): bool => app(CompanyContext::class)->isAllCompanies())
                        ->helperText('Select the company that will offer this payment method.')
                        ->searchable()
                        ->preload()
                        ->live(),
                    Select::make('type')
                        ->options(StorefrontPaymentMethod::TYPES)
                        ->default(StorefrontPaymentMethod::TYPE_MANUAL)
                        ->required()
                        ->live()
                        ->helperText('Cash on Delivery and the Online Gateway can each only be added once per company. Add as many Manual channels as you need.')
                        ->rule(function (Get $get, ?StorefrontPaymentMethod $record): \Closure {
                            return function (string $attribute, mixed $value, \Closure $fail) use ($get, $record): void {
                                if ($value === StorefrontPaymentMethod::TYPE_MANUAL) {
                                    return;
                                }

                                $companyId = $get('company_id') ?? $record?->company_id ?? app(CompanyContext::class)->id();

                                // withoutGlobalScopes() on purpose: a method in Trash still counts, so a Restore can never make a second one.
                                $existing = StorefrontPaymentMethod::withoutGlobalScopes()
                                    ->where('company_id', $companyId)
                                    ->where('type', $value)
                                    ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                                    ->first();

                                if ($existing?->trashed()) {
                                    $fail(__('This company already has a payment method of this type, and it is in Trash. Restore it from Trash instead of adding another.'));
                                } elseif ($existing) {
                                    $fail(__('This company already has a payment method of this type. Edit the existing one instead of adding another.'));
                                }
                            };
                        }),
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, ?Model $record): Unique => CompanyScopedUnique::rule()($rule, $record)->where('reseller_only', 0))
                        ->validationMessages(['unique' => __('This is already used, possibly by an item in Trash. Use another one, or restore or permanently delete the one in Trash.')])
                        ->placeholder('e.g. Rocket (Send Money), Bank Transfer')
                        ->helperText('Shown to the customer as the option label at checkout.'),
                    TextInput::make('account_number')
                        ->label('Send-money / account number')
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => $get('type') === StorefrontPaymentMethod::TYPE_MANUAL)
                        ->required(fn (Get $get): bool => $get('type') === StorefrontPaymentMethod::TYPE_MANUAL)
                        ->placeholder('01XXXXXXXXX or bank account number'),
                    Textarea::make('instructions')
                        ->rows(2)
                        ->maxLength(500)
                        ->visible(fn (Get $get): bool => $get('type') === StorefrontPaymentMethod::TYPE_MANUAL)
                        ->placeholder('Send Money to this number, then enter the Transaction ID below.')
                        ->columnSpanFull(),
                    Toggle::make('is_active')
                        ->label('Active (show at checkout)')
                        ->default(true)
                        ->helperText(fn (Get $get): ?string => $get('type') === StorefrontPaymentMethod::TYPE_ONLINE_GATEWAY
                            ? 'Also requires a configured, active gateway under Storefront Settings → Online Payments — this only controls whether it is offered as a normal checkout choice.'
                            : null),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->limit(40),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StorefrontPaymentMethod::TYPES[$state] ?? $state),
                TextColumn::make('company.name')
                    ->label('Company')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('account_number')->placeholder('-'),
                IconColumn::make('is_active')->label('Active')->boolean()->sortable(),
                TextColumn::make('sort_order')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Reseller-only channels are managed in Reseller Settings, not in the store's own list. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->forStore();
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->canManageSettings() ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->canManageSettings() ?? false;
    }

    public static function canEdit($record): bool
    {
        return Auth::user()?->canManageSettings() ?? false;
    }

    public static function canDelete($record): bool
    {
        return Auth::user()?->canManageSettings() ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->canManageSettings() ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStorefrontPaymentMethods::route('/'),
            'trash' => TrashedStorefrontPaymentMethods::route('/trash'),
            'create' => CreateStorefrontPaymentMethod::route('/create'),
            'edit' => EditStorefrontPaymentMethod::route('/{record}/edit'),
        ];
    }
}
