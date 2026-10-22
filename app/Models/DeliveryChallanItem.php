<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a Delivery Challan as written on the paper: a description, the
 * quantity with its unit (set, pcs, ctn…) and remarks. A line with a product
 * takes that product's stock; a line with a price adds quantity × price to the
 * challan's total. Lines without a price (the stand, feeders and CAMs given
 * with a machine) still leave the stock.
 */
class DeliveryChallanItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'delivery_challan_id',
        'product_id',
        'description',
        'quantity',
        'unit',
        'unit_price',
        'remarks',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function challan(): BelongsTo
    {
        return $this->belongsTo(DeliveryChallan::class, 'delivery_challan_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lineTotal(): float
    {
        return $this->unit_price === null ? 0.0 : round((int) $this->quantity * (float) $this->unit_price, 2);
    }
}
