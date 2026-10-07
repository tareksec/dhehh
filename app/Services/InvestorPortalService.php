<?php

namespace App\Services;

use App\Models\ChannelPartner;
use App\Models\ChannelPartnerPayout;
use App\Models\Investment;
use App\Models\InvestmentDocument;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\InvestorCycleElection;
use App\Models\InvestorPortalAccount;
use App\Models\SettlementPayout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every query the investor portal makes starts here
 * (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md §4.5), pinned to the signed-in
 * account's own investor or channel partner. The shared investment models
 * have no portal scope of their own because the admin side uses them across
 * investors, so no portal page builds its own query.
 *
 * Settlement figures are shown only once staff confirm the settlement: a
 * draft can still be voided and recalculated, so it is not the investor's
 * result yet.
 */
class InvestorPortalService
{
    /** Settlement statuses the portal treats as final. */
    public const FINAL_SETTLEMENT_STATUSES = ['confirmed', 'paid_out'];

    /**
     * Project papers an investor in that project may download (deed clause 3:
     * the project's contract and its cost evidence). Settlement sheets,
     * investor reports and bank slips can name other investors and are left out.
     */
    public const SHARED_PROJECT_DOCUMENT_CATEGORIES = [InvestmentDocument::CATEGORY_PROJECT_CONTRACT, 'cost_receipt', 'purchase_document'];

    public function investments(InvestorPortalAccount $account): Builder
    {
        return Investment::query()->where('investor_id', $account->investor_id ?? 0);
    }

    /** Payouts on settlements staff have confirmed. */
    public function payouts(InvestorPortalAccount $account): Builder
    {
        return SettlementPayout::query()
            ->where('investor_id', $account->investor_id ?? 0)
            ->whereHas('settlement', fn (Builder $query) => $query->whereIn('status', self::FINAL_SETTLEMENT_STATUSES));
    }

    public function partnerPayouts(InvestorPortalAccount $account): Builder
    {
        return ChannelPartnerPayout::query()
            ->where('channel_partner_id', $account->isChannelPartner() ? $account->channel_partner_id : 0)
            ->whereHas('settlement', fn (Builder $query) => $query->whereIn('status', self::FINAL_SETTLEMENT_STATUSES));
    }

    /** The investors this partner brought in. */
    public function referredInvestors(InvestorPortalAccount $account): Builder
    {
        return Investor::query()->where('channel_partner_id', $account->isChannelPartner() ? $account->channel_partner_id : 0);
    }

