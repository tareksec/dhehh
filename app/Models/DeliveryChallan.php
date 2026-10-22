<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Delivery Challan of the Trade Portal (docs/tasneem-samples/PLAN.md §4): the
 * shop's sale paper. The sale itself is the linked order (Order::SOURCE_TRADE_CHALLAN)
 * — it takes the stock and carries the customer's due and every payment —
 * while this row keeps what the paper shows. Made only through
 * App\Services\DeliveryChallanService.
 */
class DeliveryChallan extends Model
{
    use BelongsToCompany;

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id',
        'order_id',
        'customer_id',
        'challan_number',
        'challan_date',
        'buyer_name',
        'buyer_phone',
        'buyer_address',
        'total',
        'advance_amount',
        'advance_date',
        'advance_note',
        'cash_amount',
        'cash_date',
        'payment_account_id',
        'cheque_number',
        'cheque_bank',
        'due_terms',
        'authorised_name',
        'authorised_designation',
        'authorised_phone',
        'note',
        'status',
        'cancelled_at',
        'cancel_reason',
        'created_by',
    ];

    protected $casts = [
        'challan_date' => 'date',
        'advance_date' => 'date',
        'cash_date' => 'date',
        'cancelled_at' => 'datetime',
        'total' => 'decimal:2',
        'advance_amount' => 'decimal:2',
        'cash_amount' => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryChallanItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payment_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /** The due written on the paper: price less the advance and the cash paid that day. */
    public function dueOnPaper(): float
    {
        return round(max((float) $this->total - (float) $this->advance_amount - (float) $this->cash_amount, 0), 2);
    }

    /** Everything received for this sale so far (the order's payments). */
    public function paidSoFar(): float
    {
        return $this->isCancelled() ? 0.0 : (float) ($this->order?->paid_amount ?? 0);
    }

    /** What the buyer still owes on this challan now. */
    public function dueNow(): float
    {
        return $this->isCancelled() ? 0.0 : (float) ($this->order?->due_amount ?? 0);
    }
}
