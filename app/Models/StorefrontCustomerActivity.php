<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontCustomerActivity extends Model
{
    use BelongsToCompany;

    public const TYPE_ACCOUNT_CREATED = 'account_created';

    public const TYPE_LOGIN = 'login';

    public const TYPE_PROFILE_UPDATED = 'profile_updated';

    public const TYPE_PASSWORD_CHANGED = 'password_changed';

    public const TYPE_PASSWORD_RESET = 'password_reset';

    public const TYPE_ORDER_PLACED = 'order_placed';

    public const TYPE_ORDER_REORDERED = 'order_reordered';

    protected $fillable = [
        'company_id',
        'customer_id',
        'type',
        'title',
        'description',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
