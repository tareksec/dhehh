<?php

namespace App\Filament\Ims\Office;

use App\Filament\Resources\AgreementTemplates\AgreementTemplateResource;
use App\Filament\Resources\ChannelPartners\ChannelPartnerResource;
use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Filament\Resources\InvestmentProjects\InvestmentProjectResource;
use App\Filament\Resources\InvestmentRecords\InvestmentRecordResource;
use App\Filament\Resources\InvestorDrafts\InvestorDraftResource;
use App\Filament\Resources\InvestorGroups\InvestorGroupResource;
use App\Filament\Resources\InvestorRequests\InvestorRequestResource;
use App\Filament\Resources\Investors\InvestorResource;
use App\Filament\Resources\PayoutBatches\PayoutBatchResource;
use App\Filament\Resources\ProjectSettlements\ProjectSettlementResource;
use App\Ims\Todo\OwnerTodo;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Route;

/**
 * The IMS office menu (IMS-V2/PRD.md FR-81): a flat, grouped list in plain
 * words instead of the ERP's "Investments" module menu. The ERP's investor
 * resources are registered in the office panel as they are; this only lays
 * them out. An entry shows only to staff allowed to open it (ROLES.md §1.4).
 */
class OfficeNavigation
{
    /**
     * @return list<array{group: string|null, items: list<array{0: class-string, 1: string, 2: string}>}>
     */
    public static function layout(): array
    {
        return [
            ['group' => null, 'icon' => 'heroicon-o-home', 'items' => [
                [Pages\Home::class, 'Home', 'heroicon-o-home'],
            ]],
            ['group' => 'People', 'icon' => 'heroicon-o-users', 'items' => [
                [InvestorResource::class, 'Investors', 'heroicon-o-users'],
                [Resources\KycReviews\KycReviewResource::class, 'KYC', 'heroicon-o-identification'],
                [Resources\InvestorInvites\InvestorInviteResource::class, 'Invites', 'heroicon-o-envelope'],
                [InvestorDraftResource::class, 'Investor Drafts', 'heroicon-o-document-plus'],
                [ChannelPartnerResource::class, 'Channel Partners', 'heroicon-o-user-plus'],
            ]],
            ['group' => 'Groups and projects', 'icon' => 'heroicon-o-briefcase', 'items' => [
                [InvestorGroupResource::class, 'Investor Groups', 'heroicon-o-user-group'],
                [InvestmentProjectResource::class, 'Projects', 'heroicon-o-briefcase'],
                [InvestmentRecordResource::class, 'Investments', 'heroicon-o-banknotes'],
                [Pages\PendingMoney::class, 'Money to confirm', 'heroicon-o-clock'],
            ]],
            ['group' => 'Profit and payouts', 'icon' => 'heroicon-o-banknotes', 'items' => [
                [Resources\ProfitProposals\ProfitProposalResource::class, 'Profit proposals', 'heroicon-o-light-bulb'],
                [ProjectSettlementResource::class, 'Settlements', 'heroicon-o-calculator'],
                [PayoutBatchResource::class, 'Payout Batches', 'heroicon-o-queue-list'],
                [Resources\CapitalReturns\CapitalReturnResource::class, 'Capital returns', 'heroicon-o-arrow-uturn-left'],
            ]],
            ['group' => 'Talk to investors', 'icon' => 'heroicon-o-chat-bubble-left-right', 'items' => [
                [InvestorRequestResource::class, 'Investor Requests', 'heroicon-o-inbox-arrow-down'],
                [Resources\SupportThreads\SupportThreadResource::class, 'Support', 'heroicon-o-chat-bubble-left-right'],
                [Resources\FeedPosts\FeedPostResource::class, 'Feed', 'heroicon-o-newspaper'],
                [Resources\ProjectUpdates\ProjectUpdateResource::class, 'Project updates', 'heroicon-o-megaphone'],
                [Resources\ProjectReports\ProjectReportResource::class, 'Project reports', 'heroicon-o-document-chart-bar'],
            ]],
            ['group' => 'Tools', 'icon' => 'heroicon-o-wrench-screwdriver', 'items' => [
                [Resources\BankStatementImports\BankStatementImportResource::class, 'Statement matching', 'heroicon-o-arrows-right-left'],
                [AgreementTemplateResource::class, 'Agreement Templates', 'heroicon-o-document-text'],
                [ExchangeRateResource::class, 'Exchange Rates', 'heroicon-o-currency-dollar'],
                [Pages\ImsSettings::class, 'IMS settings', 'heroicon-o-cog-6-tooth'],
            ]],
        ];
    }

