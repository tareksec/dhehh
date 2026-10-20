<?php

namespace App\Services;

use App\Models\ChannelPartner;
use App\Models\Company;
use App\Models\Investor;
use App\Models\InvestorPortalAccount;
use App\Support\InvestorPortalStaffPreview;
use App\Support\LoginClient;
use App\Support\LoginThrottled;
use App\Support\StorefrontApi\Phone;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One login, many businesses (17_IMS_IN_PORTAL_PLAN.md §5, Phase M).
 *
 * A person is the same person across businesses when the same phone number
 * has an active portal account in each. After signing in, the portal's top
 * bar lists those businesses; choosing one makes it the active company.
 * Every page still renders under one company's context at a time, so no
 * query ever mixes companies.
 *
 * - On the central sign-in host (Company::imsCentralHost(), not tied to one
 *   company) the person signs in once; the chosen business is kept in the
 *   session (SESSION_KEY) and switching signs the session in to that
 *   business's own account.
 * - A business's IMS v2 domain works the same way (owner request
 *   2026-10-20): the sign-in there opens any of the person's businesses and
 *   switching stays in the session; with no other business chosen the
 *   domain's own business is shown.
 * - The per-business hosts keep working. There a switch sends the browser
 *   to the other business's own host with a one-time token (60 seconds, kept
 *   in the cache under its hash), because two hosts cannot share a session.
 *
 * Only a person's own sign-in may switch (never a staff preview or an
 * applicant), and only to an account that is active, has a password and is
 * linked to an investor or an active channel partner of a business whose
 * portal is on.
 */
class InvestorBusinessSwitchService
{
    public const VALID_SECONDS = 60;

    /** The business chosen on the central sign-in host. */
    public const SESSION_KEY = 'investor_portal_central_company_id';

    protected const CACHE_PREFIX = 'investor-portal-business-switch:';

    public function __construct(
        protected AuditLogService $audit,
        protected InvestorPortalAccountService $accounts,
        protected LoginGate $loginGate,
    ) {}

    public function onCentralHost(?Request $request = null): bool
    {
        return Company::isImsCentralHost(($request ?? request())->getHost());
    }

    /**
     * The business whose IMS v2 domain this host is (owner request 2026-10-20:
     * investors only know that address, so it signs in to every business too,
     * the same way as the central host, while staying that business's portal).
     */
    public function imsHostCompany(?Request $request = null): ?Company
    {
        return Company::forImsHost(($request ?? request())->getHost());
    }

    /** Whether this host keeps the chosen business in the session: the central host or an IMS domain. */
    public function switchesInSession(?Request $request = null): bool
    {
        return $this->onCentralHost($request) || $this->imsHostCompany($request) !== null;
    }

    /** Whether this phone has an active account with a password in a business other than $company. */
    public function hasAccountsElsewhere(string $phone, Company $company): bool
    {
        return $this->activeAccountsFor($phone)
            ->contains(fn (InvestorPortalAccount $account): bool => (int) $account->company_id !== (int) $company->getKey());
    }

    /**
     * Every business this person can open, the current one included, one
     * entry per company. Empty for a staff preview or an account that is not
     * active.
     *
     * @return Collection<int, array{company: Company, account: InvestorPortalAccount}>
     */
    public function businesses(InvestorPortalAccount $current): Collection
    {
        if (! $this->maySwitch($current)) {
            return collect();
        }

        return $this->activeAccountsFor((string) $current->phone)
            ->map(fn (InvestorPortalAccount $account): ?array => ($company = $this->openableCompany($account))
                ? ['company' => $company, 'account' => $account]
                : null)
            ->filter()
            ->values();
    }

    /**
     * The businesses other than the current one.
     *
     * @return Collection<int, array{company: Company, account: InvestorPortalAccount}>
     */
    public function otherBusinesses(InvestorPortalAccount $current): Collection
    {
        return $this->businesses($current)
            ->reject(fn (array $entry): bool => (int) $entry['company']->getKey() === (int) $current->company_id)
            ->values();
    }

    /**
     * Switches to another business. On the central host the session is signed
     * in to the account there and the portal's own home is returned; on a
     * business's own host the other business's address with a one-time token
     * is returned. Null when that business is not one this person can open.
     */
    public function switchTo(InvestorPortalAccount $current, int $companyId, Request $request): ?string
    {
        $target = $this->otherBusinesses($current)->first(fn (array $entry): bool => (int) $entry['company']->getKey() === $companyId);

        if ($target === null) {
            return null;
        }

        $this->audit->record('investor_portal.business_switch_issued', $current, null, ['to_company_id' => $companyId]);

        if ($this->switchesInSession($request)) {
            $this->openOnCentralHost($target['account'], $target['company'], $request, 'business_switch');
            $this->audit->record('investor_portal.business_switched', $target['account']);

            return Filament::getPanel('investor')->getUrl();
        }

        if ($target['company']->investorPortalHost() === null) {
            return null;
        }

        $token = Str::random(48);

        Cache::put(self::CACHE_PREFIX.hash('sha256', $token), [
            'account_id' => (int) $target['account']->getKey(),
            'company_id' => $companyId,
            'from_account_id' => (int) $current->getKey(),
        ], now()->addSeconds(self::VALID_SECONDS));

        return (string) $target['company']->investorPortalUrl('business-entry/'.$token);
    }

