<?php

namespace App\Reports;

/**
 * A report is defined once: its columns and its rows. The Reports page, CSV,
 * XLSX, PDF and the printable page all read from here.
 */
abstract class ReportDefinition
{
    public const GROUP_SALES = 'Sales';

    public const GROUP_PURCHASING = 'Purchasing';

    public const GROUP_INVENTORY = 'Inventory';

    public const GROUP_ACCOUNTS = 'Accounts';

    public const GROUP_SUPPLIERS = 'Suppliers';

    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /** One of the GROUP_* constants. */
    abstract public function group(): string;

    abstract public function icon(): string;

    /** File name without extension, e.g. "sales-report". */
    abstract public function filename(): string;

    abstract public function emptyHeading(): string;

    /**
     * @return array<ReportColumn>
     */
    abstract public function columns(ReportFilters $filters): array;

    /**
     * Raw values keyed by column key. Return a lazy collection for big tables.
     *
     * @return iterable<array<string, mixed>>
     */
    abstract public function rows(ReportFilters $filters): iterable;

    public function usesDateRange(): bool
    {
        return true;
    }

    /** One "as of" date instead of a range: the report is a running balance up to that day. */
    public function usesAsOfDate(): bool
    {
        return false;
    }

    public function usesSupplier(): bool
    {
        return false;
    }

    /** True when the report means nothing without a supplier picked. */
    public function requiresSupplier(): bool
    {
        return false;
    }

    /**
     * An account block shown above the table and written above it in the files.
     *
     * @return array<int, array{label: string, value: float, strong: bool, note: ?string}>
     */
    public function summary(ReportFilters $filters): array
    {
        return [];
    }

    /** Heading of the account block. */
    public function summaryTitle(): string
    {
        return __('Account');
    }

    /** A line of help shown instead of rows, e.g. "choose a supplier". */
    public function notice(ReportFilters $filters): ?string
    {
        return null;
    }

    /**
     * Default order of the on-screen table: [column key, 'asc'|'desc'].
     * Exports keep the natural (id) order.
     *
     * @return array{0: string, 1: string}|null
     */
    public function screenSort(): ?array
    {
        return null;
    }
}
