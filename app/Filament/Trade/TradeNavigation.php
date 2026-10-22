<?php

namespace App\Filament\Trade;

use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CompanySettingsService;
use App\Services\TradePortalCompanies;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;

/**
 * The Trade Portal menu: a short flat list in plain shop words. On wide
 * screens it is the top menu; on phones the same pages are the bottom tab bar
 * (resources/views/filament/trade/mobile-nav.blade.php). An entry shows only
 * to people allowed to open it. Later phases add their pages to layout().
 */
class TradeNavigation
{
    /** @return list<array{0: class-string<Page>, 1: string, 2: string}> */
    public static function layout(): array
    {
        return [
            [Pages\Home::class, 'Home', 'heroicon-o-home'],
            [Pages\BusinessProfile::class, 'Business profile', 'heroicon-o-building-office-2'],
        ];
    }

    public static function build(NavigationBuilder $builder): NavigationBuilder
    {
        return $builder->items(array_map(fn (array $item): NavigationItem => NavigationItem::make($item['label'])
            ->icon($item['icon'])
            ->url($item['url'])
            ->isActiveWhen(fn (): bool => $item['active']), static::items()));
    }

    /** @return list<array{label: string, icon: string, url: string, active: bool}> */
    public static function items(): array
    {
        $panel = Filament::getPanel('trade');
        $items = [];

        foreach (static::layout() as [$class, $label, $icon]) {
            if (! class_exists($class) || ! $class::canAccess()) {
                continue;
            }

            $items[] = [
                'label' => __($label),
                'icon' => $icon,
                'url' => $class::getUrl(panel: 'trade'),
                'active' => request()->routeIs($class::getRouteName($panel)),
            ];
        }

        return $items;
    }

    /**
     * The other companies the signed-in person may open here, for the menu
     * sheet's company list (empty when there is only one).
     *
     * @return list<array{name: string, url: string, current: bool}>
     */
    public static function companies(): array
    {
        $user = Filament::auth()->user();
        $current = app(CompanyContext::class)->company();

        if (! $user instanceof User || $current === null) {
            return [];
        }

        $companies = app(TradePortalCompanies::class)->for($user);

        if ($companies->count() < 2) {
            return [];
        }

        $home = Pages\Home::getUrl(panel: 'trade');
        $settings = app(CompanySettingsService::class);

        return $companies->map(fn ($company): array => [
            'name' => $settings->name($company),
            'url' => $home.'?'.TradePortalCompanies::QUERY_KEY.'='.$company->getKey(),
            'current' => (int) $company->getKey() === (int) $current->getKey(),
        ])->values()->all();
    }
}
