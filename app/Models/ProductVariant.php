<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\ResellerPricing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'product_id',
        'sku',
        'options',
        'cost_price',
        'sale_price',
        'stock',
        'weight_kg',
        'images',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'images' => 'array',
        'cost_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'weight_kg' => 'decimal:3',
        'stock' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProductVariant $variant): void {
            if (! $variant->company_id && $variant->product_id) {
                $variant->company_id = Product::withoutGlobalScopes()
                    ->whereKey($variant->product_id)
                    ->value('company_id');
            }
        });

        // Keep parent product stock in sync with the sum of variant stock.
        static::saved(fn (ProductVariant $variant) => $variant->syncProductStock());
        static::deleted(fn (ProductVariant $variant) => $variant->syncProductStock());
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Human label from options, e.g. "Size: M / Color: Red".
     */
    public function label(): string
    {
        return collect($this->options ?? [])
            ->map(fn ($value, $key) => $key.': '.$value)
            ->implode(' / ');
    }

    /**
     * Effective sale price — falls back to the parent product's price. A
     * storefront visitor shopping through a reseller sees that reseller's
     * price for the variations they sell (see ResellerPricing); everywhere
     * else — admin, reports, jobs — that is never active.
     */
    public function effectiveSalePrice(): float
    {
        return app(ResellerPricing::class)->variantPriceFor($this) ?? $this->baseSalePrice();
    }

    /** The variation's own sale price, ignoring any reseller price. */
    public function baseSalePrice(): float
    {
        return (float) ($this->sale_price ?? $this->product?->sale_price ?? 0);
    }

    /**
     * What a reseller pays for this variation (owner decision 2026-10-14):
     * its wholesale rate -- the variation's own price -- plus the company's
     * reseller cost per unit, the same as a product's lowest wholesale tier
     * price plus that cost. Null when the variation has no price.
     */
    public function resellerCost(float $costPerUnit = 0.0): ?float
    {
        $rate = $this->baseSalePrice();

        return $rate > 0 ? round($rate + max(0, $costPerUnit), 2) : null;
    }

    /**
     * Effective cost price — falls back to the parent product's cost price.
     */
    public function effectiveCostPrice(): float
    {
        return (float) ($this->cost_price ?? $this->product?->cost_price ?? 0);
    }

    /**
     * Effective weight in kg — falls back to the parent product's weight.
     * Null when neither has a weight.
     */
    public function effectiveWeightKg(): ?float
    {
        $weight = $this->weight_kg ?? $this->product?->weight_kg;

        return $weight !== null ? (float) $weight : null;
    }

    public function syncProductStock(): void
    {
        $product = $this->product()->withoutGlobalScopes()->first();

        if (! $product || ! $product->has_variants) {
            return;
        }

        $product->newQueryWithoutScopes()
            ->whereKey($product->getKey())
            ->update([
                'stock' => ProductVariant::withoutGlobalScopes()
                    ->where('product_id', $product->getKey())
                    ->where('is_active', true)
                    ->sum('stock'),
            ]);
    }
}
