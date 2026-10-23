<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Throwable;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database
        {--connection= : Database connection; defaults to the app database}
        {--kind=manual : hourly, daily, predeploy or manual - each kind is kept and pruned on its own}';

    protected $description = 'Create a private database backup';

    public function handle(DatabaseBackupService $backups): int
    {
        $kind = (string) $this->option('kind');

        if (! in_array($kind, BackupRun::KINDS, true)) {
            $this->error('--kind must be one of: '.implode(', ', BackupRun::KINDS).'.');

            return self::FAILURE;
        }

        try {
            $backup = $backups->create($this->option('connection') ?: null, $kind);
        } catch (Throwable $exception) {
            $this->error('Database backup failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Database backup created: {$backup['name']} ({$backup['size_human']})");

        return self::SUCCESS;
    }
}
