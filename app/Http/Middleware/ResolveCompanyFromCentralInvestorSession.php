<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\InvestorPortalAccount;
use App\Services\CompanyContext;
use App\Services\InvestorBusinessSwitchService;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The central investor sign-in host (17_IMS_IN_PORTAL_PLAN.md §5): after the
 * session has started, opens the business the signed-in person last chose
 * (InvestorBusinessSwitchService::SESSION_KEY), so every page still renders
 * under one company's context. With no business chosen the context stays
 * closed, so the guard finds no account and the person is asked to sign in.
 *
 * Pages that belong to one business only (sign-up with a partner's link,
 * invites, password reset, staff links) are sent to the sign-in page here:
 * they are used on that business's own portal address.
 *
 * A business's IMS v2 domain (owner request 2026-10-20) also opens the chosen
 * business, but only while the account signed in to this session belongs to
 * it; otherwise, and always on the business-only pages, the domain's own
 * business stays open.
 */
class ResolveCompanyFromCentralInvestorSession
{
    protected const BUSINESS_ONLY_PATHS = '#^investor/(register|password-reset|invite/|join/|staff-entry/|business-entry/)#';

    public function __construct(protected CompanyContext $context, protected InvestorBusinessSwitchService $switches) {}

    public function handle(Request $request, Closure $next): Response
    {
        $businessOnly = preg_match(self::BUSINESS_ONLY_PATHS, ltrim($request->path(), '/')) === 1;

        if (! Company::isImsCentralHost($request->getHost())) {
            if (! $businessOnly && ($hostCompany = $this->switches->imsHostCompany($request))) {
                $chosen = $this->switches->centralCompany($request);

                if ($chosen && (int) $chosen->getKey() !== (int) $hostCompany->getKey() && $this->signedInTo($request, $chosen)) {
                    $this->context->set($chosen);
                }
            }

            return $next($request);
        }

        if ($businessOnly) {
            return redirect()->to('/investor/login');
        }

        $company = $this->switches->centralCompany($request);
        $company ? $this->context->set($company) : $this->context->none();

        return $next($request);
    }

    /** Whether the investor account signed in to this session belongs to $company. */
    protected function signedInTo(Request $request, Company $company): bool
    {
        $guard = Auth::guard('investor');
        $id = $guard instanceof SessionGuard ? $request->session()->get($guard->getName()) : null;

        return $id !== null && InvestorPortalAccount::query()->withoutGlobalScopes()
            ->whereKey($id)
            ->where('company_id', $company->getKey())
            ->exists();
    }
}
