<?php

namespace App\Reports\Definitions;

use App\Models\Product;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use App\Reports\ReportFilters;
use App\Services\ReportService;

class StockReport extends ReportDefinition
{
    public function key(): string
    {
        return 'stock';
    }

    public function title(): string
    {
        return __('Stock Report');
    }

    public function description(): string
    {
        return __('Current product stock, reorder level, and pricing.');
    }

    public function group(): string
    {
        return self::GROUP_INVENTORY;
    }

    public function icon(): string
    {
        return 'heroicon-o-cube';
    }

    public function filename(): string
    {
        return 'stock-report';
    }

    public function emptyHeading(): string
    {
        return __('No products found');
    }

    public function usesDateRange(): bool
    {
        return false;
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            ReportColumn::text('sku', __('SKU'))->copyable(),
            ReportColumn::text('product', __('Product'))->wrap(),
            ReportColumn::text('category', __('Category')),
            ReportColumn::number('stock', __('Stock'))->sum(),
            ReportColumn::number('reorder', __('Reorder Level')),
            ReportColumn::money('cost', __('Cost Price')),
            ReportColumn::money('sale_price', __('Sale Price')),
        ];
    }

    public function rows(ReportFilters $filters): iterable
    {
        return app(ReportService::class)->stockForExport()
            ->map(fn (Product $product): array => static::productRow($product));
    }

    public function screenSort(): ?array
    {
        return ['product', 'asc'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function productRow(Product $product): array
    {
        return [
            'sku' => $product->sku,
            'product' => $product->name,
            'category' => $product->category?->name,
            'stock' => $product->stock,
            'reorder' => $product->reorder_level,
            'cost' => $product->cost_price,
            'sale_price' => $product->selling_price,
        ];
    }
}
