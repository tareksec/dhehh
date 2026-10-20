<?php

namespace App\Filament\Clusters;

use App\Filament\Pages\InvestorPortalSettings;
use App\Services\CompanyContext;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

class Investments extends NavigationCluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?string $navigationLabel = 'Investments';

    protected static ?int $navigationSort = 9;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('investments.view') ?? false;
    }

    /**
     * IMS v2 (IMS-V2/PLAN.md Q2): a company that runs IMS on its own domain
     * works with investors in the IMS office, so the ERP menu shows one link
     * there instead of the investor pages.
     *
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        $context = app(CompanyContext::class);
        $company = $context->isAllCompanies() ? null : $context->company();

        if (Filament::getCurrentPanel()?->getId() === 'admin' && $company?->imsIsLive()) {
            return [
                NavigationItem::make(__('Investments (IMS office)'))
                    ->icon(static::$navigationIcon)
                    ->sort(static::$navigationSort)
                    ->url((string) $company->imsUrl('office'), shouldOpenInNewTab: true)
                    ->visible(static::canAccess()),
                NavigationItem::make(__('IMS domain settings'))
                    ->icon('heroicon-o-globe-alt')
                    ->sort(static::$navigationSort)
                    ->url(fn (): string => InvestorPortalSettings::getUrl())
                    ->visible(InvestorPortalSettings::canAccess()),
            ];
        }

        return parent::getNavigationItems();
    }
}
