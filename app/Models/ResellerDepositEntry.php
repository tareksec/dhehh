<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of a reseller's deposit (ResellerOwnCourierService): money
 * sent above an own-courier order's total is credited (positive amount),
 * and the deposit pays the advance of later own-courier orders (negative
 * amount). The balance is the sum of a reseller's entries.
 */
class ResellerDepositEntry extends Model
{
    use BelongsToCompany;

    public const TYPE_OVERPAYMENT = 'overpayment';

    public const TYPE_USED = 'used';

    protected $fillable = [
        'company_id',
        'customer_id',
        'order_id',
        'storefront_payment_id',
        'type',
        'amount',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
