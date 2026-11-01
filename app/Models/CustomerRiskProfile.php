<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerRiskProfile extends Model
{
    use BelongsToCompany;

    public const LEVEL_LOW = 'low';

    public const LEVEL_MEDIUM = 'medium';

    public const LEVEL_HIGH = 'high';

    public const LEVEL_BLACKLISTED = 'blacklisted';

    public const LEVELS = [self::LEVEL_LOW => 'Low Risk', self::LEVEL_MEDIUM => 'Medium Risk', self::LEVEL_HIGH => 'High Risk', self::LEVEL_BLACKLISTED => 'Blacklisted'];

    protected $fillable = ['company_id', 'customer_id', 'phone', 'total_courier_orders', 'delivered_orders', 'returned_orders', 'cancelled_orders', 'success_ratio', 'return_ratio', 'cancel_ratio', 'risk_score', 'risk_level', 'is_blacklisted', 'factors', 'evaluated_at'];

    protected $casts = ['success_ratio' => 'decimal:2', 'return_ratio' => 'decimal:2', 'cancel_ratio' => 'decimal:2', 'risk_score' => 'integer', 'is_blacklisted' => 'boolean', 'factors' => 'array', 'evaluated_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(CustomerRiskEvent::class);
    }
}
