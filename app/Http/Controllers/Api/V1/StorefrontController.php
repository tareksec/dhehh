<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Product;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use App\Models\StorefrontSlide;
use App\Services\CompanyContext;
use App\Services\StorefrontCatalogService;
use App\Services\StorefrontSiteConfigService;
use App\Support\StorefrontApi\Media;
use App\Support\StorefrontApi\RichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only storefront API for the company's headless Next.js site
 * (/api/v1/storefront/*). Authenticated by the company API key and gated by
 * the "Enable Next.js site API" switch (ResolveCompanyFromApiKey +
 * EnsureNextjsSiteEnabled run first), so CompanyContext is set and every
 * query below is scoped to the calling company. Responses are
 * `{ "data": ..., "meta": ... }`, like the rest of /api/v1.
 */
class StorefrontController extends Controller
{
    public const MAX_PER_PAGE = 48;

    public const DEFAULT_PER_PAGE = 24;

    public const MAX_SLUGS = 50;

    public function __construct(protected CompanyContext $context) {}

    public function site(Request $request): JsonResponse
    {
        /** @var StorefrontSetting $setting */
        $setting = $request->attributes->get('storefront_setting');

        return response()->json([
            'data' => (new StorefrontSiteConfigService($this->context->company(), $setting))->build(),
        ]);
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => $this->catalog()->categories()]);
    }

    /**
     * Query: category (slug), q, sort (latest|price_asc|price_desc),
     * collection (flash-sale|trending|new-arrivals), slugs (comma list, for
     * refreshing cart/wishlist lines), page, per_page.
     */
    public function products(Request $request): JsonResponse
    {
        $catalog = $this->catalog();
        $query = $catalog->visibleProducts();

        if ($slugs = $this->slugList($request->query('slugs'))) {
            $products = $query->whereIn('slug', $slugs)->get()->sortBy(fn (Product $product): int => array_search($product->slug, $slugs, true))->values();

            return response()->json([
                'data' => $products->map(fn (Product $product): array => $catalog->card($product))->all(),
                'meta' => ['total' => $products->count()],
            ]);
        }

        if (filled($category = $request->query('category'))) {
            $categoryModel = Category::query()->where('is_active', true)->where('slug', (string) $category)->first();

            if (! $categoryModel) {
                return $this->error('category_not_found', 404);
            }

            $query->whereBelongsTo($categoryModel);
        }

        if (filled($collection = $request->query('collection'))) {
            $kind = StorefrontCatalogService::KIND_SLUGS[(string) $collection] ?? null;

            if (! $kind) {
                return $this->error('invalid_collection', 422, 'collection must be one of: '.implode(', ', array_keys(StorefrontCatalogService::KIND_SLUGS)));
            }

            $query->whereIn('id', Offer::query()->liveCollections()->where('collection_kind', $kind)
                ->join('offer_items', 'offer_items.offer_id', '=', 'offers.id')->select('offer_items.product_id'));
        }

        if (($search = trim((string) $request->query('q'))) !== '') {
            $search = mb_substr($search, 0, 100);
            $query->where(fn (Builder $q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('brand', 'like', "%{$search}%"));
        }

        match ((string) $request->query('sort')) {
            'price_asc' => $query->orderByRaw('COALESCE(sale_price, price) asc')->orderBy('id'),
            'price_desc' => $query->orderByRaw('COALESCE(sale_price, price) desc')->orderBy('id'),
            default => $query->latest()->orderByDesc('id'),
        };

        $paginator = $query->paginate($this->perPage($request));

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Product $product): array => $catalog->card($product))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function product(string $slug): JsonResponse
    {
        $catalog = $this->catalog();
        $product = $catalog->visibleProducts()->where('slug', $slug)->first();

        if (! $product) {
            return $this->error('product_not_found', 404);
        }

        return response()->json(['data' => $catalog->detail($product)]);
    }

    public function slides(Request $request): JsonResponse
    {
        /** @var StorefrontSetting $setting */
        $setting = $request->attributes->get('storefront_setting');
        $company = $this->context->company();

        $slides = StorefrontSlide::forCompanyTheme($company->getKey(), $setting->storefrontTheme(), $setting->homepageTemplate(), $setting);
        $products = Product::query()
            ->whereIn('id', $slides->pluck('product_id')->filter()->all())
            ->where('is_active', true)
            ->where('status', Product::STATUS_AVAILABLE)
            ->get(['id', 'slug', 'name'])
            ->keyBy('id');

        return response()->json(['data' => $slides->map(fn (StorefrontSlide $slide): array => [
            'id' => $slide->getKey(),
            'image_url' => Media::url($slide->image, $company),
            'image_mobile_url' => Media::url($slide->image_mobile, $company),
            'fit_to_frame' => (bool) $slide->fit_to_frame,
            'heading' => $slide->heading,
            'subheading' => $slide->subheading,
            'cta_label' => $slide->cta_label,
            'cta_url' => $slide->cta_url,
            'product' => ($product = $products->get($slide->product_id))
                ? ['slug' => $product->slug, 'name' => $product->name]
                : null,
        ])->values()->all()]);
    }

    /** Query: kind (flash-sale|trending|new-arrivals) to return just that kind. */
    public function collections(Request $request): JsonResponse
    {
        $kind = null;

        if (filled($slug = $request->query('kind'))) {
            $kind = StorefrontCatalogService::KIND_SLUGS[(string) $slug] ?? null;

            if (! $kind) {
                return $this->error('invalid_kind', 422, 'kind must be one of: '.implode(', ', array_keys(StorefrontCatalogService::KIND_SLUGS)));
            }
        }

        return response()->json(['data' => $this->catalog()->collections($kind)]);
    }

    public function pages(Request $request): JsonResponse
    {
        $company = $this->context->company();

        return response()->json(['data' => StorefrontPage::query()->published()
            ->forSite($request->attributes->get('storefront_setting'))
            ->orderBy('sort_order')->orderBy('title')->get()
            ->map(fn (StorefrontPage $page): array => [
                'slug' => $page->slug,
                'title' => $page->title,
                'excerpt' => $page->excerpt,
                'cover_image_url' => Media::url($page->cover_image, $company),
            ])->values()->all()]);
    }

    public function page(Request $request, string $slug): JsonResponse
    {
        $page = StorefrontPage::query()->published()
            ->forSite($request->attributes->get('storefront_setting'))
            ->where('slug', $slug)->first();

        if (! $page) {
            return $this->error('page_not_found', 404);
        }

        return response()->json(['data' => [
            'slug' => $page->slug,
            'title' => $page->title,
            'excerpt' => $page->excerpt,
            'cover_image_url' => Media::url($page->cover_image, $this->context->company()),
            'content_html' => RichText::html($page->content),
            'meta_title' => $page->meta_title,
            'meta_description' => $page->meta_description,
            'updated_at' => $page->updated_at?->toIso8601String(),
        ]]);
    }

    protected function catalog(): StorefrontCatalogService
    {
        return new StorefrontCatalogService($this->context->company());
    }

    protected function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);

        return min(self::MAX_PER_PAGE, max(1, $perPage ?: self::DEFAULT_PER_PAGE));
    }

    /** @return list<string> */
    protected function slugList(mixed $value): array
    {
        return collect(explode(',', (string) $value))
            ->map(fn (string $slug): string => trim($slug))
            ->filter()
            ->unique()
            ->take(self::MAX_SLUGS)
            ->values()
            ->all();
    }

    protected function error(string $code, int $status, ?string $hint = null): JsonResponse
    {
        return response()->json(array_filter(['ok' => false, 'error' => $code, 'hint' => $hint], fn ($value) => $value !== null), $status);
    }
}
