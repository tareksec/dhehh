<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The storefront API always answers JSON: without an `Accept: application/json`
 * header Laravel would answer a validation error with a redirect and a missing
 * record with an HTML page, neither of which a server-side client can use.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
