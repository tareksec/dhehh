<?php

namespace App\Reports\Definitions;

use App\Models\Expense;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use App\Reports\ReportFilters;
use App\Services\ReportService;

class ExpensesReport extends ReportDefinition
{
    public function key(): string
    {
        return 'expenses';
    }

    public function title(): string
    {
        return __('Expense Report');
    }

    public function description(): string
    {
        return __('Expenses by category and account within the selected date range.');
    }

    public function group(): string
    {
        return self::GROUP_ACCOUNTS;
    }

    public function icon(): string
    {
        return 'heroicon-o-receipt-percent';
    }

    public function filename(): string
    {
        return 'expense-report';
    }

    public function emptyHeading(): string
    {
        return __('No expenses found');
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            ReportColumn::date('date', __('Date')),
            ReportColumn::text('expense', __('Expense'))->copyable(),
            ReportColumn::text('category', __('Category')),
            ReportColumn::text('account', __('Account')),
            ReportColumn::money('amount', __('Amount'))->sum(),
        ];
    }

    public function rows(ReportFilters $filters): iterable
    {
        return app(ReportService::class)->expensesForExport($filters->from, $filters->to)
            ->map(fn (Expense $expense): array => [
                'date' => $expense->expense_date,
                'expense' => $expense->expense_number,
                'category' => $expense->category?->name,
                'account' => $expense->account?->name,
                'amount' => $expense->amount,
            ]);
    }

    public function screenSort(): ?array
    {
        return ['date', 'desc'];
    }
}
