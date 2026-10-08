<?php

namespace App\Filament\Investor\Widgets;

use App\Models\InvestorPortalAccount;
use App\Services\InvestorPortalService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/**
 * The investor's profit (or loss) per month for the last 12 months, by the
 * month the settlement was made; only confirmed settlements count
 * (InvestorPortalService::payouts()). Embedded in the dashboard view with
 *
 * @livewire(), like the reseller portal's CommissionTrend.
 */
class ProfitTrend extends ChartWidget
{
    protected function getData(): array
    {
        $account = Filament::auth()->user();
        $series = $account instanceof InvestorPortalAccount && $account->isInvestor()
            ? app(InvestorPortalService::class)->monthlyProfit($account)
            : [];

        return [
            'datasets' => [[
                'label' => __('Profit share'),
                'data' => array_values($series),
                'backgroundColor' => '#10b981',
            ]],
            'labels' => array_map(fn (string $month): string => Carbon::createFromFormat('Y-m', $month)->translatedFormat('M Y'), array_keys($series)),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function getHeading(): string
    {
        return __('Profit (last 12 months)');
    }
}
