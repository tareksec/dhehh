<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Support\CompanyMedia;
use App\Support\StorefrontListingCache;
use App\Support\StorefrontListingFilters;
use App\Support\StorefrontSchema;
use App\Support\StorefrontSeo;
use App\Support\StorefrontThemeRegistry;
use App\Support\StorefrontUrl;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductIndexController extends Controller
{
    public function __invoke(Request $request): View
    {
        $company = $request->attributes->get('storefront_company');

        abort_unless($company instanceof Company && $company->storefrontSetting?->is_published, 404);

        $reseller = $request->attributes->get('storefront_reseller');

        // Read the `{slug}` route parameter explicitly by name rather than
        // via method-injection: this controller is invoked from routes with
        // different parameter counts (bare /products, /category/{slug}, and
        // the reseller-store /store/{resellerSlug}/... mirrors of both), and
        // Laravel's implicit controller-call resolution binds scalar route
        // parameters by position, not name -- an extra leading URI segment
        // like {resellerSlug} would otherwise land in $slug by accident.
        $slug = $request->route('slug');

        $category = $slug
            ? Category::query()->where('slug', $slug)->where('is_active', true)->firstOrFail()
            : null;

        $sort = $request->string('sort')->value();
        $search = trim((string) $request->string('q'));
        $filters = StorefrontListingFilters::fromRequest($request);

        $productsQuery = Product::query()
            ->with('category')
            ->where('is_active', true)
            ->where('status', Product::STATUS_AVAILABLE)
            ->when(
                $reseller instanceof Customer,
                fn ($query) => $query->whereIn('id', ResellerProduct::query()
                    ->where('customer_id', $reseller->getKey())
                    ->where('is_active', true)
                    ->select('product_id')),
            )
            ->when($category, fn ($query) => $query->whereBelongsTo($category))
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
            ->tap(fn ($query) => $filters->apply($query))
            ->when($sort === 'price_asc', fn ($query) => $query->orderByRaw('COALESCE(sale_price, price) asc'))
            ->when($sort === 'price_desc', fn ($query) => $query->orderByRaw('COALESCE(sale_price, price) desc'))
            ->when(! in_array($sort, ['price_asc', 'price_desc'], true), fn ($query) => $query->latest());

        // A plain category/sort/page browse is identical for every visitor
        // and worth caching on a busy storefront; a search, a stock/price
        // filter or a reseller's catalog is per-request/per-customer, so
        // those always hit the database instead of growing the cache with one-off keys.
        // withQueryString() is applied after the cache lookup (not inside the
        // cached closure) so a cache hit still reflects *this* request's
        // query string rather than whatever first populated the cache entry.
        $cacheable = ! $reseller instanceof Customer && $search === '' && ! $filters->active();

        $products = ($cacheable
            ? StorefrontListingCache::listing(
                $company->getKey(),
                sprintf('%s|%s|%d', $category?->slug ?? '_all', $sort ?: '_default', $request->integer('page', 1)),
                fn () => $productsQuery->paginate(24),
            )
            : $productsQuery->paginate(24)
        )->withQueryString();

        $categories = StorefrontListingCache::categories(
            $company->getKey(),
            fn () => Category::query()->where('is_active', true)->orderBy('name')->get(),
        );

        $this->describe($request, $company, $category, $search, $sort, $filters);

        return StorefrontThemeRegistry::page('products.index', [
            'company' => $company,
            'setting' => $company->storefrontSetting,
            'category' => $category,
            'categories' => $categories,
            'products' => $products,
            'sort' => $sort,
            'search' => $search,
            'filters' => $filters,
        ]);
    }

    /**
     * Name the listing for search engines. Only the plain first page of the
     * shop or of a category is indexed: searches, sorted, filtered and later
     * pages repeat it and stay out of results (their links are still followed).
     */
    protected function describe(Request $request, Company $company, ?Category $category, string $search, string $sort, StorefrontListingFilters $filters): void
    {
        $seo = StorefrontSeo::current()->page(
            $search !== '' ? __('Results for “:term”', ['term' => $search]) : ($category?->name ?? __('All products')),
            $category ? StorefrontSeo::excerpt($category->description) ?? $category->tagline : null,
            $category ? CompanyMedia::publicUrl($category->image, $company) : null,
        );

        if ($search !== '' || $sort !== '' || $filters->active() || $request->integer('page', 1) > 1) {
            $seo->noindexFollow();

            return;
        }

        $trail = [
            ['name' => __('Home'), 'url' => StorefrontUrl::home()],
            ['name' => __('All products'), 'url' => StorefrontUrl::route('products.index')],
        ];

        if ($category) {
            $trail[] = ['name' => $category->name, 'url' => StorefrontUrl::route('categories.show', [$category->slug])];
        }

        $seo->index()->jsonLd(StorefrontSchema::breadcrumbs($trail));
    }
}
