<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PurchaseItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'purchase_id',
        'product_id',
        'hs_code',
        'spec_note',
        'quantity',
        'short_quantity',
        'damaged_quantity',
        'unit_cost',
        'fob_unit_price_usd',
        'subtotal',
        'allocated_cost',
        'landed_unit_cost',
        'net_weight_kg',
        'gross_weight_kg',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'short_quantity' => 'integer',
        'damaged_quantity' => 'integer',
        'unit_cost' => 'decimal:2',
        'fob_unit_price_usd' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'allocated_cost' => 'decimal:2',
        'landed_unit_cost' => 'decimal:2',
        'net_weight_kg' => 'decimal:3',
        'gross_weight_kg' => 'decimal:3',
    ];

    protected static function booted(): void
    {
        // The lines of a purchase in Trash are hidden with it (a hard delete used to remove them
        // through the foreign key); Restore shows them again. See Purchase and plan T5a.
        static::addGlobalScope('purchaseNotInTrash', function (Builder $query): void {
            $query->whereIn(
                $query->getModel()->qualifyColumn('purchase_id'),
                Purchase::withoutGlobalScopes()->withoutTrashed()->select('id'),
            );
        });

        static::creating(function (PurchaseItem $item): void {
            $item->company_id ??= $item->purchase?->company_id;
        });

        static::saving(function (PurchaseItem $item): void {
            $item->short_quantity = (int) $item->short_quantity;
            $item->damaged_quantity = (int) $item->damaged_quantity;

            if ($item->short_quantity < 0 || $item->damaged_quantity < 0) {
                throw ValidationException::withMessages([
                    'short_quantity' => __('Short and damaged quantities cannot be negative.'),
                ]);
            }

            if ($item->short_quantity + $item->damaged_quantity > (int) $item->quantity) {
                throw ValidationException::withMessages([
                    'short_quantity' => __('Short plus damaged quantity cannot be more than the purchased quantity.'),
                ]);
            }

            $item->subtotal = (int) $item->quantity * (float) $item->unit_cost;
        });

        static::saved(function (PurchaseItem $item): void {
            $item->purchase?->syncTotalsAndStock();
        });

        static::deleted(function (PurchaseItem $item): void {
            $item->purchase?->syncTotalsAndStock();
        });
    }

    /**
     * Units that actually reached stock: billed quantity minus what never
     * arrived (short) and what arrived unsellable (damaged).
     */
    public function sellableQuantity(): int
    {
        return max((int) $this->quantity - (int) $this->short_quantity - (int) $this->damaged_quantity, 0);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
