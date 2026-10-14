<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of a reseller's deposit (ResellerOwnCourierService): money
 * sent above an own-courier order's total is credited (positive amount),
 * the deposit pays the advance of later own-courier orders (negative
 * amount), and what a cancelled order took from it goes back (positive
 * amount, owner rule 2026-10-12). A returned order takes a return charge
 * from it (negative amount; unlike a staff deduction it may leave the balance
 * below zero, which later credits make up first). The balance is the sum of a
 * reseller's entries.
 */
class ResellerDepositEntry extends Model
{
    use BelongsToCompany;

    public const TYPE_OVERPAYMENT = 'overpayment';

    public const TYPE_USED = 'used';

    public const TYPE_REFUND = 'refund';

    public const TYPE_ADJUSTMENT = 'adjustment';

    /** The charge taken from the deposit when a reseller's order is returned (owner rule 2026-10-14). */
    public const TYPE_RETURN_CHARGE = 'return_charge';

    /** Shown in the admin deposit list; run through __() where displayed. */
    public const TYPES = [
        self::TYPE_OVERPAYMENT => 'Sent above an order total',
        self::TYPE_USED => 'Used on an order',
        self::TYPE_REFUND => 'Returned (order cancelled)',
        self::TYPE_ADJUSTMENT => 'Adjusted by staff',
        self::TYPE_RETURN_CHARGE => 'Return charge (order returned)',
    ];

    protected $fillable = [
        'company_id',
        'customer_id',
        'order_id',
        'storefront_payment_id',
        'type',
        'amount',
        'note',
        'created_by',
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

    /** The member of staff behind a manual adjustment; NULL for entries the system made. */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
