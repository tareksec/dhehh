<?php

namespace App\Services;

use App\Ims\Capital\CapitalCalculator;
use App\Models\CapitalReturn;
use App\Models\ChannelPartner;
use App\Models\ChannelPartnerPayout;
use App\Models\Investment;
use App\Models\InvestmentDocument;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\InvestorCycleElection;
use App\Models\InvestorGroup;
use App\Models\InvestorGroupMember;
use App\Models\InvestorPortalAccount;
use App\Models\SettlementPayout;
use Closure;
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
        // The screenshot of a payout made to them, and only that category of its papers.
        $payoutIds = SettlementPayout::query()->where('investor_id', $investorId)->select('id');
        $partnerPayoutIds = ChannelPartnerPayout::query()->where('channel_partner_id', $partnerId)->select('id');

        return InvestmentDocument::query()->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('documentable_type', (new Investor)->getMorphClass())->where('documentable_id', $investorId))
            ->orWhere(fn (Builder $query) => $query->where('documentable_type', (new ChannelPartner)->getMorphClass())->where('documentable_id', $partnerId))
            ->orWhere(fn (Builder $query) => $query->where('documentable_type', (new Investment)->getMorphClass())->whereIn('documentable_id', $investmentIds))
            ->orWhere(fn (Builder $query) => $query->where('documentable_type', (new SettlementPayout)->getMorphClass())->whereIn('documentable_id', $payoutIds)->where('category', InvestmentDocument::CATEGORY_PAYMENT_PROOF))
            ->orWhere(fn (Builder $query) => $query->where('documentable_type', (new ChannelPartnerPayout)->getMorphClass())->whereIn('documentable_id', $partnerPayoutIds)->where('category', InvestmentDocument::CATEGORY_PAYMENT_PROOF))
            ->orWhere(fn (Builder $query) => $query->where('documentable_type', (new InvestmentProject)->getMorphClass())->whereIn('documentable_id', $projectIds)
                ->whereIn('category', self::SHARED_PROJECT_DOCUMENT_CATEGORIES)));
    }

    /**
     * Money still working: what the person has in groups that are running
     * (a member stays in the group from project to project, so a settled
     * project does not take it out; a group that has not started yet is money
     * waiting, not working), what is on its way back to a
     * member who is leaving (until that last payout is paid), and the
     * investments in projects outside any group that are not settled yet.
     */
    public function capitalAtWork(InvestorPortalAccount $account): float
    {
        return $this->workingCapital(fn (Builder $query): Builder => $query->where('investor_id', $account->investor_id ?? 0));
    }

    /**
     * @param  Closure(Builder): Builder  $forInvestors  limits a query to the investors in question
     */
    protected function workingCapital(Closure $forInvestors): float
    {
        // IMS v2 (TRD §4.3): capital is always the CapitalCalculator's — after any
        // loss taken from it and any capital already paid back.
        $capital = app(CapitalCalculator::class);

        $staying = (float) $forInvestors(InvestorGroupMember::query())
            ->where('status', InvestorGroupMember::STATUS_ACTIVE)
            ->whereHas('group', fn (Builder $query) => $query->where('status', InvestorGroup::STATUS_RUNNING))
            ->get()
            ->sum(fn (InvestorGroupMember $member): float => $capital->forMember($member)['outstanding']);

        $leaving = (float) $forInvestors(InvestorGroupMember::query())
            ->where('status', InvestorGroupMember::STATUS_WITHDRAWN)
            ->whereNotNull('leaves_after_sequence')
            ->get()
            ->filter(fn (InvestorGroupMember $member): bool => $this->lastPayoutStillOpen($member))
            ->sum(fn (InvestorGroupMember $member): float => $capital->forMember($member)['returnable']);

        $outsideGroups = (float) $forInvestors(Investment::query())
            ->whereNull('group_member_id')
            ->where('status', Investment::STATUS_ACTIVE)
            ->whereHas('project', fn (Builder $query) => $query->where('status', '!=', 'settled'))
            ->get()
            ->sum(fn (Investment $investment): float => $capital->forInvestment($investment)['outstanding']);

        return round($staying + $leaving + $outsideGroups, 2);
    }

    /** A leaving member's principal is still on its way until the payout of the project they left after is paid. */
    protected function lastPayoutStillOpen(InvestorGroupMember $member): bool
    {
        $project = InvestmentProject::query()->where('group_id', $member->group_id)->where('sequence_in_group', $member->leaves_after_sequence)->first();
        $settlement = $project?->settlement;

        if (! $settlement || ! in_array($settlement->status, self::FINAL_SETTLEMENT_STATUSES, true)) {
            return true;
        }

        $payout = SettlementPayout::query()->where('settlement_id', $settlement->getKey())->where('investor_id', $member->investor_id)->first();

        return $payout === null || $payout->payment_status !== 'paid';
    }

    /** Everything the person has ever put in: one deposit per group membership, plus investments outside any group. */
    public function totalInvested(InvestorPortalAccount $account): float
    {
        // Money still waiting to be confirmed (IMS v2 "pending") is not counted.
        return round(
            (float) InvestorGroupMember::query()->where('investor_id', $account->investor_id ?? 0)->where('status', '!=', InvestorGroupMember::STATUS_PENDING)->sum('amount')
            + (float) $this->investments($account)->whereNull('group_member_id')->where('status', '!=', Investment::STATUS_PENDING)->sum('amount'),
            2,
        );
    }

    /** @return array{capital_at_work: float, total_invested: float, profit_received: float, profit_pending: float, paid_out: float} */
    public function investorSummary(InvestorPortalAccount $account): array
    {
        return [
            'capital_at_work' => $this->capitalAtWork($account),
            'total_invested' => $this->totalInvested($account),
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
            'referred_capital' => $this->workingCapital(fn (Builder $query): Builder => $query->whereIn('investor_id', $this->referredInvestors($account)->select('id'))),
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

        // Money in a group is ONE deposit however many projects it has been through
        // (each project holds a copy of it); only investments outside any group are
        // listed row by row.
        $members = InvestorGroupMember::query()->where('investor_id', $account->investor_id ?? 0)->where('status', '!=', InvestorGroupMember::STATUS_PENDING)->with('group:id,group_code,name')->get();

        foreach ($members as $member) {
            $about = trim($member->group?->group_code.' — '.$member->group?->name);
            $entries->push($this->entry($member->invested_at ?? $member->created_at, 'deposit', __('Investment'), $about, (float) $member->amount));

            // A member who left at a project's end gets the principal back inside that
            // project's payout, so only a withdrawal on its own is listed here.
            if ($member->isWithdrawn() && $member->leaves_after_sequence === null) {
                $entries->push($this->entry($member->withdrawn_at ?? $member->updated_at, 'withdrawal', __('Capital withdrawn'), $about, -(float) $member->amount));
            }
        }

        $investments = $this->investments($account)->whereNull('group_member_id')->where('status', '!=', Investment::STATUS_PENDING)->with('project:id,project_code,name')->get();

        foreach ($investments as $investment) {
            $entries->push($this->entry($investment->invested_at ?? $investment->created_at, 'deposit', __('Investment'), $investment->project, (float) $investment->amount));

            if ($investment->isWithdrawn()) {
                $entries->push($this->entry($investment->withdrawn_at ?? $investment->updated_at, 'withdrawal', __('Capital withdrawn'), $investment->project, -(float) $investment->amount));
            }
        }

        $payouts = $this->payouts($account)->with(['settlement.project:id,project_code,name', 'cycleElection.nextProject:id,project_code,name', 'cycleElection.nextGroup:id,group_code,name'])->get();

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

            // IMS v2 (TRD §4.2): a loss share is taken from the capital.
            if ((float) $payout->capital_loss_amount > 0) {
                $entries->push($this->entry($settlement->settled_at, 'loss', __('Loss taken from capital'), $settlement->project, -(float) $payout->capital_loss_amount));
            }

            if ($payout->payment_status === 'paid') {
                $entries->push($this->entry($payout->paid_at ?? $payout->updated_at, 'payout', __('Paid to you'), $settlement->project, -(float) $payout->total_payout));
            }

            $election = $payout->cycleElection;

            if ($election instanceof InvestorCycleElection && $election->election !== 'withdraw' && (float) $election->reinvest_amount > 0) {
                $entries->push($this->entry(
                    $election->created_at,
                    'reinvest',
                    __('Reinvested into :project', ['project' => $election->nextGroup ? trim($election->nextGroup->group_code.' — '.$election->nextGroup->name) : $this->projectLabel($election->nextProject)]),
                    $settlement->project,
                    0.0,
                    (float) $election->reinvest_amount,
                ));
            }
        }

        // IMS v2 (PRD FR-53): capital paid back at maturity or on exit.
        foreach (CapitalReturn::query()->paid()->where('investor_id', $account->investor_id ?? 0)->with(['investment.project:id,project_code,name', 'groupMember.group:id,group_code,name'])->get() as $return) {
            $about = $return->groupMember?->group ? trim($return->groupMember->group->group_code.' — '.$return->groupMember->group->name) : $return->investment?->project;
            $entries->push($this->entry($return->returned_on, 'capital_return', __('Capital paid back'), $about, -(float) $return->amount));
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
                    // A loss month shows below zero (IMS v2: the loss is taken from capital).
                    $series[$key] = round($series[$key] + (float) $payout->profit_share_amount - (float) $payout->capital_loss_amount, 2);
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

    /** @return array{date: Carbon, type: string, label: string, project: string, project_id: ?int, amount: float, info_amount: ?float, order: int} */
    protected function entry(mixed $date, string $type, string $label, InvestmentProject|string|null $project, float $amount, ?float $infoAmount = null): array
    {
        $order = ['deposit' => 1, 'profit' => 2, 'loss' => 2, 'payout' => 3, 'reinvest' => 4, 'withdrawal' => 5, 'capital_return' => 5][$type] ?? 9;

        return [
            'date' => Carbon::parse($date ?? now()),
            'type' => $type,
            'label' => $label,
            'project' => is_string($project) ? $project : $this->projectLabel($project),
            'project_id' => $project instanceof InvestmentProject ? (int) $project->getKey() : null,
            'amount' => round($amount, 2),
            'info_amount' => $infoAmount === null ? null : round($infoAmount, 2),
            'order' => $order,
        ];
    }
}
