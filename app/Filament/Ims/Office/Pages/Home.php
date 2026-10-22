<?php

namespace App\Filament\Ims\Office\Pages;

use App\Filament\Ims\Office\Resources\KycReviews\KycReviewResource;
use App\Filament\Ims\Office\Resources\SupportThreads\SupportThreadResource;
use App\Filament\Resources\Investors\InvestorResource;
use App\Filament\Resources\ProjectSettlements\ProjectSettlementResource;
use App\Ims\Todo\OwnerTodo;
use App\Support\ImsNumbers;
use App\Support\ModuleGuard;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

/**
 * The IMS office home (IMS-V2/PRD.md FR-80, DESIGN.md §6.1, §8.2), laid out
 * like the owner's reference dashboard: a "Welcome back" heading with today's
 * date and the main button, then a grid of cards — capital at work and the
 * money that came in, the monthly profit chart, "what to do today", payouts
 * still to send, the latest money in and the groups. Every card is its own
 * guarded widget, so one failing card does not take the page down.
 */
class Home extends Dashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected string $view = 'filament.ims.office.pages.home';

    public static function getNavigationLabel(): string
    {
        return __('Home');
    }

    public function getTitle(): string
    {
        return __('Welcome, :name', ['name' => auth()->user()?->name ?? '']);
    }

    public function getHeader(): ?View
    {
        $addInvestor = InvestorResource::canAccess() && InvestorResource::canCreate();

        $hour = CarbonImmutable::now()->hour;

        return view('filament.ims.welcome-header', [
            'name' => (string) (auth()->user()?->name ?? ''),
            'app' => true,
            'greeting' => match (true) {
                $hour < 12 => __('Good morning!'),
                $hour < 17 => __('Good afternoon!'),
                default => __('Good evening!'),
            },
            'date' => ImsNumbers::date(CarbonImmutable::now()),
            'action' => $addInvestor ? ['label' => __('Add investor'), 'url' => InvestorResource::getUrl('create', panel: 'office'), 'icon' => 'heroicon-m-plus'] : null,
        ]);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('ims.office.access') ?? false;
    }

    public function getWidgets(): array
    {
        return [];
    }

    /**
     * The round buttons under the green card on phones and tablets (DESIGN.md
     * §6.2 QuickActions): the daily jobs, each with how many are waiting.
     *
     * @return list<array{label: string, icon: string, url: string, count: int}>
     */
    public function quickActions(): array
    {
        return ModuleGuard::run('ims-office-quick-actions', function (): array {
            $counts = array_column(app(OwnerTodo::class)->items(), 'count', 'key');
            $actions = [];

            foreach ([
                [PendingMoney::class, 'Confirm money', 'heroicon-o-clock', 'pending_money'],
                [KycReviewResource::class, 'KYC', 'heroicon-o-identification', 'kyc'],
                [ProjectSettlementResource::class, 'Payouts', 'heroicon-o-banknotes', 'payouts'],
                [SupportThreadResource::class, 'Support', 'heroicon-o-chat-bubble-left-right', 'support'],
            ] as [$class, $label, $icon, $key]) {
                if ($class::canAccess()) {
                    $actions[] = ['label' => __($label), 'icon' => $icon, 'url' => $class::getUrl(panel: 'office'), 'count' => (int) ($counts[$key] ?? 0)];
                }
            }

            return $actions;
        }, []);
    }
}
