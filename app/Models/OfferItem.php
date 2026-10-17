<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'offer_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'deal_price',
        'sort_order',
    ];

    protected $casts = [
        'deal_price' => 'decimal:2',
        'quantity' => 'integer',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (OfferItem $item): void {
            if (! $item->company_id && $item->offer_id) {
                $item->company_id = Offer::withoutGlobalScopes()->whereKey($item->offer_id)->value('company_id');
            }
        });
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
