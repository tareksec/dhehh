<?php

namespace App\Filament\Resources\StorefrontSettings\Pages\Concerns;

use App\Models\Company;
use App\Models\StorefrontSetting;
use Illuminate\Validation\ValidationException;

/**
 * Server-side half of the "Next.js Site" settings: the form's own rule already
 * validates the domain, but a save must never rely on the browser alone. Also
 * keeps the "Domain verified" flag honest — it resets whenever the domain
 * changes, exactly like the Blade storefront domain's flag does.
 */
trait HandlesNextjsSiteDomain
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function applyNextjsSiteDomain(array $data, ?StorefrontSetting $record, ?int $companyId): array
    {
        $domain = Company::normalizeDomain($data['nextjs_site_domain'] ?? null);
        $conflict = StorefrontSetting::nextjsDomainConflict($domain, $companyId);

        if ($conflict) {
            throw ValidationException::withMessages(['data.nextjs_site_domain' => $conflict]);
        }

        $domainChanged = $domain !== Company::normalizeDomain($record?->nextjs_site_domain);

        $data['nextjs_site_domain'] = $domain;
        $data['nextjs_site_domain_verified'] = filled($domain)
            && ! $domainChanged
            && (bool) ($data['nextjs_site_domain_verified'] ?? false);

        return $data;
    }

    protected function assertBladeDomainIsNotNextjs(?string $domain): void
    {
        if ($domain && StorefrontSetting::nextjsDomainTaken($domain)) {
            throw ValidationException::withMessages([
                'data.company_domain' => __('This domain is already used by a Next.js site.'),
            ]);
        }
    }
}
