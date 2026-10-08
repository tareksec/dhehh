<?php

namespace App\Filament\Investor\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Models\ChannelPartnerPayout;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Page 11 of §6.2: the channel partner's commission per confirmed
 * settlement, with paid and due totals.
 */
class Commissions extends Page implements HasTable
{
    use InteractsWithTable;
    use InvestorPortalPage;

    protected static ?string $portalRole = 'partner';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyBangladeshi;

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.investor.pages.commissions';

    public static function getNavigationGroup(): ?string
    {
        return __('Channel partner');
    }

    public static function getNavigationLabel(): string
    {
        return __('Commissions');
    }

    public function getTitle(): string
    {
        return __('Commissions');
    }

    public function table(Table $table): Table
    {
        $currency = $this->currency();

        return $table
            ->query(fn () => $this->portal()->partnerPayouts($this->account())->with('settlement.project'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('project')
                    ->label(__('Project'))
                    ->state(fn (ChannelPartnerPayout $record): string => $this->portal()->projectLabel($record->settlement?->project))
                    ->wrap(),
                TextColumn::make('settlement.settled_at')->label(__('Settled on'))->date('d M Y')->visibleFrom('md'),
                TextColumn::make('amount')->label(__('Commission'))->formatStateUsing(fn ($state): string => MoneyFormatter::currency($state, $currency))->weight('bold'),
                TextColumn::make('payment_status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'paid' ? __('Paid') : __('Pending'))
                    ->color(fn (string $state): string => $state === 'paid' ? 'success' : 'warning'),
                TextColumn::make('paid_at')->label(__('Paid on'))->date('d M Y')->placeholder('-')->visibleFrom('md'),
                TextColumn::make('payment_reference')->label(__('Reference'))->placeholder('-')->toggleable()->visibleFrom('md'),
            ])
            ->emptyStateHeading(__('No commissions yet.'))
            ->paginated([10, 25, 50]);
    }
}
