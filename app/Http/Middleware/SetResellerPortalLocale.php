<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Bangla / English switch of the reseller portal's public pages (owner
 * request 2026-10-22): the landing page, the sign-in page and the application
 * page. A visitor picks a language with "?lang=bn" or "?lang=en"; the choice is
 * kept in a cookie for a year, so the pages that follow (and the portal's own
 * Livewire requests) stay in that language. Nothing changes for anyone who
 * never used the switch: they get the app's default language, as before.
 * Only reseller portal hosts are touched, never the shop or the admin.
 */
class SetResellerPortalLocale
{
    public const COOKIE = 'reseller_portal_locale';

    public const LOCALES = ['en', 'bn'];

    public function handle(Request $request, Closure $next): Response
    {
        // RestrictPortalHosts (or the portal's own company middleware) has worked out whose portal this host is.
        if (! $request->attributes->get('reseller_portal_company') instanceof Company) {
            return $next($request);
        }

        $asked = $request->query('lang');

        if (is_string($asked) && in_array($asked, self::LOCALES, true)) {
            $locale = $asked;
            Cookie::queue(Cookie::make(self::COOKIE, $locale, 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax'));
        } else {
            $stored = $request->cookie(self::COOKIE);
            $locale = is_string($stored) && in_array($stored, self::LOCALES, true) ? $stored : null;
        }

        if ($locale !== null) {
            app()->setLocale($locale);
            Carbon::setLocale($locale);
        }

        return $next($request);
    }
}
