<?php

namespace App\Reports\Definitions;

use App\Models\Expense;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierPayment;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use App\Reports\ReportFilters;
use App\Services\SupplierAccountService;
use Illuminate\Support\Collection;

/**
 * Every supplier with an account, side by side: what the purchase invoices
 * say we owe, and what the supplier account says we owe.
 */
class SupplierAccountsReport extends ReportDefinition
{
    public function key(): string
    {
        return 'supplier-accounts';
    }

    public function title(): string
    {
        return __('Supplier Accounts');
    }

    public function description(): string
    {
        return __('All supplier accounts: purchase invoice payable next to what the supplier account says we owe.');
    }

    public function group(): string
    {
        return self::GROUP_SUPPLIERS;
    }

    public function icon(): string
    {
        return 'heroicon-o-building-storefront';
    }

    public function filename(): string
    {
        return 'supplier-accounts';
    }

    public function emptyHeading(): string
    {
        return __('No supplier accounts found');
    }

    public function usesDateRange(): bool
    {
        return false;
    }

    public function usesAsOfDate(): bool
    {
        return true;
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            ReportColumn::text('supplier', __('Supplier')),
            ReportColumn::text('phone', __('Phone'))->copyable(),
            ReportColumn::money('invoice_payable', __('Purchase invoice payable'))->sum(),
            ReportColumn::money('value_received', __('Value received'))->sum(),
            ReportColumn::money('goods_taken', __('Goods taken'))->sum(),
            ReportColumn::money('advance', __('Customer advance'))->sum(),
            ReportColumn::money('total_given', __('Total we gave'))->sum(),
            ReportColumn::money('balance', __('Supplier still gets from us'))->sum(),
        ];
    }

    public function rows(ReportFilters $filters): iterable
    {
        $service = app(SupplierAccountService::class);

        return $this->suppliersWithAnAccount()
            ->map(function (Supplier $supplier) use ($service, $filters): array {
                $statement = $service->statement($supplier, $filters->to);

                return [
                    'supplier' => $supplier->name,
                    'phone' => $supplier->phone,
                    'invoice_payable' => $supplier->current_balance,
                    'value_received' => $statement->valueReceived,
                    'goods_taken' => $statement->goodsTaken,
                    'advance' => $statement->customerAdvance,
                    'total_given' => $statement->totalGiven(),
                    'balance' => $statement->balance(),
                ];
            });
    }

    public function screenSort(): ?array
    {
        return ['balance', 'desc'];
    }

    /**
     * @return Collection<int, Supplier>
     */
    private function suppliersWithAnAccount()
    {
        $ids = PurchaseReceipt::query()->pluck('supplier_id')
            ->merge(SupplierOrder::query()->pluck('supplier_id'))
            ->merge(Expense::query()->whereNotNull('supplier_id')->pluck('supplier_id'))
            ->merge(SupplierPayment::query()->where('for_supplier_account', true)->pluck('supplier_id'))
            ->unique();

        return Supplier::query()->whereIn('id', $ids)->orderBy('name')->get();
    }
}
