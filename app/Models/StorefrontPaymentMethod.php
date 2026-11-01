<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One admin-manageable checkout payment option. A company can have any
 * number of `manual` (send-money/bank-transfer) channels, plus at most one
 * `cod` row and one `online_gateway` row (the latter reuses whichever
 * gateway/credentials are already configured on StorefrontSetting - this
 * row only controls whether it is *offered* as a normal checkout choice for
 * the full order amount, not the credentials themselves).
 */
class StorefrontPaymentMethod extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    public const TYPE_COD = 'cod';

    public const TYPE_MANUAL = 'manual';

    public const TYPE_ONLINE_GATEWAY = 'online_gateway';

    public const TYPES = [
        self::TYPE_COD => 'Cash on Delivery',
        self::TYPE_MANUAL => 'Manual (Send Money / Bank Transfer)',
        self::TYPE_ONLINE_GATEWAY => 'Online Payment Gateway (Card/Mobile Banking)',
    ];

    protected $fillable = [
        'company_id',
        'type',
        'name',
        'account_number',
        'instructions',
        'is_active',
        'reseller_only',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'reseller_only' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Everything a storefront visitor or the store's own admin list may see: no reseller-only channel. */
    public function scopeForStore(Builder $query): Builder
    {
        return $query->where('reseller_only', false);
    }

    /** The value the checkout radio button/`payment_method` field uses for this row. */
    public function paymentValue(): string
    {
        return $this->type === self::TYPE_MANUAL
            ? 'manual:'.$this->getKey()
            : $this->type;
    }
}
