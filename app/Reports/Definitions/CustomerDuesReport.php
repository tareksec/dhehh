<?php

namespace App\Reports\Definitions;

use App\Models\Customer;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use App\Reports\ReportFilters;
use App\Services\ReportService;

class CustomerDuesReport extends ReportDefinition
{
    public function key(): string
    {
        return 'customer-dues';
    }

    public function title(): string
    {
        return __('Customer Due Report');
    }

    public function description(): string
    {
        return __('Customers with outstanding balances.');
    }

    public function group(): string
    {
        return self::GROUP_SALES;
    }

    public function icon(): string
    {
        return 'heroicon-o-user-group';
    }

    public function filename(): string
    {
        return 'customer-due-report';
    }

    public function emptyHeading(): string
    {
        return __('No customer dues found');
    }

    public function usesDateRange(): bool
    {
        return false;
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            ReportColumn::text('customer', __('Customer')),
            ReportColumn::text('phone', __('Phone'))->copyable(),
            ReportColumn::text('email', __('Email'))->copyable(),
            ReportColumn::money('due', __('Due'))->sum(),
        ];
    }

    public function rows(ReportFilters $filters): iterable
    {
        return app(ReportService::class)->customerDuesForExport()
            ->map(fn (Customer $customer): array => [
                'customer' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'due' => $customer->current_balance,
            ]);
    }

    public function screenSort(): ?array
    {
        return ['due', 'desc'];
    }
}
