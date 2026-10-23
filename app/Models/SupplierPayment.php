<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GeneratesSequentialNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierPayment extends Model
{
    use BelongsToCompany, GeneratesSequentialNumber;

    public const METHODS = CustomerPayment::METHODS;

    protected $fillable = [
        'company_id',
        'payment_number',
        'supplier_id',
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
        static::creating(function (SupplierPayment $payment): void {
            $payment->company_id ??= $payment->supplier?->company_id;
            $payment->payment_number ??= static::nextPaymentNumber();
            $payment->payment_date ??= now()->toDateString();
        });

        static::saving(function (SupplierPayment $payment): void {
            if ((float) $payment->amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment amount must be greater than zero.',
                ]);
            }

            $supplier = $payment->supplier;
            $existingAmount = $payment->exists ? (float) $payment->getOriginal('amount') : 0;
            $availablePayable = (float) ($supplier?->current_balance ?? 0) + $existingAmount;

            if ((float) $payment->amount > $availablePayable) {
                throw ValidationException::withMessages([
                    'amount' => __('Supplier payment cannot be greater than the current supplier payable.'),
                ]);
            }

            $ledger = TransactionLedger::query()
                ->where('reference_type', self::class)
                ->where('reference_id', $payment->getKey())
                ->first();

            if ($payment->account?->projectedBalanceAfterOutflow((float) $payment->amount, $ledger?->id) < 0) {
                throw ValidationException::withMessages([
                    'amount' => __('This payment would make the account balance negative.'),
                ]);
            }
        });

        static::saved(function (SupplierPayment $payment): void {
            $payment->syncLedger();
            $payment->supplier?->syncCurrentBalance();

            if ($payment->wasChanged('supplier_id')) {
                Supplier::find($payment->getOriginal('supplier_id'))?->syncCurrentBalance();
            }
        });

        static::deleted(function (SupplierPayment $payment): void {
            $payment->deleteLedger();
            $payment->supplier?->syncCurrentBalance();
        });
    }

    protected function sequentialNumberColumn(): string
    {
        return 'payment_number';
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public static function nextPaymentNumber(): string
    {
        return 'SPAY-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
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
                'type' => 'supplier_payment',
                'direction' => 'out',
                'amount' => $this->amount,
                'transaction_date' => $this->payment_date,
                'note' => "Supplier payment {$this->payment_number}",
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
