<?php

namespace App\Http\Middleware;

use App\Services\StorefrontCustomerSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Account endpoints of the headless storefront: the request must carry a live
 * customer token ("X-Customer-Token") for the company the API key belongs to.
 * Runs after ResolveCompanyFromApiKey, so CompanyContext is already set.
 */
class AuthenticateStorefrontCustomer
{
    public function __construct(protected StorefrontCustomerSession $session) {}

    public function handle(Request $request, Closure $next): Response
    {
        $resolved = $this->session->resolve($request);

        if (! $resolved) {
            return response()->json([
                'ok' => false,
                'error' => 'customer_unauthenticated',
                'hint' => 'The customer is not signed in, or the login has expired.',
            ], 401);
        }

        $request->attributes->set('storefront_customer', $resolved['customer']);
        $request->attributes->set('storefront_customer_token', $resolved['token']);

        return $next($request);
    }
}
