<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ProjectSettlement extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['draft' => 'Draft', 'confirmed' => 'Confirmed', 'paid_out' => 'Paid out'];

    public const OUTCOMES = [
        'profit' => 'Profit',
        'loss_investor_borne' => 'Loss — borne by investors',
        'loss_manager_borne' => 'Loss — borne by company',
    ];

    /** Settled from the sales revenue staff entered (the original way). */
    public const BASIS_SALES = 'sales';

    /** Settled from a profit staff entered by hand, with no sales needed. */
    public const BASIS_MANUAL = 'manual';

    protected $fillable = ['company_id', 'project_id', 'total_revenue', 'total_cost', 'net_profit', 'investor_pool_amount', 'channel_partner_amount', 'company_net_amount', 'annualized_return_percent', 'rate_per_lac', 'outcome', 'basis', 'loss_reason', 'status', 'settled_by', 'settled_at'];

    protected $casts = ['total_revenue' => 'decimal:2', 'total_cost' => 'decimal:2', 'net_profit' => 'decimal:2', 'investor_pool_amount' => 'decimal:2', 'channel_partner_amount' => 'decimal:2', 'company_net_amount' => 'decimal:2', 'annualized_return_percent' => 'decimal:2', 'rate_per_lac' => 'decimal:2', 'settled_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $settlement): void {
            // Computed figures are never hand-edited — in draft or confirmed.
            // A wrong settlement is corrected by voiding it and settling again,
            // not by editing the numbers in place.
            $immutable = ['project_id', 'total_revenue', 'total_cost', 'net_profit', 'investor_pool_amount', 'channel_partner_amount', 'company_net_amount', 'annualized_return_percent', 'rate_per_lac', 'outcome', 'basis', 'loss_reason', 'settled_by', 'settled_at'];

            if ($settlement->isDirty($immutable)) {
                throw ValidationException::withMessages(['settlement' => 'Settlement figures are immutable. Void the settlement and recalculate instead.']);
            }

            // Status moves forward: draft → confirmed → paid_out.
            if ($settlement->isDirty('status')) {
                $allowed = [
                    'draft' => ['confirmed'],
                    'confirmed' => ['paid_out'],
                    'paid_out' => [],
                ];
                $from = $settlement->getOriginal('status');

                // The one step back (owner, 2026-09-28): a payout's voucher went
                // to Trash, so that payout is unpaid again.
                $payoutUnpaidAgain = $from === 'paid_out' && $settlement->status === 'confirmed'
                    && ($settlement->payouts()->where('payment_status', 'pending')->exists()
                        || $settlement->channelPartnerPayouts()->where('payment_status', 'pending')->exists());

                if (! $payoutUnpaidAgain && ! in_array($settlement->status, $allowed[$from] ?? [], true)) {
                    throw ValidationException::withMessages(['status' => "A settlement cannot move from {$from} to {$settlement->status}."]);
                }
            }
        });
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** True when the profit was entered by hand, not worked out from sales. */
    public function isManualBasis(): bool
    {
        return $this->basis === self::BASIS_MANUAL;
    }

    public function hasPaidPayouts(): bool
    {
        return $this->payouts()->where('payment_status', 'paid')->exists()
            || $this->channelPartnerPayouts()->where('payment_status', 'paid')->exists();
    }

    public function project()
    {
        return $this->belongsTo(InvestmentProject::class, 'project_id');
    }

    public function settledBy()
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function payouts()
    {
        return $this->hasMany(SettlementPayout::class, 'settlement_id');
    }

    public function channelPartnerPayouts()
    {
        return $this->hasMany(ChannelPartnerPayout::class, 'settlement_id');
    }

    public function documents()
    {
        return $this->morphMany(InvestmentDocument::class, 'documentable');
    }
}
