<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Which companies a staff member may open in the Trade Portal (/trade,
 * docs/tasneem-samples/PLAN.md §1). The portal is on the main domain, so the
 * open company is the one the person chose (kept in the session), checked
 * against the signed-in user on every request.
 *
 * - The portal needs the "trade.access" permission.
 * - Only companies with the portal switched on (Companies → Trade Portal) count.
 * - A Super Admin opens every such company; everyone else only their own.
 */
class TradePortalCompanies
{
    /** The company chosen in the portal. */
    public const SESSION_KEY = 'trade_portal_company_id';

    /** A portal link's company (?trade_company=<id>). */
    public const QUERY_KEY = 'trade_company';

    /**
     * The companies $user may open in the portal, by name.
     *
     * @return Collection<int, Company>
     */
    public function for(User $user): Collection
    {
        if ($user->is_active === false || ! $user->hasPermission('trade.access') || ! Schema::hasColumn('companies', 'trade_portal_enabled')) {
            return collect();
        }

        $query = $user->isSuperAdmin()
            ? Company::query()->where('is_active', true)->where('trade_portal_enabled', true)->orderBy('name')
            : $user->companies()->where('companies.is_active', true)->where('companies.trade_portal_enabled', true)->orderBy('companies.name');

        return $query->get()->values();
    }

    public function canOpen(User $user, Company $company): bool
    {
        return $this->for($user)->contains(fn (Company $open): bool => (int) $open->getKey() === (int) $company->getKey());
    }

    /**
     * The company to open for $user on this request: a link's company
     * (?trade_company=<id>, then remembered), else the one chosen before, else
     * the person's default company, else the first they may open. Null when
     * they may open none.
     */
    public function choose(Request $request, User $user): ?Company
    {
        $open = $this->for($user);
        $session = $request->hasSession() ? $request->session() : null;
        $find = fn (int $id): ?Company => $id === 0 ? null : $open->first(fn (Company $company): bool => (int) $company->getKey() === $id);

        // Only a page load carries a link's company; Livewire's form posts re-run this with a copy of the page's address.
        $linked = $request->isMethod('GET') ? $find((int) $request->query(self::QUERY_KEY, 0)) : null;

        if ($linked) {
            $session?->put(self::SESSION_KEY, (int) $linked->getKey());

            return $linked;
        }

        $chosen = $find((int) ($session?->get(self::SESSION_KEY, 0) ?? 0))
            ?? $find((int) ($user->defaultCompany()?->getKey() ?? 0))
            ?? $open->first();

        if ($chosen === null) {
            $session?->forget(self::SESSION_KEY);
        }

        return $chosen;
    }

    /**
     * Whether $user may be in the portal now. Once someone is signed in,
     * ResolveTradePortalCompany opens one of their companies or none, so the
     * open company must be one of theirs; during the sign-in itself no company
     * is open yet, and any company they may open is enough.
     */
    public function mayEnter(User $user): bool
    {
        $company = app(CompanyContext::class)->company();

        if ($company !== null) {
            return $this->canOpen($user, $company);
        }

        return $this->for($user)->isNotEmpty();
    }
}
