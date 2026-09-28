<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\ResellerCommissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'order_id',
        'product_id',
        'product_variant_id',
        'variant_label',
        'quantity',
        'unit_price',
        'unit_cost',
        'reseller_base_price',
        'subtotal',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'reseller_base_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (OrderItem $item): void {
            $item->company_id ??= $item->order?->company_id;
        });

        static::saving(function (OrderItem $item): void {
            if ($item->unit_cost === null) {
                $item->unit_cost = $item->product?->cost_price ?? 0;
            }

            $item->subtotal = (int) $item->quantity * (float) $item->unit_price;
        });

        static::saved(function (OrderItem $item): void {
            $item->order?->syncTotalsStockAndCustomerBalance();

            if ($item->order) {
                app(ResellerCommissionService::class)->sync($item->order);
            }
        });

        static::deleted(function (OrderItem $item): void {
            $item->order?->syncTotalsStockAndCustomerBalance();

            if ($item->order) {
                app(ResellerCommissionService::class)->sync($item->order);
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
