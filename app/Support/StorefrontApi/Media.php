<?php

namespace App\Support\StorefrontApi;

use App\Models\Company;
use App\Support\CompanyMedia;

/**
 * Image/file URLs for the storefront API. The Next.js site lives on another
 * domain, so a root-relative "/storage/..." URL (local disk) must become an
 * absolute one on the ERP's own host; object-storage URLs are already absolute.
 */
final class Media
{
    public static function url(?string $path, Company $company): ?string
    {
        if (blank($path)) {
            return null;
        }

        return CompanyMedia::filamentPublicUrl($path, $company) ?: null;
    }

    /**
     * @param  iterable<int, string|null>  $paths
     * @return array<int, string>
     */
    public static function urls(iterable $paths, Company $company): array
    {
        return collect($paths)
            ->map(fn (?string $path): ?string => self::url($path, $company))
            ->filter()
            ->values()
            ->all();
    }
}
