<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\Customer;
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

        // "<label>.<company domain>" is a reseller's own store (Phase 7a):
        // resolve the company from the parent domain and scope the whole
        // request to that reseller, exactly like /store/{slug} does.
        $hostReseller = null;

        if (! $company) {
            $custom = Company::forResellerCustomDomainHost($host);

            if ($custom) {
                [$company, $hostReseller] = $custom;
                $this->context->set($company);
            }
        }

        if (! $company) {
            $subdomain = Company::forResellerSubdomainHost($host);

            if ($subdomain) {
                [$company, $label] = $subdomain;
                $this->context->set($company);
                $hostReseller = Customer::query()
                    ->where('company_id', $company->getKey())
                    ->where('reseller_subdomain', $label)
                    ->where('reseller_status', 'approved')
                    ->first();

                abort_unless($hostReseller, 404);
            }
        }

        if ($company) {
            $this->context->set($company);
            $request->attributes->set('storefront_company', $company);

            if ($hostReseller) {
                $request->attributes->set('storefront_reseller', $hostReseller);
                $request->attributes->set('storefront_reseller_host', true);

                // The reseller's store has no company home page; its front
                // page is the product list.
                if ($request->is('/')) {
                    return redirect()->to(route('storefront.products.index', [], false));
                }
            }

            // A visitor who arrived through a reseller's store keeps that
            // reseller's pricing/attribution across the whole storefront.
            $this->resellerPricing->clear();
            if ($request->hasSession()) {
                $this->resellerPricing->restoreFromSession($request->session(), $company);

                if ($hostReseller) {
                    $this->resellerPricing->remember($request->session(), $company, $hostReseller);
                }
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
