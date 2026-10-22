<?php

namespace App\Ims\Transparency;

use App\Ims\Profit\ProjectFinancials;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\ProjectMilestone;
use App\Models\ProjectReport;
use App\Models\ProjectUpdate;
use App\Models\Shipment;
use Carbon\CarbonImmutable;

/**
 * What an investor may see of a project (IMS-V2/PRD.md FR-30–35,
 * ARCHITECTURE §6). Every piece of ERP data reaches the portal only through
 * here, and only these fields: never a customer, supplier, invoice or order
 * number, unit price, margin, container or tracking number, or another
 * investor (PortalPrivacyTest).
 *
 * Three levels (FR-35): a project still raising money shows its plan to
 * everyone; updates, milestones and shipments only to an investor with money
 * in it; the money figures only with an ACTIVE investment.
 */
class InvestorProjectSummary
{
    public function __construct(private ProjectFinancials $financials) {}

    public function canSeePlan(InvestmentProject $project, Investor $investor): bool
    {
        return $project->status === 'open' || $this->hasMoneyIn($project, $investor);
    }

    public function hasMoneyIn(InvestmentProject $project, Investor $investor): bool
    {
        return Investment::query()->where('project_id', $project->getKey())->where('investor_id', $investor->getKey())->exists();
    }

    public function hasActiveMoneyIn(InvestmentProject $project, Investor $investor): bool
    {
        return Investment::query()->where('project_id', $project->getKey())->where('investor_id', $investor->getKey())
            ->whereIn('status', [Investment::STATUS_ACTIVE, Investment::STATUS_WITHDRAWN])->exists();
    }

    /**
     * @return array{plan: array<string, mixed>, follows: bool, updates: list<array<string, mixed>>, milestones: list<array<string, mixed>>, shipments: list<array<string, mixed>>, figures: array<string, mixed>|null, reports: list<array<string, mixed>>}|null
     */
    public function for(InvestmentProject $project, Investor $investor): ?array
    {
        if (! $this->canSeePlan($project, $investor)) {
            return null;
        }

        $follows = $this->hasMoneyIn($project, $investor);

        return [
            'plan' => [
                'id' => $project->getKey(),
                'code' => (string) $project->project_code,
                'name' => (string) $project->name,
                'description' => $project->description,
                'status' => (string) $project->status,
                'start_date' => $project->start_date?->toDateString(),
                'end_date' => $project->end_date?->toDateString(),
                'target_amount' => $project->status === 'open' ? (float) $project->target_amount : null,
                'investor_share_percent' => (float) $project->investor_share_percent,
            ],
            'follows' => $follows,
            'updates' => $follows ? $this->updates($project) : [],
            'milestones' => $follows ? $this->milestones($project) : [],
            'shipments' => $follows ? $this->shipments($project) : [],
            'figures' => $this->hasActiveMoneyIn($project, $investor) ? $this->figures($project, $investor) : null,
            'reports' => $follows ? $this->reports($project) : [],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function updates(InvestmentProject $project): array
    {
        return $project->updates()->visible()->with('photos')->latest('published_at')->get()
            ->map(fn (ProjectUpdate $update): array => [
                'id' => $update->getKey(),
                'title' => (string) $update->title,
                'body' => $update->body,
                'video_url' => $update->video_url,
                'published_at' => $update->published_at?->toDateString(),
                'photo_ids' => $update->photos->pluck('id')->all(),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    public function milestones(InvestmentProject $project): array
    {
        return $project->milestones()->get()->map(fn (ProjectMilestone $milestone): array => [
            'title' => (string) $milestone->title,
            'due_on' => $milestone->due_on?->toDateString(),
            'done_on' => $milestone->done_on?->toDateString(),
            'done' => $milestone->isDone(),
        ])->all();
    }

    /**
     * Shipments of the project's purchases: how, which step, and when. No
     * supplier, container, tracking number or carrier.
     *
     * @return list<array<string, mixed>>
     */
    public function shipments(InvestmentProject $project): array
    {
        $purchaseIds = $project->allPurchaseIds();

        if ($purchaseIds === []) {
            return [];
        }

        $today = CarbonImmutable::today();

        return Shipment::query()->whereIn('purchase_id', $purchaseIds)->where('status', '!=', 'cancelled')->orderBy('id')->get()
            ->map(function (Shipment $shipment) use ($today): array {
                $expected = $shipment->estimated_arrival ? CarbonImmutable::parse($shipment->estimated_arrival) : null;
                $arrived = $shipment->received_at ? CarbonImmutable::parse($shipment->received_at) : null;
                $lateBy = $expected === null ? 0 : (int) max(0, $expected->diffInDays($arrived ?? $today, false));

                return [
                    'mode' => (string) ($shipment->transport_mode ?? ''),
                    'status' => (string) $shipment->status,
                    'shipped_at' => $shipment->shipped_at?->toDateString(),
                    'expected_at' => $expected?->toDateString(),
                    'arrived_at' => $arrived?->toDateString(),
                    'late_days' => $lateBy,
                ];
            })->all();
    }

    /**
     * Totals and the investor's own share. The settled figures once the
     * project is settled; until then an estimate from the ERP, marked so.
     *
     * @return array<string, mixed>
     */
    public function figures(InvestmentProject $project, Investor $investor): array
    {
        $capital = (float) Investment::query()->where('project_id', $project->getKey())->where('investor_id', $investor->getKey())
            ->whereIn('status', [Investment::STATUS_ACTIVE, Investment::STATUS_WITHDRAWN])->sum('amount');
        $capitalBase = max(0.01, $project->totalCapitalBase());
        $percent = (float) $project->investor_share_percent;
        $settlement = $project->settlement()->whereIn('status', ['confirmed', 'paid_out'])->first();
        $computed = $this->financials->compute($project);

        if ($settlement !== null) {
            $payout = $settlement->payouts()->where('investor_id', $investor->getKey())->first();

            return [
                'estimated' => false,
                'costs' => $computed['costs'],
                'units_bought' => $computed['units_bought'],
                'units_sold' => $computed['units_sold'],
                'units_in_stock' => $computed['units_in_stock'],
                'revenue' => (float) $settlement->total_revenue,
                'net_profit' => (float) $settlement->net_profit,
                'investor_pool' => (float) $settlement->investor_pool_amount,
                'my_capital' => $capital,
                'my_share' => $payout ? (float) $payout->profit_share_amount - (float) $payout->capital_loss_amount : 0.0,
                'investor_share_percent' => $percent,
            ];
        }

        $pool = round($computed['net_profit'] * $percent / 100, 2);

        return [
            'estimated' => true,
            'costs' => $computed['costs'],
            'units_bought' => $computed['units_bought'],
            'units_sold' => $computed['units_sold'],
            'units_in_stock' => $computed['units_in_stock'],
            'revenue' => $computed['revenue'],
            'net_profit' => $computed['net_profit'],
            'investor_pool' => $pool,
            'my_capital' => $capital,
            'my_share' => round($pool * $capital / $capitalBase, 2),
            'investor_share_percent' => $percent,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function reports(InvestmentProject $project): array
    {
        return $project->reports()->where('status', ProjectReport::STATUS_PUBLISHED)->latest('published_at')->get()
            ->map(fn (ProjectReport $report): array => [
                'id' => $report->getKey(),
                'title' => (string) $report->title,
                'published_at' => $report->published_at?->toDateString(),
            ])->all();
    }
}
