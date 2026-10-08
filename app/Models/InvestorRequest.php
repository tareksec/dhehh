<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A request an investor sends from the portal
 * (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md Phase 4). Staff approve or
 * reject it; approval runs the existing flow (withdrawal notice, cycle
 * election, new investment) and links the record it created as `result`.
 */
class InvestorRequest extends Model
{
    use BelongsToCompany;

    public const TYPE_TOP_UP = 'top_up';

    public const TYPE_WITHDRAWAL = 'withdrawal';

    public const TYPE_REINVEST = 'reinvest';

    public const TYPE_SUPPORT = 'support';

    public const TYPES = [
        self::TYPE_TOP_UP => 'Add money (top-up)',
        self::TYPE_WITHDRAWAL => 'Withdrawal notice',
        self::TYPE_REINVEST => 'Payout choice (withdraw or reinvest)',
        self::TYPE_SUPPORT => 'Question / support',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING => 'Pending',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_REJECTED => 'Rejected',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    protected $fillable = ['company_id', 'portal_account_id', 'investor_id', 'channel_partner_id', 'type', 'project_id', 'settlement_payout_id', 'amount', 'payload', 'message'];

    protected $attributes = ['status' => self::STATUS_PENDING];

    protected $casts = ['amount' => 'decimal:2', 'payload' => 'array', 'decided_at' => 'datetime'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(InvestorPortalAccount::class, 'portal_account_id');
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(InvestmentProject::class, 'project_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(SettlementPayout::class, 'settlement_payout_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function result(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public static function typeLabel(?string $type): string
    {
        return __(self::TYPES[$type] ?? (string) $type);
    }

    public static function statusLabel(?string $status): string
    {
        return __(self::STATUSES[$status] ?? (string) $status);
    }
}
