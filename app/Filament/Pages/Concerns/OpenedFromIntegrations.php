<?php

namespace App\Filament\Pages\Concerns;

use App\Filament\Pages\Integrations;

/**
 * For a provider/key configuration page that lives in the Settings cluster
 * but is hidden from the sidebar: it is opened from a link card on the
 * Integrations page, so its breadcrumb leads back there instead of to the
 * cluster root.
 */
trait OpenedFromIntegrations
{
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function getBreadcrumbs(): array
    {
        return [
            Integrations::getUrl() => 'Integrations',
            $this->getTitle(),
        ];
    }
}
