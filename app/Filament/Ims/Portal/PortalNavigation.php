<?php

namespace App\Filament\Ims\Portal;

use App\Filament\Ims\Portal\Pages\Feed;
use App\Filament\Ims\Portal\Pages\Kyc;
use App\Filament\Ims\Portal\Pages\Nominees;
use App\Filament\Ims\Portal\Pages\Notifications;
use App\Filament\Ims\Portal\Pages\Projects;
use App\Filament\Ims\Portal\Pages\Support;
use App\Filament\Investor\Pages\Agreements;
use App\Filament\Investor\Pages\BankAccounts;
use App\Filament\Investor\Pages\Commissions;
use App\Filament\Investor\Pages\Dashboard;
use App\Filament\Investor\Pages\Documents;
use App\Filament\Investor\Pages\Invite;
use App\Filament\Investor\Pages\MyBusinesses;
use App\Filament\Investor\Pages\MyInvestments;
use App\Filament\Investor\Pages\MyInvestors;
use App\Filament\Investor\Pages\Payouts;
use App\Filament\Investor\Pages\Profile;
use App\Filament\Investor\Pages\Requests;
use App\Filament\Investor\Pages\Statement;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

/**
 * The investor portal menu (IMS-V2/DESIGN.md §6.1, §8.1): on wide screens the
 * main pages as pills in the top bar with everything else under "More",
 * plus an icon rail on the left; on phones the bottom tab bar
 * (filament.investor.mobile-nav) and the Profile page's list. A page shows
 * only to the people allowed to open it (InvestorPortalPage::canAccess()).
 */
class PortalNavigation
{
    /** @return list<array{group: string|null, items: list<array{0: class-string, 1: string|null, 2: string}>}> */
    public static function layout(): array
    {
        return [
            ['group' => null, 'items' => [
                [Dashboard::class, 'Home', 'heroicon-o-home'],
                // A project is what the person puts money in, company by company (owner request 2026-10-21): right after Home.
                [Projects::class, null, 'heroicon-o-rectangle-stack'],
                [MyInvestments::class, 'Investments', 'heroicon-o-briefcase'],
                [Payouts::class, 'Profit', 'heroicon-o-banknotes'],
                [Statement::class, null, 'heroicon-o-document-text'],
                [Feed::class, null, 'heroicon-o-newspaper'],
            ]],
            ['group' => 'More', 'icon' => 'heroicon-o-squares-plus', 'items' => [
                [MyBusinesses::class, null, 'heroicon-o-building-office-2'],
                [Notifications::class, null, 'heroicon-o-bell'],
                [Requests::class, null, 'heroicon-o-inbox-arrow-down'],
                [Support::class, null, 'heroicon-o-chat-bubble-left-right'],
                [Documents::class, null, 'heroicon-o-folder'],
                [Agreements::class, null, 'heroicon-o-document-check'],
                [BankAccounts::class, null, 'heroicon-o-building-library'],
                [Kyc::class, null, 'heroicon-o-identification'],
                [Nominees::class, null, 'heroicon-o-users'],
                [Profile::class, null, 'heroicon-o-user-circle'],
            ]],
            ['group' => 'Channel partner', 'icon' => 'heroicon-o-user-plus', 'items' => [
                [MyInvestors::class, null, 'heroicon-o-user-group'],
                [Commissions::class, null, 'heroicon-o-currency-bangladeshi'],
                [Invite::class, null, 'heroicon-o-share'],
            ]],
        ];
    }

    public static function build(NavigationBuilder $builder): NavigationBuilder
    {
        $groups = [];

        foreach (static::layout() as $section) {
            $items = [];

            foreach ($section['items'] as [$page, $label, $icon]) {
                if ($item = static::item($page, $label, $icon)) {
                    $items[] = $item;
                }
            }

            if ($items === []) {
                continue;
            }

            if ($section['group'] === null) {
                $builder->items($items);

                continue;
            }

            $groups[] = NavigationGroup::make(__($section['group']))->items($items);
        }

        return $builder->groups($groups);
    }

    /**
     * The icon rail: the main pages, then talking to the company, then the
     * profile at the bottom next to "Sign out".
     *
     * @return array{groups: list<list<array{label: string, icon: string, url: string, active: bool}>>, bottom: list<array{label: string, icon: string, url: string, active: bool}>}
     */
    public static function rail(): array
    {
        $pick = function (array $pages): array {
            $items = [];

            foreach ($pages as $page) {
                foreach (static::layout() as $section) {
                    foreach ($section['items'] as [$class, $label, $icon]) {
                        if ($class === $page && ($item = static::item($class, $label, $icon))) {
                            $items[] = ['label' => $item->getLabel(), 'icon' => $icon, 'url' => $item->getUrl(), 'active' => $item->isActive()];
                        }
                    }
                }
            }

            return $items;
        };

        return [
            'groups' => array_values(array_filter([
                $pick([Dashboard::class, Projects::class, MyInvestments::class, Payouts::class, Statement::class]),
                $pick([Feed::class, Support::class, Notifications::class]),
                $pick([MyInvestors::class, Commissions::class]),
            ])),
            'bottom' => $pick([Profile::class]),
        ];
    }

    /** @param  class-string  $page */
    protected static function item(string $page, ?string $label, string $icon): ?NavigationItem
    {
        if (! class_exists($page) || ! $page::canAccess()) {
            return null;
        }

        return NavigationItem::make($label !== null ? __($label) : $page::getNavigationLabel())
            ->icon($icon)
            ->url(fn (): string => $page::getUrl())
            ->isActiveWhen(fn (): bool => request()->routeIs($page::getRouteName()));
    }
}
