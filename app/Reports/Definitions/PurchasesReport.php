<?php

namespace App\Reports\Definitions;

use App\Models\Purchase;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use App\Reports\ReportFilters;
use App\Services\ReportService;

class PurchasesReport extends ReportDefinition
{
    /** @var array<string, array<string>> custom cost labels per filter set, so columns() and rows() scan once */
    private array $customCostLabels = [];

    public function key(): string
    {
        return 'purchases';
    }

    public function title(): string
    {
        return __('Purchase Report');
    }

    public function description(): string
    {
        return __('Received purchases by date range.');
    }

    public function group(): string
    {
        return self::GROUP_PURCHASING;
    }

    public function icon(): string
    {
        return 'heroicon-o-truck';
    }

    public function filename(): string
    {
        return 'purchase-report';
    }

    public function emptyHeading(): string
    {
        return __('No purchases found');
    }

    public function columns(ReportFilters $filters): array
    {
        $columns = [
            ReportColumn::date('date', __('Date')),
            ReportColumn::text('purchase', __('Purchase'))->copyable(),
            ReportColumn::text('supplier', __('Supplier')),
        ];

        // One column per China-to-BD cost head: a spreadsheet detail, too wide for the screen.
        foreach (Purchase::CHINA_TO_BD_COST_FIELDS as $field => $label) {
            $columns[] = ReportColumn::money("cost_{$field}", $label)->exportOnly();
        }

        $columns[] = ReportColumn::money('china_to_bd_costs', __('China to BD Costs'))->screenOnly();
        $columns[] = ReportColumn::money('china_to_bd_total', __('China to BD Cost Total'))->exportOnly();

        foreach ($this->customCostLabels($filters) as $index => $label) {
            $columns[] = ReportColumn::money("custom_cost_{$index}", $label);
        }

        return [
            ...$columns,
            ReportColumn::money('landed_total', __('Landed Cost Total')),
            ReportColumn::money('total', __('Total'))->sum(),
            ReportColumn::money('paid', __('Paid'))->sum(),
            ReportColumn::money('due', __('Due'))->sum(),
            ReportColumn::badge('status', __('Status'), 'info'),
        ];
    }

    public function rows(ReportFilters $filters): iterable
    {
        $labels = $this->customCostLabels($filters);

        return app(ReportService::class)->purchasesForExport($filters->from, $filters->to)
            ->map(function (Purchase $purchase) use ($labels): array {
                $row = [
                    'date' => $purchase->purchase_date,
                    'purchase' => $purchase->purchase_number,
                    'supplier' => $purchase->supplier?->name,
                ];

                foreach (array_keys(Purchase::CHINA_TO_BD_COST_FIELDS) as $field) {
                    $row["cost_{$field}"] = $purchase->{$field};
                }

                $row['china_to_bd_costs'] = $purchase->chinaToBdCostTotal();
                $row['china_to_bd_total'] = $purchase->chinaToBdCostTotal();

                foreach ($labels as $index => $label) {
                    $row["custom_cost_{$index}"] = $purchase->customCostAmountFor($label);
                }

                return [
                    ...$row,
                    'landed_total' => $purchase->landedCostTotal(),
                    'total' => $purchase->total_amount,
                    'paid' => $purchase->paid_amount,
                    'due' => $purchase->due_amount,
                    'status' => $purchase->status,
                ];
            });
    }

    public function screenSort(): ?array
    {
        return ['date', 'desc'];
    }

    /**
     * @return array<int, string>
     */
    private function customCostLabels(ReportFilters $filters): array
    {
        return $this->customCostLabels[$filters->key()] ??= app(ReportService::class)
            ->purchasesForExport($filters->from, $filters->to)
            ->flatMap(fn (Purchase $purchase): array => collect($purchase->custom_costs ?? [])
                ->pluck('label')
                ->filter()
                ->all())
            ->unique()
            ->values()
            ->all();
    }
}
