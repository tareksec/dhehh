<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasPaymentProof;
use Illuminate\Database\Eloquent\Model;

class SettlementPayout extends Model
{
    use BelongsToCompany, HasPaymentProof;

    protected $fillable = ['company_id', 'settlement_id', 'investor_id', 'investment_amount', 'principal_returned_amount', 'profit_share_amount', 'capital_loss_amount', 'total_payout', 'payment_status', 'paid_at', 'payment_method', 'recipient_name', 'recipient_bank_name', 'recipient_branch', 'recipient_account_number', 'payment_reference', 'voucher_id'];

    protected $casts = ['investment_amount' => 'decimal:2', 'principal_returned_amount' => 'decimal:2', 'profit_share_amount' => 'decimal:2', 'capital_loss_amount' => 'decimal:2', 'total_payout' => 'decimal:2', 'paid_at' => 'date'];

    /**
     * Principal paid back with this payout. A payout made before groups
     * (NULL) returns the whole `investment_amount`; a member who stays in the
     * group gets only the profit.
     */
    public function principalReturned(): float
    {
        return (float) ($this->principal_returned_amount ?? $this->investment_amount);
    }

    public function settlement()
    {
        return $this->belongsTo(ProjectSettlement::class);
    }

    public function investor()
    {
        return $this->belongsTo(Investor::class);
    }

    public function cycleElection()
    {
        return $this->hasOne(InvestorCycleElection::class, 'settlement_payout_id');
    }

    /** The investor_payout voucher booked when this was marked paid (v3 P2.5), if any. */
    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }
}
