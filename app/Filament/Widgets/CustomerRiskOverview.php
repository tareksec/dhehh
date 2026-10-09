<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Models\CustomerRiskProfile;
use App\Models\CustomerRiskReview;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class CustomerRiskOverview extends StatsOverviewWidget
{
    use GuardsWidgetRendering;

    protected ?string $heading = 'Customer Success & Risk';

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return Schema::hasTable('customer_risk_profiles') && (Auth::user()?->hasPermission('sales.view') ?? false);
    }

    /**
     * Keep the overview dense on both phone and desktop while retaining
     * Filament's native responsive grid implementation.
     *
     * @return array<string, int>
     */
    protected function getColumns(): array
    {
        return [
            'default' => 2,
            'lg' => 3,
        ];
    }

    protected function getStats(): array
    {
        $highRisk = CustomerRiskProfile::query()->where('risk_level', CustomerRiskProfile::LEVEL_HIGH)->count();
        $blacklisted = CustomerRiskProfile::query()->where('risk_level', CustomerRiskProfile::LEVEL_BLACKLISTED)->count();
        $pendingReviews = Schema::hasTable('customer_risk_reviews')
            ? CustomerRiskReview::query()->where('status', CustomerRiskReview::STATUS_PENDING)->count()
            : 0;

        return [
            Stat::make('High Risk Customers', $highRisk)
                ->icon(Heroicon::OutlinedShieldExclamation)
                ->color($highRisk > 0 ? 'danger' : 'success'),
            Stat::make('Blacklisted Matches', $blacklisted)
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color($blacklisted > 0 ? 'danger' : 'success'),
            Stat::make('Pending Risk Reviews', $pendingReviews)
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color($pendingReviews > 0 ? 'warning' : 'success'),
        ];
    }
}
