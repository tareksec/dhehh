<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Investor and channel-partner payouts paid together
 * (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md §9, Phase 5). Every change of
 * status goes through `PayoutBatchService`: draft → approved (by a different
 * user) → exported → completed, or cancelled before completion.
 */
class PayoutBatch extends Model
{
    use BelongsToCompany;

    public const KIND_INVESTOR = 'investor';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_EXPORTED = 'exported';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_EXPORTED => 'Exported',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    /** Batches whose pending items still hold their payables. */
    public const LIVE_STATUSES = [self::STATUS_DRAFT, self::STATUS_APPROVED, self::STATUS_EXPORTED];

    public const FORMAT_MANUAL_LIST = 'manual_list';

    public const FILE_FORMATS = [self::FORMAT_MANUAL_LIST => 'Payment list (CSV)'];

    protected $fillable = ['company_id', 'batch_number', 'kind', 'payout_date', 'fund_source_id', 'file_format', 'notes', 'created_by'];

    protected $attributes = ['status' => self::STATUS_DRAFT, 'kind' => self::KIND_INVESTOR, 'file_format' => self::FORMAT_MANUAL_LIST];

    protected $casts = [
        'payout_date' => 'date',
        'total_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'exported_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PayoutBatchItem::class);
    }

    public function fundSource(): BelongsTo
    {
        return $this->belongsTo(FundSource::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }

    public function isLocked(): bool
    {
        return $this->status !== self::STATUS_DRAFT;
    }

    public function label(): string
    {
        return __('Batch #:number', ['number' => $this->batch_number]);
    }

    public static function statusLabel(?string $status): string
    {
        return __(self::STATUSES[$status] ?? (string) $status);
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            self::STATUS_APPROVED, self::STATUS_EXPORTED => 'info',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_CANCELLED => 'gray',
            default => 'warning',
        };
    }
}
