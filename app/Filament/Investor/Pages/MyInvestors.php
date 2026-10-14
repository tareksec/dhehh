<?php

namespace App\Filament\Investor\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Models\Investment;
use App\Models\Investor;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Page 10 of §6.2: the investors a channel partner brought in. Only the name
 * the investor uses on shared papers (their pseudonym, if they chose one),
 * their capital at work and when they joined; never their phone, NID or
 * address (plan question P4).
 */
class MyInvestors extends Page implements HasTable
{
    use InteractsWithTable;
    use InvestorPortalPage;

    protected static ?string $portalRole = 'partner';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.investor.pages.table';

    public static function getNavigationGroup(): ?string
    {
        return __('Channel partner');
    }

    public static function getNavigationLabel(): string
    {
        return __('My investors');
    }

    public function getTitle(): string
    {
        return __('My investors');
    }

    public function table(Table $table): Table
    {
        $currency = $this->currency();

        return $table
            ->query(fn () => $this->portal()->referredInvestors($this->account())
                ->withSum(['investments as capital_at_work' => fn (Builder $query) => $query
                    ->where('status', Investment::STATUS_ACTIVE)
                    ->whereHas('project', fn (Builder $query) => $query->where('status', '!=', 'settled'))], 'amount')
                ->withMin('investments as joined_on', 'invested_at'))
            ->defaultSort('joined_on', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Investor'))
                    ->state(fn (Investor $record): string => $record->reportName()),
                TextColumn::make('capital_at_work')->label(__('Capital at work'))->formatStateUsing(fn ($state): string => MoneyFormatter::currency($state, $currency))->placeholder('-'),
                TextColumn::make('joined_on')->label(__('Joined'))->date('d M Y')->placeholder('-')->visibleFrom('sm'),
            ])
            ->emptyStateHeading(__('No investors yet.'))
            ->paginated([10, 25, 50]);
    }
}
