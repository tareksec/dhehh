<?php

namespace App\Services\Investment;

use App\Ims\Notifications\ImsNotifier;
use App\Models\ChannelPartnerPayout;
use App\Models\Company;
use App\Models\PayoutBatchItem;
use App\Models\ProjectSettlement;
use App\Models\SettlementPayout;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks an investor or channel-partner payout paid
 * (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md §9.5). The single-row "Mark as
 * Paid" action and payout batches both come through here, so the payout
 * status, its voucher and the settlement's `paid_out` status are handled in
 * one place.
 */
class PayoutCompletionService
{
    public function __construct(protected InvestmentLedgerService $ledger) {}

    /**
     * One payout paid by hand. Books its own payout voucher (when the project
     * has a payout fund source), as before.
     *
     * @param  array{payment_method?: ?string, payment_reference?: ?string}  $data
     */
    public function markPaid(SettlementPayout|ChannelPartnerPayout $payout, User $by, array $data = []): void
    {
        if ($payout->payment_status !== 'pending' || $payout->settlement?->status !== 'confirmed') {
            throw ValidationException::withMessages(['payment_status' => __('Only a pending payout of a confirmed settlement can be marked paid.')]);
        }

        if ($batch = $this->liveBatchItem($payout)?->batch) {
            throw ValidationException::withMessages(['payment_status' => __('This payout is in :batch. Record it as paid there, or take it out of the batch first.', ['batch' => $batch->label()])]);
        }

        DB::transaction(fn () => $this->applyPaid($payout, [...$data, 'paid_at' => now()->toDateString()]));

        if ($payout instanceof SettlementPayout) {
            $this->ledger->recordInvestorPayoutPaid($payout, (int) $by->getKey());
        } else {
            $this->ledger->recordChannelPartnerPayoutPaid($payout, (int) $by->getKey());
        }
    }

    /**
     * Sets the payout paid and closes the settlement when nothing is left
     * unpaid. Books no voucher: the caller does (a batch books one for all).
     *
     * @param  array<string, mixed>  $data
     */
    public function applyPaid(SettlementPayout|ChannelPartnerPayout $payout, array $data): void
    {
        $payout->update([
            ...array_filter($data, fn ($value): bool => $value !== null),
            'payment_status' => 'paid',
            'paid_at' => $data['paid_at'] ?? now()->toDateString(),
        ]);

        if ($payout->settlement) {
            $this->syncSettlementStatus($payout->settlement);
        }

        // IMS v2: "money sent to you" (one payout or a whole batch).
        if ($payout instanceof SettlementPayout && $payout->investor && Company::query()->find($payout->company_id)?->imsIsLive() && (float) $payout->total_payout > 0) {
            app(ImsNotifier::class)->send('payout_paid', $payout->investor, ['amount' => (float) $payout->total_payout]);
        }
    }

    public function syncSettlementStatus(ProjectSettlement $settlement): void
    {
        if ($settlement->status === 'confirmed'
            && ! $settlement->payouts()->where('payment_status', 'pending')->exists()
            && ! $settlement->channelPartnerPayouts()->where('payment_status', 'pending')->exists()) {
            $settlement->update(['status' => 'paid_out']);
        }
    }

    /** The pending item of a payout batch that still holds this payout, if any. */
    public function liveBatchItem(SettlementPayout|ChannelPartnerPayout $payout): ?PayoutBatchItem
    {
        return PayoutBatchItem::query()
            ->where('payable_type', $payout->getMorphClass())
            ->where('payable_id', $payout->getKey())
            ->live()
            ->with('batch')
            ->first();
    }
}
