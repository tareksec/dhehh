<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\InvestorGroup;
use App\Services\AuditLogService;
use App\Services\CompanyContext;
use App\Services\Investment\InvestorGroupCycleService;
use App\Support\ModuleGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Daily (run hourly so every company's own midnight is caught) step of the
 * group cycle: close the project whose two calendar months are over and start
 * the next one. Idempotent; only touches Running groups with Automatic cycles on.
 */
class AdvanceInvestorGroups extends Command
{
    protected $signature = 'investments:advance-groups
        {--company= : Company id (default: every active company)}
        {--dry-run : Show what would happen and change nothing}';

    protected $description = 'Close finished group projects and start the next one (every 2 calendar months), for groups with Automatic cycles on.';

    public function handle(InvestorGroupCycleService $cycles, CompanyContext $context): int
    {
        $companies = Company::withoutGlobalScopes()
            ->where('is_active', true)
            ->when($this->option('company'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            // One company's failure must not stop the others (and is reported, not swallowed).
            ModuleGuard::run("investor-groups.advance.company-{$company->getKey()}", function () use ($company, $cycles, $context): void {
                $context->set($company);

                try {
                    $this->advanceCompany($company, $cycles);
                } finally {
                    $context->clear();
                }
            });
        }

        return self::SUCCESS;
    }

    private function advanceCompany(Company $company, InvestorGroupCycleService $cycles): void
    {
        $today = InvestorGroupCycleService::today($company->timezone);
        $dryRun = (bool) $this->option('dry-run');

        $groups = InvestorGroup::query()
            ->where('status', InvestorGroup::STATUS_RUNNING)
            ->where('auto_cycle', true)
            ->orderBy('id')
            ->get();

        foreach ($groups as $group) {
            $decision = $dryRun ? $cycles->decide($group, $today) : $cycles->advance($group, $today);
            $label = "{$company->name} / {$group->group_code}";

            match ($decision['action']) {
                InvestorGroupCycleService::ADVANCE => $this->info(($dryRun ? 'Would start' : 'Started')." project {$decision['next_sequence']} of {$label}".($decision['close'] ? " (project {$decision['from_sequence']} closed)" : '').'.'),
                InvestorGroupCycleService::BEHIND => $this->behind($group, $label, $decision, $dryRun),
                InvestorGroupCycleService::NO_MEMBERS => $this->warn("{$label}: project {$decision['from_sequence']} is over but no active member is left. Close the group."),
                default => null,
            };
        }
    }

    /** @param  array<string, mixed>  $decision */
    private function behind(InvestorGroup $group, string $label, array $decision, bool $dryRun): void
    {
        $this->warn("{$label}: {$decision['behind_by']} cycle(s) behind. Nothing was started; staff must confirm \"Start the next project now\" on the group page.");

        // Once a day is enough: the check runs every hour.
        if (! $dryRun && Cache::add("investor-group-behind:{$group->getKey()}:".now()->toDateString(), true, now()->addDay())) {
            app(AuditLogService::class)->record('investor_group_cycle_behind', $group, null, [
                'from_sequence' => $decision['from_sequence'],
                'behind_by' => $decision['behind_by'],
            ]);
        }
    }
}
