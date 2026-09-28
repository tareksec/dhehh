<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\CompanyContext;
use App\Services\ResellerPricing;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCompanyFromDomain
{
    public function __construct(protected CompanyContext $context, protected ResellerPricing $resellerPricing) {}

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $host = Company::normalizeDomain($request->getHost());

        $company = $host
            ? Company::query()
                ->where('domain', $host)
                ->where('is_active', true)
                ->first()
            : null;

        if ($company) {
            $this->context->set($company);
            $request->attributes->set('storefront_company', $company);

            // A visitor who arrived through a reseller's store keeps that
            // reseller's pricing/attribution across the whole storefront.
            $this->resellerPricing->clear();
            if ($request->hasSession()) {
                $this->resellerPricing->restoreFromSession($request->session(), $company);
            }

            return $next($request);
        }

        $this->context->clear();

        if ($mode === 'optional') {
            return $next($request);
        }

        abort(404);
    }
}
