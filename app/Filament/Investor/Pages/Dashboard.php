<?php

namespace App\Filament\Investor\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Services\InvestorPortalCards;
use App\Support\ImsNumbers;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
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

    /** "Good morning" + "Welcome back, name", today's date (IMS-V2/DESIGN.md §8.1 home). */
    public function getHeader(): ?View
    {
        $hour = (int) CarbonImmutable::now()->format('G');

        return view('filament.ims.welcome-header', [
            'greeting' => match (true) {
                $hour >= 5 && $hour < 12 => __('Good morning'),
                $hour >= 12 && $hour < 17 => __('Good afternoon'),
                default => __('Good evening'),
            },
            'name' => $this->account()->name,
            'date' => ImsNumbers::date(CarbonImmutable::now()),
            'action' => null,
        ]);
    }

    /**
     * The round quick buttons under the balance card (DESIGN.md §7
     * QuickActions): the pages an investor opens most, if allowed.
     *
     * @return list<array{label: string, icon: string, url: string}>
     */
    public function quickActions(): array
    {
        $actions = [];

        foreach ([
            [Requests::class, __('Add money'), 'heroicon-o-plus'],
            [Statement::class, __('Statement'), 'heroicon-o-document-text'],
            [\App\Filament\Ims\Portal\Pages\Projects::class, __('Projects'), 'heroicon-o-rectangle-stack'],
            [\App\Filament\Ims\Portal\Pages\Support::class, __('Support'), 'heroicon-o-chat-bubble-left-right'],
        ] as [$page, $label, $icon]) {
            if ($page::canAccess()) {
                $actions[] = ['label' => $label, 'icon' => $icon, 'url' => $page::getUrl()];
            }
        }

        return $actions;
    }

    /** @return list<array{label: string, long: string, value: float}> */
    public function profitBars(): array
    {
        $bars = [];

        foreach ($this->portal()->monthlyProfit($this->account()) as $month => $amount) {
            $date = CarbonImmutable::createFromFormat('!Y-m', $month)->locale(app()->getLocale());
            $bars[] = [
                'label' => ImsNumbers::digits($date->translatedFormat('M')),
                'long' => ImsNumbers::digits($date->translatedFormat('F Y')),
                'value' => (float) $amount,
            ];
        }

        return $bars;
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
