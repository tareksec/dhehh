<?php

namespace App\Filament\Investor\Concerns;

use App\Models\Company;
use App\Models\InvestorPortalAccount;
use App\Services\CompanyContext;
use App\Services\InvestorPortalService;
use Filament\Facades\Filament;

/**
 * Shared by every investor-portal page. A page declares which role may open
 * it through $portalRole ("investor", "partner" or null for both);
 * canAccess() is re-checked by Filament on every request.
 */
trait InvestorPortalPage
{
    public static function canAccess(array $parameters = []): bool
    {
        $account = Filament::auth()->user();

        if (! $account instanceof InvestorPortalAccount) {
            return false;
        }

        return match (static::$portalRole ?? null) {
            'investor' => $account->isInvestor(),
            'partner' => $account->isChannelPartner(),
            default => true,
        };
    }

    public function account(): InvestorPortalAccount
    {
        /** @var InvestorPortalAccount $account */
        $account = Filament::auth()->user();

        return $account;
    }

    public function portal(): InvestorPortalService
    {
        return app(InvestorPortalService::class);
    }

    public function company(): ?Company
    {
        return app(CompanyContext::class)->company();
    }

    public function currency(): string
    {
        return $this->company()?->currency ?: 'BDT';
    }
}
