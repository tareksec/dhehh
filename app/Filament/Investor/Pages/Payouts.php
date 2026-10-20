<?php

namespace App\Filament\Investor\Pages;

use App\Filament\Investor\Concerns\CombinedPortalTable;
use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Models\InvestorCycleElection;
use App\Services\InvestorPortfolio;
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
 * status, paid date and reference, and the printable payout report - from
 * every company the person invests in, with a Project filter
 * (18_IMS_ALL_COMPANIES_PLAN.md §4).
 */
class Payouts extends Page implements HasTable
{
    use CombinedPortalTable;
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
        $money = fn (string $key): \Closure => fn (array $record): string => MoneyFormatter::currency($record[$key], $record['currency']);

        // Every company's payouts in one list (18_IMS_ALL_COMPANIES_PLAN.md §4, phase E).
        return $table
            ->records(fn (?array $filters, int|string $page, int|string $recordsPerPage) => $this->pageOfRows(
                app(InvestorPortfolio::class)->payouts($this->account(), $this->chosenProject($filters)),
                $page,
                $recordsPerPage,
            ))
            ->filters([$this->projectFilter()])
            ->columns([
                TextColumn::make('project')
                    ->label(__('Project'))
                    ->description(fn (array $record): ?string => $this->showsCompanies() ? $record['company_name'] : null)
                    ->wrap(),
                TextColumn::make('investment_amount')->label(__('Capital'))->state($money('investment_amount'))->visibleFrom('md'),
                TextColumn::make('principal_returned')
                    ->label(__('Capital returned'))
                    ->state(fn (array $record): string => $record['stays_invested'] ? __('Stays invested') : MoneyFormatter::currency($record['principal_returned'], $record['currency']))
                    ->visibleFrom('lg'),
                TextColumn::make('profit_share_amount')->label(__('Profit share'))->state($money('profit_share_amount'))->visibleFrom('md'),
                TextColumn::make('total_payout')->label(__('Total'))->state($money('total_payout'))->weight('bold'),
                TextColumn::make('payment_status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'paid' ? __('Paid') : __('Pending'))
                    ->color(fn (string $state): string => $state === 'paid' ? 'success' : 'warning'),
                TextColumn::make('paid_at')->label(__('Paid on'))->date('d M Y')->placeholder('-')->visibleFrom('md'),
                TextColumn::make('payment_reference')->label(__('Reference'))->placeholder('-')->toggleable()->visibleFrom('md'),
                TextColumn::make('election')
                    ->label(__('Your choice'))
                    ->formatStateUsing(fn (?string $state): ?string => $state ? __(InvestorCycleElection::ELECTIONS[$state] ?? $state) : null)
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->recordActions([
                Action::make('paymentProof')
                    ->label(__('Payment screenshot'))
                    ->icon(Heroicon::OutlinedCamera)
                    ->visible(fn (array $record): bool => $record['proof_url'] !== null)
                    ->url(fn (array $record): ?string => $record['proof_url']),
                Action::make('report')
                    ->label(__('Report'))
                    ->icon(Heroicon::OutlinedPrinter)
                    ->url(fn (array $record): string => $record['report_url'])
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading(__('No payouts yet.'))
            ->paginated([10, 25, 50]);
    }
}
