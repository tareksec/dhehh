<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A written 60-day notice that an investor is exiting the relationship (v3
 * P2.4, deed clauses 5-6). Tracking only -- nothing elsewhere is blocked by
 * a pending notice, matching the "soft" approach already used for the
 * investment window (P2.3, Q4).
 */
class InvestmentWithdrawalNotice extends Model
{
    use BelongsToCompany;

    public const NOTICE_PERIOD_DAYS = 60;

    public const STATUSES = ['pending' => 'Pending', 'honored' => 'Honored', 'cancelled' => 'Cancelled'];

    protected $fillable = ['company_id', 'investor_id', 'project_id', 'group_id', 'notice_given_at', 'effective_at', 'reason', 'status'];

    protected $casts = ['notice_given_at' => 'date', 'effective_at' => 'date'];

    protected static function booted(): void
    {
        static::creating(function (self $notice): void {
            $notice->notice_given_at ??= now()->toDateString();
            $notice->effective_at ??= $notice->notice_given_at->copy()->addDays(self::NOTICE_PERIOD_DAYS)->toDateString();
            $notice->status ??= 'pending';
        });
    }

    public function investor()
    {
        return $this->belongsTo(Investor::class);
    }

    public function group()
    {
        return $this->belongsTo(InvestorGroup::class, 'group_id');
    }

    public function project()
    {
        return $this->belongsTo(InvestmentProject::class, 'project_id');
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->effective_at->isPast();
    }

    public function daysUntilEffective(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->effective_at->copy()->startOfDay(), false);
    }
}
