<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * A single recorded installment against an order (advance, partial, final,
 * etc.) — the order-scoped counterpart to CustomerPayment, which instead
 * funds the customer's running ledger balance and isn't tied to one order.
 * Order::paid_amount / due_amount are kept in sync with the sum of these
 * rows via Order::recalculatePaidAmount(), mirroring how CustomerPayment
 * keeps Customer::current_balance in sync (see CustomerPayment::booted()).
 */
class OrderPayment extends Model
{
    use BelongsToCompany;

    public const TYPE_ADVANCE = 'advance';

    public const TYPE_PARTIAL = 'partial';

    public const TYPE_FINAL = 'final';

    public const TYPES = [
        self::TYPE_ADVANCE => 'Advance',
        self::TYPE_PARTIAL => 'Partial',
        self::TYPE_FINAL => 'Final',
    ];

    // Reuses the same method taxonomy already used for customer-level
    // payments (CustomerPayment::METHODS) rather than inventing a
    // separate, more granular per-gateway enum — keeps reporting/UI
    // consistent across both payment surfaces.
    public const METHODS = CustomerPayment::METHODS;

    protected $fillable = [
        'company_id',
        'order_id',
        'type',
        'method',
        'account_id',
        'amount',
        'note',
        'received_by',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (OrderPayment $payment): void {
            $payment->company_id ??= $payment->order?->company_id;
            $payment->type ??= self::TYPE_ADVANCE;
            $payment->method ??= 'cash';
            $payment->paid_at ??= now()->toDateString();
            // Only a staff user receives money (a reseller signed in to the
            // portal is a Customer on another guard).
            $payment->received_by ??= Auth::user() instanceof User ? Auth::id() : null;
        });

        // The account the money was received into decides the method, so
        // the two can never disagree (e.g. "bKash" account => Mobile Banking).
        static::saving(function (OrderPayment $payment): void {
            if ($payment->account_id && $payment->isDirty('account_id')) {
                $type = Account::query()->whereKey($payment->account_id)->value('type');
                $payment->method = array_key_exists((string) $type, self::METHODS) ? $type : 'other';
            }
        });

        static::saving(function (OrderPayment $payment): void {
            if ((float) $payment->amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment amount must be greater than zero.',
                ]);
            }
        });

        static::saved(function (OrderPayment $payment): void {
            $payment->syncLedger();
            $payment->order?->recalculatePaidAmount();
        });

        static::deleted(function (OrderPayment $payment): void {
            $payment->deleteLedger();
            $payment->order?->recalculatePaidAmount();
        });
    }

    /**
     * Posts the payment into the chosen account's ledger (money in), so
     * that account's balance and the finance reports update by themselves
     * — the same way CustomerPayment::syncLedger() does. A payment with no
     * account (older rows, gateway payments) posts nothing.
     */
    public function syncLedger(): void
    {
        if (! $this->account_id) {
            $this->deleteLedger();

            return;
        }

        $orderNumber = $this->order?->order_number;

        TransactionLedger::query()->updateOrCreate(
            [
                'reference_type' => self::class,
                'reference_id' => $this->getKey(),
            ],
            [
                'account_id' => $this->account_id,
                'company_id' => $this->company_id,
                'type' => 'customer_payment',
                'direction' => 'in',
                'amount' => $this->amount,
                'transaction_date' => $this->paid_at,
                'note' => trim("Order payment {$orderNumber}"),
            ],
        );
    }

    protected function deleteLedger(): void
    {
        TransactionLedger::query()
            ->where('reference_type', self::class)
            ->where('reference_id', $this->getKey())
            ->get()
            ->each
            ->delete();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * "Payment Method" choices for recording a customer payment: the
     * company's own active money accounts (Accounts page — Cash, bKash,
     * bank…), never the system accounts like Inventory Value.
     *
     * @return array<int, string>
     */
    public static function accountOptions(): array
    {
        return Account::query()
            ->manual()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
