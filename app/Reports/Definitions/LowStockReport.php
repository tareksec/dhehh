<?php

namespace App\Reports\Definitions;

use App\Models\Product;
use App\Reports\ReportColumn;
use App\Reports\ReportFilters;
use App\Services\ReportService;

class LowStockReport extends StockReport
{
    public function key(): string
    {
        return 'low-stock';
    }

    public function title(): string
    {
        return __('Low Stock Report');
    }

    public function description(): string
    {
        return __('Products that are at or below reorder level.');
    }

    public function icon(): string
    {
        return 'heroicon-o-exclamation-triangle';
    }

    public function filename(): string
    {
        return 'low-stock-report';
    }

    public function columns(ReportFilters $filters): array
    {
        return collect(parent::columns($filters))
            ->map(fn (ReportColumn $column): ReportColumn => $column->key === 'stock'
                ? ReportColumn::badge('stock', __('Stock'), 'warning')
                : $column)
            ->all();
    }

    public function rows(ReportFilters $filters): iterable
    {
        return app(ReportService::class)->lowStockForExport()
            ->map(fn (Product $product): array => static::productRow($product));
    }
}
