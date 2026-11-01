<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class ProductCarousel extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'title',
        'subtitle',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProductCarousel $carousel): void {
            $carousel->sort_order ??= 0;
            $carousel->is_active ??= true;
        });
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_carousel_product')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderBy('product_carousel_product.sort_order')
            ->orderBy('product_carousel_product.id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Active carousels for the current company context, with only
     * storefront-visible products eager loaded. Carousels that end up
     * with zero visible products are dropped.
     */
    public static function forHomepage(): Collection
    {
        return static::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with(['products' => function ($query): void {
                $query->with('category')
                    ->where('is_active', true)
                    ->where('status', Product::STATUS_AVAILABLE);
            }])
            ->get()
            ->filter(fn (ProductCarousel $carousel): bool => $carousel->products->isNotEmpty())
            ->values();
    }
}
