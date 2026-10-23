<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\DatabaseBackupService;
use App\Services\DeploymentErrorReporter;
use App\Services\OffsiteBackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * The container's start command runs this instead of `php artisan migrate`
 * directly (see nixpacks.toml) so a broken migration can never take the
 * whole app down: this command always exits successfully, and reports any
 * failure to super admins via DeploymentErrorReporter instead of letting it
 * fail silently. That gap is exactly what caused two real production 500s
 * (see CHANGELOG) -- the deploy pipeline has no automatic `artisan migrate`
 * step, so a migration added in a feature commit could reach production
 * without ever having actually run.
 */
class DeployMigrate extends Command
{
    /**
     * --path is forwarded to the underlying migrate command. Only ever used
     * by tests, to point at a throwaway migration outside database/
     * migrations that can safely be made to fail.
     */
    protected $signature = 'deploy:migrate {--path= : Migration path to run instead of database/migrations}';

    protected $description = 'Runs pending migrations as part of a deploy; reports failures to super admins instead of failing the deploy.';

    public function handle(DeploymentErrorReporter $reporter, DatabaseBackupService $backups): int
    {
        $path = filled($this->option('path')) ? (string) $this->option('path') : null;

        $this->backupBeforeMigrating($backups, $path);

        try {
            // --isolated: safe under multiple replicas starting at once --
            // only the first to grab the lock actually runs the migration.
            $options = ['--force' => true, '--isolated' => true];

            if ($path !== null) {
                $options['--path'] = $path;
            }

            $this->call('migrate', $options);
            $this->info('Migrations are up to date.');
        } catch (Throwable $exception) {
            $this->error("Migration failed: {$exception->getMessage()}");

            $reporter->report('migration', $exception, [
                'command' => 'migrate --force --isolated',
            ]);
        }

        // Always succeed: the app must still start and serve the "it's
        // broken" notification above, rather than the whole site going down
        // because one migration failed.
        return self::SUCCESS;
    }

    /**
     * A database backup (kind "predeploy") right before migrations that are
     * about to run, so a bad migration can be undone from a copy taken seconds
     * earlier. Nothing is pending on most deploys, and then nothing is made.
     *
     * A failed backup never stops the deploy, in line with the rest of this
     * command: it is recorded in the backup history and reported to the Super
     * Admins (BackupRunRecorder), and the migrations still run.
     */
    protected function backupBeforeMigrating(DatabaseBackupService $backups, ?string $path): void
    {
        try {
            if (! $this->hasPendingMigrations($path)) {
                return;
            }
        } catch (Throwable) {
            // Cannot even read the migrations table (database unreachable):
            // migrate below fails and reports that on its own.
            return;
        }

        try {
            // The most valuable copy to have off the server: a deploy may replace the
            // container (and with it the local backup folder) right after this.
            $upload = app(OffsiteBackupService::class)->shouldAutoUpload(BackupRun::SCOPE_DATABASE, BackupRun::KIND_PREDEPLOY);

            $backup = $backups->create(config('backup.app.database_connection'), BackupRun::KIND_PREDEPLOY, $upload, automatic: true);

            $this->info("Database backup before migrating: {$backup['name']}");
        } catch (Throwable $exception) {
            $this->warn("Database backup before migrating failed, migrating anyway: {$exception->getMessage()}");
        }
    }

    protected function hasPendingMigrations(?string $path): bool
    {
        $migrator = app('migrator');
        $repository = $migrator->getRepository();

        // A brand-new database has nothing worth backing up.
        if (! $repository->repositoryExists()) {
            return false;
        }

        $paths = $path !== null ? [base_path($path)] : [...$migrator->paths(), database_path('migrations')];

        return array_diff(array_keys($migrator->getMigrationFiles($paths)), $repository->getRan()) !== [];
    }
}
