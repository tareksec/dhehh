<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FundSource extends Model
{
    use BelongsToCompany;

    public const TYPES = [
        'cash' => 'Cash',
        'bank' => 'Bank',
        'mobile_banking' => 'Mobile Banking',
        'wallet' => 'Wallet',
        'petty_cash' => 'Petty Cash',
        'owner_investment' => 'Owner Investment',
        'partner_investment' => 'Partner Investment',
        'business_profit' => 'Business Profit',
        'bank_loan' => 'Bank Loan',
        'customer_advance' => 'Customer Advance',
        'supplier_credit' => 'Supplier Credit',
        'other' => 'Other',
    ];

    /** Types that wrap an existing Account rather than tracking their own balance. */
    public const ACCOUNT_LINKED_TYPES = ['cash', 'bank', 'mobile_banking', 'wallet', 'petty_cash'];

    protected $fillable = [
        'company_id',
        'name',
        'type',
        'account_id',
        'opening_balance',
        'is_active',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    /**
     * The current company's active money accounts (Finance > Accounts of type
     * cash / bank / mobile banking), name by id. Fund-source pickers offer
     * these: the Fund Sources page is retired, so the accounts are the list
     * an admin actually maintains.
     *
     * @return array<int, string>
     */
    public static function moneyAccountOptions(): array
    {
        return Account::query()
            ->manual()
            ->where('is_active', true)
            ->whereIn('type', array_keys(Account::MANUAL_TYPES))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * The fund source that books against this money account, made the first
     * time the account is picked. Null when the id is not an active money
     * account of the current company.
     */
    public static function forAccount(int|string|null $accountId): ?self
    {
        if (blank($accountId)) {
            return null;
        }

        $account = Account::query()
            ->manual()
            ->where('is_active', true)
            ->whereIn('type', array_keys(Account::MANUAL_TYPES))
            ->find($accountId);

        if (! $account) {
            return null;
        }

        return static::query()
            ->where('account_id', $account->getKey())
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first()
            ?? static::query()->create([
                'company_id' => $account->company_id,
                'name' => $account->name,
                'type' => $account->type,
                'account_id' => $account->getKey(),
                'is_active' => true,
            ]);
    }

    /** The money account a stored fund source id books against, for showing it in a picker. */
    public static function accountIdFor(int|string|null $fundSourceId): ?int
    {
        if (blank($fundSourceId)) {
            return null;
        }

        $accountId = static::query()->whereKey($fundSourceId)->value('account_id');

        return $accountId === null ? null : (int) $accountId;
    }

    public function isAccountLinked(): bool
    {
        return in_array($this->type, self::ACCOUNT_LINKED_TYPES, true);
    }

    /**
     * Current balance. Account-linked types (cash/bank/mobile banking/etc.)
     * always read the linked Account's ledger-derived balance — never a
     * separately stored figure, to avoid the two numbers ever diverging.
     * Capital-type sources (investment/profit/loan/credit) track their own
     * running balance via approved vouchers routed through this fund source.
     */
    public function balance(): float
    {
        if ($this->isAccountLinked()) {
            return (float) ($this->account?->current_balance ?? 0);
        }

        $in = (float) $this->vouchers()
            ->where('type', 'credit')
            ->where('status', 'approved')
            ->sum('amount');

        $out = (float) $this->vouchers()
            ->where('type', 'debit')
            ->where('status', 'approved')
            ->sum('amount');

        return (float) $this->opening_balance + $in - $out;
    }
}
