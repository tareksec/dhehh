<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CompanyContext;
use App\Services\Investment\InvestorGroupBackfillUndoService;
use Illuminate\Console\Command;
use Throwable;

class UndoBackfillInvestorGroups extends Command
{
    protected $signature = 'investments:undo-backfill-groups
        {--company= : Company id (default: every company)}
        {--group=* : Only this group code (G2); repeat the option for more. Default: every group of the company}
        {--include-empty : Also delete groups with no members and no project (a test group)}
        {--dry-run : Show what would happen and change nothing}
        {--force : Do not ask for confirmation}';

    protected $description = 'Take groups made by investments:backfill-groups out again (the project is ungrouped, its investments are untouched, the group and its member copies are deleted). Dry-run first.';

    public function handle(InvestorGroupBackfillUndoService $undo, CompanyContext $context): int
    {
        $companies = Company::withoutGlobalScopes()
            ->when($this->option('company'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            $this->error('No company found.');

            return self::FAILURE;
        }

        $codes = array_values(array_filter((array) $this->option('group')));
        $includeEmpty = (bool) $this->option('include-empty');
        $plans = [];

        try {
            foreach ($companies as $company) {
                $context->set($company);
                $plan = $undo->plan($codes, $includeEmpty);
                $plans[$company->getKey()] = $plan;

                $this->line("<info>{$company->name}</info> (id {$company->getKey()})");

                if ($plan['rows'] === [] && $plan['empty'] === [] && $plan['refused'] === []) {
                    $this->line('  No group found.');

                    continue;
                }

                if ($plan['rows'] !== []) {
                    $this->table(
                        ['Group', 'Project now', 'Code goes back to', 'Project status', 'Members', 'Total'],
                        array_map(fn (array $row): array => [
                            $row['group'], $row['project_code'], $row['restore_code'] ?? '(unchanged)', $row['status'], $row['members'], number_format($row['total'], 2),
                        ], $plan['rows']),
                    );
                }

                foreach ($plan['empty'] as $row) {
                    $this->line("  Will delete the empty group {$row['group']}.");
                }

                foreach ($plan['refused'] as $refused) {
                    $this->warn("  Left alone {$refused['group']}: {$refused['reason']}");
                }
            }
        } finally {
            $context->clear();
        }

        $count = collect($plans)->sum(fn (array $plan): int => count($plan['rows']) + count($plan['empty']));

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$count} group(s) would be removed. Nothing was changed.");

            return self::SUCCESS;
        }

        if ($count === 0) {
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("This removes {$count} group(s) on the REAL data (the projects and their investments stay). Continue?")) {
            $this->warn('Cancelled. Nothing was changed.');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            $plan = $plans[$company->getKey()] ?? ['rows' => [], 'empty' => []];

            if ($plan['rows'] === [] && $plan['empty'] === []) {
                continue;
            }

            $context->set($company);

            try {
                $done = $undo->apply($codes, $includeEmpty);
                $this->info("{$company->name}: removed ".count($done['rows']).' backfilled group(s) and '.count($done['empty']).' empty group(s).');
            } catch (Throwable $exception) {
                $this->error("{$company->name}: {$exception->getMessage()}");

                return self::FAILURE;
            } finally {
                $context->clear();
            }
        }

        $this->info('Done. Run investments:backfill-groups --dry-run to see the new numbering.');

        return self::SUCCESS;
    }
}
