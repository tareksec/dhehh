<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global "web" middleware enforcing 13_RESELLER_PORTAL_PLAN.md §6: "nothing
 * from the admin panel is reachable on the portal host, and the portal is not
 * reachable on the admin or shop hosts."
 *
 * The reseller Filament panel lives at the "/portal" path prefix on every
 * host (see ResellerPanelProvider) -- Filament panels aren't domain-scoped
 * in this app because each company's domain is arbitrary, not a subdomain of
 * one shared root, so a single Route::domain() pattern can't express "any
 * company's reseller.<domain> or custom portal domain". This middleware does
 * that matching itself, using the exact same rule as
 * Company::forResellerPortalHost() (also used by
 * ResolveCompanyFromResellerPortalDomain, so the two middleware always agree
 * on which host is a portal host).
 */
class RestrictResellerPortalHost
{
    protected const PANEL_PATH = 'portal';

    /**
     * Always allowed regardless of host: framework/infra routes no page can
     * function without.
     */
    protected const PASSTHROUGH_PREFIXES = ['livewire', 'internal', 'up', 'health', 'build', 'storage', 'vendor', 'broadcasting'];

    public function handle(Request $request, Closure $next): Response
    {
        $path = ltrim($request->path(), '/');

        if ($this->isPassthrough($path)) {
            return $next($request);
        }

        $isPortalPath = $path === self::PANEL_PATH || str_starts_with($path, self::PANEL_PATH.'/');
        $portalCompany = Company::forResellerPortalHost($request->getHost());

        if ($portalCompany && ! $isPortalPath) {
            return redirect()->to('/'.self::PANEL_PATH);
        }

        if (! $portalCompany && $isPortalPath) {
            abort(404);
        }

        return $next($request);
    }

    protected function isPassthrough(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        foreach (self::PASSTHROUGH_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
