<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\SupplierOrderService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class SupplierOrderItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'supplier_order_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'unit_price',
        'supplier_unit_cost',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'supplier_unit_cost' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupplierOrderItem $item): void {
            $item->company_id ??= $item->order?->company_id;
        });

        static::saving(function (SupplierOrderItem $item): void {
            app(SupplierOrderService::class)->prepare($item);
        });

        static::saved(function (SupplierOrderItem $item): void {
            app(SupplierOrderService::class)->syncItem($item);
        });

        static::deleting(function (SupplierOrderItem $item): void {
            app(SupplierOrderService::class)->releaseItem($item);
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class, 'supplier_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** Saved and deleted together with the stock it moves: a failed stock change leaves no half-saved row. */
    public function save(array $options = [])
    {
        return DB::transaction(fn () => parent::save($options));
    }

    public function delete()
    {
        return DB::transaction(fn () => parent::delete());
    }

    /** What the customer pays for this line. */
    public function amount(): float
    {
        return (int) $this->quantity * (float) $this->unit_price;
    }

    /** What this line takes off the supplier's account. */
    public function cost(): float
    {
        return (int) $this->quantity * (float) $this->supplier_unit_cost;
    }
}
