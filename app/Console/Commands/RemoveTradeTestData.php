<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\DatabaseBackupService;
use App\Services\TradeTestDataCleanupService;
use Illuminate\Console\Command;
use RuntimeException;

class RemoveTradeTestData extends Command
{
    protected $signature = 'trade:remove-test-data
        {company : Company slug}
        {--prefix=TEST- : Name prefix of the test records}
        {--apply : Really remove them (without it nothing is changed)}
        {--no-backup : Skip the backup before removing (non-production only)}';

    protected $description = 'Remove a Trade Portal company\'s QA test records (named with the prefix) and what hangs on them';

    public function handle(TradeTestDataCleanupService $cleanup, DatabaseBackupService $backups): int
    {
        $company = Company::query()->where('slug', (string) $this->argument('company'))->first();
        $prefix = trim((string) $this->option('prefix'));

        if (! $company) {
            $this->error('Company not found: '.$this->argument('company'));

            return self::FAILURE;
        }

        if (mb_strlen($prefix) < 3) {
            $this->error('The prefix must be at least 3 characters.');

            return self::FAILURE;
        }

        try {
            $plan = $cleanup->plan($company, $prefix);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("{$company->name}: records named \"{$prefix}…\" and what hangs on them");
        $this->table(['Kind', 'Count', 'Found'], collect($plan)->map(fn ($items, string $kind): array => [$kind, $items->count(), $items->take(8)->implode(', ').($items->count() > 8 ? ', …' : '')])->values()->all());

        $apply = (bool) $this->option('apply');

        if ($apply && ! $this->option('no-backup')) {
            $backup = $backups->create();
            $this->info("Backup created: {$backup['name']}");
        } elseif ($apply && app()->isProduction()) {
            $this->error('--no-backup is not allowed in production.');

            return self::FAILURE;
        }

        $after = $cleanup->remove($company, $prefix, $apply);

        $this->info($apply ? 'Removed. Money accounts now:' : 'Dry run (rolled back, nothing changed). Money accounts after removal would be:');
        $this->table(['Account', 'Opening', 'Balance'], collect($after['accounts'])->map(fn (array $row): array => [$row['name'], number_format($row['opening'], 2), number_format($row['balance'], 2)])->all());

        if ($after['ledger'] === []) {
            $this->info('No ledger rows are left on the money accounts.');
        } else {
            $this->warn(count($after['ledger']).' ledger row(s) are left on the money accounts — check that each one is real:');
            $this->table(['Date', 'Account', 'Type', 'In/Out', 'Amount', 'From', 'Note'], collect($after['ledger'])->map(fn (array $row): array => [$row['date'], $row['account'], $row['type'], $row['direction'], number_format($row['amount'], 2), $row['reference'], (string) $row['note']])->all());
        }

        if (! $apply) {
            $this->line('Run again with --apply to remove them.');
        }

        return self::SUCCESS;
    }
}
