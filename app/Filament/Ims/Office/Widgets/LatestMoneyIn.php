<?php

namespace App\Filament\Ims\Office\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Ims\Office\OfficeFigures;
use App\Services\CompanyContext;
use Filament\Widgets\Widget;

/** The latest money recorded from investors (IMS-V2/DESIGN.md §7 HistoryTable), on the office home. */
class LatestMoneyIn extends Widget
{
    use GuardsWidgetRendering;

    protected static bool $isLazy = false;

    protected string $view = 'filament.ims.office.widgets.latest-money-in';

    /** @return list<array{name: string, date: \Carbon\CarbonInterface|null, where: string, waiting: bool, amount: float}> */
    public function rows(): array
    {
        return app(OfficeFigures::class)->latestMoneyIn(5);
    }

    public function currency(): string
    {
        return app(CompanyContext::class)->company()?->currency ?: 'BDT';
    }
}
