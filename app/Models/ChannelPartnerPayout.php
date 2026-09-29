<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ChannelPartnerPayout extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'settlement_id', 'channel_partner_id', 'amount', 'payment_status', 'paid_at', 'payment_method', 'payment_reference', 'voucher_id'];

    protected $casts = ['amount' => 'decimal:2', 'paid_at' => 'date'];

    public function settlement()
    {
        return $this->belongsTo(ProjectSettlement::class);
    }

    public function channelPartner()
    {
        return $this->belongsTo(ChannelPartner::class);
    }

    /** The investor_payout voucher booked when this was marked paid (v3 P2.5), if any. */
    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }
}
