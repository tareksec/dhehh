<?php

namespace App\Filament\Resources\InvestorGroups;

use App\Filament\Clusters\Investments;
use App\Filament\Forms\Components\FundSourceSelect;
use App\Filament\Resources\InvestmentProjects\InvestmentProjectResource;
use App\Filament\Resources\InvestorGroups\Pages\CreateInvestorGroup;
use App\Filament\Resources\InvestorGroups\Pages\EditInvestorGroup;
use App\Filament\Resources\InvestorGroups\Pages\ListInvestorGroups;
use App\Filament\Resources\InvestorGroups\Pages\ViewInvestorGroup;
use App\Filament\Resources\InvestorGroups\RelationManagers\MembersRelationManager;
use App\Filament\Resources\InvestorGroups\RelationManagers\ProjectsRelationManager;
use App\Models\InvestorGroup;
use App\Services\Investment\InvestorGroupCycleService;
use App\Support\MoneyFormatter;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Investor groups (16_INVESTOR_GROUPS_PLAN.md): the unit investors join.
 * A group runs Project-1, 2, 3 … one after another; status changes go
 * through `InvestorGroupService`.
 */
class InvestorGroupResource extends Resource
{
    protected static ?string $model = InvestorGroup::class;

    protected static ?string $cluster = Investments::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('Investor Groups');
    }

    public static function getModelLabel(): string
    {
        return __('investor group');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Investor Groups');
    }

    public static function canManage(): bool
    {
        return auth()->user()?->hasPermission('investments.manage') ?? false;
    }

    public static function statusLabel(?string $status): string
    {
        return __(InvestorGroup::STATUSES[$status ?? InvestorGroup::STATUS_OPEN] ?? (string) $status);
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            InvestorGroup::STATUS_OPEN => 'info',
            InvestorGroup::STATUS_RUNNING => 'success',
            default => 'gray',
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('Group'))->schema([
                TextInput::make('group_code')->label(__('Group code'))->disabled()->dehydrated(false)->placeholder(__('Made when saved')),
                TextInput::make('name')->label(__('Group name'))->maxLength(255)->helperText(__('Leave empty to name it Group-1, Group-2 … after its code.')),
                Select::make('cycle_months')
                    ->label(__('Months per project'))
                    ->options(array_combine(range(1, 12), range(1, 12)))
                    ->default(2)
                    ->required()
                    ->disabled(fn (?InvestorGroup $record): bool => (bool) ($record?->isRunning() && $record->auto_cycle))
                    ->helperText(__('A new project starts this many calendar months after the previous one. The owner\'s rule is 2.')),
                DatePicker::make('running_since')
                    ->label(__('Running since'))
                    ->visible(fn (?InvestorGroup $record): bool => (bool) $record?->isRunning())
                    ->disabled(fn (?InvestorGroup $record): bool => (bool) $record?->auto_cycle)
                    ->helperText(__('Project 2 starts this many months after this date. It is fixed once Automatic cycles are on.')),
                Toggle::make('auto_cycle')
                    ->label(__('Automatic cycles'))
                    ->visible(fn (?InvestorGroup $record): bool => (bool) $record?->isRunning())
                    ->helperText(__('When on, the next project starts by itself when the current one reaches its end date. Check the dates first.')),
                Textarea::make('notes')->label(__('Notes'))->rows(3)->columnSpanFull(),
            ])->columns(2),
            Section::make(__('Profit split (%) and accounts'))
                ->description(__('Copied into every project the group starts. The three shares must total exactly 100%.'))
                ->schema([
                    TextInput::make('investor_share_percent')->label(__('Investor share'))->numeric()->default(40)->minValue(0)->maxValue(100)->suffix('%')->required()->live(debounce: 500),
                    TextInput::make('channel_partner_share_percent')->label(__('Channel partner share'))->numeric()->default(10)->minValue(0)->maxValue(100)->suffix('%')->required()->live(debounce: 500),
                    TextInput::make('company_share_percent')->label(__('Company share'))->numeric()->default(50)->minValue(0)->maxValue(100)->suffix('%')->required()->live(debounce: 500)
                        ->rule(fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $total = (float) ($get('investor_share_percent') ?? 0) + (float) ($get('channel_partner_share_percent') ?? 0) + (float) ($get('company_share_percent') ?? 0);

                            if (abs($total - 100) > 0.001) {
                                $fail(__('Investor, channel partner, and company shares must total exactly 100%.'));
                            }
                        }),
                    FundSourceSelect::make('receiving_fund_source_id')->label(__('Receiving Fund Source'))
                        ->helperText(__('Each deposit into the group is booked as a pending capital investment voucher here. Leave empty to book none.')),
                    FundSourceSelect::make('payout_fund_source_id')->label(__('Payout Fund Source'))
                        ->helperText(__('Copied into each project: payouts are booked from here when marked paid.')),
                ])->columns(3),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('Group'))->schema([
                TextEntry::make('group_code')->label(__('Group code')),
                TextEntry::make('name')->label(__('Group name')),
                TextEntry::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state): string => static::statusLabel($state))
                    ->color(fn (string $state): string => static::statusColor($state)),
                TextEntry::make('opened_on')->label(__('Opened on'))->date()->placeholder('-'),
                TextEntry::make('running_since')->label(__('Running since'))->date()->placeholder('-'),
                TextEntry::make('closed_on')->label(__('Closed on'))->date()->placeholder('-')->visible(fn (InvestorGroup $record): bool => $record->isClosed()),
                TextEntry::make('close_reason')->label(__('Closed because'))->placeholder('-')->visible(fn (InvestorGroup $record): bool => $record->isClosed()),
                TextEntry::make('cycle_months')->label(__('Months per project')),
                TextEntry::make('auto_cycle')->label(__('Automatic cycles'))->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('On') : __('Off'))
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextEntry::make('current_project')->label(__('Current project'))
                    ->state(fn (InvestorGroup $record): ?string => $record->currentProject?->project_code)
                    ->url(fn (InvestorGroup $record): ?string => $record->currentProject ? InvestmentProjectResource::getUrl('view', ['record' => $record->currentProject]) : null)
                    ->placeholder('-'),
                TextEntry::make('current_project_ends')->label(__('Current project ends'))
                    ->state(fn (InvestorGroup $record): ?string => static::currentProjectEnd($record))
                    ->placeholder('-'),
                TextEntry::make('cycle_note')->label(__('Cycle'))
                    ->state(fn (InvestorGroup $record): ?string => static::cycleNote($record))
                    ->color('danger')
                    ->columnSpanFull()
                    ->visible(fn (InvestorGroup $record): bool => static::cycleNote($record) !== null),
                TextEntry::make('notes')->label(__('Notes'))->placeholder('-')->columnSpanFull(),
            ])->columns(3),
            Section::make(__('Money'))->schema([
                TextEntry::make('members_count')->label(__('Active members'))
                    ->state(fn (InvestorGroup $record): int => $record->activeMembers()->count()),
                TextEntry::make('total_deposits')->label(__('Active deposits'))
                    ->state(fn (InvestorGroup $record): string => MoneyFormatter::currency($record->totalActiveDeposits())),
                TextEntry::make('profit_split')->label(__('Investor / Channel / Company'))
                    ->state(fn (InvestorGroup $record): string => "{$record->investor_share_percent}% / {$record->channel_partner_share_percent}% / {$record->company_share_percent}%"),
                TextEntry::make('receivingFundSource.name')->label(__('Receiving Fund Source'))->placeholder(__('Not configured — no voucher created')),
                TextEntry::make('payoutFundSource.name')->label(__('Payout Fund Source'))->placeholder(__('Not configured — no voucher created')),
            ])->columns(3),
        ]);
    }

    /** A line for staff when the cycle needs them (null when everything is on time). */
    public static function cycleNote(InvestorGroup $group): ?string
    {
        if (! $group->isRunning()) {
            return null;
        }

        $decision = app(InvestorGroupCycleService::class)->decide($group, InvestorGroupCycleService::today($group->company?->timezone));

        return match ($decision['action']) {
            InvestorGroupCycleService::BEHIND => __('Behind by :count cycle(s). The next project was not started on its own; use "Start the next project now" after checking the dates.', ['count' => $decision['behind_by']]),
            InvestorGroupCycleService::NO_MEMBERS => __('Project :number is over but no active member is left. Close the group.', ['number' => $decision['from_sequence']]),
            default => null,
        };
    }

    /** The end date of the project that is running now (for a running group). */
    private static function currentProjectEnd(InvestorGroup $group): ?string
    {
        $project = $group->currentProject;

        if (! $project?->end_date) {
            return null;
        }

        return $project->end_date->translatedFormat('d M Y');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->withCount(['activeMembers', 'projects'])
                ->withSum(['members as active_deposits_sum' => fn ($members) => $members->where('status', 'active')], 'amount'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('group_code')->label(__('Group code'))->searchable()->sortable(),
                TextColumn::make('name')->label(__('Group name'))->searchable()->sortable(),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state): string => static::statusLabel($state))
                    ->color(fn (string $state): string => static::statusColor($state)),
                TextColumn::make('active_members_count')->label(__('Members')),
                TextColumn::make('active_deposits_sum')->label(__('Active deposits'))->formatStateUsing(fn ($state): string => MoneyFormatter::currency((float) $state)),
                TextColumn::make('projects_count')->label(__('Projects')),
                TextColumn::make('running_since')->label(__('Running since'))->date()->placeholder('-')->visibleFrom('md'),
                IconColumn::make('auto_cycle')->label(__('Automatic cycles'))->boolean()->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(collect(InvestorGroup::STATUSES)->map(fn (string $label): string => __($label))->all()),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function canCreate(): bool
    {
        return static::canManage();
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof InvestorGroup && ! $record->isClosed() && static::canManage();
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof InvestorGroup && ! $record->members()->exists() && ! $record->projects()->exists() && static::canManage();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [MembersRelationManager::class, ProjectsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvestorGroups::route('/'),
            'create' => CreateInvestorGroup::route('/create'),
            'view' => ViewInvestorGroup::route('/{record}'),
            'edit' => EditInvestorGroup::route('/{record}/edit'),
        ];
    }
}
