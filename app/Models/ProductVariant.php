<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
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
     * Effective sale price — falls back to the parent product's price.
     */
    public function effectiveSalePrice(): float
    {
        return (float) ($this->sale_price ?? $this->product?->sale_price ?? 0);
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
