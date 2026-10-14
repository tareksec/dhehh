<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Services\ReportService;
use Filament\Widgets\ChartWidget;

class SalesPurchaseTrend extends ChartWidget
{
    use GuardsWidgetRendering;

    protected ?string $heading = 'Sales vs Purchases';

    protected ?string $description = 'Last six months confirmed sales and received purchases';

    protected function getData(): array
    {
        $rows = app(ReportService::class)->monthlySalesAndPurchases();

        return [
            'datasets' => [
                [
                    'label' => 'Sales',
                    'data' => $rows->pluck('sales')->all(),
                    'borderColor' => '#22c55e',
                    'backgroundColor' => 'rgba(34, 197, 94, 0.16)',
                ],
                [
                    'label' => 'Purchases',
                    'data' => $rows->pluck('purchases')->all(),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.16)',
                ],
            ],
            'labels' => $rows->pluck('label')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