    /**
     * The investor's own papers, their investments' papers, and the shared
     * papers of the projects they have money in.
     */
    public function documents(InvestorPortalAccount $account): Builder
    {
        $investorId = $account->investor_id ?? 0;
        $partnerId = $account->isChannelPartner() ? $account->channel_partner_id : 0;
        $investmentIds = Investment::query()->where('investor_id', $investorId)->select('id');
        $projectIds = Investment::query()->where('investor_id', $investorId)->select('project_id');

        return InvestmentDocument::query()->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('documentable_type', (new Investor)->getMorphClass())->where('documentable_id', $investorId))
            ->orWhere(fn (Builder $query) => $query->where('documentable_type', (new ChannelPartner)->getMorphClass())->where('documentable_id', $partnerId))
            ->orWhere(fn (Builder $query) => $query->where('documentable_type', (new Investment)->getMorphClass())->whereIn('documentable_id', $investmentIds))
            ->orWhere(fn (Builder $query) => $query->where('documentable_type', (new InvestmentProject)->getMorphClass())->whereIn('documentable_id', $projectIds)
                ->whereIn('category', self::SHARED_PROJECT_DOCUMENT_CATEGORIES)));
    }

    /**
     * Money still working in a project: active investments in projects that
     * are not settled yet (after settlement the principal comes back with
     * the payout).
     */
    public function capitalAtWork(InvestorPortalAccount $account): float
    {
        return round((float) $this->investments($account)
            ->where('status', Investment::STATUS_ACTIVE)
            ->whereHas('project', fn (Builder $query) => $query->where('status', '!=', 'settled'))
            ->sum('amount'), 2);
    }

    /** @return array{capital_at_work: float, total_invested: float, profit_received: float, profit_pending: float, paid_out: float} */
    public function investorSummary(InvestorPortalAccount $account): array
    {
        return [
            'capital_at_work' => $this->capitalAtWork($account),
            'total_invested' => round((float) $this->investments($account)->sum('amount'), 2),
            'profit_received' => round((float) $this->payouts($account)->where('payment_status', 'paid')->sum('profit_share_amount'), 2),
            'profit_pending' => round((float) $this->payouts($account)->where('payment_status', 'pending')->sum('profit_share_amount'), 2),
            'paid_out' => round((float) $this->payouts($account)->where('payment_status', 'paid')->sum('total_payout'), 2),
        ];
    }

    /** @return array{investors: int, referred_capital: float, earned: float, paid: float, due: float} */
    public function partnerSummary(InvestorPortalAccount $account): array
    {
        $earned = round((float) $this->partnerPayouts($account)->sum('amount'), 2);
        $paid = round((float) $this->partnerPayouts($account)->where('payment_status', 'paid')->sum('amount'), 2);

        return [
            'investors' => $this->referredInvestors($account)->count(),
            'referred_capital' => round((float) Investment::query()
                ->whereIn('investor_id', $this->referredInvestors($account)->select('id'))
                ->where('status', Investment::STATUS_ACTIVE)
                ->whereHas('project', fn (Builder $query) => $query->where('status', '!=', 'settled'))
                ->sum('amount'), 2),
            'earned' => $earned,
            'paid' => $paid,
            'due' => round($earned - $paid, 2),
        ];
    }

    /**
     * The investor's statement: every money event on their investments, newest
     * first, built from the records themselves so it can never drift from
     * them. Amounts are signed from the investor's side: money they put in or
     * earned is positive, money they took out or lost is negative.
     *
     * @return Collection<int, array{date: Carbon, type: string, label: string, project: ?string, amount: float}>
     */
    public function statement(InvestorPortalAccount $account, ?Carbon $from = null, ?Carbon $until = null): Collection
    {
        $entries = collect();

        $investments = $this->investments($account)->with('project:id,project_code,name')->get();

        foreach ($investments as $investment) {
            $entries->push($this->entry($investment->invested_at ?? $investment->created_at, 'deposit', __('Investment'), $investment->project, (float) $investment->amount));

            if ($investment->isWithdrawn()) {
                $entries->push($this->entry($investment->withdrawn_at ?? $investment->updated_at, 'withdrawal', __('Capital withdrawn'), $investment->project, -(float) $investment->amount));
            }
        }

        $payouts = $this->payouts($account)->with(['settlement.project:id,project_code,name', 'cycleElection.nextProject:id,project_code,name'])->get();

        foreach ($payouts as $payout) {
            $settlement = $payout->settlement;
            $profit = (float) $payout->profit_share_amount;

            if ($profit != 0.0) {
                $entries->push($this->entry(
                    $settlement->settled_at,
                    $profit > 0 ? 'profit' : 'loss',
                    $profit > 0 ? __('Profit share') : __('Loss share'),
                    $settlement->project,
                    $profit,
                ));
            }

            if ($payout->payment_status === 'paid') {
                $entries->push($this->entry($payout->paid_at ?? $payout->updated_at, 'payout', __('Paid to you'), $settlement->project, -(float) $payout->total_payout));
            }

            $election = $payout->cycleElection;

            if ($election instanceof InvestorCycleElection && $election->election !== 'withdraw' && (float) $election->reinvest_amount > 0) {
                $entries->push($this->entry(
                    $election->created_at,
                    'reinvest',
                    __('Reinvested into :project', ['project' => $this->projectLabel($election->nextProject)]),
                    $settlement->project,
                    0.0,
                    (float) $election->reinvest_amount,
                ));
            }
        }

        return $entries
            ->filter(fn (array $entry): bool => (! $from || $entry['date']->gte($from->copy()->startOfDay())) && (! $until || $entry['date']->lte($until->copy()->endOfDay())))
            ->sortByDesc(fn (array $entry): string => $entry['date']->format('Y-m-d H:i:s').'|'.$entry['order'])
            ->values();
    }

    /**
     * Profit and loss per month for the last $months months, oldest first.
     *
     * @return array<string, float> "Y-m" => amount
     */
    public function monthlyProfit(InvestorPortalAccount $account, int $months = 12): array
    {
        $series = [];
        $start = now()->startOfMonth()->subMonths($months - 1);

        for ($i = 0; $i < $months; $i++) {
            $series[$start->copy()->addMonths($i)->format('Y-m')] = 0.0;
        }

        $this->payouts($account)->with('settlement:id,settled_at')->get()
            ->each(function (SettlementPayout $payout) use (&$series): void {
                $key = $payout->settlement?->settled_at?->format('Y-m');

                if ($key !== null && array_key_exists($key, $series)) {
                    $series[$key] = round($series[$key] + (float) $payout->profit_share_amount, 2);
                }
            });

        return $series;
    }

    public function projectLabel(?InvestmentProject $project): string
    {
        if (! $project) {
            return '-';
        }

        return trim(($project->project_code ? $project->project_code.' — ' : '').$project->name);
    }

    /** @return array{date: Carbon, type: string, label: string, project: string, amount: float, info_amount: ?float, order: int} */
    protected function entry(mixed $date, string $type, string $label, ?InvestmentProject $project, float $amount, ?float $infoAmount = null): array
    {
        $order = ['deposit' => 1, 'profit' => 2, 'loss' => 2, 'payout' => 3, 'reinvest' => 4, 'withdrawal' => 5][$type] ?? 9;

        return [
            'date' => Carbon::parse($date ?? now()),
            'type' => $type,
            'label' => $label,
            'project' => $this->projectLabel($project),
            'amount' => round($amount, 2),
            'info_amount' => $infoAmount === null ? null : round($infoAmount, 2),
            'order' => $order,
        ];
    }
}
