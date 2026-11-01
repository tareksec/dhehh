<?php

namespace App\Reports\Definitions;

use App\Models\Order;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use App\Reports\ReportFilters;
use App\Services\ReportService;

class SalesReport extends ReportDefinition
{
    public function key(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return __('Sales Report');
    }

    public function description(): string
    {
        return __('Confirmed and completed sales invoices by date range.');
    }

    public function group(): string
    {
        return self::GROUP_SALES;
    }

    public function icon(): string
    {
        return 'heroicon-o-shopping-bag';
    }

    public function filename(): string
    {
        return 'sales-report';
    }

    public function emptyHeading(): string
    {
        return __('No sales found');
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            ReportColumn::date('date', __('Date')),
            ReportColumn::text('invoice', __('Invoice'))->copyable(),
            ReportColumn::text('customer', __('Customer')),
            ReportColumn::money('total', __('Total'))->sum(),
            ReportColumn::money('paid', __('Paid'))->sum(),
            ReportColumn::money('due', __('Due'))->sum(),
            ReportColumn::badge('status', __('Status')),
        ];
    }

    public function rows(ReportFilters $filters): iterable
    {
        return app(ReportService::class)->salesForExport($filters->from, $filters->to)
            ->map(fn (Order $order): array => [
                'date' => $order->order_date,
                'invoice' => $order->order_number,
                'customer' => $order->customer?->name,
                'total' => $order->total_amount,
                'paid' => $order->paid_amount,
                'due' => $order->due_amount,
                'status' => $order->status,
            ]);
    }

    public function screenSort(): ?array
    {
        return ['date', 'desc'];
    }
}
