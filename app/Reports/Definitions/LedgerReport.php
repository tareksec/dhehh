<?php

namespace App\Reports\Definitions;

use App\Models\TransactionLedger;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use App\Reports\ReportFilters;
use App\Services\ReportService;

class LedgerReport extends ReportDefinition
{
    public function key(): string
    {
        return 'ledger';
    }

    public function title(): string
    {
        return __('Account Transaction Report');
    }

    public function description(): string
    {
        return __('Account ledger inflows and outflows by date range.');
    }

    public function group(): string
    {
        return self::GROUP_ACCOUNTS;
    }

    public function icon(): string
    {
        return 'heroicon-o-banknotes';
    }

    public function filename(): string
    {
        return 'account-transaction-report';
    }

    public function emptyHeading(): string
    {
        return __('No transactions found');
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            ReportColumn::date('date', __('Date')),
            ReportColumn::text('account', __('Account')),
            ReportColumn::text('type', __('Type')),
            ReportColumn::badge('direction', __('Direction'))->colors(['in' => 'success', 'out' => 'danger']),
            ReportColumn::money('amount', __('Amount')),
            ReportColumn::text('note', __('Note'))->wrap(),
        ];
    }

    public function rows(ReportFilters $filters): iterable
    {
        return app(ReportService::class)->ledgerForExport($filters->from, $filters->to)
            ->map(fn (TransactionLedger $entry): array => [
                'date' => $entry->transaction_date,
                'account' => $entry->account?->name,
                'type' => (string) str($entry->type)->headline(),
                'direction' => $entry->direction,
                'amount' => $entry->amount,
                'note' => $entry->note,
            ]);
    }

    public function screenSort(): ?array
    {
        return ['date', 'desc'];
    }
}
