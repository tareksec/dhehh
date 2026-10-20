<?php

namespace App\Filament\Actions;

use App\Models\ChannelPartner;
use App\Models\Company;
use App\Models\Investor;
use App\Support\ImsPanelLinks;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * "Open investor portal" in the header of an investor's or channel partner's
 * page: one click opens the portal in a new tab, signed in as that person
 * (look only; see InvestorPortalStaffEntryService). The button is a plain
 * link to the dashboard route, so the browser opens the tab itself (no
 * pop-up blocker). Needs investments.portal_manage and the portal switched on.
 */
class InvestorPortalOpenAction
{
    public static function make(): Action
    {
        return Action::make('portalOpen')
            ->label(__('Open investor portal'))
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->tooltip(__('Opens the investor portal in a new tab, signed in as this person. You can look at everything but change nothing.'))
            ->url(fn (Investor|ChannelPartner $record): string => ImsPanelLinks::route($record instanceof Investor ? 'investor-portal.open-investor' : 'investor-portal.open-partner', [$record->getKey()]))
            ->openUrlInNewTab()
            ->visible(fn (Investor|ChannelPartner $record): bool => (auth()->user()?->hasPermission('investments.portal_manage') ?? false)
                && Company::query()->find($record->company_id)?->investorPortalHost() !== null);
    }
}
