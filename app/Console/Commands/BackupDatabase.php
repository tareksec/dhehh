<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\DatabaseBackupService;
use App\Services\OffsiteBackupService;
use Illuminate\Console\Command;
use Throwable;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database
        {--connection= : Database connection; defaults to the app database}
        {--kind=manual : hourly, daily, predeploy or manual - each kind is kept and pruned on its own}
        {--upload : Also send an encrypted copy to Google Drive}
        {--auto-upload : Send to Google Drive only when "Auto upload" is switched on (Backups page) for this kind}';

    protected $description = 'Create a private database backup';

    public function handle(DatabaseBackupService $backups, OffsiteBackupService $offsite): int
    {
        $kind = (string) $this->option('kind');

        if (! in_array($kind, BackupRun::KINDS, true)) {
            $this->error('--kind must be one of: '.implode(', ', BackupRun::KINDS).'.');

            return self::FAILURE;
        }

        // "Auto upload" needs Drive switched on as well. If it is on but Drive or the
        // encryption key is not set up, the upload fails and the run is recorded as
        // failed (with the reason) instead of silently keeping the copy on the server.
        $upload = (bool) $this->option('upload')
            || ((bool) $this->option('auto-upload') && $offsite->shouldAutoUpload(BackupRun::SCOPE_DATABASE, $kind));

        try {
            $backup = $backups->create($this->option('connection') ?: null, $kind, $upload);
        } catch (Throwable $exception) {
            $this->error('Database backup failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Database backup created: {$backup['name']} ({$backup['size_human']})".($upload ? ' and sent to Google Drive' : ''));

        return self::SUCCESS;
    }
}
