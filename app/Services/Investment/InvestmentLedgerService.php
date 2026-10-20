<?php

namespace App\Services\Investment;

use App\Models\ChannelPartnerPayout;
use App\Models\FundSource;
use App\Models\Investment;
use App\Models\InvestorGroupMember;
use App\Models\SettlementPayout;
use App\Models\User;
use App\Models\Voucher;
use App\Services\VoucherService;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Books investor capital-in and payout-out through the existing Voucher /
 * FundSource system (v3 P2.5, Q5) -- no new ledger engine, no separate
 * liability account (owner confirmed; `capital_investment` /
 * `investor_payout` are both in Voucher::NON_EXPENSE_TRANSACTION_TYPES).
 * Both fund sources are opt-in per project: a project that never configures
 * one creates no vouchers at all, and a voucher-creation failure here must
 * never break the Investment/payout save it's attached to.
 */
class InvestmentLedgerService
{
    public function __construct(private VoucherService $vouchers) {}

    public function recordInvestmentReceived(Investment $investment): void
    {
        // A group project's rows are snapshots of the group's members. The
        // money was booked once, when the member joined the group
        // (`recordGroupDepositReceived`) -- never a second voucher here.
        // Money still waiting to be confirmed (IMS v2) is booked when it is confirmed.
        if ($investment->group_member_id || $investment->isPending()) {
            return;
        }

        $project = $investment->project;
        $fundSource = $project?->receiving_fund_source_id ? FundSource::query()->find($project->receiving_fund_source_id) : null;

        if (! $fundSource || (float) $investment->amount <= 0) {
            return;
        }

        $submitter = User::query()->find($investment->received_by);

        if (! $submitter) {
            return;
        }

        $this->submitQuietly($investment, function () use ($investment, $fundSource, $submitter): Voucher {
            return $this->vouchers->submit([
                'company_id' => $investment->company_id,
                'type' => Voucher::TYPE_CREDIT,
                'transaction_type' => 'capital_investment',
                'amount' => (float) $investment->amount,
                'fund_source_id' => $fundSource->id,
                'account_id' => $fundSource->account_id,
                'payment_method' => $investment->payment_method,
                'transaction_id' => $investment->payment_reference,
                'confirmation_source' => 'manual',
                'purpose' => "Mudarabah investor capital — {$investment->investor?->name} — {$investment->project?->project_code}",
            ], $submitter);
        });
    }

    /** The receiving voucher for a deposit into a group (16_INVESTOR_GROUPS_PLAN.md §3.3). */
    public function recordGroupDepositReceived(InvestorGroupMember $member): void
    {
        $group = $member->group;
        $fundSource = $group?->receiving_fund_source_id ? FundSource::query()->find($group->receiving_fund_source_id) : null;

        if (! $fundSource || (float) $member->amount <= 0 || $member->isPending()) {
            return;
        }

        $submitter = User::query()->find($member->received_by);

        if (! $submitter) {
            return;
        }

        $this->submitQuietly($member, function () use ($member, $group, $fundSource, $submitter): Voucher {
            return $this->vouchers->submit([
                'company_id' => $member->company_id,
                'type' => Voucher::TYPE_CREDIT,
                'transaction_type' => 'capital_investment',
                'amount' => (float) $member->amount,
                'fund_source_id' => $fundSource->id,
                'account_id' => $fundSource->account_id,
                'payment_method' => $member->payment_method,
                'transaction_id' => $member->payment_reference,
                'confirmation_source' => 'manual',
                'purpose' => "Mudarabah investor capital — {$member->investor?->name} — {$group->name} ({$group->group_code})",
            ], $submitter);
        });
    }

    public function recordInvestorPayoutPaid(SettlementPayout $payout, int $paidByUserId): void
    {
        $fundSource = $this->payoutFundSource($payout->settlement?->project);
        $submitter = User::query()->find($paidByUserId);

        if (! $fundSource || ! $submitter || (float) $payout->total_payout <= 0) {
            return;
        }

        $this->submitQuietly($payout, function () use ($payout, $fundSource, $submitter): Voucher {
            return $this->vouchers->submit([
                'company_id' => $payout->company_id,
                'type' => Voucher::TYPE_DEBIT,
                'transaction_type' => 'investor_payout',
                'amount' => (float) $payout->total_payout,
                'fund_source_id' => $fundSource->id,
                'account_id' => $fundSource->account_id,
                'payment_method' => $payout->payment_method,
                'confirmation_source' => 'manual',
                'purpose' => "Mudarabah investor payout — {$payout->recipient_name}",
            ], $submitter);
        });
    }

    public function recordChannelPartnerPayoutPaid(ChannelPartnerPayout $payout, int $paidByUserId): void
    {
        $fundSource = $this->payoutFundSource($payout->settlement?->project);
        $submitter = User::query()->find($paidByUserId);

        if (! $fundSource || ! $submitter || (float) $payout->amount <= 0) {
            return;
        }

        $this->submitQuietly($payout, function () use ($payout, $fundSource, $submitter): Voucher {
            return $this->vouchers->submit([
                'company_id' => $payout->company_id,
                'type' => Voucher::TYPE_DEBIT,
                'transaction_type' => 'investor_payout',
                'amount' => (float) $payout->amount,
                'fund_source_id' => $fundSource->id,
                'account_id' => $fundSource->account_id,
                'payment_method' => $payout->payment_method,
                'confirmation_source' => 'manual',
                'purpose' => "Mudarabah channel-partner payout — {$payout->channelPartner?->name}",
            ], $submitter);
        });
    }

    private function payoutFundSource(?object $project): ?FundSource
    {
        if (! $project || ! $project->payout_fund_source_id) {
            return null;
        }

        return FundSource::query()->find($project->payout_fund_source_id);
    }

    /**
     * Submits the voucher and stamps `voucher_id` back onto the record --
     * quietly, so this never fires another round of model events. Any
     * failure (e.g. the fund source's linked account was deleted) is
     * swallowed after being reported: booking a voucher is a bookkeeping
     * nicety here, never a reason to lose the underlying investment/payout.
     */
    private function submitQuietly(Investment|InvestorGroupMember|SettlementPayout|ChannelPartnerPayout $record, \Closure $submit): void
    {
        try {
            $voucher = $submit();
            $record->forceFill(['voucher_id' => $voucher->id])->saveQuietly();
        } catch (ValidationException|Throwable $exception) {
            report($exception);
        }
    }
}
