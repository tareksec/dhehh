<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The "ask" endpoint for a proxy that issues SSL certificates on demand
 * (Caddy's `on_demand_tls { ask ... }`): before getting a certificate for a
 * host it asks here, and only a 200 lets it. Only a reseller's verified own
 * domain gets a 200, so nobody can make the server request certificates for
 * arbitrary names. It reveals nothing but yes/no and is rate limited.
 */
class TlsAskController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $domain = Company::normalizeDomain((string) $request->query('domain', ''));

        $allowed = $domain !== null && Company::forResellerCustomDomainHost($domain) !== null;

        return response($allowed ? 'ok' : 'unknown domain', $allowed ? 200 : 404);
    }
}
