<?php

namespace App\Filament\Ims\Office\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Ims\Office\OfficeFigures;
use App\Services\CompanyContext;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;

/** Profit paid to investors per month, the bar chart in the middle of the office home. */
class OfficeProfitChart extends Widget
{
    use GuardsWidgetRendering;

    protected static bool $isLazy = false;

    protected string $view = 'filament.ims.office.widgets.profit-chart';

    /** @return list<array{label: string, long: string, value: float}> */
    public function bars(): array
    {
        $bars = [];

        foreach (app(OfficeFigures::class)->profitPaidByMonth(12) as $month => $amount) {
            $date = CarbonImmutable::createFromFormat('!Y-m', $month)->locale(app()->getLocale());
            $bars[] = [
                'label' => \App\Support\ImsNumbers::digits($date->translatedFormat('M')),
                'long' => \App\Support\ImsNumbers::digits($date->translatedFormat('F Y')),
                'value' => $amount,
            ];
        }

        return $bars;
    }

    public function currency(): string
    {
        return app(CompanyContext::class)->company()?->currency ?: 'BDT';
    }
}
