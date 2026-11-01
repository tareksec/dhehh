<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The "ask" endpoint for a proxy that issues SSL certificates on demand
 * (Caddy's `on_demand_tls { ask ... }`): before getting a certificate for a
 * host it asks here, and only a 200 lets it. Only a reseller's verified own
 * domain, or the subdomain an approved reseller chose (so a subdomain gets
 * its SSL certificate without a wildcard certificate), gets a 200, so nobody can make the server request certificates for
 * arbitrary names. It reveals nothing but yes/no and is rate limited.
 */
class TlsAskController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $domain = Company::normalizeDomain((string) $request->query('domain', ''));

        $allowed = $domain !== null
            && (Company::forResellerCustomDomainHost($domain) !== null || $this->isResellerSubdomain($domain));

        return response($allowed ? 'ok' : 'unknown domain', $allowed ? 200 : 404);
    }

    protected function isResellerSubdomain(string $host): bool
    {
        $subdomain = Company::forResellerSubdomainHost($host);

        if (! $subdomain) {
            return false;
        }

        [$company, $label] = $subdomain;

        // withoutGlobalScopes() also drops the Trash filter: a reseller in Trash gets no certificate.
        return Customer::withoutGlobalScopes()
            ->withoutTrashed()
            ->where('company_id', $company->getKey())
            ->where('reseller_subdomain', $label)
            ->where('reseller_status', 'approved')
            ->exists();
    }
}
