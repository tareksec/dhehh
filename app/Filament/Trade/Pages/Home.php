<?php

namespace App\Filament\Trade\Pages;

use App\Services\TradePortalHome;
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

        return [
            'sales' => $home->todaySales(),
            'dues' => $home->dues(),
            'lowStock' => $home->lowStock(),
            'lcs' => $home->openLcs(),
        ];
    }
}
