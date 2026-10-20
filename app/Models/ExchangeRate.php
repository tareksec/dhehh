<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * A daily rate typed in by staff (17_IMS_IN_PORTAL_PLAN.md, phase R): how many
 * BDT one unit of `currency` was worth on `rate_date`. Used to turn a project
 * cost entered in USD or RMB into the BDT figure the settlement reads.
 */
class ExchangeRate extends Model
{
    use BelongsToCompany;

    public const BASE = 'BDT';

    /** Currencies a cost can be entered in besides BDT. Adding one is a line here and its label. */
    public const CURRENCIES = [
        'USD' => 'US Dollar (USD)',
        'CNY' => 'RMB / Chinese Yuan (CNY)',
    ];

    protected $fillable = ['company_id', 'currency', 'rate_date', 'rate', 'note', 'created_by'];

    protected $casts = ['rate_date' => 'date:Y-m-d', 'rate' => 'decimal:6'];

    protected static function booted(): void
    {
        static::saving(function (self $rate): void {
            if (! array_key_exists((string) $rate->currency, self::CURRENCIES)) {
                throw ValidationException::withMessages(['currency' => __('Choose a currency from the list.')]);
            }

            if ((float) $rate->rate <= 0) {
                throw ValidationException::withMessages(['rate' => __('The rate must be more than zero.')]);
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The rate in force on `$date`: the newest one dated on or before it.
     * Null when no rate was entered yet (staff then type one by hand).
     */
    public static function rateOn(string $currency, CarbonInterface|string $date): ?float
    {
        $row = static::query()
            ->where('currency', $currency)
            ->whereDate('rate_date', '<=', $date instanceof CarbonInterface ? $date->toDateString() : $date)
            ->orderByDesc('rate_date')
            ->first();

        return $row ? (float) $row->rate : null;
    }

    public static function labelFor(?string $currency): string
    {
        return $currency && isset(self::CURRENCIES[$currency]) ? __(self::CURRENCIES[$currency]) : self::BASE;
    }
}
