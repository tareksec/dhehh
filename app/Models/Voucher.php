<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GeneratesSequentialNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Voucher extends Model
{
    // Owner (2026-09-28): a voucher is never deleted directly. It goes to
    // Trash first, and only a user with `voucher.delete` may delete it
    // permanently from the Trash page (VoucherTrashService).
    use BelongsToCompany, GeneratesSequentialNumber, SoftDeletes;

    public const TYPE_CREDIT = 'credit';

    public const TYPE_DEBIT = 'debit';

    public const FORM_TYPE_FUND_TRANSFER = 'fund_transfer';

    public const TYPES = [
        self::TYPE_CREDIT => 'Credit Voucher',
        self::TYPE_DEBIT => 'Debit Voucher',
    ];

    public const FORM_TYPES = [
        ...self::TYPES,
        self::FORM_TYPE_FUND_TRANSFER => 'Fund Transfer',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING => 'Pending',
        self::STATUS_VERIFIED => 'Verified',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_REJECTED => 'Rejected',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    public const TRANSACTION_TYPES = [
        'inventory_purchase' => 'Inventory Purchase',
        'business_expense' => 'Business Expense',
        'capital_investment' => 'Capital Investment',
        'owner_withdrawal' => 'Owner Withdrawal',
        'supplier_payment' => 'Supplier Payment',
        'customer_payment' => 'Customer Payment',
        'loan' => 'Loan',
        'refund' => 'Refund',
        'asset_purchase' => 'Asset Purchase',
        'fund_transfer' => 'Fund Transfer',
        'investor_payout' => 'Investor Payout (Mudarabah)',
        'other' => 'Other',
    ];

    public const CONFIRMATION_SOURCES = [
        'telegram' => 'Telegram',
        'whatsapp' => 'WhatsApp',
        'messenger' => 'Messenger',
        'sms' => 'SMS',
        'phone_call' => 'Phone Call',
        'email' => 'Email',
        'manual' => 'Manual',
    ];

    /** Transaction types that create an Asset, never an Expense (Rule 1). */
    public const NON_EXPENSE_TRANSACTION_TYPES = [
        'inventory_purchase', 'capital_investment', 'owner_withdrawal',
        'asset_purchase', 'loan', 'fund_transfer', 'investor_payout',
    ];

    protected $fillable = [
        'company_id',
        'voucher_number',
        'type',
        'status',
        'transaction_type',
        'amount',
        'currency',
        'customer_id',
        'supplier_id',
        'order_id',
        'purchase_id',
        'expense_category_id',
        'fund_source_id',
        'account_id',
        'payment_method',
        'transaction_id',
        'confirmation_source',
        'purpose',
        'remarks',
        'submitted_by',
        'verified_by',
        'approved_by',
        'verified_at',
        'approved_at',
        'rejection_reason',
        'resulting_model_type',
        'resulting_model_id',
        'deleted_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'verified_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    protected function sequentialNumberColumn(): string
    {
        return 'voucher_number';
    }

    public static function nextVoucherNumber(string $type, ?Company $company = null): string
    {
        $company ??= app()->bound('company.context') ? app('company.context')->company() : null;
        $company ??= Company::defaultCompany();
        $prefix = $type === self::TYPE_CREDIT ? 'CV' : 'DV';
        $companyPrefix = $company?->invoice_prefix ?: 'GEN';

        do {
            $number = "{$prefix}-{$companyPrefix}-".now()->format('Ymd').'-'.Str::upper(Str::random(5));
        } while (self::query()
            ->withTrashed()
            ->when($company, fn ($query) => $query->where('company_id', $company->getKey()))
            ->where('voucher_number', $number)
            ->exists());

        return $number;
    }

    public function isCredit(): bool
    {
        return $this->type === self::TYPE_CREDIT;
    }

    public function isDebit(): bool
    {
        return $this->type === self::TYPE_DEBIT;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class)->withTimestamps();
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    public function fundSource(): BelongsTo
    {
        return $this->belongsTo(FundSource::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(VoucherAttachment::class);
    }

    /**
     * The downstream record created on approval (CustomerPayment / Expense /
     * SupplierPayment / a future Mudarabah Investment), resolved from the
     * lightweight resulting_model_type/id pointer.
     */
    public function resultingModel(): ?Model
    {
        if (! $this->resulting_model_type || ! $this->resulting_model_id) {
            return null;
        }

        return $this->resulting_model_type::query()->find($this->resulting_model_id);
    }
}
