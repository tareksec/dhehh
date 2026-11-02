<?php

namespace App\Filament\Resources\ResellerLevels;

use App\Filament\Clusters\Resellers;
use App\Filament\Concerns\TrashFollowsDelete;
use App\Filament\Resources\ResellerLevels\Pages\ManageResellerLevels;
use App\Filament\Resources\ResellerLevels\Pages\TrashedResellerLevels;
use App\Filament\Resources\Resellers\ResellerResource;
use App\Models\ResellerLevel;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Unique;

/**
 * The company's reseller levels (owner decisions 2026-10-16): the admin makes up
 * as many as wanted, names them, and sets each reseller's level by hand on the
 * reseller's page. A level is shown to the reseller as a badge and may carry a
 * lower return charge and a lower minimum withdrawal than the amounts in Reseller
 * Settings; an amount left empty means the company's amount applies. One list with
 * pop-up forms, no separate pages.
 */
class ResellerLevelResource extends Resource
{
    use TrashFollowsDelete;

    protected static ?string $model = ResellerLevel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?string $cluster = Resellers::class;

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'reseller-levels';

    public static function getNavigationLabel(): string
    {
        return __('Reseller levels');
    }

    public static function getModelLabel(): string
    {
        return __('Reseller level');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Reseller levels');
    }

    public static function form(Schema $schema): Schema
    {
        $defaults = static::companyDefaults();

        return $schema->components([
            TextInput::make('name')
                ->label(__('Level name'))
                ->required()
                ->maxLength(80)
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, ?ResellerLevel $record): Unique => $rule->where('company_id', $record?->company_id ?? app(CompanyContext::class)->id()),
                )
                // A level in Trash still holds its name (the unique index is unchanged).
                ->validationMessages(['unique' => __('This is already used, possibly by an item in Trash. Use another one, or restore or permanently delete the one in Trash.')]),
            TextInput::make('sort_order')
                ->label(__('Order in lists'))
                ->helperText(__('A smaller number is listed first.'))
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(0)
                ->required(),
            TextInput::make('return_charge')
                ->label(__('Return charge for this level'))
                ->helperText(__('Leave empty to use the company amount (:amount). A lower amount here rewards the level.', ['amount' => $defaults['return_charge']]))
                ->numeric()
                ->minValue(0),
            TextInput::make('min_withdrawal')
                ->label(__('Minimum withdrawal for this level'))
                ->helperText(__('Leave empty to use the company amount (:amount). A lower amount here rewards the level.', ['amount' => $defaults['min_withdrawal']]))
                ->numeric()
                ->minValue(0),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Level'))->searchable()->weight('medium'),
                TextColumn::make('sort_order')->label(__('Order in lists'))->sortable(),
                TextColumn::make('return_charge')
                    ->label(__('Return charge'))
                    ->formatStateUsing(fn ($state): string => $state === null ? __('Company amount') : MoneyFormatter::number($state))
                    ->color(fn ($state): string => $state === null ? 'gray' : 'success'),
                TextColumn::make('min_withdrawal')
                    ->label(__('Minimum withdrawal'))
                    ->formatStateUsing(fn ($state): string => $state === null ? __('Company amount') : MoneyFormatter::number($state))
                    ->color(fn ($state): string => $state === null ? 'gray' : 'success'),
                TextColumn::make('resellers_count')
                    ->label(__('Resellers'))
                    ->counts('resellers')
                    ->numeric(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make()->visible(fn (): bool => static::canManage('update')),
                DeleteAction::make()
                    ->visible(fn (): bool => static::canManage('delete'))
                    // Always a text: a level nobody has used to return null here, which failed the whole request.
                    ->modalDescription(fn (ResellerLevel $record): string => ($record->resellers()->exists()
                        ? __('The resellers on this level go back to the company amounts. Their orders and money are not changed.').' '
                        : '').__('This will be moved to Trash. You can restore it, or delete it permanently, from the Trash page.')),
            ])
            ->emptyStateHeading(__('No reseller levels yet'))
            ->emptyStateDescription(__('Make up the levels you want, for example Bronze, Silver and Gold, then give each reseller a level on the reseller\'s page.'))
            ->emptyStateIcon(Heroicon::OutlinedTrophy);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageResellerLevels::route('/'),
            'trash' => TrashedResellerLevels::route('/trash'),
        ];
    }

    /** The company's own return charge and minimum withdrawal, as text for the helper lines. */
    protected static function companyDefaults(): array
    {
        $companyId = app(CompanyContext::class)->id();
        $setting = $companyId ? StorefrontSetting::bladeFor((int) $companyId) : null;

        return [
            'return_charge' => MoneyFormatter::number((float) ($setting?->reseller_return_charge ?? 0)),
            'min_withdrawal' => MoneyFormatter::number((float) ($setting?->reseller_min_withdrawal ?? 0)),
        ];
    }

    public static function canManage(string $ability): bool
    {
        return (Auth::user()?->canPerformModelAbility($ability, ResellerLevel::class) ?? false)
            && ResellerResource::resellerModuleEnabledForCurrentContext();
    }

    public static function canViewAny(): bool
    {
        return static::canManage('viewAny');
    }

    public static function canCreate(): bool
    {
        return static::canManage('create');
    }

    public static function canEdit($record): bool
    {
        return static::canManage('update');
    }

    public static function canDelete($record): bool
    {
        return static::canManage('delete');
    }

    public static function canDeleteAny(): bool
    {
        return static::canManage('delete');
    }
}
