<?php

namespace App\Filament\Investor\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Services\InvestorPortalCards;
use BackedEnum;
use Carbon\CarbonImmutable;
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

    public static function getNavigationGroup(): ?string
    {
        return __('Overview');
    }

    public static function getNavigationLabel(): string
    {
        return __('Dashboard');
    }

    public function getTitle(): string
    {
        return __('Dashboard');
    }

    public function getHeading(): string
    {
        return __('Welcome, :name', ['name' => $this->account()->name]);
    }

    public function getSubheading(): ?string
    {
        return CarbonImmutable::now()->translatedFormat('l, d F Y');
    }

    /** @var array{groups: SupportCollection<int, array<string, mixed>>, legacy: SupportCollection<int, array<string, mixed>>}|null */
    protected ?array $boardCache = null;

    /**
     * The investor's groups with a card per project that is still running or
     * waiting for its payment, plus the cards of projects outside any group.
     * A project leaves here once its payout is paid and the screenshot is up.
     *
     * @return array{groups: SupportCollection<int, array<string, mixed>>, legacy: SupportCollection<int, array<string, mixed>>}
     */
    public function board(): array
    {
        return $this->boardCache ??= app(InvestorPortalCards::class)->dashboard($this->account());
    }

    /**
     * The person's money in each group they are still in, for the "Capital by
     * group" ring: amount, share of the total and the ring segment (circle
     * radius 60, so the circumference is 2π·60).
     *
     * @return list<array{code: string, name: string, amount: float, percent: int, dash: float, offset: float, tone: int}>
     */
    public function capitalShares(): array
    {
        $groups = $this->board()['groups']->reject(fn (array $section): bool => $section['leaving'] || $section['amount'] <= 0)->values();
        $total = (float) $groups->sum('amount');

        if ($total <= 0) {
            return [];
        }

        $circumference = 2 * M_PI * 60;
        $offset = 0.0;
        $shares = [];

        foreach ($groups as $index => $section) {
            $length = $circumference * $section['amount'] / $total;
            $shares[] = [
                'code' => (string) $section['group']->group_code,
                'name' => (string) $section['group']->name,
                'amount' => (float) $section['amount'],
                'percent' => (int) round(100 * $section['amount'] / $total),
                // A 2px gap between segments when there is more than one.
                'dash' => round(max($length - ($groups->count() > 1 ? 2 : 0), 0.5), 2),
                'offset' => round(-$offset, 2),
                'tone' => $index % 5,
            ];
            $offset += $length;
        }

        return $shares;
    }

    /** @return array{card: array<string, mixed>, steps: list<array{label: string, state: string}>}|null */
    public function nextPayout(): ?array
    {
        $board = $this->board();

        return app(InvestorPortalCards::class)->nextPayout($board['groups']->flatMap(fn (array $section) => $section['cards'])->concat($board['legacy'])->values());
    }

    public function projectCalendar(): SupportCollection
    {
        return app(InvestorPortalCards::class)->calendar($this->account());
    }

    public function recentStatement(): SupportCollection
    {
        return $this->portal()->statement($this->account())->take(5);
    }
}
