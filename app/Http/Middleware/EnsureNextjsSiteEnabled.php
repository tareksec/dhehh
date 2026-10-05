<?php

namespace App\Http\Middleware;

use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for /api/v1/storefront/*: the company's headless Next.js site may only
 * read the storefront API while the owner has switched it on in Site →
 * Storefront Settings → Next.js Site. Runs after ResolveCompanyFromApiKey, so
 * CompanyContext is already set; it is the owner's kill switch for the site.
 */
class EnsureNextjsSiteEnabled
{
    public function __construct(protected CompanyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $setting = $this->context->hasCompany()
            ? StorefrontSetting::query()->where('company_id', $this->context->id())->first()
            : null;

        if (! $setting?->nextjsSiteEnabled()) {
            return response()->json([
                'ok' => false,
                'error' => 'nextjs_site_disabled',
                'hint' => 'Turn on "Enable Next.js site API" in ERP → Site → Storefront Settings → Next.js Site.',
            ], 403);
        }

        $request->attributes->set('storefront_setting', $setting);

        return $next($request);
    }
}
