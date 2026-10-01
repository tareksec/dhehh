<?php

namespace App\Services;

use App\Models\Company;

/**
 * Checks that a reseller's own domain already points at this app's server,
 * which is also what lets the proxy get an SSL certificate for it. "Points at
 * us" means the domain resolves (an A record, or a CNAME followed to one) to
 * at least one IP address the company's own storefront domain resolves to.
 * Bound in the container so tests can swap in a fake.
 */
class ResellerDomainDnsChecker
{
    public function pointsToUs(string $domain, Company $company): bool
    {
        if (blank($company->domain)) {
            return false;
        }

        $theirs = $this->resolve($domain);
        $ours = $this->resolve($company->domain);

        return $theirs !== [] && array_intersect($theirs, $ours) !== [];
    }

    /** @return list<string> */
    protected function resolve(string $host): array
    {
        $ips = @gethostbynamel($host);

        return $ips === false ? [] : array_values($ips);
    }
}
