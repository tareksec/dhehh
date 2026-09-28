<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reseller's (Customer with reseller_status='approved') pick of one
 * product for their storefront. Deliberately NOT BelongsToCompany -- it
 * has no independent company context of its own, only through its parent
 * customer_id/product_id (both of which are already company-scoped), same
 * reasoning already used for other pivot-style records in this codebase.
 */
class ResellerProduct extends Model
{
    protected $fillable = [
        'customer_id',
        'product_id',
        'price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'price' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
