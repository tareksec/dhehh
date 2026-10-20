<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every request to the "investor" Filament panel (and its plain
 * routes). Resolves whose investor portal this host is
 * (Company::forInvestorPortalHost()) and puts it in CompanyContext, so
 * CompanyScope keeps every query on that company and
 * InvestorPortalAccount::canAccessPanel() refuses another company's login.
 *
 * On the central sign-in host (17_IMS_IN_PORTAL_PLAN.md §5) no company is
 * known yet: the context is closed (every company-owned query returns
 * nothing) until ResolveCompanyFromCentralInvestorSession, after the session
 * has started, opens the business the person chose.
 */
class ResolveCompanyFromInvestorPortalDomain
{
    public function __construct(protected CompanyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $company = Company::forInvestorPortalHost($request->getHost());

        if (! $company && Company::isImsCentralHost($request->getHost())) {
            $this->context->none();
            $request->attributes->set('investor_portal_central', true);

            return $next($request);
        }

        if (! $company) {
            abort(404);
        }

        $this->context->set($company);
        $request->attributes->set('investor_portal_company', $company);

        return $next($request);
    }
}
