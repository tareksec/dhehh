<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GeneratesSequentialNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerPayment extends Model
{
    use BelongsToCompany, GeneratesSequentialNumber;

    public const METHODS = [
        'cash' => 'Cash',
        'bank' => 'Bank',
        'mobile_banking' => 'Mobile Banking',
        'card' => 'Card',
        'other' => 'Other',
    ];

    protected $fillable = [
        'company_id',
        'payment_number',
        'customer_id',
        'account_id',
        'amount',
        'payment_date',
        'method',
        'reference',
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (CustomerPayment $payment): void {
            $payment->company_id ??= $payment->customer?->company_id;
            $payment->payment_number ??= static::nextPaymentNumber();
            $payment->payment_date ??= now()->toDateString();
        });

        static::saving(function (CustomerPayment $payment): void {
            if ((float) $payment->amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment amount must be greater than zero.',
                ]);
            }

            $customer = $payment->customer;
            $existingAmount = $payment->exists ? (float) $payment->getOriginal('amount') : 0;
            $availableDue = (float) ($customer?->current_balance ?? 0) + $existingAmount;

            if ((float) $payment->amount > $availableDue) {
                throw ValidationException::withMessages([
                    'amount' => __('Customer payment cannot be greater than the current customer due.'),
                ]);
            }
        });

        static::saved(function (CustomerPayment $payment): void {
            $payment->syncLedger();
            $payment->customer?->syncCurrentBalance();

            if ($payment->wasChanged('customer_id')) {
                Customer::find($payment->getOriginal('customer_id'))?->syncCurrentBalance();
            }
        });

        static::deleted(function (CustomerPayment $payment): void {
            $payment->deleteLedger();
            $payment->customer?->syncCurrentBalance();
        });
    }

    protected function sequentialNumberColumn(): string
    {
        return 'payment_number';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public static function nextPaymentNumber(): string
    {
        return 'CPAY-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
    }

    public function syncLedger(): void
    {
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
                'transaction_date' => $this->payment_date,
                'note' => "Customer payment {$this->payment_number}",
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
}
