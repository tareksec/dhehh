<?php

namespace App\Ims\Capital;

use App\Models\CapitalReturn;
use App\Models\Investment;
use App\Models\InvestorGroupMember;
use App\Models\SettlementPayout;

/**
 * The one place capital is worked out (IMS-V2/TRD.md §4.3). Home, portal,
 * statements, reminders and the to-do list all call this; nobody writes
 * "amount − returned" on their own.
 *
 *   capitalLoss        = min(amount, Σ loss taken from this money in confirmed settlements)
 *   returnableCapital  = amount − capitalLoss
 *   outstandingCapital = max(0, returnableCapital − Σ capital paid back)
 *
 * Capital is paid back by a capital return (maturity or exit) or with a
 * paid settlement payout (the principal a payout carries). An investor
 * never owes money: the loss never goes beyond what was put in.
 *
 * A settlement payout is one row per investor per project, so its loss and
 * principal are spread over that investor's rows in the project by amount.
 *
 * Example (owner, 2026-10-19): investor % = 35, project loss ৳1,00,000,
 * one investor with all the capital → loss ৳35,000; ৳10,00,000 invested →
 * returnable ৳9,65,000.
 */
class CapitalCalculator
{
    /** @return array{amount: float, loss: float, returnable: float, returned: float, outstanding: float} */
    public function forInvestment(Investment $investment): array
    {
        $amount = round((float) $investment->amount, 2);

        if ($investment->isPending()) {
            return $this->figures($amount, 0.0, 0.0, pending: true);
        }

        [$loss, $principalPaid] = $this->fromPayouts($investment);
        $returned = $principalPaid + (float) CapitalReturn::query()->paid()->where('investment_id', $investment->getKey())->sum('amount');

        // A withdrawn investment's capital went back with its withdrawal voucher.
        if ($investment->isWithdrawn()) {
            $returned = max($returned, $amount - min($amount, $loss));
        }

        return $this->figures($amount, $loss, $returned);
    }

    /** @return array{amount: float, loss: float, returnable: float, returned: float, outstanding: float} */
    public function forMember(InvestorGroupMember $member): array
    {
        $amount = round((float) $member->amount, 2);

        if ($member->isPending()) {
            return $this->figures($amount, 0.0, 0.0, pending: true);
        }

        $loss = 0.0;
        $returned = 0.0;

        foreach ($member->investments()->get() as $row) {
            [$rowLoss, $rowPrincipal] = $this->fromPayouts($row);
            $loss += $rowLoss;
            $returned += $rowPrincipal;
        }

        $returned += (float) CapitalReturn::query()->paid()->where('group_member_id', $member->getKey())->sum('amount');

        if ($member->isWithdrawn()) {
            $returned = max($returned, $amount - min($amount, $loss));
        }

        return $this->figures($amount, $loss, $returned);
    }

    /**
     * This row's share of the loss taken from capital, and of the principal
     * paid back, by the settlement payout of its project.
     *
     * @return array{0: float, 1: float}
     */
    public function fromPayouts(Investment $row): array
    {
        $payout = SettlementPayout::query()
            ->where('investor_id', $row->investor_id)
            ->whereHas('settlement', fn ($query) => $query->where('project_id', $row->project_id)->whereIn('status', ['confirmed', 'paid_out']))
            ->first();

        if (! $payout || (float) $payout->investment_amount <= 0) {
            return [0.0, 0.0];
        }

        $share = (float) $row->amount / (float) $payout->investment_amount;
        $loss = round((float) $payout->capital_loss_amount * $share, 2);

        // Principal travels with a paid payout only. A group member who stays
        // in the group gets no principal (principal_returned_amount 0).
        $principal = $payout->payment_status === 'paid' ? round($payout->principalReturned() * $share, 2) : 0.0;

        return [$loss, $principal];
    }

    /** @return array{amount: float, loss: float, returnable: float, returned: float, outstanding: float, pending: bool} */
    private function figures(float $amount, float $loss, float $returned, bool $pending = false): array
    {
        $loss = round(min($amount, max(0.0, $loss)), 2);
        $returnable = round($amount - $loss, 2);
        $returned = round(max(0.0, $returned), 2);

        return [
            'amount' => $amount,
            'loss' => $loss,
            'returnable' => $returnable,
            'returned' => $returned,
            'outstanding' => $pending ? 0.0 : round(max(0.0, $returnable - $returned), 2),
            'pending' => $pending,
        ];
    }
}
