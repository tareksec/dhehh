<?php

namespace App\Filament\Resources\InvestmentProjects\RelationManagers;

use App\Filament\Resources\InvestmentRecords\InvestmentRecordResource;
use App\Models\Investment;
use App\Models\Investor;
use App\Services\Investment\InvestmentLedgerService;
use App\Support\ImsPanelLinks;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvestmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'investments';

    public function isReadOnly(): bool
    {
        // Frozen once the investment window is closed, not only once settled
        // (v3 P2.2) -- the recorded investments feed the capital base and
        // report figures from the moment the project stops accepting money.
        return in_array($this->getOwnerRecord()->status, ['closed', 'settled'], true) || parent::isReadOnly();
    }

    /**
     * A group project's rows are snapshots of the group's members
     * (16_INVESTOR_GROUPS_PLAN.md): money is added to the group, and the
     * amounts here are not edited by hand.
     */
    private function isGroupProject(): bool
    {
        return $this->getOwnerRecord()->isGrouped();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            // 'nid_number' dropped from the search columns -- it is encrypted
            // at rest (v3 P3), so a DB-level LIKE search can never match it.
            Select::make('investor_id')->relationship('investor', 'name')->searchable(['name', 'phone'])->preload()
                ->createOptionForm([
                    TextInput::make('name')->required(),
                    TextInput::make('phone')->required(),
                    TextInput::make('nid_number')->label(__('NID / Passport (Investor ID)'))->required(),
                ])->createOptionUsing(fn (array $data): int => Investor::query()->create($data)->getKey())->required(),
            TextInput::make('amount')->numeric()->prefix('৳')->minValue(0.01)->required(),
            Select::make('payment_method')->options(Investment::PAYMENT_METHODS)->required(),
            TextInput::make('payment_reference'),
            DatePicker::make('invested_at')->default(now())->required(),
            // IMS v2 (PRD FR-23): an optional tenor; the maturity date follows from it.
            TextInput::make('tenor_months')->label(__('Tenor in months (optional)'))->numeric()->integer()->minValue(1)->maxValue(240)
                ->helperText(__('Leave empty for no fixed term: the money then leaves with a 60-day notice.')),
            Toggle::make('pending')->label(__('The money has not arrived yet'))
                ->helperText(__('It waits on "Money to confirm" and is not part of any settlement until it is confirmed.'))
                ->default(false)
                ->hiddenOn('edit'),
            Textarea::make('override_reason')
                ->label('Reason (investment window closed)')
                ->rows(2)
                ->visible(fn (): bool => $this->windowRequiresOverride())
                ->required(fn (): bool => $this->windowRequiresOverride())
                ->helperText('এই project-এর investment window বন্ধ — এখানে একটি কারণ audit log-এ থাকবে।'),
        ]);
    }

    /** Whether adding a new investment right now needs the override reason. */
    private function windowRequiresOverride(): bool
    {
        $project = $this->getOwnerRecord();

        return (bool) $project->investment_closes_at && ! $project->isWithinInvestmentWindow();
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->withCount('securityInstruments')->with('securityInstruments'))->columns([
            TextColumn::make('investor.name')->searchable(),
            TextColumn::make('investor.channelPartner.name')->label('Channel Partner')->placeholder('Direct'),
            TextColumn::make('amount')->moneyWithoutTrailingZeroes('BDT')->summarize(Sum::make()->moneyWithoutTrailingZeroes('BDT')),
            TextColumn::make('payment_method')->badge(),
            TextColumn::make('invested_at')->date(),
            TextColumn::make('status')
                ->label(__('Status'))
                ->badge()
                ->formatStateUsing(fn (?string $state): string => InvestmentRecordResource::statusLabel($state))
                ->color(fn (?string $state): string => InvestmentRecordResource::statusColor($state)),
            // Inline cheque number straight off the investment row (v3 P3) --
            // previously only visible by opening the separate
            // SecurityInstrumentsRelationManager on the investment's own view.
            TextColumn::make('cheque_number')
                ->label('Cheque No.')
                ->state(fn (Investment $record): ?string => $record->securityInstruments->first()?->cheque_number)
                ->placeholder('-'),
            TextColumn::make('security_instruments_count')->label('Security')->badge(),
            TextColumn::make('override_reason')->label('Late-Window Override')->limit(30)->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('voucher.status')
                ->label('Voucher')
                ->badge()
                ->placeholder('-')
                ->url(fn (Investment $record): ?string => $record->voucher_id ? ImsPanelLinks::voucher($record->voucher_id) : null),
        ])->headerActions([
            CreateAction::make()
                ->hidden(fn (): bool => $this->isGroupProject())
                ->mutateDataUsing(function (array $data): array {
                    $pending = (bool) ($data['pending'] ?? false);
                    unset($data['pending']);

                    return [...$data, 'company_id' => $this->getOwnerRecord()->company_id, 'received_by' => auth()->id(), 'status' => $pending ? Investment::STATUS_PENDING : Investment::STATUS_ACTIVE];
                })
                ->after(fn (Investment $record) => app(InvestmentLedgerService::class)->recordInvestmentReceived($record)),
        ])
            ->recordActions([
                ViewAction::make()->url(fn (Investment $record): string => InvestmentRecordResource::getUrl('view', ['record' => $record])),
                InvestmentRecordResource::withdrawAction(),
                EditAction::make()->hidden(fn (Investment $record): bool => $record->isWithdrawn() || filled($record->group_member_id)),
                DeleteAction::make()->hidden(fn (Investment $record): bool => $record->isWithdrawn() || filled($record->group_member_id)),
            ]);
    }
}
