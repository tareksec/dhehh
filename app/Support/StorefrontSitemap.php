<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Company;
use App\Models\Offer;
use App\Models\Product;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use Illuminate\Database\Eloquent\Model;

/**
 * The addresses a storefront domain wants search engines to know: the home
 * page, the shop, categories that have products, available products, the
 * published pages and offer landing pages, and the contact page. These are the
 * same pages the storefront serves publicly (the same filters the controllers
 * use), so nothing hidden or unpublished is listed. A reseller's mini-store is
 * not listed: it repeats the company's catalog at other prices.
 *
 * A sitemap file may hold 50,000 addresses; a larger catalog is split into
 * pages of MAX_URLS behind a sitemap index.
 */
final class StorefrontSitemap
{
    public const MAX_URLS = 45000;

    /**
     * Must run with the company already set (the domain middleware does) so
     * the company scope applies to every query.
     *
     * @return list<array{loc: string, lastmod: ?string}>
     */
    public static function entries(Company $company, StorefrontSetting $setting): array
    {
        $entries = [
            self::entry(route('marketing.home')),
            self::entry(route('storefront.products.index')),
            self::entry(route('storefront.contact')),
        ];

        $availableProducts = fn ($query) => $query->where('is_active', true)->where('status', Product::STATUS_AVAILABLE);

        foreach (Category::query()->where('is_active', true)->whereHas('products', $availableProducts)->orderBy('id')->cursor() as $category) {
            $entries[] = self::entry(route('storefront.categories.show', $category->slug), $category);
        }

        foreach (Product::query()->where('is_active', true)->where('status', Product::STATUS_AVAILABLE)->orderBy('id')->cursor() as $product) {
            $entries[] = self::entry(route('storefront.products.show', $product->slug), $product);
        }

        foreach (StorefrontPage::query()->where('company_id', $company->getKey())->forSite($setting)->published()->orderBy('id')->cursor() as $page) {
            $entries[] = self::entry(route('storefront.pages.show', $page->slug), $page);
        }

        $offers = Offer::query()->landingPages()->published()->orderBy('id')->get();

        if ($offers->isNotEmpty()) {
            $entries[] = self::entry(route('storefront.offers.index'));
        }

        foreach ($offers as $offer) {
            $entries[] = self::entry(route('storefront.offers.show', $offer->slug), $offer);
        }

        return $entries;
    }

    /** @param  list<array{loc: string, lastmod: ?string}>  $entries */
    public static function pageCount(array $entries): int
    {
        return max(1, (int) ceil(count($entries) / self::MAX_URLS));
    }

    /** @param  list<array{loc: string, lastmod: ?string}>  $entries */
    public static function urlset(array $entries): string
    {
        $urls = '';

        foreach ($entries as $entry) {
            $urls .= '<url><loc>'.self::escape($entry['loc']).'</loc>'
                .($entry['lastmod'] ? '<lastmod>'.self::escape($entry['lastmod']).'</lastmod>' : '')
                .'</url>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$urls.'</urlset>'."\n";
    }

    /**
     * @param  list<array{loc: string, lastmod: ?string}>  $entries
     * @return list<array{loc: string, lastmod: ?string}>
     */
    public static function page(array $entries, int $page): array
    {
        return array_slice($entries, ($page - 1) * self::MAX_URLS, self::MAX_URLS);
    }

    /** @param  list<string>  $locations  The address of every sitemap page. */
    public static function index(array $locations): string
    {
        $items = '';

        foreach ($locations as $location) {
            $items .= '<sitemap><loc>'.self::escape($location).'</loc></sitemap>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$items.'</sitemapindex>'."\n";
    }

    /** @return array{loc: string, lastmod: ?string} */
    private static function entry(string $loc, ?Model $model = null): array
    {
        return ['loc' => $loc, 'lastmod' => $model?->updated_at?->toAtomString()];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
