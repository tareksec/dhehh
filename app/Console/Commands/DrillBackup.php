<?php

namespace App\Console\Commands;

use App\Services\BackupRestoreDrill;
use Illuminate\Console\Command;
use Throwable;

class DrillBackup extends Command
{
    protected $signature = 'backup:drill
        {--file= : A .sql.gz in the backup folder; default: the newest}
        {--offsite : Test the newest copy off-site instead (Cloudflare R2 if set up, else Google Drive; downloads and decrypts it)}
        {--r2 : Test the newest copy on Cloudflare R2}
        {--drive : Test the newest copy on Google Drive}';

    protected $description = 'Restore test: load the newest database dump into a scratch database, check it, and drop it';

    public function handle(BackupRestoreDrill $drill): int
    {
        try {
            $offsite = match (true) {
                (bool) $this->option('r2') => 'r2',
                (bool) $this->option('drive') => 'drive',
                default => (bool) $this->option('offsite'),
            };

            $result = $drill->run($this->option('file') ?: null, $offsite);
        } catch (Throwable $exception) {
            $this->error("Restore test failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Restore test passed: {$result['name']}");
        $this->line($result['note']);

        return self::SUCCESS;
    }
}
