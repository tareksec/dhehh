<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\ValidatesEmailAddress;
use App\Reports\SupplierStatement;
use App\Services\SupplierAccountService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use BelongsToCompany, ValidatesEmailAddress;

    protected $fillable = [
        'company_id',
        'name',
        'phone',
        'email',
        'address',
        'company_name',
        'opening_balance',
        'current_balance',
        'is_active',
        'country',
        'fax',
        'bank_name',
        'bank_address',
        'bank_account_name',
        'bank_account_number',
        'bank_swift_code',
        'bank_extra_note',
        'signature_path',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Supplier $supplier): void {
            static::validateEmailAttribute($supplier);
        });

        static::saved(function (Supplier $supplier): void {
            $supplier->syncCurrentBalance();
        });
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    /** This supplier's account as of today, worked out once per loaded record. */
    public function accountStatement(): SupplierStatement
    {
        return once(fn (): SupplierStatement => app(SupplierAccountService::class)->statement($this));
    }

    /** True once goods came in through Goods Receipts: the supplier then has an account (see SupplierAccountService). */
    public function hasConsignmentAccount(): bool
    {
        return $this->receipts()->exists();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SupplierOrder::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function syncCurrentBalance(): void
    {
        // Cash paid into the supplier account belongs to that account, not to the purchase invoices.
        $balance = (float) $this->opening_balance + (float) $this->purchases()
            ->where('status', 'received')
            ->sum('due_amount') - (float) $this->payments()->where('for_supplier_account', false)->sum('amount');

        if ($this->current_balance != $balance) {
            $this->forceFill(['current_balance' => $balance])->saveQuietly();
        }
    }
}
