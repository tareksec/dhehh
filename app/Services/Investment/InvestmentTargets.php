<?php

namespace App\Services\Investment;

use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\InvestorDraft;
use App\Models\InvestorGroup;
use App\Models\InvestorGroupMember;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Where new money can go (16_INVESTOR_GROUPS_PLAN.md, phase G3): an OPEN GROUP,
 * or, for the few projects that are not part of a group, the project itself.
 * New money never joins a running group (owner, 2026-10-12): top-up, reinvest,
 * imports and sign-ups all pick from this list and place the money through
 * `place()`, so the rule lives in one place.
 */
class InvestmentTargets
{
    public function __construct(private InvestorGroupService $groups, private InvestmentLedgerService $ledger) {}

    /**
     * Open groups first, then projects without a group that still take money,
     * keyed `g<id>` / `p<id>`.
     *
     * @return Collection<string, InvestorGroup|InvestmentProject>
     */
    public function open(): Collection
    {
        $groups = InvestorGroup::query()->open()->orderBy('id')->get()
            ->mapWithKeys(fn (InvestorGroup $group): array => [self::key($group) => $group]);

        $projects = $this->openProjects()
            ->mapWithKeys(fn (InvestmentProject $project): array => [self::key($project) => $project]);

        // Plain arrays: an Eloquent collection would re-key its models by id.
        return new Collection($groups->all() + $projects->all());
    }

    /** Projects outside any group that are open or running and inside their investment window. */
    public function openProjects(): Collection
    {
        return InvestmentProject::query()
            ->ungrouped()
            ->whereIn('status', InvestorDraft::OPEN_PROJECT_STATUSES)
            ->orderByDesc('id')
            ->get()
            ->filter(fn (InvestmentProject $project): bool => $project->investment_closes_at === null || $project->isWithinInvestmentWindow())
            ->values();
    }

    public function find(?string $key): InvestorGroup|InvestmentProject|null
    {
        return filled($key) ? $this->open()->get($key) : null;
    }

    public static function key(InvestorGroup|InvestmentProject $target): string
    {
        return ($target instanceof InvestorGroup ? 'g' : 'p').$target->getKey();
    }

    public function label(InvestorGroup|InvestmentProject|null $target): string
    {
        return match (true) {
            $target instanceof InvestorGroup => trim($target->group_code.' — '.$target->name),
            $target instanceof InvestmentProject => trim(($target->project_code ? $target->project_code.' — ' : '').$target->name),
            default => '-',
        };
    }

    /** @return array<string, string> key => label, for a select */
    public function options(): array
    {
        return $this->open()->map(fn (InvestorGroup|InvestmentProject $target): string => $this->label($target))->all();
    }

    /**
     * Puts the money in: a new member of an open group (one capital voucher),
     * or an investment in an ungrouped project (its capital voucher).
     *
     * @param  array{amount: float|int|string, invested_at?: string|null, payment_method?: string|null, payment_reference?: string|null, notes?: string|null, paperwork?: array<string, mixed>|null, override_reason?: string|null}  $details
     */
    public function place(InvestorGroup|InvestmentProject $target, Investor $investor, array $details, ?User $by = null): InvestorGroupMember|Investment
    {
        if ($target instanceof InvestorGroup) {
            return $this->groups->addMember($target, $investor, $details, $by);
        }

        $investment = Investment::query()->create([
            'company_id' => $target->company_id,
            'project_id' => $target->getKey(),
            'investor_id' => $investor->getKey(),
            'amount' => $details['amount'],
            'payment_method' => $details['payment_method'] ?? 'other',
            'payment_reference' => $details['payment_reference'] ?? null,
            'invested_at' => $details['invested_at'] ?? now()->toDateString(),
            'override_reason' => $details['override_reason'] ?? null,
            'received_by' => $by?->getKey(),
        ]);

        $this->ledger->recordInvestmentReceived($investment);

        return $investment;
    }
}
