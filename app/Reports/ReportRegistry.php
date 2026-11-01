<?php

namespace App\Reports;

use App\Reports\Definitions\CustomerDuesReport;
use App\Reports\Definitions\ExpensesReport;
use App\Reports\Definitions\LedgerReport;
use App\Reports\Definitions\LowStockReport;
use App\Reports\Definitions\ProfitReport;
use App\Reports\Definitions\PurchasesReport;
use App\Reports\Definitions\SalesReport;
use App\Reports\Definitions\StockReport;
use App\Reports\Definitions\SupplierAccountReport;
use App\Reports\Definitions\SupplierAccountsReport;
use App\Reports\Definitions\SupplierDuesReport;

/**
 * The list of reports. Adding a report means writing one ReportDefinition and
 * adding its class here: the page, exports and print view pick it up.
 */
final class ReportRegistry
{
    /** @var array<class-string<ReportDefinition>> */
    private const DEFINITIONS = [
        SalesReport::class,
        ProfitReport::class,
        CustomerDuesReport::class,
        PurchasesReport::class,
        SupplierDuesReport::class,
        StockReport::class,
        LowStockReport::class,
        ExpensesReport::class,
        LedgerReport::class,
        SupplierAccountReport::class,
        SupplierAccountsReport::class,
    ];

    /** Order the groups appear in on the Reports page. */
    private const GROUP_ORDER = [
        ReportDefinition::GROUP_SALES,
        ReportDefinition::GROUP_PURCHASING,
        ReportDefinition::GROUP_INVENTORY,
        ReportDefinition::GROUP_ACCOUNTS,
        ReportDefinition::GROUP_SUPPLIERS,
    ];

    /**
     * @return array<string, ReportDefinition> keyed by report key
     */
    public static function all(): array
    {
        $reports = [];

        foreach (self::DEFINITIONS as $class) {
            $report = app($class);
            $reports[$report->key()] = $report;
        }

        return $reports;
    }

    public static function find(string $key): ?ReportDefinition
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Report keys per group, in display order.
     *
     * @return array<string, array<string>>
     */
    public static function groups(): array
    {
        $groups = [];

        foreach (self::all() as $key => $report) {
            $groups[$report->group()][] = $key;
        }

        return collect(self::GROUP_ORDER)
            ->filter(fn (string $group): bool => isset($groups[$group]))
            ->mapWithKeys(fn (string $group): array => [$group => $groups[$group]])
            ->all();
    }
}
