<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\PurchaseReceiptService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class PurchaseReceiptItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'purchase_receipt_id',
        'product_id',
        'product_variant_id',
        'cartons',
        'pcs_per_carton',
        'quantity',
        'short_quantity',
        'damage_quantity',
        'unit_cost',
    ];

    protected $casts = [
        'cartons' => 'integer',
        'pcs_per_carton' => 'integer',
        'quantity' => 'integer',
        'short_quantity' => 'integer',
        'damage_quantity' => 'integer',
        'unit_cost' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (PurchaseReceiptItem $item): void {
            $item->company_id ??= $item->receipt?->company_id;
        });

        static::saving(function (PurchaseReceiptItem $item): void {
            app(PurchaseReceiptService::class)->prepare($item);
        });

        static::saved(function (PurchaseReceiptItem $item): void {
            app(PurchaseReceiptService::class)->syncStock($item);
        });

        static::deleting(function (PurchaseReceiptItem $item): void {
            app(PurchaseReceiptService::class)->releaseStock($item);
        });
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** Saved and deleted together with the stock it books: a failed stock change leaves no half-saved row. */
    public function save(array $options = [])
    {
        return DB::transaction(fn () => parent::save($options));
    }

    public function delete()
    {
        return DB::transaction(fn () => parent::delete());
    }

    /** Pieces that reached the godown (sent minus lost on the way). */
    public function arrivedQuantity(): int
    {
        return max(0, (int) $this->quantity - (int) $this->short_quantity);
    }

    /** Pieces sent x the supplier's buy price: what the supplier account owes for this line. */
    public function value(): float
    {
        return (int) $this->quantity * (float) $this->unit_cost;
    }
}
