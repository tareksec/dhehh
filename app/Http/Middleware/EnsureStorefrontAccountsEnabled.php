<?php

namespace App\Http\Middleware;

use App\Models\StorefrontSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer login, registration and the account area exist only while the
 * owner keeps "Customer accounts" on in Storefront Settings — the same switch
 * the Blade storefront obeys. Runs after EnsureNextjsSiteEnabled, which has
 * already loaded the company's StorefrontSetting onto the request.
 */
class EnsureStorefrontAccountsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $setting = $request->attributes->get('storefront_setting');

        if (! $setting instanceof StorefrontSetting || ! $setting->customer_accounts_enabled) {
            return response()->json([
                'ok' => false,
                'error' => 'customer_accounts_disabled',
                'hint' => 'Turn on "Customer accounts" in ERP → Site → Storefront Settings.',
            ], 403);
        }

        return $next($request);
    }
}
