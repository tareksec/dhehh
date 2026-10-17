<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for every response (storefront and admin alike).
 *
 * The Content-Security-Policy here deliberately has no script-src/style-src/
 * connect-src — see 09_DASHBOARD_WOOCOMMERCE_SECURITY_PLAN.md step 3.4: a
 * script policy can break the whole site (inline scripts, CDN-loaded
 * Alpine/Chart.js/pixel scripts) and needs its own carefully-tested pass.
 * It only sets directives that can't block a script: no framing by other
 * sites, no <base> hijacking, no plugins. A response that already set its own
 * policy (e.g. ConversationMediaController's sandbox) keeps it.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
        }

        // Only on HTTPS, and without includeSubDomains: company and reseller
        // custom domains/subdomains are not all guaranteed to serve HTTPS.
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
