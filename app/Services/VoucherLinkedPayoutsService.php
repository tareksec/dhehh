<?php

namespace App\Services;

use App\Models\ChannelPartnerPayout;
use App\Models\ProjectSettlement;
use App\Models\ResellerPayoutRequest;
use App\Models\SettlementPayout;
use App\Models\Voucher;

/**
 * Payouts marked paid together with a voucher (investor settlement payouts,
 * channel-partner payouts, reseller withdrawals). Owner (2026-09-28): when
 * that voucher goes to Trash the payout is unpaid again; restoring the
 * voucher marks it paid again. The voucher link is kept so a restore can find
 * the payout.
 */
class VoucherLinkedPayoutsService
{
    /**
     * The voucher went to Trash, or was rejected or cancelled (owner,
     * 2026-09-28): the payout that was marked paid with it is unpaid again.
     */
    public function voucherVoided(Voucher $voucher): void
    {
        $settlementIds = [];

        foreach ([SettlementPayout::class, ChannelPartnerPayout::class] as $model) {
            $model::query()->where('voucher_id', $voucher->getKey())->where('payment_status', 'paid')->get()
                ->each(function ($payout) use (&$settlementIds): void {
                    $payout->update(['payment_status' => 'pending', 'paid_at' => null]);
                    $settlementIds[] = $payout->settlement_id;
                });
        }

        // A settlement that was fully paid out has an unpaid payout again.
        ProjectSettlement::query()->whereKey(array_unique($settlementIds))->where('status', 'paid_out')->get()
            ->each(fn (ProjectSettlement $settlement) => $settlement->update(['status' => 'confirmed']));

        ResellerPayoutRequest::query()->where('voucher_id', $voucher->getKey())->where('status', ResellerPayoutRequest::STATUS_PAID)->get()
            ->each(fn (ResellerPayoutRequest $request) => $request->update(['status' => ResellerPayoutRequest::STATUS_APPROVED, 'paid_at' => null]));
    }

    public function voucherTrashed(Voucher $voucher): void
    {
        $this->voucherVoided($voucher);
    }

    public function voucherRestored(Voucher $voucher): void
    {
        if ($voucher->isVoid()) {
            return;
        }

        $paidAt = $voucher->created_at?->toDateString() ?? now()->toDateString();
        $settlementIds = [];

        foreach ([SettlementPayout::class, ChannelPartnerPayout::class] as $model) {
            $model::query()->where('voucher_id', $voucher->getKey())->where('payment_status', 'pending')->get()
                ->each(function ($payout) use (&$settlementIds, $paidAt): void {
                    $payout->update(['payment_status' => 'paid', 'paid_at' => $paidAt]);
                    $settlementIds[] = $payout->settlement_id;
                });
        }

        ProjectSettlement::query()->whereKey(array_unique($settlementIds))->where('status', 'confirmed')->get()
            ->each(function (ProjectSettlement $settlement): void {
                if (! $settlement->payouts()->where('payment_status', 'pending')->exists()
                    && ! $settlement->channelPartnerPayouts()->where('payment_status', 'pending')->exists()) {
                    $settlement->update(['status' => 'paid_out']);
                }
            });

        ResellerPayoutRequest::query()->where('voucher_id', $voucher->getKey())->where('status', ResellerPayoutRequest::STATUS_APPROVED)->get()
            ->each(fn (ResellerPayoutRequest $request) => $request->update(['status' => ResellerPayoutRequest::STATUS_PAID, 'paid_at' => $voucher->created_at ?? now()]));
    }
}
