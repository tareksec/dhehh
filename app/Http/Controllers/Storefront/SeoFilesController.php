<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\StorefrontListingCache;
use App\Support\StorefrontSitemap;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * /robots.txt and /sitemap.xml for whichever domain asked. A storefront domain
 * gets its own rules and its own sitemap; every other host (the ERP's app
 * domain) keeps the admin-only rules and has no sitemap.
 */
class SeoFilesController extends Controller
{
    /** Private or transactional pages that crawlers have no reason to visit. */
    private const STOREFRONT_DISALLOW = [
        '/admin',
        '/livewire',
        '/cart',
        '/checkout',
        '/account',
        '/track',
        '/complaints',
        '/store/*/cart',
        '/store/*/checkout',
    ];

    public function robots(Request $request): Response
    {
        $isStorefront = $this->publishedCompany($request) !== null;
        $lines = ['User-agent: *'];

        foreach ($isStorefront ? self::STOREFRONT_DISALLOW : ['/admin', '/livewire'] as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        if ($this->storefront($request)) {
            $lines[] = '';
            $lines[] = 'Sitemap: '.route('storefront.sitemap');
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function sitemap(Request $request, ?int $page = null): Response
    {
        $company = $this->storefront($request);

        abort_unless($company, 404);

        $entries = StorefrontListingCache::sitemap(
            $company->getKey(),
            $request->getHost(),
            fn (): array => StorefrontSitemap::entries($company, $company->storefrontSetting),
        );
        $pages = StorefrontSitemap::pageCount($entries);

        if ($pages === 1) {
            abort_if($page !== null, 404);
            $xml = StorefrontSitemap::urlset($entries);
        } elseif ($page === null) {
            $xml = StorefrontSitemap::index(array_map(
                fn (int $number): string => route('storefront.sitemap.page', $number),
                range(1, $pages),
            ));
        } else {
            abort_if($page < 1 || $page > $pages, 404);
            $xml = StorefrontSitemap::urlset(StorefrontSitemap::page($entries, $page));
        }

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /** The company whose published storefront this domain serves (a reseller's own store included); null for the app domain. */
    private function publishedCompany(Request $request): ?Company
    {
        $company = $request->attributes->get('storefront_company');

        return $company instanceof Company && $company->storefrontSetting?->is_published ? $company : null;
    }

    /** Same, but not a reseller's own store: that repeats the company's catalog at other prices, so it has no sitemap. */
    private function storefront(Request $request): ?Company
    {
        return $request->attributes->get('storefront_reseller') ? null : $this->publishedCompany($request);
    }
}
