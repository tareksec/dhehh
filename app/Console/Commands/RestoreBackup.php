<?php

namespace App\Console\Commands;

use App\Services\AppBackupService;
use App\Services\BackupEncryptor;
use App\Services\BackupRestoreException;
use App\Services\BackupRestorer;
use App\Services\DatabaseBackupService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Number;
use Throwable;

class RestoreBackup extends Command
{
    protected $signature = 'backup:restore
        {file? : A backup in the backup folder, or the path of a downloaded one (.sql.gz or .zip, with or without .enc)}
        {--drive : Use the newest database copy on Google Drive instead of a file}
        {--only= : For a .zip: "database" or "files" (default: both)}
        {--confirm= : The database name (or "files" for a files-only restore), to confirm without being asked}
        {--skip-rehearsal : Do not try the dump in a scratch database first}
        {--skip-safety-backup : Do not copy the current database first}
        {--no-migrate : Do not run pending migrations afterwards}';

    protected $description = 'Restore a backup: replace the database (and the uploaded files from a full app archive)';

    public function handle(BackupRestorer $restorer, BackupEncryptor $encryptor): int
    {
        $only = $this->option('only') ?: null;
        $file = $this->argument('file');
        $fromDrive = (bool) $this->option('drive');

        if ($only !== null && ! in_array($only, ['database', 'files'], true)) {
            $this->error('--only must be "database" or "files".');

            return self::FAILURE;
        }

        if ($file === null && ! $fromDrive) {
            $this->error('Say which backup to restore: a file name or path, or --drive for the newest database copy on Google Drive.');
            $this->listBackups();

            return self::FAILURE;
        }

        // The key is only asked for when it is needed and not already in the environment.
        $key = null;

        if (($fromDrive || str_ends_with((string) $file, BackupEncryptor::EXTENSION)) && ! $encryptor->isConfigured()) {
            $key = ((string) $this->secret('Backup encryption key (BACKUP_ENCRYPTION_KEY)')) ?: null;
        }

        try {
            $this->line($fromDrive ? 'Fetching the newest database copy from Google Drive...' : 'Reading the backup...');
            $source = $restorer->prepare($file, $fromDrive, $key);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            return $this->restore($restorer, $source, $only);
        } finally {
            $restorer->release($source);
        }
    }

    protected function restore(BackupRestorer $restorer, array $source, ?string $only): int
    {
        $archive = $source['kind'] === BackupRestorer::ARCHIVE;

        if ($only === 'files' && ! $archive) {
            $this->error('--only=files needs a full app archive (.zip); a .sql.gz holds only the database.');

            return self::FAILURE;
        }

        $database = $only !== 'files';
        $files = $archive && $only !== 'database';

        if ($database && $source['dump'] === null) {
            $this->error('This archive holds no database dump. Use --only=files to restore just its files.');

            return self::FAILURE;
        }

        try {
            $checked = $restorer->check($source);
        } catch (Throwable $exception) {
            $this->error('The backup is not usable: '.$exception->getMessage());

            return self::FAILURE;
        }

        $target = (array) config('database.connections.'.config('database.default'));
        $takenAt = $source['taken_at'];

        $this->newLine();
        $this->line('Backup:    '.$source['label']);
        $this->line('Size:      '.Number::fileSize($source['size'], precision: 1));
        $this->line('Made:      '.($takenAt ? $takenAt->format('d M Y, h:i A').' ('.$takenAt->diffForHumans().')' : 'unknown'));

        if ($database && $checked['tables'] !== null) {
            $this->line("Database:  {$checked['tables']} tables, checksum {$checked['checksum']}");
        }

        if ($checked['files'] !== null) {
            $this->line("Files:     {$checked['files']['restore']} files (".Number::fileSize($checked['files']['bytes'], precision: 1).") can be copied back; {$checked['files']['skipped']} entries (code, .env, ...) are never restored");

            if ($checked['files']['unsafe'] > 0) {
                $this->warn("{$checked['files']['unsafe']} entries have unsafe names and will be refused.");
            }
        }

        $this->line('Target:    '.($database ? "database \"{$target['database']}\" on ".($target['host'] ?? 'localhost') : 'uploaded files on this server'));
        $this->newLine();

        if ($database && ! $this->option('skip-rehearsal')) {
            if (! $restorer->canRehearse()) {
                $this->line('No scratch-database test first (the restore test user is not set up).');
            } else {
                $this->line('Trying the dump in a scratch database first...');

                try {
                    $this->info(ucfirst($restorer->rehearse($source, (int) $checked['tables'])));
                } catch (Throwable $exception) {
                    $this->error('The dump does not load, so the database was not touched: '.$exception->getMessage());

                    return self::FAILURE;
                }
            }
        }

        if (! $this->confirmed($database, $takenAt, $target['database'] ?? '')) {
            return self::FAILURE;
        }

        $this->newLine();

        try {
            $result = $restorer->restore(
                $source,
                $database,
                $files,
                ! $this->option('skip-safety-backup'),
                ! $this->option('no-migrate'),
                $checked['tables'],
                fn (string $message) => $this->line($message),
            );
        } catch (BackupRestoreException $exception) {
            $this->failedAfterChanges($exception);

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Restore failed: '.$exception->getMessage());
            $this->line(app()->isDownForMaintenance()
                ? 'The app is in maintenance mode. Look at the message above, fix it, and run the same command again (it starts over), or run "php artisan up" if nothing was changed.'
                : 'Nothing was changed in the database.');

            return self::FAILURE;
        }

        $this->report($result);

        return self::SUCCESS;
    }

