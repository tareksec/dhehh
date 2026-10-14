<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * What an investor chose to do with one settlement cycle's payout (v3 P2.4,
 * deed clauses 5-6 / channel-partner agreement clause 8): take it out, roll
 * all of it into the next project, or roll part of it and take the rest.
 * Immutable once recorded -- record a new project's payout for the next
 * cycle instead of editing history.
 */
class InvestorCycleElection extends Model
{
    use BelongsToCompany;

    public const ELECTIONS = [
        'withdraw' => 'Withdraw',
        'reinvest' => 'Reinvest (Full)',
        'partial_reinvest' => 'Partial Reinvest',
    ];

    protected $fillable = ['company_id', 'settlement_payout_id', 'election', 'reinvest_amount', 'next_project_id', 'next_group_id', 'reinvestment_id', 'reinvestment_member_id', 'recorded_by'];

    protected $casts = ['reinvest_amount' => 'decimal:2'];

    protected static function booted(): void
    {
        static::saving(function (self $election): void {
            if (! array_key_exists($election->election, self::ELECTIONS)) {
                throw ValidationException::withMessages(['election' => 'Choose withdraw, reinvest, or partial reinvest.']);
            }

            if ($election->election !== 'withdraw' && blank($election->next_project_id) && blank($election->next_group_id)) {
                throw ValidationException::withMessages(['next_project_id' => 'A next project or an open group is required to reinvest.']);
            }

            if ($election->election === 'partial_reinvest' && (float) $election->reinvest_amount <= 0) {
                throw ValidationException::withMessages(['reinvest_amount' => 'Enter how much of the payout is being reinvested.']);
            }
        });

        static::updating(function (self $election): void {
            // A recorded election is history -- void it (delete, only while the
            // payout is still pending) and record a fresh one instead.
            throw new LogicException('An investor cycle election cannot be edited once recorded.');
        });
    }

    public function payout()
    {
        return $this->belongsTo(SettlementPayout::class, 'settlement_payout_id');
    }

    public function nextProject()
    {
        return $this->belongsTo(InvestmentProject::class, 'next_project_id');
    }

    public function nextGroup()
    {
        return $this->belongsTo(InvestorGroup::class, 'next_group_id');
    }

    public function reinvestmentMember()
    {
        return $this->belongsTo(InvestorGroupMember::class, 'reinvestment_member_id');
    }

    public function reinvestment()
    {
        return $this->belongsTo(Investment::class, 'reinvestment_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
