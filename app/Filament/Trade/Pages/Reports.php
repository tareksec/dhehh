<?php

namespace App\Filament\Trade\Pages;

use App\Services\TradeReportService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The Trade Portal's reports list (docs/tasneem-samples/PLAN.md §6): one big
 * tile per report; each opens on screen with PDF and Excel buttons.
 * Needs "trade.reports".
 */
class Reports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'reports';

    protected string $view = 'filament.trade.pages.reports';

    /** key => icon. */
    public const ICONS = [
        'daily_sales' => 'heroicon-o-document-text',
        'monthly_sales' => 'heroicon-o-calendar-days',
        'receivables_aging' => 'heroicon-o-clock',
        'stock_ledger' => 'heroicon-o-archive-box',
        'supplier_payable' => 'heroicon-o-truck',
        'cash_bank' => 'heroicon-o-banknotes',
        'lc_status' => 'heroicon-o-globe-asia-australia',
        'landed_cost' => 'heroicon-o-calculator',
        'shipment_advice_register' => 'heroicon-o-shield-check',
    ];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('trade.reports') ?? false;
    }

    public function getTitle(): string
    {
        return __('Reports');
    }

    public static function getNavigationLabel(): string
    {
        return __('Reports');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'reports' => collect(TradeReportService::REPORTS)->map(fn (array $report, string $key): array => [
                'title' => __($report[0]),
                'icon' => self::ICONS[$key] ?? 'heroicon-o-document-chart-bar',
                'url' => Report::getUrl(['type' => $key], panel: 'trade'),
            ])->all(),
        ];
    }
}
