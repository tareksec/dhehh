<?php

namespace App\Filament\Trade\Pages;

use App\Filament\Trade\Resources\DeliveryChallans\DeliveryChallanResource;
use App\Filament\Trade\Resources\Dues\DueResource;
use App\Filament\Trade\Resources\GoodsIn\GoodsInResource;
use App\Filament\Trade\Resources\LcFiles\LcFileResource;
use App\Filament\Trade\Resources\StockItems\StockItemResource;
use App\Services\TradePortalHome;
use App\Support\ModuleGuard;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;

/**
 * The Trade Portal home (docs/tasneem-samples/BUILD-BRIEF.md Phase 1): four
 * big cards — today's sales, money due from customers, low stock and the LC
 * files still open — each with a friendly line when there is nothing yet.
 * The figures come from TradePortalHome, card by card.
 */
class Home extends Dashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected string $view = 'filament.trade.pages.home';

    public static function getNavigationLabel(): string
    {
        return __('Home');
    }

    public function getTitle(): string
    {
        return __('Welcome, :name', ['name' => auth()->user()?->name ?? '']);
    }

    /** The page draws its own greeting (home.blade.php) instead of a heading. */
    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('trade.access') ?? false;
    }

    public function getWidgets(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $home = app(TradePortalHome::class);

        $hour = now()->hour;

        return [
            'greeting' => __($hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening')),
            'name' => auth()->user()?->name ?? '',
            'sales' => $home->todaySales(),
            'dues' => $home->dues(),
            'lowStock' => $home->lowStock(),
            'lcs' => $home->openLcs(),
            'money' => CashBook::canAccess() ? $home->money() : null,
            'quick' => $this->quickActions(),
            'links' => [
                'sales' => DeliveryChallanResource::canAccess() ? DeliveryChallanResource::getUrl(panel: 'trade') : null,
                'dues' => DueResource::canAccess() ? DueResource::getUrl(panel: 'trade') : null,
                'stock' => StockItemResource::canAccess() ? StockItemResource::getUrl(panel: 'trade') : null,
                'lcs' => LcFileResource::canAccess() ? LcFileResource::getUrl(panel: 'trade') : (GoodsInResource::canAccess() ? GoodsInResource::getUrl(panel: 'trade') : null),
                'money' => CashBook::canAccess() ? CashBook::getUrl(panel: 'trade') : null,
                'newSale' => DeliveryChallanResource::canAccess() ? DeliveryChallanResource::getUrl('create', panel: 'trade') : null,
            ],
        ];
    }

    /**
     * The big buttons at the top: the shop's daily jobs, each shown only to
     * people allowed to do it.
     *
     * @return list<array{label: string, icon: string, url: string}>
     */
    public function quickActions(): array
    {
        return ModuleGuard::run('trade-home-quick-actions', function (): array {
            $actions = [];

            foreach ([
                [DeliveryChallanResource::class, 'create', 'New challan', 'heroicon-o-document-plus'],
                [DueResource::class, 'index', 'Receive money', 'heroicon-o-banknotes'],
                [GoodsInResource::class, 'create', 'Goods in', 'heroicon-o-truck'],
                [StockItemResource::class, 'create', 'New item', 'heroicon-o-archive-box'],
            ] as [$class, $page, $label, $icon]) {
                if ($class::canAccess()) {
                    $actions[] = ['label' => __($label), 'icon' => $icon, 'url' => $class::getUrl($page, panel: 'trade')];
                }
            }

            return $actions;
        }, []);
    }
}