    /**
     * The icon rail on the left of wide screens (IMS-V2/DESIGN.md §6.1): the
     * pages staff open most, in small groups; the settings sit at the bottom
     * next to "Sign out". Every page is also in the top menu.
     *
     * @return array{groups: list<list<array{label: string, icon: string, url: string, active: bool}>>, bottom: list<array{label: string, icon: string, url: string, active: bool}>}
     */
    public static function rail(): array
    {
        $pick = fn (array $classes): array => array_values(array_filter(array_map(function (string $class): ?array {
            foreach (static::layout() as $section) {
                foreach ($section['items'] as [$itemClass, $label, $icon]) {
                    if ($itemClass === $class && ($item = static::item($class, $label, $icon))) {
                        return ['label' => $item->getLabel(), 'icon' => $icon, 'url' => $item->getUrl(), 'active' => $item->isActive()];
                    }
                }
            }

            return null;
        }, $classes)));

        return [
            'groups' => array_values(array_filter([
                $pick([Pages\Home::class, InvestorResource::class, Resources\KycReviews\KycReviewResource::class, Pages\PendingMoney::class]),
                $pick([ProjectSettlementResource::class, PayoutBatchResource::class, Resources\CapitalReturns\CapitalReturnResource::class]),
                $pick([Resources\SupportThreads\SupportThreadResource::class, Resources\FeedPosts\FeedPostResource::class]),
            ])),
            'bottom' => $pick([Pages\ImsSettings::class]),
        ];
    }

    /**
     * The phone menu (IMS-V2/DESIGN.md §6.2, owner request 2026-10-22): a tab
     * bar at the bottom — Home, Investors, a round "+" for adding, "To do"
     * and "Menu". "+" opens the things staff add most, "To do" the owner's
     * list, "Menu" every page as tiles in the same groups as the top menu.
     *
     * @return array{tabs: list<array{label: string, icon: string, url: string, active: bool}>, add: list<array{label: string, icon: string, url: string}>, todo: list<array{key: string, label: string, count: int, url: string|null, tone: string}>, menu: list<array{group: string|null, items: list<array{label: string, icon: string, url: string, active: bool, badge: string|null}>}>}
     */
    public static function mobile(): array
    {
        $menu = [];

        foreach (static::layout() as $section) {
            $items = [];

            foreach ($section['items'] as [$class, $label, $icon]) {
                if ($item = static::item($class, $label, $icon)) {
                    $items[] = ['label' => $item->getLabel(), 'icon' => $icon, 'url' => $item->getUrl(), 'active' => $item->isActive(), 'badge' => $item->getBadge()];
                }
            }

            if ($items !== []) {
                $menu[] = ['group' => $section['group'] === null ? null : __($section['group']), 'items' => $items];
            }
        }

        $tabs = [];

        foreach ([[Pages\Home::class, 'Home', 'heroicon-o-home'], [InvestorResource::class, 'Investors', 'heroicon-o-users']] as [$class, $label, $icon]) {
            if ($item = static::item($class, $label, $icon)) {
                $tabs[] = ['label' => $item->getLabel(), 'icon' => $icon, 'url' => $item->getUrl(), 'active' => $item->isActive()];
            }
        }

        $add = [];

        foreach ([
            [InvestorResource::class, 'Add investor', 'heroicon-o-user-plus'],
            [InvestmentRecordResource::class, 'Record an investment', 'heroicon-o-banknotes'],
            [Resources\InvestorInvites\InvestorInviteResource::class, 'Invite an investor', 'heroicon-o-envelope'],
            [InvestmentProjectResource::class, 'New project', 'heroicon-o-briefcase'],
            [Resources\ProjectUpdates\ProjectUpdateResource::class, 'Post a project update', 'heroicon-o-megaphone'],
            [Resources\FeedPosts\FeedPostResource::class, 'Write a feed post', 'heroicon-o-newspaper'],
        ] as [$class, $label, $icon]) {
            if (class_exists($class) && $class::canAccess() && $class::canCreate() && $class::hasPage('create')) {
                $add[] = ['label' => __($label), 'icon' => $icon, 'url' => $class::getUrl('create', panel: 'office')];
            }
        }

        $todo = array_map(fn (array $item): array => [
            ...$item,
            'url' => $item['page'] !== null && $item['page']::canAccess() ? $item['page']::getUrl(panel: 'office') : null,
        ], app(OwnerTodo::class)->items());

        return ['tabs' => $tabs, 'add' => $add, 'todo' => $todo, 'menu' => $menu];
    }

    public static function build(NavigationBuilder $builder): NavigationBuilder
    {
        $groups = [];

        foreach (static::layout() as $section) {
            $items = [];

            foreach ($section['items'] as [$class, $label, $icon]) {
                if ($item = static::item($class, $label, $icon)) {
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

    /** @param  class-string  $class */
    protected static function item(string $class, string $label, string $icon): ?NavigationItem
    {
        if (! class_exists($class) || ! $class::canAccess()) {
            return null;
        }

        $panel = Filament::getPanel('office');

        if (is_subclass_of($class, Resource::class)) {
            $base = $class::getRouteBaseName($panel);

            if (! Route::has($base.'.index')) {
                return null;
            }

            return NavigationItem::make(__($label))
                ->icon($icon)
                ->url(fn (): string => $class::getUrl(panel: 'office'))
                ->isActiveWhen(fn (): bool => request()->routeIs($base.'.*'))
                ->badge(fn (): ?string => $class::getNavigationBadge());
        }

        if (is_subclass_of($class, Page::class)) {
            $routeName = $class::getRouteName($panel);

            return NavigationItem::make(__($label))
                ->icon($icon)
                ->url(fn (): string => $class::getUrl(panel: 'office'))
                ->isActiveWhen(fn (): bool => request()->routeIs($routeName));
        }

        return null;
    }
}
