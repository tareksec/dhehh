<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Trade Portal's language: Bangla first (docs/tasneem-samples/PLAN.md §1),
 * English one tap away. The choice is kept for this browser's portal session.
 */
class SetTradePortalLocale
{
    public const SESSION_KEY = 'trade_portal_locale';

    public const LOCALES = ['bn', 'en'];

    public const DEFAULT_LOCALE = 'bn';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
        $locale = in_array($locale, self::LOCALES, true) ? $locale : self::DEFAULT_LOCALE;

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
