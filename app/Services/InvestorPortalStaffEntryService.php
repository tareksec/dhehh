<?php

namespace App\Services;

use App\Models\ChannelPartner;
use App\Models\Company;
use App\Models\Investor;
use App\Models\InvestorPortalAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One-click "open the investor portal as this person" for staff with
 * investments.portal_manage (see App\Support\InvestorPortalStaffPreview).
 *
 * The dashboard and the portal are on different hosts, so they cannot share a
 * session. The dashboard issues a one-time token (60 seconds, kept in the
 * cache under its hash) and sends the browser to the portal host with it; the
 * portal host trades it for a staff-preview session. The token works once,
 * only on the portal of the company it was issued for, and only while the
 * staff member can still manage portal access.
 */
class InvestorPortalStaffEntryService
{
    public const VALID_SECONDS = 60;

    protected const CACHE_PREFIX = 'investor-portal-staff-entry:';

    public function __construct(
        protected InvestorPortalAccountService $accounts,
        protected AuditLogService $audit,
    ) {}

    /**
     * Dashboard side: makes sure the person has a portal account (no SMS, no
     * invite) and returns the portal address that signs the staff member in as
     * them. Throws a ValidationException when the portal is off, the person
     * has no phone, their phone belongs to someone else, or access is disabled.
     */
    public function issue(Investor|ChannelPartner $holder, User $staff): string
    {
        abort_unless($this->mayOpen($staff), 403);

        $company = Company::query()->findOrFail($holder->company_id);
        $account = $this->accounts->ensureAccount($holder);
        $token = Str::random(48);

        Cache::put(self::CACHE_PREFIX.hash('sha256', $token), [
            'account_id' => (int) $account->getKey(),
            'company_id' => (int) $company->getKey(),
            'staff_user_id' => (int) $staff->getKey(),
            'staff_name' => (string) $staff->name,
        ], now()->addSeconds(self::VALID_SECONDS));

        $this->audit->record('investor_portal.staff_entry_issued', $account);

        return (string) $company->investorLinkUrl('staff-entry/'.$token);
    }

    /**
     * Portal side: trades the token for the account and the staff member, or
     * null when it was already used, has expired, belongs to another company's
     * portal, or the account or the staff member may no longer be used.
     * Resolving the token uses it up, whatever the outcome.
     *
     * @return array{account: InvestorPortalAccount, staff_user_id: int, staff_name: string}|null
     */
    public function redeem(string $token, Company $company): ?array
    {
        if ($token === '') {
            return null;
        }

        $entry = Cache::pull(self::CACHE_PREFIX.hash('sha256', $token));

        if (! is_array($entry) || (int) ($entry['company_id'] ?? 0) !== (int) $company->getKey() || ! $company->investorPortalIsOn()) {
            return null;
        }

        $staff = User::query()->find($entry['staff_user_id'] ?? 0);
        $account = InvestorPortalAccount::query()->find($entry['account_id'] ?? 0);

        if (
            ! $staff
            || ! $this->mayOpen($staff)
            || ! $account
            || $account->status === InvestorPortalAccount::STATUS_DISABLED
            || ! ($account->isInvestor() || $account->isChannelPartner())
        ) {
            return null;
        }

        return ['account' => $account, 'staff_user_id' => (int) $staff->getKey(), 'staff_name' => (string) $staff->name];
    }

    public function mayOpen(User $staff): bool
    {
        return (bool) $staff->is_active && $staff->hasPermission('investments.portal_manage');
    }
}
