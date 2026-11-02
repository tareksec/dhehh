<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Next.js site is Bangla + English. It sends the visitor's language in the
 * `X-Locale` header (or `?lang=`), and every message the ERP writes for that
 * request (checkout and account errors, order status words, tracking updates)
 * comes back in the same language instead of always English. Anything else
 * keeps the application locale.
 */
class SetStorefrontApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = strtolower((string) ($request->header('X-Locale') ?: $request->query('lang')));

        if (in_array($locale, ['bn', 'en'], true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
