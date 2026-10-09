<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Services\ReportService;
use Filament\Widgets\Widget;

class TopBusinessPerformers extends Widget
{
    use GuardsWidgetRendering;

    protected string $view = 'filament.widgets.top-business-performers';

    protected int|string|array $columnSpan = 'full';

    public function getViewData(): array
    {
        $reports = app(ReportService::class);

        return [
            'products' => $reports->topSellingProducts(),
            'customers' => $reports->topCustomers(),
            'suppliers' => $reports->topSuppliers(),
        ];
    }
}
