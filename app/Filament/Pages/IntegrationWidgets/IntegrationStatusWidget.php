<?php

namespace App\Filament\Pages\IntegrationWidgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Filament\Resources\CourierProviders\CourierProviderResource;
use App\Filament\Resources\MetaAdAccounts\MetaAdAccountResource;
use App\Models\CourierProvider;
use App\Models\MetaAdAccount;
use App\Services\CompanyContext;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Status-card links for the two integrations that are NOT editable inline
 * on the Integrations page's form — Courier Providers and Meta Ads are both
 * full multi-record CRUD areas (many providers/ad accounts each, plus their
 * own dashboards/bookings/campaigns), so cramming them into a settings tab
 * would be a regression. This widget only reads existing data (never
 * duplicates or owns any of it) and links straight to the real resource.
 * Deliberately kept outside app/Filament/Widgets (which is auto-discovered
 * onto the main admin Dashboard) — embedded only into the Integrations
 * page, same reasoning as CourierWidgets\CourierQuickLinksWidget.
 */
class IntegrationStatusWidget extends StatsOverviewWidget
{
    use GuardsWidgetRendering;

    protected function getColumns(): int|array|null
    {
        return ['default' => 1, 'md' => 2];
    }

    protected function getStats(): array
    {
        $context = app(CompanyContext::class);

        if (! $context->hasCompany() || $context->isAllCompanies()) {
            return [];
        }

        return [
            $this->stat(
                label: 'Courier Providers',
                connected: CourierProvider::query()->exists(),
                icon: Heroicon::OutlinedTruck,
                url: CourierProviderResource::getUrl(),
                description: 'Steadfast, Pathao, RedX, e-Courier booking credentials — add as many as needed.',
            ),
            $this->stat(
                label: 'Meta Ads (Ad Manager)',
                connected: MetaAdAccount::query()->exists(),
                icon: Heroicon::OutlinedMegaphone,
                url: MetaAdAccountResource::getUrl(),
                description: 'Connected ad accounts for campaign creation and the AI assistant.',
            ),
        ];
    }

    protected function stat(string $label, bool $connected, Heroicon $icon, string $url, string $description): Stat
    {
        return Stat::make($label, $connected ? 'Connected' : 'Not configured')
            ->description($description)
            ->icon($icon)
            ->color($connected ? 'success' : 'warning')
            ->url($url);
    }
}
