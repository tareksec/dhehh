<?php

namespace App\Filament\Resources\ChannelPartners;

use App\Filament\Clusters\Investments;
use App\Filament\Resources\ChannelPartners\Pages\CreateChannelPartner;
use App\Filament\Resources\ChannelPartners\Pages\EditChannelPartner;
use App\Filament\Resources\ChannelPartners\Pages\ListChannelPartners;
use App\Filament\Resources\ChannelPartners\Pages\ViewChannelPartner;
use App\Filament\Resources\ChannelPartners\RelationManagers\PayoutsRelationManager;
use App\Filament\Resources\ChannelPartners\RelationManagers\ReferredInvestorsRelationManager;
use App\Filament\Resources\InvestmentProjects\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Investors\RelationManagers\BankAccountsRelationManager;
use App\Models\ChannelPartner;
use App\Support\InvestorIdentity;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Channel partners, kept apart from investors with their own accounts
 * (owner, 2026-09-28).
 */
class ChannelPartnerResource extends Resource
{
    protected static ?string $model = ChannelPartner::class;

    protected static ?string $cluster = Investments::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return __('Channel Partners');
    }

    public static function getModelLabel(): string
    {
        return __('Channel Partner');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Channel Partners');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('Channel Partner'))->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                TextInput::make('phone')->label(__('Phone'))->tel()->required()->maxLength(255),
                TextInput::make('nid_number')
                    ->label(__('NID / Passport (Partner ID)'))
                    ->required()
                    ->rule(fn (?ChannelPartner $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        $owner = ChannelPartner::query()->whereNid((string) $value)->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))->first();

                        if ($owner) {
                            $fail(__('This NID / passport number already belongs to channel partner :name.', ['name' => $owner->name]));
                        }
                    }),
                Toggle::make('is_active')->label(__('Active'))->default(true),
                Textarea::make('address')->label(__('Address'))->columnSpanFull(),
            ])->columns(2),
            Section::make(__('Payment account'))
                ->description(__('Where this partner\'s commission is sent.'))
                ->schema([
                    Select::make('payment_method')->label(__('Payment Method'))->options(collect(ChannelPartner::PAYMENT_METHODS)->map(fn (string $label): string => __($label))->all()),
                    TextInput::make('account_number')->label(__('Account / wallet number'))->maxLength(255),
                    TextInput::make('account_name')->label(__('Account name'))->maxLength(255),
                    TextInput::make('bank_name')->label(__('Bank name'))->maxLength(255),
                    TextInput::make('bank_branch')->label(__('Branch'))->maxLength(255),
                ])->columns(2)->collapsible(),
            Section::make(__('Notes'))->schema([
                Textarea::make('notes')->hiddenLabel()->rows(3),
            ])->collapsible()->collapsed(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('Channel Partner'))->schema([
                TextEntry::make('name')->label(__('Name')),
                TextEntry::make('phone')->label(__('Phone')),
                TextEntry::make('nid_number')->label(__('NID / Passport (Partner ID)'))->formatStateUsing(fn (?string $state): string => InvestorIdentity::normalize($state) ?? '-')->placeholder('-'),
                TextEntry::make('is_active')->label(__('Status'))->badge()->formatStateUsing(fn (bool $state): string => $state ? __('Active') : __('Inactive'))->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextEntry::make('address')->label(__('Address'))->placeholder('-')->columnSpanFull(),
            ])->columns(2),
            Section::make(__('Accounts'))->schema([
                TextEntry::make('investors_count')->label(__('Investors brought in'))->state(fn (ChannelPartner $record): int => $record->investors()->count()),
                TextEntry::make('referred_capital')->label(__('Their active capital'))->state(fn (ChannelPartner $record): float => $record->referredCapital())->moneyWithoutTrailingZeroes('BDT'),
                TextEntry::make('earned')->label(__('Commission earned'))->state(fn (ChannelPartner $record): float => $record->totalEarned())->moneyWithoutTrailingZeroes('BDT'),
                TextEntry::make('paid')->label(__('Commission paid'))->state(fn (ChannelPartner $record): float => $record->totalPaid())->moneyWithoutTrailingZeroes('BDT'),
                TextEntry::make('due')->label(__('Commission due'))->state(fn (ChannelPartner $record): float => $record->totalDue())->moneyWithoutTrailingZeroes('BDT')->color(fn (ChannelPartner $record): string => $record->totalDue() > 0 ? 'warning' : 'success'),
            ])->columns(5),
            Section::make(__('Payment account'))->schema([
                TextEntry::make('payment_method')->label(__('Payment Method'))->formatStateUsing(fn (?string $state): string => __(ChannelPartner::PAYMENT_METHODS[$state] ?? (string) $state))->placeholder('-'),
                TextEntry::make('account_number')->label(__('Account / wallet number'))->placeholder('-'),
                TextEntry::make('account_name')->label(__('Account name'))->placeholder('-'),
                TextEntry::make('bank_name')->label(__('Bank name'))->placeholder('-'),
                TextEntry::make('bank_branch')->label(__('Branch'))->placeholder('-'),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount('investors')
                ->withSum('payouts', 'amount')
                ->withSum(['payouts as paid_payouts_sum' => fn (Builder $payouts) => $payouts->where('payment_status', 'paid')], 'amount'))
            ->splitSearchTerms(false)
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('phone')->label(__('Phone'))->searchable(),
                TextColumn::make('nid_number')
                    ->label(__('Partner ID (NID / Passport)'))
                    ->formatStateUsing(fn (?string $state): ?string => InvestorIdentity::normalize($state))
                    ->placeholder(__('NID missing'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('nid_hash', InvestorIdentity::hash($search) ?? '-')),
                TextColumn::make('investors_count')->label(__('Investors brought in'))->sortable(),
                TextColumn::make('payouts_sum_amount')->label(__('Commission earned'))->moneyWithoutTrailingZeroes('BDT')->placeholder('-'),
                TextColumn::make('commission_due')
                    ->label(__('Commission due'))
                    ->state(fn (ChannelPartner $record): float => round((float) $record->payouts_sum_amount - (float) $record->paid_payouts_sum, 2))
                    ->moneyWithoutTrailingZeroes('BDT'),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('Active')),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [ReferredInvestorsRelationManager::class, PayoutsRelationManager::class, DocumentsRelationManager::class, BankAccountsRelationManager::class];
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasPermission('investments.manage') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->hasPermission('investments.manage') ?? false;
    }

    /** A partner with investors or payouts is kept as a record. */
    public static function canDelete(Model $record): bool
    {
        return $record instanceof ChannelPartner
            && ! $record->investors()->exists()
            && ! $record->payouts()->exists()
            && (auth()->user()?->hasPermission('investments.manage') ?? false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChannelPartners::route('/'),
            'create' => CreateChannelPartner::route('/create'),
            'view' => ViewChannelPartner::route('/{record}'),
            'edit' => EditChannelPartner::route('/{record}/edit'),
        ];
    }
}
