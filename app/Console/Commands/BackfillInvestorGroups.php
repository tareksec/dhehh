<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CompanyContext;
use App\Services\Investment\InvestorGroupBackfillService;
use Illuminate\Console\Command;
use Throwable;

class BackfillInvestorGroups extends Command
{
    protected $signature = 'investments:backfill-groups
        {--company= : Company id (default: every company)}
        {--dry-run : Show what would happen and change nothing}
        {--force : Do not ask for confirmation}';

    protected $description = 'Move existing investment projects into investor groups (one group per project, each project becomes Project 1 and takes its group code, e.g. G2-P01). Dry-run first.';

    public function handle(InvestorGroupBackfillService $backfill, CompanyContext $context): int
    {
        $companies = Company::withoutGlobalScopes()
            ->when($this->option('company'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            $this->error('No company found.');

            return self::FAILURE;
        }

        $plans = [];

        try {
            foreach ($companies as $company) {
                $context->set($company);
                $plan = $backfill->plan();
                $plans[$company->getKey()] = $plan;

                $this->line("<info>{$company->name}</info> (id {$company->getKey()})");

                if ($plan['rows'] === [] && $plan['skipped'] === []) {
                    $this->line('  Nothing to move: every project is already in a group.');

                    continue;
                }

                if ($plan['rows'] !== []) {
                    $this->table(
                        ['Project', 'Status', '→ Group', 'New code', 'Running since', 'Members', 'Total', 'Active total'],
                        array_map(fn (array $row): array => [
                            $row['project'], $row['status'], "{$row['group_code']} ({$row['group_name']})", $row['new_project_code'], $row['running_since'] ?? '-',
                            $row['members'].' ('.$row['active_members'].' active)', number_format($row['total'], 2), number_format($row['active_total'], 2),
                        ], $plan['rows']),
                    );
                }

                foreach ($plan['skipped'] as $skipped) {
                    $this->warn("  Skipped {$skipped['project']}: {$skipped['reason']}");
                }
            }
        } finally {
            $context->clear();
        }

        $toMove = collect($plans)->sum(fn (array $plan): int => count($plan['rows']));

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$toMove} project(s) would move into groups. Nothing was changed.");

            return self::SUCCESS;
        }

        if ($toMove === 0) {
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("This moves {$toMove} project(s) into groups on the REAL data. Groups start Running with automatic cycles OFF. Continue?")) {
            $this->warn('Cancelled. Nothing was changed.');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            if (($plans[$company->getKey()]['rows'] ?? []) === []) {
                continue;
            }

            $context->set($company);

            try {
                $moved = $backfill->apply();
                $this->info("{$company->name}: moved ".count($moved).' project(s) into groups.');
            } catch (Throwable $exception) {
                $this->error("{$company->name}: {$exception->getMessage()}");

                return self::FAILURE;
            } finally {
                $context->clear();
            }
        }

        $this->info('Done. Check each group, then switch Automatic cycles on when the dates are right.');

        return self::SUCCESS;
    }
}