    /**
     * Portal side of a switch between two business hosts: the account to sign
     * in, or null when the token was used, has expired, belongs to another
     * business's portal, or either account may no longer be used. Resolving
     * the token uses it up.
     */
    public function redeem(string $token, Company $company): ?InvestorPortalAccount
    {
        if ($token === '') {
            return null;
        }

        $entry = Cache::pull(self::CACHE_PREFIX.hash('sha256', $token));

        if (! is_array($entry) || (int) ($entry['company_id'] ?? 0) !== (int) $company->getKey()) {
            return null;
        }

        $from = InvestorPortalAccount::query()->withoutGlobalScopes()->find($entry['from_account_id'] ?? 0);
        $account = InvestorPortalAccount::query()->withoutGlobalScopes()->find($entry['account_id'] ?? 0);

        if (
            ! $from
            || ! $account
            || (int) $account->company_id !== (int) $company->getKey()
            || $from->status !== InvestorPortalAccount::STATUS_ACTIVE
            || $from->phone !== $account->phone
            || ! $this->isUsable($account)
            || $this->openableCompany($account) === null
        ) {
            return null;
        }

        return $account;
    }

    /**
     * Central host sign-in: phone + password. The password of any of the
     * person's active accounts opens the first business it belongs to (the
     * same phone across businesses is one person, §5 Q2). On an IMS domain the
     * account of that domain's business ($prefer) is tried first.
     *
     * @return array{account: InvestorPortalAccount, company: Company}|null
     *
     * @throws LoginThrottled when LoginGate stops the attempt before the password is checked
     */
    public function attemptCentralLogin(string $phone, string $password, ?Company $prefer = null): ?array
    {
        $candidates = $this->activeAccountsFor($phone);

        if ($prefer !== null) {
            $candidates = $candidates
                ->sortBy(fn (InvestorPortalAccount $account): int => (int) $account->company_id === (int) $prefer->getKey() ? 0 : 1)
                ->values();
        }
        $client = LoginClient::fromRequest(request(), LoginGate::SURFACE_INVESTOR);
        $attempt = $this->loginGate->begin(LoginGate::SURFACE_INVESTOR, null, Phone::normalize($phone), $candidates->first(), $client);

        if ($attempt->isDenied()) {
            $this->loginGate->failed($attempt);

            return null;
        }

        if ($attempt->isBlocked()) {
            throw LoginThrottled::for($attempt, 'data.phone');
        }

        if ($candidates->isEmpty()) {
            // Costs the same time as a wrong password, so timing can't show who invests.
            $this->loginGate->spendHashTime($password);
        }

        foreach ($candidates as $account) {
            if (Hash::check($password, (string) $account->password) && ($company = $this->openableCompany($account))) {
                $this->loginGate->succeeded($attempt);

                return ['account' => $account, 'company' => $company];
            }
        }

        $this->loginGate->failed($attempt);
        $this->audit->record('investor_portal.login_failed', $candidates->first() ?? InvestorPortalAccount::class, null, ['method' => 'password', 'central' => true]);

        return null;
    }

    /** Signs the session on the central host in to this business's account. */
    public function openOnCentralHost(InvestorPortalAccount $account, Company $company, Request $request, string $method, bool $remember = false): void
    {
        app(CompanyContext::class)->set($company);
        session()->put(self::SESSION_KEY, (int) $company->getKey());

        $panel = Filament::getPanel('investor');
        Auth::shouldUse($panel->getAuthGuard());
        $panel->auth()->login($account, $remember);
        session()->regenerate();

        $this->accounts->recordLogin($account, $request->ip(), $method);
    }

    /** The business chosen in this central-host session, while it can still be opened. */
    public function centralCompany(Request $request): ?Company
    {
        if (! $request->hasSession()) {
            return null;
        }

        $id = (int) $request->session()->get(self::SESSION_KEY, 0);

        if ($id === 0) {
            return null;
        }

        $company = Company::query()->whereKey($id)->where('is_active', true)->first();

        return $company && $company->investorPortalIsOn() ? $company : null;
    }

    /** A person's own active sign-in; never a staff preview or an applicant. */
    public function maySwitch(InvestorPortalAccount $account): bool
    {
        return $account->isActive()
            && filled($account->phone)
            && ! InvestorPortalStaffPreview::isFor($account);
    }

    /** @return Collection<int, InvestorPortalAccount> */
    protected function activeAccountsFor(string $phone): Collection
    {
        $phone = Phone::normalize($phone);

        if ($phone === '') {
            return collect();
        }

        return InvestorPortalAccount::query()
            ->withoutGlobalScopes()
            ->where('phone', $phone)
            ->where('status', InvestorPortalAccount::STATUS_ACTIVE)
            ->whereNotNull('password')
            ->orderBy('company_id')
            ->get();
    }

    protected function isUsable(InvestorPortalAccount $account): bool
    {
        return $account->status === InvestorPortalAccount::STATUS_ACTIVE && filled($account->password);
    }

    /** The account's company while its portal can be opened and the account has someone to show. */
    protected function openableCompany(InvestorPortalAccount $account): ?Company
    {
        $company = Company::query()->whereKey($account->company_id)->where('is_active', true)->first();

        if (! $company || ! $company->investorPortalIsOn()) {
            return null;
        }

        // Read past the company scope: the current host's company is not this one.
        $hasInvestor = $account->investor_id !== null
            && Investor::query()->withoutGlobalScopes()->whereKey($account->investor_id)->where('company_id', $company->getKey())->exists();
        $hasPartner = $account->channel_partner_id !== null
            && ChannelPartner::query()->withoutGlobalScopes()->whereKey($account->channel_partner_id)->where('company_id', $company->getKey())->where('is_active', true)->exists();

        return $hasInvestor || $hasPartner ? $company : null;
    }
}
