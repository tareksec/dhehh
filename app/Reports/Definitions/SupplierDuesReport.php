<?php

namespace App\Reports\Definitions;

use App\Models\Supplier;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use App\Reports\ReportFilters;
use App\Services\ReportService;

class SupplierDuesReport extends ReportDefinition
{
    public function key(): string
    {
        return 'supplier-dues';
    }

    public function title(): string
    {
        return __('Supplier Due Report');
    }

    public function description(): string
    {
        return __('Suppliers with outstanding payables.');
    }

    public function group(): string
    {
        return self::GROUP_PURCHASING;
    }

    public function icon(): string
    {
        return 'heroicon-o-building-storefront';
    }

    public function filename(): string
    {
        return 'supplier-due-report';
    }

    public function emptyHeading(): string
    {
        return __('No supplier payables found');
    }

    public function usesDateRange(): bool
    {
        return false;
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            ReportColumn::text('supplier', __('Supplier')),
            ReportColumn::text('phone', __('Phone'))->copyable(),
            ReportColumn::text('company', __('Company')),
            ReportColumn::money('payable', __('Payable'))->sum(),
        ];
    }

    public function rows(ReportFilters $filters): iterable
    {
        return app(ReportService::class)->supplierDuesForExport()
            ->map(fn (Supplier $supplier): array => [
                'supplier' => $supplier->name,
                'phone' => $supplier->phone,
                'company' => $supplier->company_name,
                'payable' => $supplier->current_balance,
            ]);
    }

    public function screenSort(): ?array
    {
        return ['payable', 'desc'];
    }
}
