<?php

namespace App\Filament\Investor\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Services\InvestorPortalCards;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection as SupportCollection;

/**
 * The portal home (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md §6.1 page 1):
 * totals, a card per project under its group, the 12-month profit chart and the latest
 * statement rows for an investor; the referred-capital and commission
 * totals for a channel partner. One person who is both sees both.
 */
class Dashboard extends Page
{
    use InvestorPortalPage;

    protected static ?string $portalRole = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.investor.pages.dashboard';

    public static function getNavigationLabel(): string
    {
        return __('Dashboard');
    }

    public function getTitle(): string
    {
        return __('Dashboard');
    }

    /**
     * The investor's groups with a card per project that is still running or
     * waiting for its payment, plus the cards of projects outside any group.
     * A project leaves here once its payout is paid and the screenshot is up.
     *
     * @return array{groups: SupportCollection<int, array<string, mixed>>, legacy: SupportCollection<int, array<string, mixed>>}
     */
    public function board(): array
    {
        return app(InvestorPortalCards::class)->dashboard($this->account());
    }

    public function recentStatement(): SupportCollection
    {
        return $this->portal()->statement($this->account())->take(5);
    }
}
