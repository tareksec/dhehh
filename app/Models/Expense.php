<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GeneratesSequentialNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Expense extends Model
{
    use BelongsToCompany, GeneratesSequentialNumber, SoftDeletes;

    /** What ZamZam paid on a supplier's behalf; both count in that supplier's account. */
    public const SUPPLIER_COST_SHIPPING = 'shipping';

    public const SUPPLIER_COST_AD = 'ad';

    public const SUPPLIER_COST_TYPES = [
        self::SUPPLIER_COST_SHIPPING => 'Shipping',
        self::SUPPLIER_COST_AD => 'Ad cost',
    ];

    protected $fillable = [
        'company_id',
        'expense_number',
        'expense_category_id',
        'supplier_id',
        'supplier_cost_type',
        'account_id',
        'amount',
        'expense_date',
        'reference',
        'note',
        'expense_scan_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (Expense $expense): void {
            $expense->company_id ??= $expense->account?->company_id ?? $expense->category?->company_id;
            $expense->expense_number ??= static::nextExpenseNumber();
            $expense->expense_date ??= now()->toDateString();
        });

        static::saving(function (Expense $expense): void {
            // A supplier and a cost type only make sense together.
            if (blank($expense->supplier_id)) {
                $expense->supplier_cost_type = null;
            } elseif (! array_key_exists((string) $expense->supplier_cost_type, self::SUPPLIER_COST_TYPES)) {
                throw ValidationException::withMessages([
                    'supplier_cost_type' => __('Please choose what this cost is for the supplier.'),
                ]);
            }

            if ($expense->exists && $expense->isDirty('expense_number')) {
                throw ValidationException::withMessages([
                    'expense_number' => 'Expense number is generated automatically and cannot be changed.',
                ]);
            }

            if ((float) $expense->amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Expense amount must be greater than zero.',
                ]);
            }

            $ledger = TransactionLedger::query()
                ->where('reference_type', self::class)
                ->where('reference_id', $expense->getKey())
                ->first();

            if ($expense->account?->projectedBalanceAfterOutflow((float) $expense->amount, $ledger?->id) < 0) {
                throw ValidationException::withMessages([
                    'amount' => __('This expense would make the account balance negative.'),
                ]);
            }
        });

        static::saved(function (Expense $expense): void {
            $expense->syncLedger();
        });

        static::deleted(function (Expense $expense): void {
            $expense->deleteLedger();
        });
    }

    protected function sequentialNumberColumn(): string
    {
        return 'expense_number';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** The AI Expense Scan whose photo is this expense's proof, if it came from one. */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(ExpenseScan::class, 'expense_scan_id');
    }

    public static function nextExpenseNumber(): string
    {
        return 'EXP-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
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
                'type' => 'expense',
                'direction' => 'out',
                'amount' => $this->amount,
                'transaction_date' => $this->expense_date,
                'note' => "Expense {$this->expense_number}",
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
