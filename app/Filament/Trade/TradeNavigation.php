<?php

namespace App\Filament\Trade;

use App\Filament\Trade\Resources\DeliveryChallans\DeliveryChallanResource;
use App\Filament\Trade\Resources\Dues\DueResource;
use App\Filament\Trade\Resources\GoodsIn\GoodsInResource;
use App\Filament\Trade\Resources\LcFiles\LcFileResource;
use App\Filament\Trade\Resources\MachineSerials\MachineSerialResource;
use App\Filament\Trade\Resources\MoneyAccounts\MoneyAccountResource;
use App\Filament\Trade\Resources\StockItems\StockItemResource;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CompanySettingsService;
use App\Services\TradePortalCompanies;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\Pages\ViewRecord;
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
    /** The headings of the Menu sheet, in order; each page of the sheet sits under one. */
    public const GROUPS = [
        'money' => 'Accounts and reports',
        'import' => 'Import and goods',
        'setup' => 'Setup',
    ];

    /** @return list<array{0: class-string<Page|resource>, 1: string, 2: string, 3: bool, 4: string|null}> class, label, icon, on the phone tab bar, Menu sheet group */
    public static function layout(): array
    {
        return [
            [Pages\Home::class, 'Home', 'heroicon-o-home', true, null],
            [DeliveryChallanResource::class, 'Sales', 'heroicon-o-document-text', true, null],
            [StockItemResource::class, 'Stock', 'heroicon-o-archive-box', true, null],
            [DueResource::class, 'Dues', 'heroicon-o-clock', true, null],
            [Pages\Reports::class, 'Reports', 'heroicon-o-chart-bar', false, 'money'],
            [Pages\CashBook::class, 'Cash book', 'heroicon-o-book-open', false, 'money'],
            [Pages\BalanceSheet::class, 'Balance sheet', 'heroicon-o-scale', false, 'money'],
            [GoodsInResource::class, 'Goods in', 'heroicon-o-truck', false, 'import'],
            [LcFileResource::class, 'LC files', 'heroicon-o-globe-asia-australia', false, 'import'],
            [MachineSerialResource::class, 'Serial numbers', 'heroicon-o-qr-code', false, 'import'],
            [MoneyAccountResource::class, 'Cash and bank accounts', 'heroicon-o-building-library', false, 'money'],
            [Pages\ImportOldData::class, 'Bring in old data', 'heroicon-o-arrow-up-tray', false, 'setup'],
            [Pages\BusinessProfile::class, 'Business profile', 'heroicon-o-building-office-2', false, 'setup'],
        ];
    }

    public static function build(NavigationBuilder $builder): NavigationBuilder
    {
        return $builder->items(array_map(fn (array $item): NavigationItem => NavigationItem::make($item['label'])
            ->icon($item['icon'])
            ->url($item['url'])
            ->isActiveWhen(fn (): bool => $item['active']), static::items()));
    }

    /** @return list<array{label: string, icon: string, url: string, active: bool, tab: bool, group: string|null}> */
    public static function items(): array
    {
        $panel = Filament::getPanel('trade');
        $items = [];

        foreach (static::layout() as [$class, $label, $icon, $tab, $group]) {
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
                'group' => $group,
            ];
        }

        return $items;
    }

    /**
     * The Menu sheet's pages under their headings, empty groups left out.
     *
     * @param  list<array{label: string, icon: string, url: string, active: bool, tab: bool, group: string|null}>  $items
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    public static function groups(array $items): array
    {
        $groups = [];

        foreach (static::GROUPS as $key => $label) {
            $inGroup = array_values(array_filter($items, fn (array $item): bool => ! $item['tab'] && $item['group'] === $key));

            if ($inGroup !== []) {
                $groups[] = ['label' => __($label), 'items' => $inGroup];
            }
        }

        return $groups;
    }

    /**
     * Where the round back button in a page's header goes on a phone: a
     * record's page → its list, a list or a page of the menu → Home, one
     * report → the reports, a record's other page (landed cost) → the
     * record. Null on Home. Read from the page being drawn, so a Livewire
     * update draws the same button.
     */
    public static function backUrl(mixed $page): ?string
    {
        if ($page === null || $page instanceof Pages\Home) {
            return null;
        }

        if ($page instanceof Pages\Report) {
            return Pages\Reports::getUrl(panel: 'trade');
        }

        if ($page instanceof ResourcePage && ! $page instanceof ListRecords) {
            $resource = $page::getResource();
            $record = method_exists($page, 'getRecord') ? $page->getRecord() : null;

            if ($record !== null && ! $page instanceof ViewRecord && $resource::hasPage('view')) {
                return $resource::getUrl('view', ['record' => $record], panel: 'trade');
            }

            return $resource::getUrl(panel: 'trade');
        }

        return Pages\Home::getUrl(panel: 'trade');
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
