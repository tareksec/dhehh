<?php

namespace App\Http\Middleware;

use App\Models\CompanyApiKey;
use App\Services\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an external-website API request by a per-company Bearer
 * token (see CompanyApiKey) and sets CompanyContext for the rest of the
 * request — the routes/api.php equivalent of ResolveCompanyFromDomain for
 * the storefront. Every route behind this middleware relies on the context
 * being set before it runs; on any failure this aborts instead of letting a
 * request fall through with company context unset (CompanyScope treats an
 * unset context as unscoped/cross-company, see its docblock).
 */
class ResolveCompanyFromApiKey
{
    public function __construct(protected CompanyContext $context) {}

    public function handle(Request $request, Closure $next, string $scope = CompanyApiKey::SCOPE_WEBSITE): Response
    {
        $token = $request->bearerToken();

        if (blank($token)) {
            return response()->json([
                'ok' => false,
                'error' => 'missing_api_key',
                'hint' => 'Send your API key as "Authorization: Bearer <token>".',
            ], 401);
        }

        $apiKey = CompanyApiKey::findByToken($token);

        if (! $apiKey) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_api_key',
                'hint' => 'This API key is invalid or has been revoked. Generate a new one from ZamZam ERP → Settings → Integrations → Website API.',
            ], 401);
        }

        // A key only opens the API it was made for: a website key never reads
        // the purchase and cost feed, and a data-sync key never writes products.
        if ($apiKey->scope !== $scope) {
            return response()->json([
                'ok' => false,
                'error' => 'wrong_key_scope',
                'hint' => match ($scope) {
                    CompanyApiKey::SCOPE_SYNC => 'This endpoint needs a "Data sync" API key. Generate one from ZamZam ERP → Settings → Integrations → Website API.',
                    CompanyApiKey::SCOPE_INVESTOR_SYNC => 'This endpoint needs an "Investor sync" API key. Generate one from ZamZam ERP → Settings → Integrations → Website API.',
                    default => 'This endpoint needs a "Website" API key; a sync key can only read its own /api/v1/sync/* feed.',
                },
            ], 403);
        }

        if ($apiKey->last_used_at === null || $apiKey->last_used_at->diffInMinutes(now()) >= 1) {
            $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $this->context->set($apiKey->company);
        $request->attributes->set('company_api_key', $apiKey);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
