<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\Customer;
use App\Services\ResellerPricing;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after ResolveCompanyFromDomain on the /store/{resellerSlug}/... route
 * group -- a reseller's store always lives under their own company's
 * existing domain, so the company must already be resolved first. Sets
 * `storefront_reseller` on the request for the shared storefront
 * controllers (ProductIndexController, ProductShowController, CartController,
 * CheckoutController) to additively scope against, without duplicating them.
 */
class ResolveResellerFromSlug
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->attributes->get('storefront_company');

        abort_unless($company instanceof Company, 404);
        abort_unless($company->reseller_module_enabled, 404);

        $slug = $request->route('resellerSlug');

        $reseller = Customer::query()
            ->where('company_id', $company->getKey())
            ->where('reseller_slug', $slug)
            ->where('reseller_status', 'approved')
            ->first();

        abort_unless($reseller, 404);

        $request->attributes->set('storefront_reseller', $reseller);
        app(ResellerPricing::class)->remember($request->session(), $company, $reseller);

        return $next($request);
    }
}
