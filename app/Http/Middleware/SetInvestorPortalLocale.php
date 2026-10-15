<?php

namespace App\Http\Middleware;

use App\Models\InvestorPortalAccount;
use Carbon\Carbon;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The investor portal's language (English / বাংলা switch in the sidebar and
 * on the sign-in page). The choice made in this browser wins, then the
 * signed-in person's saved language, then the app default. Nothing changes
 * for anyone who has not used the switch: accounts start with no language.
 */
class SetInvestorPortalLocale
{
    public const SESSION_KEY = 'investor_portal_locale';

    public const LOCALES = ['en', 'bn'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->chosen($request);

        if ($locale !== null) {
            app()->setLocale($locale);
            Carbon::setLocale($locale);
        }

        return $next($request);
    }

    private function chosen(Request $request): ?string
    {
        $fromSession = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (in_array($fromSession, self::LOCALES, true)) {
            return $fromSession;
        }

        $account = Filament::auth()->user();

        if ($account instanceof InvestorPortalAccount && in_array($account->locale, self::LOCALES, true)) {
            return $account->locale;
        }

        return null;
    }
}
