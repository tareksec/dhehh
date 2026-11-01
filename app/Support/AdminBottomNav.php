<?php

namespace App\Support;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Resources\Resource;
use Illuminate\Support\HtmlString;

/**
 * The admin panel's phone bottom bar (owner request 2026-11-01).
 *
 * Below the `lg` breakpoint (where Filament swaps the sidebar for a menu button)
 * a bar is fixed to the bottom of the screen: Dashboard and the company switch
 * on the left, a raised "+" in the middle, Orders and Products on the right.
 * The "+" lists Create a new order / Add a new product / Add a new purchase /
 * Add a new expense; picking one opens the resource's own Create page in a
 * pop-up (an iframe named FRAME_NAME), so every rule of that page — stock
 * check, payment account, opening stock — applies exactly as on the full page
 * and nothing is re-implemented here.
 *
 * Every entry follows the person's permissions: a page the role may not open,
 * or a record it may not create, is not offered. The bar is drawn inside
 * ModuleGuard, so a failure here never takes a page down.
 *
 * The view is resources/views/filament/admin/bottom-nav.blade.php, the
 * behaviour is resources/views/filament/admin/bottom-nav-script.blade.php.
 */
final class AdminBottomNav
{
    /** The pop-up's iframe name: the page inside it knows it is embedded by this name. */
    public const FRAME_NAME = 'zz-quick-create';

    /** Added to the pop-up's first URL so the page inside leaves out the bar and the updater. */
    public const EMBED_QUERY = 'embed';

    /** The Create pages offered by the "+" button, in the order owner listed them. */
    private const CREATE_ENTRIES = [
        ['key' => 'order', 'resource' => OrderResource::class, 'icon' => 'heroicon-o-shopping-cart'],
        ['key' => 'product', 'resource' => ProductResource::class, 'icon' => 'heroicon-o-cube'],
        ['key' => 'purchase', 'resource' => PurchaseResource::class, 'icon' => 'heroicon-o-truck'],
        ['key' => 'expense', 'resource' => ExpenseResource::class, 'icon' => 'heroicon-o-receipt-percent'],
    ];

    /** True for the request that loads a Create page into the pop-up. */
    public static function isEmbedded(): bool
    {
        return request()->query(self::EMBED_QUERY) !== null;
    }

    /** The render-hook callback: the bar, or nothing when it does not apply or fails. */
    public static function html(): HtmlString
    {
        return new HtmlString(ModuleGuard::run('admin.bottom-nav', function (): string {
            $data = self::data();

            if ($data === null) {
                return '';
            }

            return ModuleGuard::renderView('admin.bottom-nav', view('filament.admin.bottom-nav', $data)) ?? '';
        }, ''));
    }

    /**
     * @return array<string, mixed>|null null = draw no bar (not signed in, or inside the pop-up)
     */
    public static function data(): ?array
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || self::isEmbedded()) {
            return null;
        }

        $dashboardUrl = Dashboard::getUrl();
        $orders = self::listLink(OrderResource::class, __('Orders'), 'heroicon-o-clipboard-document-list');
        $products = self::listLink(ProductResource::class, __('Products'), 'heroicon-o-cube');
        $creates = self::createEntries();

        // After a save, a list page that shows these figures is refreshed so the new record appears.
        $refreshPaths = array_values(array_unique(array_filter(array_map(
            self::pathOf(...),
            [
                $dashboardUrl,
                $orders['url'] ?? null,
                $products['url'] ?? null,
                ...array_map(fn (array $entry): ?string => $entry['list_url'], $creates),
            ],
        ))));

        return [
            'dashboard' => [
                'label' => __('Dashboard'),
                'icon' => 'heroicon-o-home',
                'url' => $dashboardUrl,
            ],
            'orders' => $orders,
            'products' => $products,
            'creates' => $creates,
            'company' => CompanySwitcherState::for($user),
            'refreshPaths' => $refreshPaths,
            'frameName' => self::FRAME_NAME,
        ];
    }

    /**
     * @param  class-string<resource>  $resource
     * @return array{label: string, icon: string, url: string}|null null when the role may not open the list
     */
    private static function listLink(string $resource, string $label, string $icon): ?array
    {
        if (! $resource::canViewAny()) {
            return null;
        }

        return [
            'label' => $label,
            'icon' => $icon,
            'url' => $resource::getUrl('index'),
        ];
    }

    /**
     * @return list<array{key: string, label: string, icon: string, url: string, list_url: string|null}>
     */
    private static function createEntries(): array
    {
        $labels = [
            'order' => __('Create a new order'),
            'product' => __('Add a new product'),
            'purchase' => __('Add a new purchase'),
            'expense' => __('Add a new expense'),
        ];

        $entries = [];

        foreach (self::CREATE_ENTRIES as $entry) {
            /** @var class-string<resource> $resource */
            $resource = $entry['resource'];

            if (! $resource::canCreate()) {
                continue;
            }

            $entries[] = [
                'key' => $entry['key'],
                'label' => $labels[$entry['key']],
                'icon' => $entry['icon'],
                'url' => self::popupUrl($resource::getUrl('create')),
                'list_url' => $resource::canViewAny() ? $resource::getUrl('index') : null,
            ];
        }

        return $entries;
    }

    /**
     * The pop-up's address: path and query only, so the iframe is always on the
     * very host the person is using (a company can have its own domain, and
     * `APP_URL` need not match it) and the page inside can talk to the opener.
     */
    private static function popupUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $path.'?'.http_build_query([...$query, self::EMBED_QUERY => 1]);
    }

    private static function pathOf(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? rtrim($path, '/') : null;
    }
}
