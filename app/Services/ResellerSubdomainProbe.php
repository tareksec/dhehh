<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
        return $this->diagnose($host, $company)['status'] === 'ok';
    }

    /**
     * Which step of the subdomain set-up is missing for $host: 'dns' (the
     * wildcard record is not there, or does not point at this server),
     * 'https' (it resolves but the SSL handshake or connection failed; the
     * reason is in 'detail') or 'ok'. Used by Reseller Settings → "Test
     * subdomain setup" so the wildcard DNS and certificate can be checked
     * without waiting for a reseller to pick a name.
     *
     * @return array{status: 'ok'|'dns'|'https', detail: ?string}
     */
    public function diagnose(string $host, Company $company): array
    {
        if (! $this->dns->pointsToUs($host, $company)) {
            return ['status' => 'dns', 'detail' => null];
        }

        try {
            Http::timeout(8)->withOptions(['allow_redirects' => false])->get('https://'.$host.'/');

            return ['status' => 'ok', 'detail' => null];
        } catch (Throwable $exception) {
            return ['status' => 'https', 'detail' => Str::limit($exception->getMessage(), 200)];
        }
    }
}
