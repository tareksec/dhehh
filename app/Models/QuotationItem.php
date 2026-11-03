<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'quotation_id', 'product_id', 'product_variant_id',
        'variant_label', 'quantity', 'unit_price', 'subtotal',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (QuotationItem $item): void {
            $item->company_id ??= $item->quotation?->company_id;
        });

        static::saving(function (QuotationItem $item): void {
            $item->subtotal = (int) $item->quantity * (float) $item->unit_price;
        });

        static::saved(function (QuotationItem $item): void {
            $item->quotation?->recalculateTotal();
        });

        static::deleted(function (QuotationItem $item): void {
            $item->quotation?->recalculateTotal();
        });
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
