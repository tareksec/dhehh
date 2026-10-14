<?php

namespace App\Filament\Resources\StorefrontCartRecords\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Models\StorefrontCartRecord;
use App\Support\MoneyFormatter;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StorefrontRecoveryOverview extends StatsOverviewWidget
{
    use GuardsWidgetRendering;

    protected static bool $isLazy = false;

    protected ?string $heading = 'Checkout Recovery — Last 30 Days';

    protected function getStats(): array
    {
        $base = StorefrontCartRecord::query()
            ->where('checkout_started_at', '>=', now()->subDays(30));
        $started = (clone $base)->count();
        $open = (clone $base)->where('status', '!=', StorefrontCartRecord::STATUS_CONVERTED)->count();
        $recovered = (clone $base)->whereNotNull('recovered_at')->count();
        $revenue = (float) (clone $base)->whereNotNull('recovered_at')->sum('subtotal');
        $rate = $started > 0 ? round(($recovered / $started) * 100, 1) : 0;

        return [
            Stat::make('Checkout started', $started)
                ->icon(Heroicon::OutlinedShoppingCart),
            Stat::make('Still incomplete', $open)
                ->icon(Heroicon::OutlinedClock)
                ->color($open > 0 ? 'warning' : 'success'),
            Stat::make('Recovered', $recovered)
                ->description($rate.'% recovery rate')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('success'),
            Stat::make('Recovered revenue', MoneyFormatter::currency($revenue))
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success'),
        ];
    }
}