    /** Asks the person to type the database name (or the word "files"), or checks --confirm. */
    protected function confirmed(bool $database, ?CarbonInterface $takenAt, string $name): bool
    {
        $expected = $database ? $name : 'files';

        if ($this->option('confirm') === null) {
            if (! $this->input->isInteractive()) {
                $this->error("Not confirmed. To run without being asked, add --confirm={$expected}.");

                return false;
            }

            if ($database) {
                $this->warn("This REPLACES the whole database \"{$name}\" with the backup.");
                $this->warn($takenAt
                    ? "Everything saved after {$takenAt->format('d M Y, h:i A')} ({$takenAt->diffForHumans()}) will be gone."
                    : 'Everything saved since the backup was made will be gone.');
                $this->line($this->option('skip-safety-backup')
                    ? 'No safety copy will be taken (you asked to skip it).'
                    : 'A copy of the current database is taken first; if the restore fails it is put back.');
                $this->line('The app is in maintenance mode until the restore is finished.');
            } else {
                $this->warn('Uploaded files from the archive are copied over the ones on this server: files with the same name are replaced, nothing is deleted.');
            }

            $answer = (string) $this->ask("Type {$expected} to continue");
        } else {
            $answer = (string) $this->option('confirm');
        }

        if ($answer !== $expected) {
            $this->error('Not confirmed, so nothing was changed.');

            return false;
        }

        return true;
    }

    protected function failedAfterChanges(BackupRestoreException $exception): void
    {
        $this->error($exception->getMessage());

        match ($exception->rolledBack) {
            true => $this->info("The previous database was put back from the safety copy {$exception->safetyFile}; nothing was lost."),
            false => $this->putBackByHand((string) $exception->safetyFile),
            default => $this->warn('There was no safety copy, so the database may be empty or half loaded. Run the same command again (it starts over), or restore another backup.'),
        };

        $this->line(app()->isDownForMaintenance()
            ? 'The app is in maintenance mode. Run "php artisan up" when you are satisfied with the database.'
            : 'The app is online.');
    }

    protected function putBackByHand(string $safetyFile): void
    {
        $this->error("The previous database could NOT be put back automatically. The safety copy is {$safetyFile} (storage/app/private/backups/database).");
        $this->line("Put it back with: php artisan backup:restore {$safetyFile}");
    }

    protected function report(array $result): void
    {
        $this->newLine();
        $this->info('Restore finished.');

        if ($result['database'] !== null) {
            $database = $result['database'];
            $this->line("Database:  {$database['tables']} tables, {$database['migrations']} migrations (latest {$database['latest']})".match ($database['migrated']) {
                true => ', pending migrations run',
                false => ', MIGRATIONS FAILED (see below)',
                default => '',
            });

            if ($database['safety'] !== null) {
                $this->line("Safety copy of the database before the restore: {$database['safety']}");
                $this->line("  to go back to it: php artisan backup:restore {$database['safety']}");
            }
        }

        if ($result['files'] !== null) {
            $files = $result['files'];
            $this->line("Files:     {$files['restored']} copied (".Number::fileSize($files['bytes'], precision: 1).')'.($files['unsafe'] > 0 ? ", {$files['unsafe']} refused" : ''));
        }

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        $this->line(match ($result['maintenance']) {
            'lifted' => 'Maintenance mode is off: the app is online.',
            'left_on' => 'Maintenance mode is still ON. Fix the problem above, then run "php artisan up".',
            default => app()->isDownForMaintenance() ? 'The app was already in maintenance mode and still is: run "php artisan up" when ready.' : 'The app stays online.',
        });

        if ($result['database'] !== null) {
            $this->newLine();
            $this->line('Now check: sign in, the dashboard totals, the latest orders and payments, and /health/version.');
        }
    }

    /** What is on this server, to choose from when no file was named. */
    protected function listBackups(): void
    {
        foreach ([['Database', app(DatabaseBackupService::class)->all()], ['Full app', app(AppBackupService::class)->all()]] as [$title, $backups]) {
            if ($backups === []) {
                continue;
            }

            $this->newLine();
            $this->line("{$title} backups on this server (newest first):");

            foreach (array_slice($backups, 0, 8) as $backup) {
                $this->line("  {$backup['name']}  ({$backup['size_human']}, {$backup['modified_label']})");
            }
        }
    }
}
