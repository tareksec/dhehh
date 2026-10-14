<?php

namespace App\Filament\Investor\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\ProjectSettlement;
use App\Services\InvestorPortalCards;
use App\Services\InvestorPortalService;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Page 2 of §6.1: every investment of the signed-in investor with its
 * project and, once staff confirm the settlement, the result.
 */
class MyInvestments extends Page implements HasTable
{
    use InteractsWithTable;
    use InvestorPortalPage;

    protected static ?string $portalRole = 'investor';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'investments';

    protected string $view = 'filament.investor.pages.table';

    public static function getNavigationLabel(): string
    {
        return __('My investments');
    }

    public function getTitle(): string
    {
        return __('My investments');
    }

    public function table(Table $table): Table
    {
        $currency = $this->currency();

        return $table
            ->query(fn () => $this->portal()->investments($this->account())->with(['project', 'project.settlement']))
            ->defaultSort('invested_at', 'desc')
            ->columns([
                TextColumn::make('project')
                    ->label(__('Project'))
                    ->state(fn (Investment $record): string => app(InvestorPortalCards::class)->title($record->project))
                    ->description(fn (Investment $record): ?string => $record->project?->deal_reference)
                    ->wrap(),
                TextColumn::make('amount')->label(__('Amount'))->formatStateUsing(fn ($state): string => MoneyFormatter::currency($state, $currency)),
                TextColumn::make('invested_at')->label(__('Invested on'))->date('d M Y')->sortable()->visibleFrom('md'),
                TextColumn::make('project.end_date')->label(__('Expected end'))->date('d M Y')->placeholder('-')->toggleable()->visibleFrom('lg'),
                TextColumn::make('state')
                    ->label(__('Status'))
                    ->badge()
                    ->state(fn (Investment $record): string => match (true) {
                        $record->isWithdrawn() => __('Withdrawn'),
                        $this->finalSettlement($record) !== null => __('Settled'),
                        // In a group, "closed" means the two months are over, not that anything went wrong.
                        $record->project?->group_id !== null && $record->project->status === 'closed' => __('Completed'),
                        default => __(InvestmentProject::STATUSES[$record->project?->status] ?? 'Open'),
                    })
                    ->color(fn (Investment $record): string => match (true) {
                        $record->isWithdrawn() => 'gray',
                        $this->finalSettlement($record) !== null => 'success',
                        default => 'info',
                    }),
                TextColumn::make('result')
                    ->label(__('Result'))
                    ->state(function (Investment $record) use ($currency): ?string {
                        $settlement = $this->finalSettlement($record);

                        if (! $settlement) {
                            return null;
                        }

                        if ($settlement->outcome !== 'profit') {
                            return __(ProjectSettlement::OUTCOMES[$settlement->outcome] ?? $settlement->outcome);
                        }

                        return __(':rate per lakh', ['rate' => MoneyFormatter::currency($settlement->rate_per_lac, $currency)])
                            .($settlement->annualized_return_percent !== null ? ' · '.__(':percent% a year', ['percent' => MoneyFormatter::number($settlement->annualized_return_percent)]) : '');
                    })
                    ->placeholder(__('Not settled yet'))
                    ->visibleFrom('md')
                    ->wrap(),
            ])
            ->emptyStateHeading(__('No investments yet.'))
            ->paginated([10, 25, 50]);
    }

    protected function finalSettlement(Investment $record): ?ProjectSettlement
    {
        $settlement = $record->project?->settlement;

        return $settlement && in_array($settlement->status, InvestorPortalService::FINAL_SETTLEMENT_STATUSES, true) ? $settlement : null;
    }
}
