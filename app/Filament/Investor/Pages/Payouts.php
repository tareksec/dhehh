<?php

namespace App\Filament\Investor\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Models\InvestorCycleElection;
use App\Models\SettlementPayout;
use App\Services\InvestorPortalCards;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Page 4 of §6.1: each settlement payout (principal + profit) with its
 * status, paid date and reference, and the printable payout report.
 */
class Payouts extends Page implements HasTable
{
    use InteractsWithTable;
    use InvestorPortalPage;

    protected static ?string $portalRole = 'investor';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.investor.pages.table';

    public static function getNavigationGroup(): ?string
    {
        return __('Money');
    }

    public static function getNavigationLabel(): string
    {
        return __('Payouts');
    }

    public function getTitle(): string
    {
        return __('Payouts');
    }

    public function table(Table $table): Table
    {
        $currency = $this->currency();

        return $table
            ->query(fn () => $this->portal()->payouts($this->account())->with(['settlement.project', 'cycleElection']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('project')
                    ->label(__('Project'))
                    ->state(fn (SettlementPayout $record): string => app(InvestorPortalCards::class)->title($record->settlement?->project))
                    ->wrap(),
                TextColumn::make('investment_amount')->label(__('Capital'))->formatStateUsing(fn ($state): string => MoneyFormatter::currency($state, $currency))->visibleFrom('md'),
                TextColumn::make('principal_returned_amount')
                    ->label(__('Capital returned'))
                    ->state(fn (SettlementPayout $record): string => $record->principal_returned_amount !== null && (float) $record->principal_returned_amount <= 0
                        ? __('Stays invested')
                        : MoneyFormatter::currency($record->principalReturned(), $currency))
                    ->visibleFrom('lg'),
                TextColumn::make('profit_share_amount')->label(__('Profit share'))->formatStateUsing(fn ($state): string => MoneyFormatter::currency($state, $currency))->visibleFrom('md'),
                TextColumn::make('total_payout')->label(__('Total'))->formatStateUsing(fn ($state): string => MoneyFormatter::currency($state, $currency))->weight('bold'),
                TextColumn::make('payment_status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'paid' ? __('Paid') : __('Pending'))
                    ->color(fn (string $state): string => $state === 'paid' ? 'success' : 'warning'),
                TextColumn::make('paid_at')->label(__('Paid on'))->date('d M Y')->placeholder('-')->visibleFrom('md'),
                TextColumn::make('payment_reference')->label(__('Reference'))->placeholder('-')->toggleable()->visibleFrom('md'),
                TextColumn::make('cycleElection.election')
                    ->label(__('Your choice'))
                    ->formatStateUsing(fn (?string $state): ?string => $state ? __(InvestorCycleElection::ELECTIONS[$state] ?? $state) : null)
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->recordActions([
                Action::make('paymentProof')
                    ->label(__('Payment screenshot'))
                    ->icon(Heroicon::OutlinedCamera)
                    ->visible(fn (SettlementPayout $record): bool => $record->payment_status === 'paid' && $record->hasPaymentProof())
                    ->url(fn (SettlementPayout $record): string => route('filament.investor.documents.download', ['document' => $record->paymentProof()?->getKey()])),
                Action::make('report')
                    ->label(__('Report'))
                    ->icon(Heroicon::OutlinedPrinter)
                    ->url(fn (SettlementPayout $record): string => route('filament.investor.reports.payout', ['payout' => $record->getKey()]))
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading(__('No payouts yet.'))
            ->paginated([10, 25, 50]);
    }
}
