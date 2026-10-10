<?php

namespace App\Filament\Investor\Concerns;

use App\Models\Company;
use App\Models\InvestorPortalAccount;
use App\Services\CompanyContext;
use App\Services\InvestorPortalService;
use App\Support\InvestorPortalStaffPreview;
use Filament\Facades\Filament;

/**
 * Shared by every investor-portal page. A page declares which role may open
 * it through $portalRole ("investor", "partner", "holder" for either, or
 * null for everyone signed in, applicants included);
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
            // An approved investor or partner (not an applicant).
            'holder' => $account->isInvestor() || $account->isChannelPartner(),
            default => true,
        };
    }

    public function account(): InvestorPortalAccount
    {
        /** @var InvestorPortalAccount $account */
        $account = Filament::auth()->user();

        return $account;
    }

    /** True while a staff member is looking at the portal as this person (look only). */
    public function isStaffPreview(): bool
    {
        return InvestorPortalStaffPreview::isFor($this->account());
    }

    /**
     * Call first in anything that changes data (a form save, an action, a
     * button): a staff preview is look-only. Hide the button too.
     */
    protected function refuseInStaffPreview(): void
    {
        abort_if($this->isStaffPreview(), 403);
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
