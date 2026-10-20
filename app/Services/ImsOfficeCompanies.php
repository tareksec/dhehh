<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Which companies a staff member may open in the IMS office
 * (18_IMS_ALL_COMPANIES_PLAN.md §3.1–3.2). Every company runs on the one IMS
 * domain, so the office's company is the one the person chose (kept in the
 * session), checked against the signed-in user on every request.
 *
 * - The office needs the "ims.office.access" permission, as before.
 * - ERP Super Admins and office admins (the "ims.all_companies" permission,
 *   given in Settings → Roles) open every active company.
 * - Everyone else opens only the companies they work for.
 */
class ImsOfficeCompanies
{
    /** The company chosen in the office. */
    public const SESSION_KEY = 'ims_office_company_id';

    /** An office link's company (Company::imsOfficeUrl()). */
    public const QUERY_KEY = 'office_company';

    public function seesEveryCompany(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasPermission('ims.all_companies');
    }

    /**
     * The companies $user may open in the office on $host (null = any IMS host), by name.
     *
     * @return Collection<int, Company>
     */
    public function for(User $user, ?string $host = null): Collection
    {
        if ($user->is_active === false || ! $user->hasPermission('ims.office.access')) {
            return collect();
        }

        $companies = $this->seesEveryCompany($user)
            ? Company::query()->where('is_active', true)->orderBy('name')->get()
            : $user->companies()->where('companies.is_active', true)->orderBy('companies.name')->get();

        return $companies
            ->filter(fn (Company $company): bool => $host === null ? $company->imsIsLive() : $company->imsHost() === $host)
            ->values();
    }

    public function canOpen(User $user, Company $company, ?string $host = null): bool
    {
        return $this->for($user, $host)->contains(fn (Company $open): bool => (int) $open->getKey() === (int) $company->getKey());
    }

    /**
     * The company to open for $user on this request: an office link's company
     * (?office_company=<id>, then remembered), else the one chosen before, else
     * the IMS domain's own company, else the first the person may open. Null
     * when the person may open none.
     */
    public function choose(Request $request, User $user, Company $hostCompany): ?Company
    {
        $open = $this->for($user, $hostCompany->ims_domain);
        $session = $request->hasSession() ? $request->session() : null;
        $find = fn (int $id): ?Company => $id === 0 ? null : $open->first(fn (Company $company): bool => (int) $company->getKey() === $id);

        // Only a page load carries a link's company; Livewire's form posts re-run this with a copy of the page's address.
        $linked = request()->isMethod('GET') ? $find((int) $request->query(self::QUERY_KEY, 0)) : null;

        if ($linked) {
            $session?->put(self::SESSION_KEY, (int) $linked->getKey());

            return $linked;
        }

        $chosen = $find((int) ($session?->get(self::SESSION_KEY, 0) ?? 0))
            ?? $find((int) $hostCompany->getKey())
            ?? $open->first();

        if ($chosen === null) {
            $session?->forget(self::SESSION_KEY);
        }

        return $chosen;
    }

    /**
     * Whether $user may be in the office now: the open company is one they may
     * open. Once someone is signed in, ResolveImsOfficeCompany opens one of
     * their companies or none, so another company is only ever open during
     * the sign-in itself (the domain's company, nobody signed in yet): then
     * any company they may open is enough, and the next request opens it.
     */
    public function mayEnter(User $user): bool
    {
        $company = app(CompanyContext::class)->company();

        if ($company === null) {
            return false;
        }

        $host = Company::forImsHost(request()->getHost())?->ims_domain;

        return $this->canOpen($user, $company, $host) || $this->for($user, $host)->isNotEmpty();
    }
}
