<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\MaturityDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * One deposit of one investor into a group (16_INVESTOR_GROUPS_PLAN.md §3.2).
 * The money is booked once, here (receiving voucher). Each project of the
 * group copies its active members into `investments` rows (the snapshot the
 * settlement engine reads), tagged with `group_member_id`.
 *
 * Rows can be added only while the group is Open; amount and investor are
 * fixed once the group runs.
 */
class InvestorGroupMember extends Model
{
    use BelongsToCompany;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_WITHDRAWN = 'withdrawn';

    /** IMS v2 (PRD FR-22): recorded, the money not confirmed yet; not copied into any project. */
    public const STATUS_PENDING = 'pending';

    public const STATUSES = [self::STATUS_PENDING => 'Waiting for the money', self::STATUS_ACTIVE => 'Active', self::STATUS_WITHDRAWN => 'Withdrawn'];

    protected $fillable = [
        'company_id', 'group_id', 'investor_id', 'amount', 'invested_at', 'payment_method', 'payment_reference',
        'received_by', 'voucher_id', 'status', 'withdrawn_at', 'withdrawal_voucher_id', 'withdrawal_note', 'leaves_after_sequence', 'notes', 'paperwork',
        'tenor_months', 'maturity_date', 'confirmed_at', 'confirmed_by',
    ];

    protected $attributes = ['status' => self::STATUS_ACTIVE];

    protected $casts = ['amount' => 'decimal:2', 'invested_at' => 'date', 'withdrawn_at' => 'date', 'paperwork' => 'array', 'leaves_after_sequence' => 'integer', 'tenor_months' => 'integer', 'maturity_date' => 'date', 'maturity_reminder_sent_at' => 'datetime', 'confirmed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (self $member): void {
            if ($member->isDirty(['tenor_months', 'invested_at'])) {
                $member->maturity_date = MaturityDate::from($member->invested_at, $member->tenor_months);
            }
        });

        static::saving(function (self $member): void {
            $group = InvestorGroup::query()->find($member->group_id);
            $investor = Investor::query()->find($member->investor_id);

            if (! $group || ! $investor || (int) $group->company_id !== (int) $investor->company_id) {
                throw ValidationException::withMessages(['investor_id' => __('Group and investor must belong to the same company.')]);
            }

            $member->company_id = $group->company_id;

            if ((float) $member->amount <= 0) {
                throw ValidationException::withMessages(['amount' => __('Enter an amount greater than zero.')]);
            }

            if (! $member->exists && ! $group->isOpen()) {
                throw ValidationException::withMessages(['group_id' => __('New members can be added only while the group is Open.')]);
            }

            if ($member->exists && $member->isDirty(['amount', 'investor_id', 'group_id']) && ! $group->isOpen()) {
                throw ValidationException::withMessages(['amount' => __('The amount and investor of a member cannot change once the group is running.')]);
            }
        });

        static::deleting(function (self $member): void {
            if (! $member->group?->isOpen() || $member->investments()->exists() || filled($member->withdrawal_voucher_id)) {
                throw ValidationException::withMessages(['status' => __('A member is kept as a record once the group has run or the member has withdrawn.')]);
            }
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(InvestorGroup::class, 'group_id');
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    /** The per-project snapshot rows copied from this member. */
    public function investments(): HasMany
    {
        return $this->hasMany(Investment::class, 'group_member_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function withdrawalVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'withdrawal_voucher_id');
    }

    public function isWithdrawn(): bool
    {
        return $this->status === self::STATUS_WITHDRAWN;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function capitalReturns(): HasMany
    {
        return $this->hasMany(CapitalReturn::class, 'group_member_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
