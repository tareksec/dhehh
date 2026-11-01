<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\ResellerNotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reseller's withdrawal request against their payable commission
 * balance. The reseller requests from /account/reseller; staff approve,
 * reject or mark it paid from the admin Resellers cluster.
 */
class ResellerPayoutRequest extends Model
{
    use BelongsToCompany;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_REQUESTED => 'Requested',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_PAID => 'Paid',
        self::STATUS_REJECTED => 'Rejected',
    ];

    /** Every status except rejected holds (or has spent) balance. */
    public const BALANCE_HOLDING_STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_PAID,
    ];

    public const METHODS = [
        'bkash' => 'bKash',
        'nagad' => 'Nagad',
        'rocket' => 'Rocket',
        'bank' => 'Bank transfer',
    ];

    protected $fillable = [
        'company_id',
        'customer_id',
        'amount',
        'method',
        'account_details',
        'status',
        'payment_reference',
        'admin_note',
        'processed_by',
        'voucher_id',
        'approved_at',
        'paid_at',
    ];

    protected static function booted(): void
    {
        // The "your withdrawal was paid" SMS (wording set in Reseller Settings; empty = not sent).
        static::updated(function (ResellerPayoutRequest $request): void {
            if ($request->wasChanged('status') && $request->status === self::STATUS_PAID) {
                $reseller = Customer::withoutGlobalScopes()->find($request->customer_id);

                if ($reseller) {
                    app(ResellerNotificationService::class)->notify(
                        ResellerNotificationService::EVENT_PAYOUT_PAID, $reseller, (int) $request->getKey(), 'payout-'.$request->getKey(),
                    );
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id')->withTrashed();
    }

    /** The Finance debit voucher booked when this was marked paid. */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
