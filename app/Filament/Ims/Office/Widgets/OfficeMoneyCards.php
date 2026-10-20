<?php

namespace App\Filament\Ims\Office\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Ims\Office\OfficeFigures;
use App\Services\CompanyContext;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;

/**
 * The office home's left column (IMS-V2/DESIGN.md §7 BalanceCard + KpiCard):
 * the big green "capital at work" card, then the money that came in this
 * month next to last month's.
 */
class OfficeMoneyCards extends Widget
{
    use GuardsWidgetRendering;

    protected static bool $isLazy = false;

    protected string $view = 'filament.ims.office.widgets.money-cards';

    /** @return array{capital: float, received: float, received_before: float, change: float|null, currency: string} */
    public function figures(): array
    {
        $figures = app(OfficeFigures::class);
        $received = $figures->receivedThisMonth();
        $before = $figures->receivedThisMonth(CarbonImmutable::now()->startOfMonth()->subMonthNoOverflow());

        return [
            'capital' => $figures->capitalAtWork(),
            'received' => $received,
            'received_before' => $before,
            'change' => $before > 0 ? round(100 * ($received - $before) / $before, 1) : null,
            'currency' => app(CompanyContext::class)->company()?->currency ?: 'BDT',
        ];
    }
}
