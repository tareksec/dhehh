<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class Investment extends Model
{
    use BelongsToCompany;

    public const PAYMENT_METHODS = ['cash' => 'Cash', 'bkash' => 'bKash', 'bank' => 'Bank', 'other' => 'Other'];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUSES = [self::STATUS_ACTIVE => 'Active', self::STATUS_WITHDRAWN => 'Withdrawn'];

    protected $fillable = ['company_id', 'project_id', 'group_member_id', 'investor_id', 'amount', 'status', 'withdrawn_at', 'withdrawal_voucher_id', 'withdrawal_note', 'payment_method', 'payment_reference', 'invested_at', 'override_reason', 'received_by', 'voucher_id'];

    protected $attributes = ['status' => self::STATUS_ACTIVE];

    protected $casts = ['amount' => 'decimal:2', 'invested_at' => 'date', 'withdrawn_at' => 'date'];

    protected static function booted(): void
    {
        // Owner's fixed rules (2026-09-28): a withdrawal always gets a voucher,
        // and the status is "withdrawn" only while that voucher stands (not in
        // Trash, not rejected, not cancelled). A withdrawn investment is
        // history: never deleted, money never edited. A voided voucher sends it
        // back to active; restoring a trashed one marks it withdrawn again
        // (InvestmentWithdrawalService).
        static::saving(function (self $investment): void {
            if ($investment->status === self::STATUS_WITHDRAWN
                && ! Voucher::query()->whereKey($investment->withdrawal_voucher_id ?? 0)->whereNotIn('status', [Voucher::STATUS_REJECTED, Voucher::STATUS_CANCELLED])->exists()) {
                throw ValidationException::withMessages(['status' => __('An investment can be marked withdrawn only after its withdrawal voucher is created.')]);
            }

            if ($investment->exists && $investment->getOriginal('status') === self::STATUS_WITHDRAWN) {
                $withdrawalVoucher = Voucher::withTrashed()->find($investment->withdrawal_voucher_id);
                $voucherVoided = $withdrawalVoucher && ($withdrawalVoucher->trashed() || $withdrawalVoucher->isVoid());
                $revertedByVoidedVoucher = $investment->status === self::STATUS_ACTIVE
                    && ! $investment->isDirty(['amount', 'project_id', 'investor_id', 'withdrawal_voucher_id'])
                    && $voucherVoided;

                if (! $revertedByVoidedVoucher && $investment->isDirty(['status', 'amount', 'project_id', 'investor_id', 'withdrawal_voucher_id'])) {
                    throw ValidationException::withMessages(['amount' => __('A withdrawn investment is kept as a record and cannot be changed.')]);
                }
            }
        });

        static::deleting(function (self $investment): void {
            if ($investment->status === self::STATUS_WITHDRAWN || filled($investment->withdrawal_voucher_id)) {
                throw ValidationException::withMessages(['status' => __('A withdrawn investment is kept as a record and cannot be deleted.')]);
            }
        });

        static::saving(function (self $investment): void {
            $project = InvestmentProject::query()->find($investment->project_id);
            $investor = Investor::query()->find($investment->investor_id);

            if (! $project || ! $investor || (int) $project->company_id !== (int) $investor->company_id) {
                throw ValidationException::withMessages(['investor_id' => 'Project and investor must belong to the same company.']);
            }

            $investment->company_id = $project->company_id;

            // Money goes into a GROUP, not into one of its projects
            // (16_INVESTOR_GROUPS_PLAN.md). A project of a group holds only the
            // snapshot rows copied from the group's members, each tagged with
            // its member; a loose investment there would skip the group's
            // rules and be booked twice.
            if ($project->group_id) {
                $member = $investment->group_member_id ? InvestorGroupMember::query()->find($investment->group_member_id) : null;

                if (! $member || (int) $member->group_id !== (int) $project->group_id || (int) $member->investor_id !== (int) $investment->investor_id) {
                    throw ValidationException::withMessages(['investor_id' => __('This project belongs to a group. Add the investor to the group instead; the project copies the group\'s members when it starts.')]);
                }
            }

            // A project that never configured an investment window
            // (investment_opens_at/closes_at both null) is unrestricted --
            // this is an opt-in feature (v3 P2.3, Q4).
            if (! $investment->exists && $project->investment_closes_at && ! $project->isWithinInvestmentWindow()) {
                if (! Auth::user()?->hasPermission('investments.override_investment_window')) {
                    throw ValidationException::withMessages(['invested_at' => 'The investment window for this project has closed. Only a user with the override permission may add an investment now.']);
                }

                if (blank($investment->override_reason)) {
                    throw ValidationException::withMessages(['override_reason' => 'A reason is required to add an investment after the window has closed.']);
                }
            }
        });
    }

    public function project()
    {
        return $this->belongsTo(InvestmentProject::class, 'project_id');
    }

    public function investor()
    {
        return $this->belongsTo(Investor::class);
    }

    /** The group deposit this snapshot row was copied from (null for an ungrouped investment). */
    public function groupMember()
    {
        return $this->belongsTo(InvestorGroupMember::class, 'group_member_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function securityInstruments()
    {
        return $this->hasMany(InvestorSecurityInstrument::class);
    }

    public function witnesses()
    {
        return $this->hasMany(InvestmentWitness::class);
    }

    public function documents()
    {
        return $this->morphMany(InvestmentDocument::class, 'documentable');
    }

    public function withdrawalVoucher()
    {
        return $this->belongsTo(Voucher::class, 'withdrawal_voucher_id');
    }

    public function isWithdrawn(): bool
    {
        return $this->status === self::STATUS_WITHDRAWN;
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** The capital_investment voucher booked when this money came in (v3 P2.5), if any. */
    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }
}
