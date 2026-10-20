<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The system's own profit figure for a finished cycle (IMS-V2/PRD.md FR-45).
 * Only a suggestion: nothing is shared out until staff approve it, which
 * runs the normal Calculate & Settle with these (or hand-entered) numbers.
 */
class ProfitProposal extends Model
{
    use BelongsToCompany;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DISMISSED = 'dismissed';

    /** The project was settled another way before anyone decided. */
    public const STATUS_STALE = 'stale';

    public const STATUSES = [
        self::STATUS_PENDING => 'Waiting for a decision',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_DISMISSED => 'Dismissed',
        self::STATUS_STALE => 'No longer needed',
    ];

    protected $fillable = ['company_id', 'project_id', 'period_start', 'period_end', 'figures', 'status', 'decided_by', 'decided_at', 'reason', 'settlement_id'];

    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'figures' => 'array', 'decided_at' => 'datetime'];

    protected $attributes = ['status' => self::STATUS_PENDING];

    public function project(): BelongsTo
    {
        return $this->belongsTo(InvestmentProject::class, 'project_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ProjectSettlement::class, 'settlement_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public static function statusLabel(?string $status): string
    {
        return __(self::STATUSES[$status] ?? (string) $status);
    }
}
