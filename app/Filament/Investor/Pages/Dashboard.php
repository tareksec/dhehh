<?php

namespace App\Filament\Investor\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Models\Investment;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * The portal home (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md §6.1 page 1):
 * totals, a card per investment, the 12-month profit chart and the latest
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

    /** @return Collection<int, Investment> */
    public function investmentCards(): Collection
    {
        return $this->portal()->investments($this->account())
            ->with(['project', 'project.settlement'])
            ->orderByDesc('invested_at')
            ->orderByDesc('id')
            ->get();
    }

    public function recentStatement(): SupportCollection
    {
        return $this->portal()->statement($this->account())->take(5);
    }
}
