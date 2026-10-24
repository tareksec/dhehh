<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Throwable;

class VerifyDatabaseBackup extends Command
{
    protected $signature = 'backup:verify {filename? : Backup filename; defaults to the newest SQLite backup or compressed MySQL dump}';

    protected $description = 'Verify a database backup without touching the live database (SQLite: restore into a disposable file; MySQL dump: completeness and checksum)';

    public function handle(DatabaseBackupService $backups): int
    {
        $filename = $this->argument('filename')
            ?: collect($backups->all())->first(fn (array $backup): bool => str_ends_with($backup['name'], '.sqlite') || str_ends_with($backup['name'], '.sql.gz'))['name'] ?? null;

        if (! $filename) {
            $this->error('No SQLite backup or compressed MySQL dump is available to verify.');

            return self::FAILURE;
        }

        try {
            $result = $backups->verifyRestore($filename);
        } catch (Throwable $exception) {
            $this->error("Backup verification failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Restore drill passed: {$result['backup']}");
        $this->line("Integrity: {$result['integrity']}; tables: {$result['table_count']}".(isset($result['checksum']) ? "; checksum: {$result['checksum']}" : ''));

        return self::SUCCESS;
    }
}
