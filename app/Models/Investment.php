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

    protected $fillable = ['company_id', 'project_id', 'investor_id', 'amount', 'status', 'withdrawn_at', 'withdrawal_voucher_id', 'withdrawal_note', 'payment_method', 'payment_reference', 'invested_at', 'override_reason', 'received_by', 'voucher_id'];

    protected $attributes = ['status' => self::STATUS_ACTIVE];

    protected $casts = ['amount' => 'decimal:2', 'invested_at' => 'date', 'withdrawn_at' => 'date'];

    protected static function booted(): void
    {
        // Owner's fixed rule (2026-09-28): a withdrawal always gets a voucher,
        // and the status changes only once that voucher exists. A withdrawn
        // investment is history: it is never deleted and its money is not edited.
        static::saving(function (self $investment): void {
            if ($investment->status === self::STATUS_WITHDRAWN && blank($investment->withdrawal_voucher_id)) {
                throw ValidationException::withMessages(['status' => __('An investment can be marked withdrawn only after its withdrawal voucher is created.')]);
            }

            if ($investment->exists && $investment->getOriginal('status') === self::STATUS_WITHDRAWN
                && $investment->isDirty(['status', 'amount', 'project_id', 'investor_id', 'withdrawal_voucher_id'])) {
                throw ValidationException::withMessages(['amount' => __('A withdrawn investment is kept as a record and cannot be changed.')]);
            }
        });

        static::deleting(function (self $investment): void {
            if ($investment->status === self::STATUS_WITHDRAWN) {
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

            $otherPartnerId = $project->investments()
                ->when($investment->exists, fn ($query) => $query->whereKeyNot($investment->getKey()))
                ->join('investors', 'investors.id', '=', 'investments.investor_id')
                ->whereNotNull('investors.channel_partner_id')
                ->value('investors.channel_partner_id');

            if ($otherPartnerId && $investor->channel_partner_id && (int) $otherPartnerId !== (int) $investor->channel_partner_id) {
                throw ValidationException::withMessages(['investor_id' => 'A project currently supports only one channel partner.']);
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
