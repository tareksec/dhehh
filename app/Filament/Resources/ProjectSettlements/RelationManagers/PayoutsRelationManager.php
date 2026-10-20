<?php

namespace App\Filament\Resources\ProjectSettlements\RelationManagers;

use App\Filament\Actions\PayoutProofAction;
use App\Models\InvestorCycleElection;
use App\Models\SettlementPayout;
use App\Services\Investment\CycleElectionService;
use App\Services\Investment\InvestmentTargets;
use App\Services\Investment\PayoutCompletionService;
use App\Support\CompanyMedia;
use App\Support\ImsPanelLinks;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'payouts';

    protected static ?string $title = 'Investor Payouts';

    /**
     * `ProjectSettlementResource` has no Edit page by design (settlement
     * figures are immutable once confirmed) — but that leaves this relation
     * manager's own EditAction (recipient bank details, correctable while a
     * payout is still `pending`) with no writable page to fall back to under
     * Filament's ViewRecord-page read-only default, so it silently never
     * mounted (v3 P3 audit — same root cause as
     * SecurityInstrumentsRelationManager::isReadOnly()). Always writable
     * here; the real lock is EditAction's own `visible()` (payoutsUnlocked()
     * + still pending + investments.settle permission).
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('recipient_name')->required(),
            TextInput::make('recipient_bank_name'),
            TextInput::make('recipient_branch'),
            TextInput::make('recipient_account_number'),
            Select::make('payment_method')->options(['cash' => 'Cash', 'bkash' => 'bKash', 'bank' => 'Bank', 'other' => 'Other']),
            TextInput::make('payment_reference'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('cycleElection'))->columns([
            TextColumn::make('investor.name'),
            TextColumn::make('investment_amount')->moneyWithoutTrailingZeroes('BDT'),
            TextColumn::make('principal_returned')
                ->label(__('Principal returned'))
                ->state(fn (SettlementPayout $record): float => $record->principalReturned())
                ->moneyWithoutTrailingZeroes('BDT')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('profit_share_amount')->moneyWithoutTrailingZeroes('BDT'),
            TextColumn::make('total_payout')->moneyWithoutTrailingZeroes('BDT'),
            TextColumn::make('recipient_name'),
            TextColumn::make('payment_status')->badge(),
            TextColumn::make('paid_at')->date()->placeholder('-'),
            TextColumn::make('cycleElection.election')
                ->label('Election')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => $state ? InvestorCycleElection::ELECTIONS[$state] : '-')
                ->color(fn (?string $state): string => $state ? 'success' : 'gray'),
            TextColumn::make('voucher.status')
                ->label('Voucher')
                ->badge()
                ->placeholder('-')
                ->url(fn (SettlementPayout $record): ?string => $record->voucher_id ? ImsPanelLinks::voucher($record->voucher_id) : null),
            PayoutProofAction::column(),
        ])->recordActions([
            Action::make('report')->label('Report')->icon('heroicon-o-document-text')
                ->url(fn ($record): string => ImsPanelLinks::route('investments.reports.investor-payout', $record))
                ->openUrlInNewTab(),
            EditAction::make()->visible(fn ($record): bool => $this->payoutsUnlocked() && $record->payment_status === 'pending' && (auth()->user()?->hasPermission('investments.settle') ?? false)),
            Action::make('election')
                ->label('Record Election')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('gray')
                ->visible(fn (SettlementPayout $record): bool => ! $record->cycleElection && (auth()->user()?->hasPermission('investments.settle') ?? false))
                ->schema([
                    Select::make('election')
                        ->options(InvestorCycleElection::ELECTIONS)
                        ->required()
                        ->live(),
                    TextInput::make('reinvest_amount')
                        ->label('Reinvest Amount')
                        ->numeric()->prefix('৳')->minValue(0.01)
                        ->required(fn (Get $get): bool => $get('election') === 'partial_reinvest')
                        ->visible(fn (Get $get): bool => $get('election') === 'partial_reinvest')
                        ->helperText(fn (SettlementPayout $record): string => 'Total payout: '.MoneyFormatter::currency((float) $record->total_payout)),
                    Select::make('next_target')
                        ->label(__('Reinvest into (open group)'))
                        ->options(fn (): array => app(InvestmentTargets::class)->options())
                        ->searchable()
                        ->required(fn (Get $get): bool => in_array($get('election'), ['reinvest', 'partial_reinvest'], true))
                        ->visible(fn (Get $get): bool => in_array($get('election'), ['reinvest', 'partial_reinvest'], true))
                        ->helperText(__('New money joins a group that is still open; a group that has started takes no new members.')),
                ])
                ->requiresConfirmation()
                ->modalDescription(__('This is a one-time record per payout. A (partial) reinvest also adds the investor to the chosen open group right away, as a new member.'))
                ->action(function (SettlementPayout $record, array $data): void {
                    try {
                        app(CycleElectionService::class)->recordElection(
                            $record,
                            $data['election'],
                            filled($data['reinvest_amount'] ?? null) ? (float) $data['reinvest_amount'] : null,
                            app(InvestmentTargets::class)->find($data['next_target'] ?? null),
                            (int) auth()->id(),
                        );
                    } catch (RuntimeException $exception) {
                        Notification::make()->danger()->title('Could not record the election')->body($exception->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title('Election recorded')->send();
                }),
            Action::make('deleteElection')
                ->label('Undo Election')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (SettlementPayout $record): bool => $record->cycleElection !== null && $record->payment_status === 'pending' && (auth()->user()?->hasPermission('investments.settle') ?? false))
                ->requiresConfirmation()
                ->modalDescription('This removes the recorded election. The reinvestment Investment row (if one was created) is kept — void it separately if it was a mistake.')
                ->action(function (SettlementPayout $record): void {
                    $record->cycleElection?->delete();
                    Notification::make()->success()->title('Election removed')->send();
                }),
            Action::make('markPaid')->label('Mark as Paid')->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn ($record): bool => $this->payoutsUnlocked() && $record->payment_status === 'pending' && (auth()->user()?->hasPermission('investments.settle') ?? false))
                ->requiresConfirmation()
                ->modalDescription('Confirm the payout has actually been sent to the investor.')
                ->schema([PayoutProofAction::field(fn () => CompanyMedia::require($this->getOwnerRecord()))])
                ->action(function (SettlementPayout $record, array $data): void {
                    try {
                        app(PayoutCompletionService::class)->markPaid($record, auth()->user());
                    } catch (ValidationException $exception) {
                        PayoutProofAction::discard($data['payment_proof'] ?? null);
                        Notification::make()->danger()->title(__('Could not mark the payout paid'))->body(collect($exception->errors())->flatten()->first())->send();

                        return;
                    }

                    PayoutProofAction::attach($record->fresh(), $data['payment_proof'] ?? null);

                    Notification::make()->success()->title('Investor payout marked paid')->send();
                }),
            PayoutProofAction::uploadAction(fn () => CompanyMedia::require($this->getOwnerRecord())),
        ]);
    }

    /** Payouts can only be edited / paid once the settlement is confirmed. */
    private function payoutsUnlocked(): bool
    {
        return $this->getOwnerRecord()->status === 'confirmed';
    }
}
