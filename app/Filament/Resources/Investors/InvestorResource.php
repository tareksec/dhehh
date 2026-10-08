<?php

namespace App\Filament\Resources\Investors;

use App\Filament\Clusters\Investments;
use App\Filament\Resources\InvestmentProjects\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Investors\Pages\CreateInvestor;
use App\Filament\Resources\Investors\Pages\EditInvestor;
use App\Filament\Resources\Investors\Pages\ListInvestors;
use App\Filament\Resources\Investors\Pages\ViewInvestor;
use App\Filament\Resources\Investors\RelationManagers\InvestmentsRelationManager;
use App\Filament\Resources\Investors\RelationManagers\WithdrawalNoticesRelationManager;
use App\Models\ChannelPartner;
use App\Models\Company;
use App\Models\Investment;
use App\Models\InvestmentDocument;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Support\CompanyScopedUnique;
use App\Support\InvestorIdentity;
use BackedEnum;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
                    ->relationship('channelPartner', 'name')
                    ->searchable()->preload()
                    ->disabled(fn (?Investor $record): bool => filled($record?->getOriginal('channel_partner_id')) && ! (auth()->user()?->hasPermission('investments.manage_channel_partner') ?? false))
                    ->dehydrated(),
                Textarea::make('channel_partner_change_reason')->label('Channel Partner Change Reason')
                    ->helperText('শুধু বিদ্যমান একজন channel partner-কে বদলানোর সময় লাগবে — প্রথমবার assign করলে দরকার নেই।')
                    ->required(fn (?Investor $record): bool => filled($record?->getOriginal('channel_partner_id')) && (auth()->user()?->hasPermission('investments.manage_channel_partner') ?? false))
                    ->visible(fn (?Investor $record): bool => filled($record?->getOriginal('channel_partner_id')) && (auth()->user()?->hasPermission('investments.manage_channel_partner') ?? false))
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
            Section::make(__('Paper copies'))
                ->description(__('Scanned copies are kept in private storage and only users who can manage investments can see them. Files uploaded here are added to the Documents tab; nothing already there is replaced.'))
                ->visible(fn (): bool => auth()->user()?->hasPermission('investments.manage') ?? false)
                ->schema(collect(self::PAPER_COPY_UPLOADS)
                    ->map(fn (string $category, string $field): FileUpload => FileUpload::make($field)
                        ->label(InvestmentDocument::categoryLabel($category))
                        ->multiple()
                        ->maxFiles(10)
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(10240)
                        ->disk(fn (): string => app(CompanyStorageService::class)->privateDiskName())
                        ->directory(fn (?Investor $record): string => app(CompanyStorageService::class)->privateDirectory(self::paperCopyCompany($record), 'investment-documents'))
                        ->visibility('private')
                        ->previewable(false)
                        ->openable(false))
                    ->values()->all())
                ->columns(2)->collapsible(),
        ]);
    }

    /** Upload field on the investor form => document category it is saved as. */
    public const PAPER_COPY_UPLOADS = [
        'paper_investor_nid' => InvestmentDocument::CATEGORY_INVESTOR_NID,
        'paper_nominee_nid' => InvestmentDocument::CATEGORY_NOMINEE_NID,
        'paper_signed_contract' => 'signed_contract',
        'paper_cheque_copy' => 'cheque_copy',
    ];

    private static function paperCopyCompany(?Investor $record): Company
    {
        $company = $record?->company ?? app(CompanyContext::class)->company();
        abort_unless($company instanceof Company, 422, __('Switch to a single company before uploading documents.'));

        return $company;
    }

    /**
     * Take the paper-copy uploads out of the form data.
     *
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>} [investor data, category => stored paths]
     */
    public static function splitPaperCopies(array $data): array
    {
        $files = [];

        foreach (self::PAPER_COPY_UPLOADS as $field => $category) {
            $files[$category] = array_values(array_filter((array) ($data[$field] ?? [])));
            unset($data[$field]);
        }

        return [$data, array_filter($files)];
    }

    /** @param  array<string, list<string>>  $files  category => stored paths */
    public static function attachPaperCopies(Investor $investor, array $files): void
    {
        abort_unless(auth()->user()?->hasPermission('investments.manage') || $files === [], 403);

        foreach ($files as $category => $paths) {
            foreach ($paths as $path) {
                $investor->documents()->create([
                    'company_id' => $investor->company_id,
                    'category' => $category,
                    'file_path' => $path,
                ]);
            }
        }
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
                TextEntry::make('lifetime_invested')->state(fn (Investor $record): float => $record->totalInvestedLifetime())->moneyWithoutTrailingZeroes('BDT'),
                TextEntry::make('lifetime_profit')->state(fn (Investor $record): float => $record->totalProfitReceivedLifetime())->moneyWithoutTrailingZeroes('BDT'),
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
                ->description(__('Upload them on the investor\'s edit page or in the Documents tab below.'))
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
        return $table->modifyQueryUsing(fn ($query) => $query->withSum('investments', 'amount')->with('investments.project:id,project_code,name'))
            // An NID typed with spaces ("1479 939 603") is one search term.
            ->splitSearchTerms(false)
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('phone')->searchable(),
                // nid_number is encrypted at rest (v3 P3), so search goes through
                // the keyed nid_hash of the exact (normalised) number instead.
                TextColumn::make('nid_number')
                    ->label(__('Investor ID (NID / Passport)'))
                    ->formatStateUsing(fn (?string $state): ?string => InvestorIdentity::normalize($state))
                    ->placeholder(__('NID missing'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('nid_hash', InvestorIdentity::hash($search) ?? '-')),
                TextColumn::make('channelPartner.name')->label('Channel Partner')->placeholder('Direct'),
                TextColumn::make('investment_projects')
                    ->label(__('Projects'))
                    ->badge()
                    ->state(fn (Investor $record): array => $record->investments
                        ->map(fn ($investment): ?string => $investment->project?->project_code ?: $investment->project?->name)
                        ->filter()->unique()->values()->all())
                    ->placeholder('-'),
                TextColumn::make('investments_sum_amount')->label('Lifetime Invested')->moneyWithoutTrailingZeroes('BDT'),
                TextColumn::make('investment_status')
                    ->label(__('Status'))
                    ->badge()
                    ->state(fn (Investor $record): string => match ($record->investmentStatus()) {
                        Investor::STATUS_WITHDRAWN => __('Withdrawn'),
                        Investor::STATUS_ACTIVE => __('Active'),
                        default => __('No investment'),
                    })
                    ->color(fn (Investor $record): string => match ($record->investmentStatus()) {
                        Investor::STATUS_WITHDRAWN => 'gray',
                        Investor::STATUS_ACTIVE => 'success',
                        default => 'warning',
                    }),
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
                SelectFilter::make('project')
                    ->label(__('Project'))
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => self::projectFilterOptions())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['values'] ?? null),
                        fn (Builder $query): Builder => $query->whereHas('investments', fn (Builder $investments) => $investments->whereIn('project_id', $data['values'])),
                    )),
                SelectFilter::make('investment_status')
                    ->label(__('Status'))
                    ->options([Investor::STATUS_ACTIVE => __('Active'), Investor::STATUS_WITHDRAWN => __('Withdrawn')])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        Investor::STATUS_ACTIVE => $query->whereHas('investments', fn (Builder $investments) => $investments->where('status', Investment::STATUS_ACTIVE)),
                        Investor::STATUS_WITHDRAWN => $query->whereHas('investments')->whereDoesntHave('investments', fn (Builder $investments) => $investments->where('status', Investment::STATUS_ACTIVE)),
                        default => $query,
                    }),
                SelectFilter::make('channel_partner_id')
                    ->label('Channel Partner')
                    ->relationship('channelPartner', 'name')
                    ->searchable()->preload(),
                Filter::make('nid_missing')->label(__('NID missing'))->query(fn (Builder $query): Builder => $query->whereNull('nid_hash')),
                Filter::make('documents_missing')
                    ->label(__('Paper copies missing'))
                    ->visible(fn (): bool => auth()->user()?->hasPermission('investments.manage') ?? false)
                    ->query(fn (Builder $query): Builder => $query->missingDocuments()),
            ])->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('assignChannelPartner')
                        ->label(__('Assign channel partner'))
                        ->icon(Heroicon::OutlinedUserPlus)
                        ->visible(fn (): bool => auth()->user()?->hasPermission('investments.manage') ?? false)
                        ->modalDescription(__('Sets the channel partner of the selected investors who do not have one yet. Investors who already have a channel partner are left unchanged; change those one by one from their edit page.'))
                        ->schema([
                            Select::make('channel_partner_id')
                                ->label('Channel Partner')
                                ->options(fn (): array => ChannelPartner::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            abort_unless(auth()->user()?->hasPermission('investments.manage'), 403);

                            $partnerId = (int) $data['channel_partner_id'];
                            $assigned = 0;
                            $skipped = 0;

                            foreach ($records as $investor) {
                                if (filled($investor->channel_partner_id)) {
                                    $skipped++;

                                    continue;
                                }

                                $investor->update(['channel_partner_id' => $partnerId]);
                                $assigned++;
                            }

                            Notification::make()
                                ->title(__('Channel partner set for :count investors', ['count' => $assigned]))
                                ->body($skipped > 0 ? __(':count investors were left unchanged because they already have a channel partner.', ['count' => $skipped]) : null)
                                ->status($skipped > 0 ? 'warning' : 'success')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /**
     * Projects offered by the investor list's project filter, newest first.
     * Used by the filter dropdown and by the project picker on the table toolbar.
     *
     * @return array<int, string> project id => "CODE — name"
     */
    public static function projectFilterOptions(): array
    {
        return InvestmentProject::query()
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (InvestmentProject $project): array => [$project->getKey() => trim(($project->project_code ? $project->project_code.' — ' : '').$project->name)])
            ->all();
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
            && (auth()->user()?->hasPermission('investments.manage') ?? false);
    }

    public static function getRelations(): array
    {
        return [InvestmentsRelationManager::class, DocumentsRelationManager::class, WithdrawalNoticesRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListInvestors::route('/'), 'create' => CreateInvestor::route('/create'), 'view' => ViewInvestor::route('/{record}'), 'edit' => EditInvestor::route('/{record}/edit')];
    }
}
