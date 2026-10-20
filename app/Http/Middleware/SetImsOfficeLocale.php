<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The IMS office's language (IMS-V2/DESIGN.md §9: English / বাংলা in one
 * click). The choice is kept for this browser's office session.
 */
class SetImsOfficeLocale
{
    public const SESSION_KEY = 'ims_office_locale';

    public const LOCALES = ['en', 'bn'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (in_array($locale, self::LOCALES, true)) {
            app()->setLocale($locale);
            Carbon::setLocale($locale);
        }

        return $next($request);
    }
}
