<?php

namespace App\Console\Commands;

use App\Services\BackupRestoreDrill;
use Illuminate\Console\Command;
use Throwable;

class DrillBackup extends Command
{
    protected $signature = 'backup:drill
        {--file= : A .sql.gz in the backup folder; default: the newest}
        {--offsite : Test the newest copy on Google Drive instead (downloads and decrypts it)}';

    protected $description = 'Restore test: load the newest database dump into a scratch database, check it, and drop it';

    public function handle(BackupRestoreDrill $drill): int
    {
        try {
            $result = $drill->run($this->option('file') ?: null, (bool) $this->option('offsite'));
        } catch (Throwable $exception) {
            $this->error("Restore test failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Restore test passed: {$result['name']}");
        $this->line($result['note']);

        return self::SUCCESS;
    }
}
