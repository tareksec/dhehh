<?php

namespace App\Filament\Reseller\Widgets;

use App\Models\Customer;
use App\Models\ResellerCommission;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/**
 * Reseller portal Phase 3 (13_RESELLER_PORTAL_PLAN.md §3 row 1): total
 * commission per month for the last 6 months, embedded directly in the
 * dashboard view via @livewire() -- this page isn't Filament's own
 * Dashboard page class, so it has no built-in ->getWidgets() slot.
 * Grouped by `created_at` (when the commission row was recorded, i.e. the
 * order was placed) rather than `payable_at`, since a pending commission's
 * `payable_at` is null and would otherwise disappear from the chart until
 * the order is delivered.
 */
class CommissionTrend extends ChartWidget
{
    protected function getData(): array
    {
        $reseller = Filament::auth()->user();

        $months = 6;
        $start = now()->startOfMonth()->subMonths($months - 1);

        $periodExpression = ResellerCommission::monthExpression();

        // A cancelled/returned order earns nothing (ResellerCommissionService),
        // so it must not show up in the chart either.
        $totals = $reseller instanceof Customer
            ? ResellerCommission::query()
                ->where('customer_id', $reseller->getKey())
                ->where('status', '!=', ResellerCommission::STATUS_CANCELLED)
                ->where('created_at', '>=', $start)
                ->selectRaw("{$periodExpression} as period, sum(amount) as total")
                ->groupBy('period')
                ->pluck('total', 'period')
            : collect();

        $labels = [];
        $data = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $key = $month->format('Y-m');
            $labels[] = $month->format('M Y');
            $data[] = (float) ($totals[$key] ?? 0);
        }

        return [
            'datasets' => [[
                'label' => __('Commission'),
                'data' => $data,
                'backgroundColor' => '#6366f1',
            ]],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function getHeading(): string
    {
        return __('Commission (last 6 months)');
    }
}
