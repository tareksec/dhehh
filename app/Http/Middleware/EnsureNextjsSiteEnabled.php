<?php

namespace App\Http\Middleware;

use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for /api/v1/storefront/*: the company's headless Next.js site may only
 * read the storefront API while the owner has switched it on in its own
 * Storefront Settings row (Site type: Next.js). Runs after ResolveCompanyFromApiKey, so
 * CompanyContext is already set; it is the owner's kill switch for the site.
 */
class EnsureNextjsSiteEnabled
{
    public function __construct(protected CompanyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The Next.js site has its own settings row (site_type "nextjs"); the
        // company's Blade storefront row is never what this API serves.
        $setting = $this->context->hasCompany()
            ? StorefrontSetting::nextjsFor($this->context->id())
            : null;

        if (! $setting?->nextjsSiteEnabled()) {
            return response()->json([
                'ok' => false,
                'error' => 'nextjs_site_disabled',
                'hint' => 'Create a Next.js site in ERP → Site → Storefront Settings (Site type: Next.js) and turn on "Enable Next.js site API".',
            ], 403);
        }

        $request->attributes->set('storefront_setting', $setting);

        return $next($request);
    }
}
