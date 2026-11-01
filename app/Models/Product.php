<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\ResellerPricing;
use App\Services\WebsiteWebhookDispatcher;
use App\Support\StorefrontListingCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Product extends Model
{
    use BelongsToCompany;

    /**
     * Set by the website API's PATCH /products/{sku} controller right
     * before saving a change it just made, so the outbound webhook doesn't
     * immediately echo that same change back to the website that sent it —
     * same pattern as Order::$suppressWooCommercePush.
     */
    public bool $suppressWebsiteWebhook = false;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_COMING_SOON = 'coming_soon';

    public const STATUSES = [
        self::STATUS_AVAILABLE => 'Available',
        self::STATUS_COMING_SOON => 'Coming Soon',
    ];

    public const COMING_SOON_PURCHASE_PRODUCTS = Purchase::CHINA_TO_BD_COST_FIELDS;

    protected $fillable = [
        'company_id',
        'stock_pool_id',
        'supplier_id',
        'name',
        'slug',
        'description',
        'sku',
        'barcode',
        'hs_code',
        'unit',
        'pcs_per_carton',
        'weight_kg',
        'brand',
        'cost_price',
        'sale_price',
        'price',
        'stock',
        'reorder_level',
        'moq',
        'tier_prices',
        'vat_rate',
        'is_active',
        'status',
        'is_preorder',
        'preorder_advance_percent',
        'image',
        'gallery_images',
        'variant_attributes',
        'has_variants',
        'category_id',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'price' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'weight_kg' => 'decimal:3',
        'is_active' => 'boolean',
        'moq' => 'integer',
        'tier_prices' => 'array',
        'is_preorder' => 'boolean',
        'preorder_advance_percent' => 'integer',
        'gallery_images' => 'array',
        'variant_attributes' => 'array',
        'has_variants' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Product $product): void {
            if (filled($product->slug)) {
                $product->slug = Str::slug($product->slug);

                return;
            }

            $product->slug = static::uniqueSlug($product);
        });

        static::saved(function (Product $product): void {
            StorefrontListingCache::forgetCompany($product->company_id);

            // Stock is deliberately excluded here: it only ever changes via
            // a StockMovement (StockMovementService::syncProductStock()
            // writes the derived total through a query-builder mass update,
            // which never fires this hook anyway) — StockMovement::booted()
            // is the single dispatch point for stock-driven webhooks.
            if (! $product->suppressWebsiteWebhook && $product->wasChanged(['price', 'sale_price'])) {
                app(WebsiteWebhookDispatcher::class)->dispatchProductUpdated($product);
            }
        });
        static::deleted(fn (Product $product) => StorefrontListingCache::forgetCompany($product->company_id));
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    public function reviews()
    {
        return $this->hasMany(ProductReview::class);
    }

    public function activeVariants()
    {
        return $this->variants()->where('is_active', true);
    }

    /**
     * Every photo of the product: the main image, the gallery and the images
     * of its active variants, without repeats. Used by the reseller portal's
     * product details and its "Download photos" zip.
     *
     * @return list<string>
     */
    public function allImagePaths(): array
    {
        return collect([$this->image, ...($this->gallery_images ?? [])])
            ->merge($this->variants->where('is_active', true)->flatMap(fn (ProductVariant $variant): array => $variant->images ?? []))
            ->filter(fn ($path): bool => is_string($path) && $path !== '')
            ->unique()
            ->values()
            ->all();
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function stockPool(): BelongsTo
    {
        return $this->belongsTo(StockPool::class);
    }

    /** The supplier this product is mainly bought from (drives the supplier account). */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** True when this product is the pool member whose ledger holds the real stock (see StockPool). */
    public function isPoolSource(): bool
    {
        return $this->stock_pool_id !== null
            && (int) $this->stockPool?->source_product_id === (int) $this->getKey();
    }

    public function purchaseItems()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function getSellingPriceAttribute(): float
    {
        // A storefront visitor shopping through a reseller sees that
        // reseller's price for their catalog products (see ResellerPricing);
        // everywhere else — admin, reports, jobs — this is never active.
        return app(ResellerPricing::class)->priceFor($this) ?? $this->baseSellingPrice();
    }

    /** The product's own selling price, ignoring any reseller price. */
    public function baseSellingPrice(): float
    {
        return (float) ($this->sale_price ?? $this->price ?? 0);
    }

    /**
     * A reseller's wholesale buying rate: the lowest wholesale tier price.
     * Null when the product has no tiers or has variants (variant lines
     * keep their own variant price, so they aren't reseller-priced).
     */
    public function resellerWholesaleRate(): ?float
    {
        if ($this->has_variants) {
            return null;
        }

        $tiers = $this->rawNormalizedTiers();

        return $tiers === [] ? null : (float) min(array_column($tiers, 'price'));
    }

    /**
     * Percentage of the line subtotal payable online in advance when this
     * product is fulfilled as a pre-order. Defaults to full payment.
     */
    public function preorderAdvancePercent(): int
    {
        $percent = (int) ($this->preorder_advance_percent ?? 100);

        return min(100, max(1, $percent));
    }

    /**
     * Minimum order quantity for the storefront. Products without an
     * explicit MOQ behave as before (minimum 1).
     */
    public function effectiveMoq(): int
    {
        return max(1, (int) ($this->moq ?? 1));
    }

    /**
     * Wholesale tiers as a validated, min_qty-ascending list of
     * ['min_qty' => int, 'price' => float] rows. Invalid rows are dropped.
     */
    public function normalizedTiers(): array
    {
        // Hidden while a reseller price applies: the lowest tier is the
        // reseller's own buying rate, not something their customers see.
        if (app(ResellerPricing::class)->priceFor($this) !== null) {
            return [];
        }

        return $this->rawNormalizedTiers();
    }

    protected function rawNormalizedTiers(): array
    {
        return collect($this->tier_prices ?? [])
            ->map(fn ($tier): ?array => is_array($tier) && (int) ($tier['min_qty'] ?? 0) > 0 && is_numeric($tier['price'] ?? null)
                ? ['min_qty' => (int) $tier['min_qty'], 'price' => (float) $tier['price']]
                : null)
            ->filter()
            ->sortBy('min_qty')
            ->values()
            ->all();
    }

    /**
     * Unit price for a given quantity: the deepest matching wholesale tier,
     * falling back to the regular selling price. Tier prices apply to the
     * base product price only; variant lines keep their own variant price.
     */
    public function priceForQuantity(int $quantity): float
    {
        $price = $this->selling_price;

        foreach ($this->normalizedTiers() as $tier) {
            if ($quantity >= $tier['min_qty']) {
                $price = $tier['price'];
            }
        }

        return $price;
    }

    public static function ensureComingSoonPurchaseProducts(): void
    {
        foreach (self::COMING_SOON_PURCHASE_PRODUCTS as $name) {
            $product = self::query()->firstOrNew(['name' => $name]);

            $product->fill([
                'sku' => $product->sku ?: self::comingSoonSku($name),
                'price' => $product->price ?? 0,
                'sale_price' => $product->sale_price ?? 0,
                'cost_price' => $product->cost_price ?? 0,
                'stock' => $product->stock ?? 0,
                'unit' => $product->unit ?: 'pcs',
                'reorder_level' => $product->reorder_level ?? 0,
                'vat_rate' => $product->vat_rate ?? 0,
                'is_active' => false,
                'status' => self::STATUS_COMING_SOON,
            ]);

            $product->save();
        }
    }

    protected static function comingSoonSku(string $name): string
    {
        $baseSku = 'CS-'.Str::upper(Str::slug($name));
        $sku = $baseSku;
        $suffix = 2;

        while (self::query()->where('sku', $sku)->exists()) {
            $sku = "{$baseSku}-{$suffix}";
            $suffix++;
        }

        return $sku;
    }

    protected static function uniqueSlug(Product $product): string
    {
        $base = Str::slug($product->name) ?: Str::slug($product->sku) ?: 'product';
        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->when($product->company_id, fn ($query) => $query->where('company_id', $product->company_id))
            ->where('slug', $slug)
            ->when($product->exists, fn ($query) => $query->whereKeyNot($product->getKey()))
            ->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * $referenceType/$referenceId/$reason/$note let a caller other than the
     * admin product form (currently: the website API's stock-sync endpoint,
     * see App\Http\Controllers\Api\V1\ProductController) stamp the resulting
     * StockMovement with its own origin instead of `self::class` — this is
     * how StockMovement::booted()'s webhook dispatch trigger tells an
     * API-originated adjustment apart from an admin-made one.
     */
    public function setStockFromProductForm(
        int $targetStock,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $reason = null,
        ?string $note = null,
    ): void {
        $this->refresh();

        $currentStock = (int) $this->stock;

        if ($targetStock === $currentStock) {
            return;
        }

        if ($currentStock === 0 && $targetStock > 0 && ! $this->stockMovements()->exists()) {
            StockMovement::query()->create([
                'product_id' => $this->getKey(),
                'type' => 'opening',
                'quantity' => $targetStock,
                'reference_type' => $referenceType ?? self::class,
                'reference_id' => $referenceId ?? $this->getKey(),
                'reason' => $reason ?? 'Opening stock setup',
                'note' => $note ?? 'Opening stock from product form',
            ]);

            return;
        }

        StockMovement::query()->create([
            'product_id' => $this->getKey(),
            'type' => 'adjustment',
            'quantity' => $targetStock - $currentStock,
            'reference_type' => $referenceType ?? self::class,
            'reference_id' => $referenceId ?? $this->getKey(),
            'reason' => $reason ?? 'Product form stock correction',
            'note' => $note ?? 'Stock adjustment from product form',
        ]);
    }

    public function isLowStock(): bool
    {
        return $this->stock <= $this->reorder_level;
    }
}
