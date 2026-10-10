<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks whether a reseller's store subdomain already opens over HTTPS: its
 * name resolves to this server (the wildcard DNS record) and the server
 * answers with a valid SSL certificate (wildcard, or one issued on demand
 * through /internal/tls-ask). Any HTTP answer counts; a DNS, connection or
 * certificate error does not. Bound in the container so tests can swap in a
 * fake.
 */
class ResellerSubdomainProbe
{
    public function __construct(protected ResellerDomainDnsChecker $dns) {}

    public function isLive(string $host, Company $company): bool
    {
        if (! $this->dns->pointsToUs($host, $company)) {
            return false;
        }

        try {
            Http::timeout(8)->withOptions(['allow_redirects' => false])->get('https://'.$host.'/');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
