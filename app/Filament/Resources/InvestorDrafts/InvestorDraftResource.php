<?php

namespace App\Filament\Resources\InvestorDrafts;

use App\Filament\Clusters\Investments;
use App\Filament\Resources\InvestorDrafts\Pages\EditInvestorDraft;
use App\Filament\Resources\InvestorDrafts\Pages\ListInvestorDrafts;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\InvestorDraft;
use App\Services\Investment\InvestorImportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Investors read from an Excel/CSV file or pasted text wait here as drafts.
 * Staff open each one (the investor form, pre-filled), fix it, and confirm
 * it; only then does it become an Investor in the main list.
 */
class InvestorDraftResource extends Resource
{
    protected static ?string $model = InvestorDraft::class;

    protected static ?string $cluster = Investments::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowUp;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('Investor Drafts');
    }

    public static function getModelLabel(): string
    {
        return __('investor draft');
    }

    public static function getPluralModelLabel(): string
    {
        return __('investor drafts');
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $count = InvestorDraft::query()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('Review before confirming'))
                ->description(__('Check every field against the original. Nothing is added to the investor list until you confirm.'))
                ->schema([
                    TextEntry::make('blocking_issues')
                        ->label(__('Must be fixed before confirming'))
                        ->state(fn (?InvestorDraft $record): array => $record?->blockingIssues() ?? [])
                        ->listWithLineBreaks()->bulleted()->color('danger')
                        ->visible(fn (?InvestorDraft $record): bool => $record !== null && $record->blockingIssues() !== []),
                    TextEntry::make('import_note_messages')
                        ->label(__('Notes from the import'))
                        ->state(fn (?InvestorDraft $record): array => $record?->importNoteMessages() ?? [])
                        ->listWithLineBreaks()->bulleted()->color('warning')
                        ->visible(fn (?InvestorDraft $record): bool => $record !== null && $record->importNoteMessages() !== []),
                    TextEntry::make('source')
                        ->label(__('Imported from'))
                        ->state(fn (?InvestorDraft $record): string => $record ? static::sourceDescription($record) : '-'),
                    Select::make('existing_investor_id')
                        ->label(__('Add to an existing investor'))
                        ->relationship('existingInvestor', 'name')
                        ->searchable(['name', 'phone'])
                        ->getOptionLabelFromRecordUsing(fn (Investor $record): string => "{$record->name} ({$record->phone})")
                        ->live()
                        ->helperText(__('Pick an investor who already exists to add only the investment below to them. Their details are not changed. Leave empty to create a new investor.')),
                ]),
            Section::make(__('Investment'))
                ->description(__('Filled in when a project and amount are set. Confirming then records the investment, with the stamp and cheque numbers as its security instrument.'))
                ->schema(static::investmentFields(withAmount: true))
                ->columns(2),
            Section::make(__('Investor Identity'))->visible(fn (Get $get): bool => blank($get('existing_investor_id')))->schema([
                TextInput::make('name')->label(__('Name'))->maxLength(255),
                TextInput::make('display_name')->label(__('Display / Pseudonym'))->maxLength(255),
                TextInput::make('guardian_name')->label(__('Father / Spouse Name'))->maxLength(255),
                DatePicker::make('date_of_birth')->label(__('Date of Birth'))->maxDate(now()),
                TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(255),
                TextInput::make('email')->label(__('Email'))->email()->maxLength(255),
                TextInput::make('nid_number')->label(__('NID / Passport')),
                Select::make('channel_partner_id')
                    ->label(__('Channel Partner'))
                    ->relationship('channelPartner', 'name')
                    ->searchable()->preload(),
                Toggle::make('is_channel_partner')
                    ->label(__('This investor is also a Channel Partner'))
                    ->columnSpanFull(),
                Textarea::make('address')->label(__('Address'))->columnSpanFull(),
            ])->columns(2),
            Section::make(__('Nominee'))->visible(fn (Get $get): bool => blank($get('existing_investor_id')))->schema([
                TextInput::make('nominee_name')->label(__('Nominee Name'))->maxLength(255),
                TextInput::make('nominee_nid_or_passport')->label(__('Nominee NID / Passport')),
                TextInput::make('nominee_phone')->label(__('Nominee Phone'))->tel()->maxLength(255),
                TextInput::make('nominee_relation')->label(__('Relation to Investor'))->maxLength(255),
                Textarea::make('nominee_address')->label(__('Nominee Address'))->columnSpanFull(),
            ])->columns(2)->collapsible(),
            Section::make(__('Security Documents'))->schema([
                TextInput::make('stamp_number')->label(__('Stamp No.'))->maxLength(255),
                TextInput::make('cheque_number')->label(__('Cheque No.'))->maxLength(255),
            ])->columns(2)->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->placeholder(__('Missing'))->searchable()->sortable(),
                TextColumn::make('phone')->label(__('Phone'))->placeholder(__('Missing'))->searchable(),
                TextColumn::make('guardian_name')->label(__('Father / Spouse Name'))->placeholder('-')->toggleable(),
                TextColumn::make('nominee_name')->label(__('Nominee Name'))->placeholder('-')->toggleable(),
                TextColumn::make('existingInvestor.name')
                    ->label(__('Existing investor'))
                    ->badge()->color('info')
                    ->placeholder(__('New investor')),
                TextColumn::make('project.project_code')->label(__('Project'))->placeholder('-'),
                TextColumn::make('investment_amount')->label(__('Investment'))->moneyWithoutTrailingZeroes('BDT')->placeholder('-'),
                TextColumn::make('review_status')
                    ->label(__('Review'))
                    ->badge()
                    ->state(fn (InvestorDraft $record): string => $record->blockingIssues() === [] ? __('Ready to confirm') : __('Needs fixing'))
                    ->color(fn (InvestorDraft $record): string => $record->blockingIssues() === [] ? 'success' : 'danger')
                    ->tooltip(fn (InvestorDraft $record): ?string => implode("\n", [...$record->blockingIssues(), ...$record->importNoteMessages()]) ?: null),
                TextColumn::make('source')
                    ->label(__('Imported from'))
                    ->state(fn (InvestorDraft $record): string => static::sourceDescription($record))
                    ->wrap(),
                TextColumn::make('created_at')->label(__('Imported at'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('source')->label(__('Imported from'))->options([
                    InvestorDraft::SOURCE_EXCEL => InvestorDraft::sourceLabel(InvestorDraft::SOURCE_EXCEL),
                    InvestorDraft::SOURCE_CSV => InvestorDraft::sourceLabel(InvestorDraft::SOURCE_CSV),
                    InvestorDraft::SOURCE_TEXT => InvestorDraft::sourceLabel(InvestorDraft::SOURCE_TEXT),
                ]),
            ])
            ->recordActions([
                EditAction::make()->label(__('Review')),
                Action::make('confirm')
                    ->label(__('Confirm'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('Add this investor to the investor list?'))
                    ->action(function (InvestorDraft $record, InvestorImportService $imports): void {
                        $message = static::confirmedMessage($record);

                        try {
                            $imports->confirm($record);
                        } catch (ValidationException $exception) {
                            Notification::make()->title(__('Could not confirm this draft'))->body(implode("\n", $exception->validator->errors()->all()))->danger()->send();

                            return;
                        }

                        Notification::make()->title($message)->success()->send();
                    }),
                DeleteAction::make()->label(__('Discard')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('confirmSelected')
                        ->label(__('Confirm selected'))
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription(__('Each selected draft that has no problems is added to the investor list. Drafts that still need fixing stay here.'))
                        ->action(function (Collection $records, InvestorImportService $imports): void {
                            static::confirmMany($records, $imports);
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('setInvestment')
                        ->label(__('Set project / payment'))
                        ->icon(Heroicon::OutlinedBanknotes)
                        ->schema(static::investmentFields(withAmount: false))
                        ->action(function (Collection $records, array $data): void {
                            $changes = array_filter($data, fn ($value): bool => filled($value));

                            foreach ($records as $draft) {
                                $draft->update($changes);
                            }

                            Notification::make()->title(__(':count drafts updated', ['count' => $records->count()]))->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make()->label(__('Discard selected')),
                ]),
            ])
            ->defaultSort('id');
    }

    /**
     * Project / amount / payment method / date fields, shared by the review
     * form, the import dialogs and the "Set project / payment" bulk action.
     *
     * @return list<Component|Field>
     */
    public static function investmentFields(bool $withAmount): array
    {
        return array_values(array_filter([
            Select::make('project_id')
                ->label(__('Project'))
                ->options(fn (): array => InvestmentProject::query()
                    ->whereIn('status', InvestorDraft::OPEN_PROJECT_STATUSES)
                    ->orderByDesc('id')
                    ->get()
                    ->mapWithKeys(fn (InvestmentProject $project): array => [$project->getKey() => trim(($project->project_code ? $project->project_code.' — ' : '').$project->name)])
                    ->all())
                ->searchable(),
            $withAmount ? TextInput::make('investment_amount')->label(__('Investment amount'))->numeric()->minValue(0.01)->prefix('৳') : null,
            Select::make('payment_method')
                ->label(__('Payment Method'))
                ->options(collect(Investment::PAYMENT_METHODS)->map(fn (string $label): string => __($label))->all()),
            DatePicker::make('invested_at')->label(__('Investment date'))->maxDate(now()),
        ]));
    }

    /**
     * @param  Collection<int, InvestorDraft>  $records
     */
    public static function confirmMany(Collection $records, InvestorImportService $imports): void
    {
        $confirmed = 0;
        $failed = [];

        foreach ($records as $draft) {
            try {
                $imports->confirm($draft);
                $confirmed++;
            } catch (ValidationException $exception) {
                $failed[] = ($draft->name ?: __('Row :row', ['row' => $draft->source_row ?? $draft->id])).': '.implode(' ', $exception->validator->errors()->all());
            }
        }

        Notification::make()
            ->title(__(':count drafts confirmed', ['count' => $confirmed]))
            ->body($failed === [] ? null : __('Still in drafts:')."\n".implode("\n", $failed))
            ->status($failed === [] ? 'success' : 'warning')
            ->persistent($failed !== [])
            ->send();
    }

    /** Success message for a draft, worked out before confirming deletes it. */
    public static function confirmedMessage(InvestorDraft $draft): string
    {
        $name = $draft->existingInvestor?->name ?? (string) $draft->name;

        if (! $draft->willCreateInvestment()) {
            return __('Investor :name added to the investor list', ['name' => $name]);
        }

        $amount = number_format((float) $draft->investment_amount);

        return $draft->existing_investor_id
            ? __('New investment of :amount added for :name', ['amount' => $amount, 'name' => $name])
            : __('Investor :name added with an investment of :amount', ['name' => $name, 'amount' => $amount]);
    }

    public static function sourceDescription(InvestorDraft $record): string
    {
        $parts = [InvestorDraft::sourceLabel($record->source)];

        if (filled($record->source_name)) {
            $parts[] = $record->source_name;
        }

        if ($record->source_row) {
            $parts[] = __('Row :row', ['row' => $record->source_row]);
        }

        return implode(' · ', $parts);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasPermission('investments.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny();
    }

    /** Whether the signed-in user may import drafts (same as creating investors). */
    public static function canImport(): bool
    {
        return static::canViewAny() && (auth()->user()?->can('create', Investor::class) ?? false);
    }

    public static function getPages(): array
    {
        return ['index' => ListInvestorDrafts::route('/'), 'edit' => EditInvestorDraft::route('/{record}/edit')];
    }
}
