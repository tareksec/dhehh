<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * Capital paid back to an investor (IMS-V2/PRD.md FR-53, FR-55): for an
 * ungrouped investment or a group deposit, in part or in full. Never
 * deleted; a mistake is voided with a reason (App\Ims\Capital\CapitalReturnService).
 */
class CapitalReturn extends Model
{
    use BelongsToCompany;

    public const STATUS_PAID = 'paid';

    public const STATUS_VOIDED = 'voided';

    protected $fillable = ['company_id', 'investor_id', 'investment_id', 'group_member_id', 'amount', 'returned_on', 'method', 'reference', 'proof_path', 'status', 'void_reason', 'voided_by', 'voided_at', 'voucher_id', 'created_by'];

    protected $casts = ['amount' => 'decimal:2', 'returned_on' => 'date', 'voided_at' => 'datetime'];

    protected $attributes = ['status' => self::STATUS_PAID];

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw ValidationException::withMessages(['status' => __('A capital return is kept as a record. Void it instead.')]);
        });
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }

    public function groupMember(): BelongsTo
    {
        return $this->belongsTo(InvestorGroupMember::class, 'group_member_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function scopePaid($query)
    {
        return $query->where('status', self::STATUS_PAID);
    }
}
