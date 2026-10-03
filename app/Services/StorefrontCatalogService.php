<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Company;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Support\StorefrontApi\Media;
use App\Support\StorefrontApi\RichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Read-only catalog shapes for the headless Next.js storefront
 * (/api/v1/storefront/*). Visibility rules deliberately mirror the Blade
 * storefront (ProductIndexController / ProductShowController): a product is
 * shown only while it is active and "available" — and every query runs under
 * CompanyContext, which ResolveCompanyFromApiKey has already set.
 *
 * Prices are plain ERP data: `price` is the regular price, `sale_price` the
 * discounted one. A collection's optional `deal_price` is reported separately
 * (`deal`) while that collection is live; nothing here changes what checkout
 * charges — that is decided server-side at checkout, never by the website.
 */
class StorefrontCatalogService
{
    /** URL slug => Offer collection kind. */
    public const KIND_SLUGS = [
        'flash-sale' => Offer::KIND_FLASH_SALE,
        'trending' => Offer::KIND_TRENDING,
        'new-arrivals' => Offer::KIND_NEW_ARRIVALS,
    ];

    /** @var array<int, array<string, mixed>>|null product_id => live deal */
    protected ?array $deals = null;

    /** @var Collection<int, Offer>|null */
    protected ?Collection $live = null;

    public function __construct(protected Company $company) {}

    public static function kindSlug(?string $kind): ?string
    {
        $slug = array_search($kind, self::KIND_SLUGS, true);

        return $slug === false ? null : $slug;
    }

    /** Products the storefront may show, with the rating aggregates a card needs. */
    public function visibleProducts(): Builder
    {
        return Product::query()
            ->with('category')
            ->where('is_active', true)
            ->where('status', Product::STATUS_AVAILABLE)
            ->withCount(['reviews as approved_reviews_count' => fn (Builder $query) => $query->approved()])
            ->withAvg(['reviews as approved_reviews_avg' => fn (Builder $query) => $query->approved()], 'rating');
    }

    /** @return array<string, mixed> */
    public function card(Product $product): array
    {
        $price = (float) $product->price;
        $salePrice = $product->sale_price !== null ? (float) $product->sale_price : null;
        $hasDiscount = $salePrice !== null && $price > 0 && $salePrice < $price;
        $stock = (int) $product->stock;
        $isPreorder = (bool) $product->is_preorder;

        return [
            'id' => $product->getKey(),
            'sku' => $product->sku,
            'slug' => $product->slug,
            'name' => $product->name,
            'brand' => $product->brand,
            'unit' => $product->unit,
            'category' => $product->category ? [
                'id' => $product->category->getKey(),
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ] : null,
            'image_url' => Media::url($product->image, $this->company),
            'price' => $price,
            'sale_price' => $salePrice,
            'selling_price' => $salePrice ?? $price,
            'has_discount' => $hasDiscount,
            'discount_percent' => $hasDiscount ? (int) round((1 - $salePrice / $price) * 100) : 0,
            'deal' => $this->deals()[$product->getKey()] ?? null,
            'stock' => $stock,
            'reorder_level' => (int) $product->reorder_level,
            // The ERP's own low-stock rule (stock <= reorder level), not a website guess.
            'stock_status' => match (true) {
                $stock <= 0 => $isPreorder ? 'preorder' : 'out_of_stock',
                $product->isLowStock() => 'low_stock',
                default => 'in_stock',
            },
            'is_preorder' => $isPreorder,
            // A variable product's stock is the sum of its active variants (see ProductVariant).
            'orderable' => $stock > 0 || $isPreorder,
            'moq' => $product->effectiveMoq(),
            'tier_prices' => $product->normalizedTiers(),
            'has_variants' => (bool) $product->has_variants,
            'rating' => [
                'average' => isset($product->approved_reviews_avg) ? round((float) $product->approved_reviews_avg, 1) : null,
                'count' => (int) ($product->approved_reviews_count ?? 0),
            ],
            'created_at' => $product->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Product $product): array
    {
        $variants = $product->has_variants ? $product->activeVariants()->get() : collect();
        $stock = (int) $product->stock;
        $isPreorder = (bool) $product->is_preorder;
        // Same bounds the Blade product page and cart enforce.
        $maxOrderQuantity = $isPreorder
            ? max($stock, StorefrontCart::PREORDER_STOCK_CEILING)
            : max(1, $stock);

        $related = $this->visibleProducts()
            ->where('id', '!=', $product->getKey())
            ->when($product->category_id, fn (Builder $query) => $query->where('category_id', $product->category_id))
            ->latest()
            ->limit(4)
            ->get();

        return $this->card($product) + [
            'description_html' => RichText::html($product->description),
            'description_text' => RichText::text($product->description),
            'weight_kg' => $product->weight_kg !== null ? (float) $product->weight_kg : null,
            'gallery_urls' => Media::urls(collect([$product->image])->merge($product->gallery_images ?? []), $this->company),
            'video_url' => Media::url($product->video_path, $this->company),
            'variants' => $variants->map(fn (ProductVariant $variant): array => [
                'id' => $variant->getKey(),
                'sku' => $variant->sku ?: $product->sku,
                'options' => $variant->options ?? [],
                'label' => $variant->label(),
                'price' => $variant->effectiveSalePrice(),
                'stock' => (int) $variant->stock,
                'image_urls' => Media::urls($variant->images ?? [], $this->company),
            ])->values()->all(),
            'min_order_quantity' => min($product->effectiveMoq(), $maxOrderQuantity),
            'max_order_quantity' => $maxOrderQuantity,
            'preorder' => $isPreorder ? ['advance_percent' => $product->preorderAdvancePercent()] : null,
            'reviews' => $product->reviews()->approved()->latest()->limit(10)->get()
                ->map(fn (ProductReview $review): array => [
                    'customer_name' => $review->customer_name,
                    'rating' => (int) $review->rating,
                    'comment' => $review->comment,
                    'photo_url' => Media::url($review->photo, $this->company),
                    'created_at' => $review->created_at?->toIso8601String(),
                ])->all(),
            'related' => $related->map(fn (Product $item): array => $this->card($item))->all(),
            'seo' => [
                'title' => $product->name,
                'description' => Str::limit(RichText::text($product->description), 160, ''),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function categories(): array
    {
        return Category::query()
            ->where('is_active', true)
            ->withCount(['products as visible_products_count' => fn (Builder $query) => $query
                ->where('is_active', true)
                ->where('status', Product::STATUS_AVAILABLE)])
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category): array => [
                'id' => $category->getKey(),
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'image_url' => Media::url($category->image, $this->company),
                'icon' => $category->icon,
                'products_count' => (int) $category->visible_products_count,
            ])->values()->all();
    }

    /**
     * Live collections (Flash Sale first, then Trending, New Arrivals) with
     * their visible products in the admin's order. Collections with no
     * visible product are dropped, like an empty homepage carousel.
     *
     * @return list<array<string, mixed>>
     */
    public function collections(?string $kind = null): array
    {
        $offers = $this->liveCollections($kind);
        $products = $this->visibleProducts()
            ->whereIn('id', $offers->flatMap(fn (Offer $offer) => $offer->items->pluck('product_id'))->unique()->all())
            ->get()
            ->keyBy('id');

        return $offers->map(function (Offer $offer) use ($products): ?array {
            $cards = $offer->items
                ->map(fn ($item) => $products->get($item->product_id))
                ->filter()
                ->map(fn (Product $product): array => $this->card($product))
                ->values();

            if ($cards->isEmpty()) {
                return null;
            }

            return [
                'id' => $offer->getKey(),
                'kind' => $offer->collection_kind,
                'kind_slug' => self::kindSlug($offer->collection_kind),
                'title' => $offer->title,
                'slug' => $offer->slug,
                'short_description' => $offer->short_description,
                'cover_image_url' => Media::url($offer->cover_image, $this->company),
                'starts_at' => $offer->starts_at?->toIso8601String(),
                'ends_at' => $offer->ends_at?->toIso8601String(),
                'products' => $cards->all(),
            ];
        })->filter()->values()->all();
    }

    /** @return Collection<int, Offer> */
    protected function liveCollections(?string $kind = null): Collection
    {
        // Loaded once per request: collections() and deals() both need them.
        $this->live ??= Offer::query()
            ->liveCollections()
            ->with(['items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->get()
            ->sortBy(fn (Offer $offer): array => [
                array_search($offer->collection_kind, array_values(self::KIND_SLUGS), true),
                -$offer->getKey(),
            ])
            ->values();

        return $kind ? $this->live->where('collection_kind', $kind)->values() : $this->live;
    }

    /**
     * product_id => the live deal that applies (Flash Sale wins over the other
     * kinds when a product is in several). Only items with a deal price count.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function deals(): array
    {
        if ($this->deals !== null) {
            return $this->deals;
        }

        $this->deals = [];

        foreach ($this->liveCollections() as $offer) {
            foreach ($offer->items as $item) {
                if ($item->deal_price === null || isset($this->deals[$item->product_id])) {
                    continue;
                }

                $this->deals[$item->product_id] = [
                    'price' => (float) $item->deal_price,
                    'kind' => $offer->collection_kind,
                    'collection_id' => $offer->getKey(),
                    'ends_at' => $offer->ends_at?->toIso8601String(),
                ];
            }
        }

        return $this->deals;
    }
}
