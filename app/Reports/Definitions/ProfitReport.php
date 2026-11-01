<?php

namespace App\Reports\Definitions;

use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use App\Reports\ReportFilters;
use App\Services\ReportService;

class ProfitReport extends ReportDefinition
{
    public function key(): string
    {
        return 'profit';
    }

    public function title(): string
    {
        return __('Product Profit Report');
    }

    public function description(): string
    {
        return __('Product-wise revenue, cost, and profit from sales.');
    }

    public function group(): string
    {
        return self::GROUP_SALES;
    }

    public function icon(): string
    {
        return 'heroicon-o-chart-pie';
    }

    public function filename(): string
    {
        return 'product-profit-report';
    }

    public function emptyHeading(): string
    {
        return __('No profit data found');
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            ReportColumn::date('date', __('Date')),
            ReportColumn::text('invoice', __('Invoice'))->copyable(),
            ReportColumn::text('product', __('Product'))->wrap(),
            ReportColumn::number('quantity', __('Qty'))->sum(),
            ReportColumn::money('revenue', __('Revenue'))->sum(),
            ReportColumn::money('cost', __('Cost'))->sum(),
            ReportColumn::money('profit', __('Profit'))->sum(),
        ];
    }

    public function rows(ReportFilters $filters): iterable
    {
        return app(ReportService::class)->profitForExport($filters->from, $filters->to);
    }
}
