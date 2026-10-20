<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global "web" middleware that keeps each portal on its own host
 * (13_RESELLER_PORTAL_PLAN.md §6, 15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md
 * §4.2): on a portal host only that portal's path is served (anything else
 * redirects to it), and a portal's path is a 404 on every other host.
 *
 * The portal Filament panels live at a path prefix on every host (see
 * ResellerPanelProvider / InvestorPanelProvider) -- they aren't
 * domain-scoped because each company's domain is arbitrary, so a single
 * Route::domain() pattern can't express "any company's reseller.<domain> or
 * custom portal domain". This middleware does that matching itself, using
 * the exact same Company::for*PortalHost() rule the company-resolving
 * middleware of each panel uses, so the two always agree on which host is a
 * portal host.
 */
class RestrictPortalHosts
{
    /**
     * Each portal: its panel path => the Company resolver for its host.
     *
     * @var array<string, string>
     */
    protected const PORTALS = [
        'portal' => 'forResellerPortalHost',
        'investor' => 'forInvestorPortalHost',
    ];

    /**
     * The panel paths an IMS v2 domain serves: the investor portal and the
     * IMS office. Anything else on that domain goes to the portal.
     *
     * @var list<string>
     */
    public const IMS_PATHS = ['investor', 'office'];

    /**
     * Always allowed regardless of host: framework/infra routes no page can
     * function without.
     */
    protected const PASSTHROUGH_PREFIXES = ['internal', 'up', 'health', 'build', 'storage', 'vendor', 'broadcasting'];

    public function handle(Request $request, Closure $next): Response
    {
        $path = ltrim($request->path(), '/');

        if ($this->isPassthrough($path)) {
            return $next($request);
        }

        // The central investor sign-in for every business (17_IMS_IN_PORTAL_PLAN.md §5): the investor portal only.
        if (Company::isImsCentralHost($request->getHost())) {
            return $this->isUnder($path, 'investor') ? $next($request) : redirect()->to('/investor');
        }

        // IMS v2 (IMS-V2/ARCHITECTURE.md §2): its own domain carries two panels,
        // the investor portal (the domain's root) and the office at /office.
        if (Company::forImsHost($request->getHost())) {
            foreach (self::IMS_PATHS as $panelPath) {
                if ($this->isUnder($path, $panelPath)) {
                    return $next($request);
                }
            }

            return redirect()->to('/investor');
        }

        // The old investor-portal host of a company that moved to IMS v2.
        if ($company = Company::forRetiredInvestorPortalHost($request->getHost())) {
            return redirect()->away($company->imsUrl($this->isUnder($path, 'investor') ? $request->getRequestUri() : 'investor'));
        }

        // The portal that owns this host, if any: every other path redirects to it.
        foreach (self::PORTALS as $panelPath => $resolver) {
            if (Company::{$resolver}($request->getHost())) {
                return $this->isUnder($path, $panelPath) ? $next($request) : redirect()->to('/'.$panelPath);
            }
        }

        // Not a portal host: no portal path is served here.
        foreach ([...array_keys(self::PORTALS), ...self::IMS_PATHS] as $panelPath) {
            if ($this->isUnder($path, $panelPath)) {
                abort(404);
            }
        }

        return $next($request);
    }

    protected function isUnder(string $path, string $panelPath): bool
    {
        return $path === $panelPath || str_starts_with($path, $panelPath.'/');
    }

    protected function isPassthrough(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // Livewire's own endpoints ("livewire/update", but also the
        // randomised "livewire-<hash>/update" and ".../livewire.js" this app
        // uses): every form on a portal -- login, apply, tables, actions --
        // posts there, so blocking them silently breaks the whole portal.
        if (preg_match('#^livewire(-[A-Za-z0-9]+)?(/|$)#', $path) === 1) {
            return true;
        }

        foreach (self::PASSTHROUGH_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
