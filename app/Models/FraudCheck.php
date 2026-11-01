<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FraudCheck extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'order_id', 'customer_id', 'phone', 'risk_score', 'risk_level', 'factors', 'is_blacklisted', 'checked_by'];

    protected $casts = ['risk_score' => 'integer', 'factors' => 'array', 'is_blacklisted' => 'boolean'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
