<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * A figure of a supplier account kept outside the ledgers: the cash, shipping
 * or ad cost of the old workbook, or a later correction. It adds to what we
 * have given the supplier; it never touches an account balance.
 */
class SupplierAccountAdjustment extends Model
{
    use BelongsToCompany;

    public const TYPE_CASH = 'cash';

    public const TYPE_SHIPPING = 'shipping';

    public const TYPE_AD_COST = 'ad_cost';

    public const TYPES = [
        self::TYPE_CASH => 'Cash we gave',
        self::TYPE_SHIPPING => 'Shipping we paid',
        self::TYPE_AD_COST => 'Ad cost we paid',
    ];

    protected $fillable = [
        'company_id',
        'supplier_id',
        'entry_date',
        'type',
        'amount',
        'external_reference',
        'note',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupplierAccountAdjustment $entry): void {
            $entry->entry_date ??= now()->toDateString();
        });

        static::saving(function (SupplierAccountAdjustment $entry): void {
            if (! array_key_exists((string) $entry->type, self::TYPES)) {
                throw ValidationException::withMessages(['type' => __('Please choose what this entry is.')]);
            }

            if ((float) $entry->amount <= 0) {
                throw ValidationException::withMessages(['amount' => __('Amount must be more than zero.')]);
            }
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
