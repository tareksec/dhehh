<?php

namespace App\Reports\Definitions;

use App\Filament\Pages\SupplierProductAccount;
use App\Models\Supplier;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use App\Reports\ReportFilters;
use App\Reports\SupplierStatement;
use App\Services\SupplierAccountService;

class SupplierAccountReport extends ReportDefinition
{
    /** @var array<string, SupplierStatement|null> one statement per filter set, shared by summary() and rows() */
    private array $statements = [];

    public function key(): string
    {
        return 'supplier-account';
    }

    public function title(): string
    {
        return __('Supplier Account');
    }

    public function description(): string
    {
        return __('What we still owe one supplier: goods received, goods he took from our stock, customer advance he collected, and what we gave him.');
    }

    public function group(): string
    {
        return self::GROUP_SUPPLIERS;
    }

    public function icon(): string
    {
        return 'heroicon-o-scale';
    }

    public function filename(): string
    {
        return 'supplier-account';
    }

    public function emptyHeading(): string
    {
        return __('No goods found for this supplier');
    }

    public function usesDateRange(): bool
    {
        return false;
    }

    public function usesAsOfDate(): bool
    {
        return true;
    }

    public function usesSupplier(): bool
    {
        return true;
    }

    public function requiresSupplier(): bool
    {
        return true;
    }

    public function summaryTitle(): string
    {
        return __('Account');
    }

    public function notice(ReportFilters $filters): ?string
    {
        return $this->statement($filters) === null
            ? __('Choose a supplier to see the account.')
            : null;
    }

    public function summary(ReportFilters $filters): array
    {
        return $this->statement($filters)?->lines() ?? [];
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            // The product name opens that product's part of the account.
            ReportColumn::text('product', __('Product'))
                ->wrap()
                ->linkTo(fn (array $row, ReportFilters $filters): ?string => $filters->supplierId && isset($row['product_id'])
                    ? SupplierProductAccount::urlFor($filters->supplierId, (int) $row['product_id'], $filters->to->toDateString())
                    : null),
            ReportColumn::number('received', __('Received (pcs)'))->sum(),
            ReportColumn::number('sold', __('Sold (pcs)'))->sum(),
            ReportColumn::money('sales_amount', __('Sales amount'))->sum(),
            ReportColumn::money('avg_rate', __('Avg rate')),
            ReportColumn::number('damage', __('Damage (pcs)'))->sum(),
            ReportColumn::number('short', __('Short (pcs)'))->sum(),
            ReportColumn::number('stock', __('Stock (pcs)'))->sum(),
            ReportColumn::number('outside', __('Outside godown (pcs)'))->sum(),
            ReportColumn::number('taken', __('Taken by supplier (pcs)'))->sum(),
            ReportColumn::money('value_received', __('Value received'))->sum(),
            ReportColumn::money('goods_taken', __('Goods taken value'))->sum(),
            ReportColumn::money('advance', __('Customer advance'))->sum(),
            ReportColumn::number('difference', __('Difference (pcs)'))->sum(),
        ];
    }

    public function rows(ReportFilters $filters): iterable
    {
        return collect($this->statement($filters)?->products ?? []);
    }

    private function statement(ReportFilters $filters): ?SupplierStatement
    {
        return $this->statements[$filters->key()] ??= $this->build($filters);
    }

    private function build(ReportFilters $filters): ?SupplierStatement
    {
        $supplier = $filters->supplierId ? Supplier::query()->find($filters->supplierId) : null;

        return $supplier ? app(SupplierAccountService::class)->statement($supplier, $filters->to) : null;
    }
}
