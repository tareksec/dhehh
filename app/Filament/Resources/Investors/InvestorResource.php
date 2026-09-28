<?php

namespace App\Filament\Resources\Investors;

use App\Filament\Clusters\Investments;
use App\Filament\Resources\InvestmentProjects\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Investors\Pages\CreateInvestor;
use App\Filament\Resources\Investors\Pages\EditInvestor;
use App\Filament\Resources\Investors\Pages\ListInvestors;
use App\Filament\Resources\Investors\Pages\ViewInvestor;
use App\Filament\Resources\Investors\RelationManagers\InvestmentsRelationManager;
use App\Filament\Resources\Investors\RelationManagers\PartnerPayoutsRelationManager;
use App\Filament\Resources\Investors\RelationManagers\WithdrawalNoticesRelationManager;
use App\Models\Investor;
use App\Support\CompanyScopedUnique;
use App\Support\InvestorIdentity;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
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
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InvestorResource extends Resource
{
    protected static ?string $model = Investor::class;

    protected static ?string $cluster = Investments::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Investor Identity')->schema([
                TextInput::make('name')->required(),
                TextInput::make('display_name')
                    ->label('Display / Pseudonym')
                    ->helperText('চুক্তির ধারা ৩ অনুযায়ী shared রিপোর্টে investor ছদ্মনামে দেখাতে চাইলে। খালি রাখলে আসল নাম ব্যবহার হবে।'),
                TextInput::make('guardian_name')->label('Father / Spouse Name'),
                DatePicker::make('date_of_birth')->label('Date of Birth')->maxDate(now()),
                TextInput::make('phone')->tel()->required()->unique(ignoreRecord: true, modifyRuleUsing: CompanyScopedUnique::rule()),
                TextInput::make('email')->email(),
                TextInput::make('nid_number')
                    ->label(__('NID / Passport (Investor ID)'))
                    ->helperText(__('The investor is identified by this number, so it stays the same even if their phone number changes.'))
                    ->required()
                    ->rule(fn (?Investor $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        $owner = Investor::query()->whereNid((string) $value)->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))->first();

                        if ($owner) {
                            $fail(__('This NID / passport number already belongs to investor :name.', ['name' => $owner->name]));
                        }
                    }),
                Select::make('channel_partner_id')
                    ->label('Channel Partner')
                    ->relationship('channelPartner', 'name', fn ($query, ?Investor $record) => $query->when($record, fn ($q) => $q->whereKeyNot($record->getKey())))
                    ->searchable()->preload()
                    ->disabled(fn (?Investor $record): bool => filled($record?->getOriginal('channel_partner_id')) && ! (auth()->user()?->hasPermission('investments.manage_channel_partner') ?? false))
                    ->dehydrated(),
                Textarea::make('channel_partner_change_reason')->label('Channel Partner Change Reason')
                    ->helperText('শুধু বিদ্যমান একজন channel partner-কে বদলানোর সময় লাগবে — প্রথমবার assign করলে দরকার নেই।')
                    ->required(fn (?Investor $record): bool => filled($record?->getOriginal('channel_partner_id')) && (auth()->user()?->hasPermission('investments.manage_channel_partner') ?? false))
                    ->visible(fn (?Investor $record): bool => filled($record?->getOriginal('channel_partner_id')) && (auth()->user()?->hasPermission('investments.manage_channel_partner') ?? false))
                    ->columnSpanFull(),
                Toggle::make('is_channel_partner')
                    ->label('This investor is also a Channel Partner')
                    ->helperText('অন্য investor-দের রেফার করেন এমন একজনকে চিহ্নিত করুন — তালিকায় আলাদা দেখাবে ও চুক্তির নথি/পেআউট হিস্ট্রি ট্যাব চালু হবে।')
                    ->columnSpanFull(),
                Textarea::make('address')->columnSpanFull(),
            ])->columns(2),
            Section::make('Nominee')
                ->description('চুক্তিপত্রের ধারা ১০ অনুযায়ী প্রতি investor একজন নমিনি দেন।')
                ->schema([
                    TextInput::make('nominee_name'),
                    TextInput::make('nominee_nid_or_passport')->label('Nominee NID / Passport'),
                    TextInput::make('nominee_phone')->tel(),
                    TextInput::make('nominee_relation')->label('Relation to Investor')->placeholder('স্ত্রী / পুত্র / ভাই ...'),
                    Textarea::make('nominee_address')->columnSpanFull(),
                ])->columns(2)->collapsible(),
            Section::make('Security Documents')
                ->description('ডিট পেপারের ৳১০০ স্ট্যাম্প ও নিরাপত্তা চেকের নম্বর।')
                ->schema([
                    TextInput::make('stamp_number')
                        ->label('Stamp No.')
                        ->helperText('৳১০০ স্ট্যাম্পের সিরিয়াল নম্বর — একাধিক হলে কমা দিয়ে লিখুন।'),
                    TextInput::make('cheque_number')->label('Cheque No.'),
                ])->columns(2)->collapsible(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Investor Summary')->schema([
                TextEntry::make('name'),
                TextEntry::make('display_name')->label('Display / Pseudonym')->placeholder('-'),
                TextEntry::make('guardian_name')->placeholder('-'),
                TextEntry::make('date_of_birth')->date()->placeholder('-'),
                TextEntry::make('phone'),
                TextEntry::make('email')->placeholder('-'),
                TextEntry::make('nid_number')->label(__('NID / Passport (Investor ID)'))->formatStateUsing(fn (?string $state): string => InvestorIdentity::normalize($state) ?? '-')->placeholder('-'),
                TextEntry::make('channelPartner.name')->label('Channel Partner')->placeholder('Direct investor'),
                TextEntry::make('is_channel_partner')->label('Is a Channel Partner')->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')->badge()->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextEntry::make('lifetime_invested')->state(fn (Investor $record): float => $record->totalInvestedLifetime())->moneyWithoutTrailingZeroes('BDT'),
                TextEntry::make('lifetime_profit')->state(fn (Investor $record): float => $record->totalProfitReceivedLifetime())->moneyWithoutTrailingZeroes('BDT'),
                TextEntry::make('lifetime_partner_payouts')->label('Lifetime Channel Partner Earnings')->visible(fn (Investor $record): bool => $record->is_channel_partner)->state(fn (Investor $record): float => $record->totalChannelPartnerPayoutsPaidLifetime())->moneyWithoutTrailingZeroes('BDT'),
                TextEntry::make('address')->columnSpanFull()->placeholder('-'),
            ])->columns(2),
            Section::make('Nominee')->schema([
                TextEntry::make('nominee_name')->placeholder('-'),
                TextEntry::make('nominee_nid_or_passport')->label('Nominee NID / Passport')->placeholder('-'),
                TextEntry::make('nominee_phone')->placeholder('-'),
                TextEntry::make('nominee_relation')->label('Relation')->placeholder('-'),
                TextEntry::make('nominee_address')->columnSpanFull()->placeholder('-'),
            ])->columns(2),
            Section::make('Security Documents')->schema([
                TextEntry::make('stamp_number')->label('Stamp No.')->placeholder('-'),
                TextEntry::make('cheque_number')->label('Cheque No.')->placeholder('-'),
            ])->columns(2),
            Section::make(__('Paper copies'))
                ->description(__('Upload them in the Documents tab below, or for many investors at once with "Upload documents by NID" on the investor list.'))
                ->visible(fn (): bool => auth()->user()?->hasPermission('investments.manage') ?? false)
                ->schema([
                    TextEntry::make('document_checklist')
                        ->hiddenLabel()
                        ->state(fn (Investor $record): array => collect($record->documentChecklist())
                            ->map(fn (bool $onFile, string $label): string => ($onFile ? '✓ ' : '✗ ').$label.($onFile ? '' : ' — '.__('missing')))
                            ->values()->all())
                        ->listWithLineBreaks(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->withSum('investments', 'amount'))
            // An NID typed with spaces ("1479 939 603") is one search term.
            ->splitSearchTerms(false)
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                IconColumn::make('is_channel_partner')->label('Partner')->boolean()->trueColor('success')->tooltip(fn (bool $state): string => $state ? 'Channel Partner' : 'Direct investor'),
                TextColumn::make('phone')->searchable(),
                // nid_number is encrypted at rest (v3 P3), so search goes through
                // the keyed nid_hash of the exact (normalised) number instead.
                TextColumn::make('nid_number')
                    ->label(__('Investor ID (NID / Passport)'))
                    ->formatStateUsing(fn (?string $state): ?string => InvestorIdentity::normalize($state))
                    ->placeholder(__('NID missing'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('nid_hash', InvestorIdentity::hash($search) ?? '-')),
                TextColumn::make('channelPartner.name')->label('Channel Partner')->placeholder('Direct'),
                TextColumn::make('investments_sum_amount')->label('Lifetime Invested')->moneyWithoutTrailingZeroes('BDT'),
                TextColumn::make('documents_status')
                    ->label(__('Paper copies'))
                    ->badge()
                    ->visible(fn (): bool => auth()->user()?->hasPermission('investments.manage') ?? false)
                    ->state(function (Investor $record): string {
                        $checklist = $record->documentChecklist();
                        $missing = array_keys(array_filter($checklist, fn (bool $onFile): bool => ! $onFile));

                        return $missing === [] ? __('Complete') : __('Missing: :items', ['items' => implode(', ', $missing)]);
                    })
                    ->color(fn (string $state): string => $state === __('Complete') ? 'success' : 'warning')
                    ->wrap(),
            ])->filters([
                TernaryFilter::make('is_channel_partner')->label('Channel Partners'),
                Filter::make('nid_missing')->label(__('NID missing'))->query(fn (Builder $query): Builder => $query->whereNull('nid_hash')),
                Filter::make('documents_missing')
                    ->label(__('Paper copies missing'))
                    ->visible(fn (): bool => auth()->user()?->hasPermission('investments.manage') ?? false)
                    ->query(fn (Builder $query): Builder => $query->missingDocuments()),
            ])->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasPermission('investments.manage') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->hasPermission('investments.manage') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Investor
            && ! $record->investments()->exists()
            && ! $record->referredInvestors()->exists()
            && (auth()->user()?->hasPermission('investments.manage') ?? false);
    }

    public static function getRelations(): array
    {
        return [InvestmentsRelationManager::class, DocumentsRelationManager::class, PartnerPayoutsRelationManager::class, WithdrawalNoticesRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListInvestors::route('/'), 'create' => CreateInvestor::route('/create'), 'view' => ViewInvestor::route('/{record}'), 'edit' => EditInvestor::route('/{record}/edit')];
    }
}
