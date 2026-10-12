<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Caches the storefront home/category/product-listing queries per company so
 * a busy storefront doesn't re-run the same product/category query for every
 * visitor. This uses its own key prefix ("storefront-home-products:",
 * "storefront-categories:", "storefront-products:") so it never collides
 * with the pre-existing `storefront-home:{companyId}` slide cache that
 * Category/StorefrontSetting/StorefrontSlide already forget on save/delete
 * (see those models' booted() hooks) — that key caches slides only.
 *
 * The product-listing page is parameterized by category/sort/page, so there
 * is no single fixed key to forget when a Product or Category changes.
 * Instead its keys (and the plain category-sidebar key) carry a per-company
 * "generation" number: forgetCompany() bumps it, which makes every
 * previously cached listing/category key unreachable — it simply expires
 * unused via TTL — without having to enumerate every combination.
 */
final class StorefrontListingCache
{
    public const TTL_MINUTES = 10;

    /**
     * @return array{categories: mixed, products: mixed}
     */
    public static function home(int $companyId, Closure $callback): array
    {
        return Cache::remember(
            "storefront-home-products:{$companyId}",
            now()->addMinutes(self::TTL_MINUTES),
            $callback,
        );
    }

    public static function categories(int $companyId, Closure $callback): mixed
    {
        return Cache::remember(
            sprintf('storefront-categories:%d:g%d', $companyId, self::generation($companyId)),
            now()->addMinutes(self::TTL_MINUTES),
            $callback,
        );
    }

    public static function listing(int $companyId, string $paramsKey, Closure $callback): mixed
    {
        return Cache::remember(
            sprintf('storefront-products:%d:g%d:%s', $companyId, self::generation($companyId), $paramsKey),
            now()->addMinutes(self::TTL_MINUTES),
            $callback,
        );
    }

    /** The sitemap's addresses depend on the host they are served from, so each host has its own entry. */
    public static function sitemap(int $companyId, string $host, Closure $callback): mixed
    {
        return Cache::remember(
            sprintf('storefront-sitemap:%d:g%d:%s', $companyId, self::generation($companyId), $host),
            now()->addMinutes(self::TTL_MINUTES),
            $callback,
        );
    }

    /**
     * Invalidate every cached home/category/listing entry for a company.
     * Call this from a model's saved/deleted hook whenever storefront-visible
     * product or category data changes.
     */
    public static function forgetCompany(?int $companyId): void
    {
        if (! $companyId) {
            return;
        }

        Cache::forget("storefront-home-products:{$companyId}");
        Cache::forever(self::generationKey($companyId), self::generation($companyId) + 1);
    }

    protected static function generation(int $companyId): int
    {
        return (int) Cache::rememberForever(self::generationKey($companyId), fn (): int => 1);
    }

    protected static function generationKey(int $companyId): string
    {
        return "storefront-products-generation:{$companyId}";
    }
}
