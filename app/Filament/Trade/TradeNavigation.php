<?php

namespace App\Filament\Trade;

use App\Filament\Trade\Resources\DeliveryChallans\DeliveryChallanResource;
use App\Filament\Trade\Resources\Dues\DueResource;
use App\Filament\Trade\Resources\GoodsIn\GoodsInResource;
use App\Filament\Trade\Resources\StockItems\StockItemResource;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CompanySettingsService;
use App\Services\TradePortalCompanies;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Resources\Resource;

/**
 * The Trade Portal menu: a short flat list in plain shop words. On wide
 * screens it is the top menu; on phones the daily pages are the bottom tab
 * bar and the rest are tiles in the Menu sheet
 * (resources/views/filament/trade/mobile-nav.blade.php). An entry shows only
 * to people allowed to open it.
 */
class TradeNavigation
{
    /** @return list<array{0: class-string<Page|resource>, 1: string, 2: string, 3: bool}> class, label, icon, on the phone tab bar */
    public static function layout(): array
    {
        return [
            [Pages\Home::class, 'Home', 'heroicon-o-home', true],
            [DeliveryChallanResource::class, 'Sales', 'heroicon-o-document-text', true],
            [StockItemResource::class, 'Stock', 'heroicon-o-archive-box', true],
            [DueResource::class, 'Dues', 'heroicon-o-clock', true],
            [GoodsInResource::class, 'Goods in', 'heroicon-o-truck', false],
            [Pages\ImportOldData::class, 'Bring in old data', 'heroicon-o-arrow-up-tray', false],
            [Pages\BusinessProfile::class, 'Business profile', 'heroicon-o-building-office-2', false],
        ];
    }

    public static function build(NavigationBuilder $builder): NavigationBuilder
    {
        return $builder->items(array_map(fn (array $item): NavigationItem => NavigationItem::make($item['label'])
            ->icon($item['icon'])
            ->url($item['url'])
            ->isActiveWhen(fn (): bool => $item['active']), static::items()));
    }

    /** @return list<array{label: string, icon: string, url: string, active: bool, tab: bool}> */
    public static function items(): array
    {
        $panel = Filament::getPanel('trade');
        $items = [];

        foreach (static::layout() as [$class, $label, $icon, $tab]) {
            if (! class_exists($class) || ! $class::canAccess()) {
                continue;
            }

            $active = is_subclass_of($class, Resource::class)
                ? request()->routeIs($class::getRouteBaseName($panel).'.*')
                : request()->routeIs($class::getRouteName($panel));

            $items[] = [
                'label' => __($label),
                'icon' => $icon,
                'url' => $class::getUrl(panel: 'trade'),
                'active' => $active,
                'tab' => $tab,
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
