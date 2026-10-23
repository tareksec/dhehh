<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\AppBackupService;
use App\Services\OffsiteBackupService;
use Illuminate\Console\Command;
use Throwable;

class BackupApp extends Command
{
    protected $signature = 'backup:app
        {--kind=manual : hourly, daily, predeploy or manual - each kind is kept and pruned on its own}
        {--upload : Also send an encrypted copy to every off-site place that is switched on (Cloudflare R2, Google Drive)}
        {--auto-upload : Send off-site only to the places with "Auto upload" switched on, and only for the kinds listed in backup.offsite}';

    protected $description = 'Create a private full app backup (files + a database backup), optionally uploading it off-site (Cloudflare R2, Google Drive)';

    public function handle(AppBackupService $backups, OffsiteBackupService $offsite): int
    {
        $kind = (string) $this->option('kind');

        if (! in_array($kind, BackupRun::KINDS, true)) {
            $this->error('--kind must be one of: '.implode(', ', BackupRun::KINDS).'.');

            return self::FAILURE;
        }

        // "Auto upload" needs a place switched on as well. If it is on but the place or the
        // encryption key is not set up, the upload step fails and the run is recorded
        // as failed (with the reason) instead of silently leaving the archive on the server.
        $upload = (bool) $this->option('upload')
            || ((bool) $this->option('auto-upload') && $offsite->shouldAutoUpload(BackupRun::SCOPE_APP, $kind));

        try {
            $backup = $backups->create($upload, $kind, automatic: ! (bool) $this->option('upload'));
        } catch (Throwable $exception) {
            $this->error('Full app backup failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Full app backup created: {$backup['name']} ({$backup['size_human']})".$this->sentTo($backup));

        return self::SUCCESS;
    }

    /** " and sent to Cloudflare R2, Google Drive" or "" */
    protected function sentTo(array $backup): string
    {
        $places = array_keys($backup['offsite']['destinations'] ?? []);

        if ($places === []) {
            return '';
        }

        $labels = array_map(fn (string $key): string => $key === 'r2' ? 'Cloudflare R2' : 'Google Drive', $places);
        $failed = array_keys($backup['offsite']['failed'] ?? []);

        return ' and sent to '.implode(', ', $labels).($failed !== [] ? ' (not to: '.implode(', ', $failed).' - see the Backups page)' : '');
    }
}
