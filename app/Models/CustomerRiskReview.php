<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerRiskReview extends Model
{
    use BelongsToCompany;

    public const TYPE_MANAGER = 'manager';

    public const TYPE_OWNER = 'owner';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING => 'Pending',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_REJECTED => 'Rejected',
    ];

    public const TYPES = [
        self::TYPE_MANAGER => 'Manager Approval',
        self::TYPE_OWNER => 'Owner Approval',
    ];

    protected $fillable = [
        'company_id',
        'order_id',
        'customer_id',
        'fraud_check_id',
        'approval_type',
        'risk_level',
        'risk_score',
        'status',
        'reason',
        'review_note',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'risk_score' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function fraudCheck(): BelongsTo
    {
        return $this->belongsTo(FraudCheck::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
