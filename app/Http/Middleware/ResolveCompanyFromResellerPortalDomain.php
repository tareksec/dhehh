<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every request to the "reseller" Filament panel. Resolves which
 * company's reseller portal this host belongs to (see
 * Company::forResellerPortalHost()) and puts it in CompanyContext, the same
 * way ResolveCompanyFromDomain does for the main storefront. Customer::
 * canAccessPanel() reads CompanyContext to keep a reseller logged into one
 * company's portal from ever seeing another company's data.
 */
class ResolveCompanyFromResellerPortalDomain
{
    public function __construct(protected CompanyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $company = Company::forResellerPortalHost($request->getHost());

        if (! $company) {
            abort(404);
        }

        $this->context->set($company);
        $request->attributes->set('reseller_portal_company', $company);

        return $next($request);
    }
}
