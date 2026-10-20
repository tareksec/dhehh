<?php

namespace App\Filament\Ims\Office\Pages;

use App\Filament\Resources\Investors\InvestorResource;
use App\Support\ImsNumbers;
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

        return view('filament.ims.welcome-header', [
            'name' => (string) (auth()->user()?->name ?? ''),
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
}
