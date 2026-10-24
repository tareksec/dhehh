<?php

namespace App\Services;

use App\Models\BackupRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Puts a backup back. The one place that replaces the live database, so it is built to leave
 * nothing half done and to undo itself:
 *
 *  1. prepare()  - find the file (backup folder, a path, or the newest copy off-site: R2 or Drive),
 *                  decrypt it, and take the dump out of a full app archive. Touches nothing live.
 *  2. check()    - read the dump to the end and compare its checksum; look through the archive.
 *  3. rehearse() - load the dump into a scratch database (the restore test), if that is set up.
 *  4. restore()  - copy the current database first (kind "prerestore"), switch maintenance mode
 *                  on, empty the database, load the dump, run pending migrations, clear caches,
 *                  switch maintenance mode off. If the load fails, the safety copy is loaded back.
 *
 * Emptying first matters: a dump only replaces the tables it contains, and a table that a newer
 * migration created would stay behind and make the next `migrate` fail with "already exists".
 *
 * From a full app archive only storage/app/public and storage/app/private (config
 * backup.restore.file_paths) are copied back, file by file, never deleting anything. The code
 * comes from the deploy and the .env from the server's environment: neither is overwritten.
 *
 * MySQL/MariaDB only. Every step that touches the database or the machine is a small protected
 * method so the tests can replace it.
 */
class BackupRestorer
{
    public const DUMP = 'dump';

    public const ARCHIVE = 'archive';

    /** Where a downloaded or decrypted copy is unpacked while it is restored. */
    private const STAGING_DIRECTORY = 'backups/tmp';

    public function __construct(
        protected DatabaseBackupService $databases,
        protected AppBackupService $apps,
        protected OffsiteBackupService $offsite,
        protected BackupEncryptor $encryptor,
        protected BackupRestoreDrill $drill,
        protected BackupRunRecorder $runs,
    ) {}

    /**
     * Find $file (a name in the backup folders, or a path) or fetch the newest database copy from
     * an off-site place, decrypt it and unpack the dump. The caller must release() the result.
     *
     * @param  bool|string  $offsite  false = use $file; true = the newest copy in the first off-site
     *                                place that is set up (R2, else Drive); "r2" or "drive" = that place
     *
     * @return array{label: string, name: string, kind: string, path: string, dump: ?array{name: string, path: string}, taken_at: ?CarbonInterface, size: int, directory: string}
     */
    public function prepare(?string $file, bool|string $offsite = false, ?string $key = null): array
    {
        $fromOffsite = $offsite !== false;

        if ($fromOffsite === ($file !== null)) {
            throw new InvalidArgumentException('Name one backup file, or use --offsite (or --r2, --drive) for the newest database copy off-site.');
        }

        $this->deleteOldStaging();

        $directory = Storage::disk('local')->path(self::STAGING_DIRECTORY.'/restore-'.now()->format('YmdHis').'-'.Str::lower(Str::random(6)));
        File::ensureDirectoryExists($directory);

        try {
            if ($fromOffsite) {
                $place = $this->offsite->destination(is_string($offsite) ? $offsite : null);
                $copy = $this->offsite->latestDatabaseDump($place->key())
                    ?? throw new RuntimeException("No database copy was found on {$place->label()}.");
                $label = $copy['name'];
                $path = $this->offsite->fetch($copy, $directory, $key);
            } else {
                $path = $this->locate((string) $file);
                $label = basename($path);

                if (str_ends_with($path, BackupEncryptor::EXTENSION)) {
                    $plain = $directory.'/'.basename(substr($path, 0, -strlen(BackupEncryptor::EXTENSION)));
                    $this->encryptor->decryptFile($path, $plain, $key);
                    $path = $plain;
                }
            }

            $name = basename($path);
            $kind = match (true) {
                str_ends_with($name, '.sql.gz') => self::DUMP,
                str_ends_with($name, '.zip') => self::ARCHIVE,
                default => throw new RuntimeException("[{$name}] is not a MySQL dump (.sql.gz) or a full app archive (.zip), or either of them with .enc. A SQLite file or an old plain .sql dump is restored by hand: see docs/backup-restore.md."),
            };

            return [
                'label' => $label,
                'name' => $name,
                'kind' => $kind,
                'path' => $path,
                'dump' => $kind === self::DUMP ? ['name' => $name, 'path' => $path] : $this->extractDump($path, $directory),
                'taken_at' => $this->takenAt($name, $path),
                'size' => (int) filesize($path),
                'directory' => $directory,
            ];
        } catch (Throwable $exception) {
            File::deleteDirectory($directory);

            throw $exception;
        }
    }

    /** Deletes what prepare() unpacked (the decrypted copy, the extracted dump). */
    public function release(array $source): void
    {
        if (isset($source['directory'])) {
            File::deleteDirectory($source['directory']);
        }
    }

    /**
     * Read the dump to the end and check its checksum, and look through the archive's files.
     * Throws when the dump is incomplete or no longer matches the checksum recorded when it was made.
     *
     * @return array{tables: ?int, checksum: ?string, files: ?array{restore: int, bytes: int, skipped: int, unsafe: int}}
     */
    public function check(array $source): array
    {
        $dump = $source['dump'] !== null ? $this->databases->verifyDump($source['dump']) : null;
        $files = null;

        if ($source['kind'] === self::ARCHIVE) {
            $zip = $this->openArchive($source['path']);

            try {
                $plan = $this->planFiles($zip);
            } finally {
                $zip->close();
            }

            $files = [
                'restore' => count($plan['restore']),
                'bytes' => (int) array_sum(array_column($plan['restore'], 'size')),
                'skipped' => $plan['skipped'],
                'unsafe' => $plan['unsafe'],
            ];
        }

        return ['tables' => $dump['table_count'] ?? null, 'checksum' => $dump['checksum'] ?? null, 'files' => $files];
    }

    /** Whether a scratch database is available to try the dump in first. */
    public function canRehearse(): bool
    {
        return $this->drill->isEnabled();
    }

    /** @return string what the scratch restore found; throws when the dump does not load */
    public function rehearse(array $source, int $expectedTables): string
    {
        return $this->drill->rehearse($source['dump'] ?? throw new RuntimeException('This archive holds no database dump.'), $expectedTables);
    }

    /**
     * @param  bool  $database  replace the database with the dump
     * @param  bool  $files  copy the archive's uploaded files back
     * @param  bool  $safetyBackup  copy the current database first (the way back if this was a mistake)
     * @param  bool  $migrate  run pending migrations once the dump is loaded (the code may be newer than the backup)
     * @param  ?int  $expectedTables  how many tables the dump defines (from check()); the restored database must have as many
     * @param  callable(string): void  $progress
     * @return array{database: ?array{tables: int, migrations: int, latest: string, safety: ?string, migrated: ?bool}, files: ?array{restored: int, bytes: int, skipped: int, unsafe: int}, warnings: array<int, string>, maintenance: string}
     *
     * @throws BackupRestoreException the database step failed after it began (says whether it was rolled back)
     */
    public function restore(array $source, bool $database, bool $files, bool $safetyBackup, bool $migrate, ?int $expectedTables, callable $progress): array
    {
        if (! $database && ! $files) {
            throw new InvalidArgumentException('Choose what to restore: the database, the files, or both.');
        }

        if ($database && $source['dump'] === null) {
            throw new RuntimeException('This archive holds no database dump.');
        }

        if ($files && $source['kind'] !== self::ARCHIVE) {
            throw new RuntimeException('Uploaded files come from a full app archive (.zip); a .sql.gz holds only the database.');
        }

        $lock = $this->lock();
        $started = now();
        $what = match (true) {
            $database && $files => BackupRun::RESTORED_BOTH,
            $database => BackupRun::RESTORED_DATABASE,
            default => BackupRun::RESTORED_FILES,
        };
        $result = ['database' => null, 'files' => null, 'warnings' => [], 'maintenance' => 'unchanged'];
        $down = false;

        Log::warning('backup:restore started', ['file' => $source['label'], 'what' => $what]);

        try {
            if ($database) {
                [$connection, $config] = $this->databaseTarget();
                $safety = $this->safetyCopy($connection, $safetyBackup, $progress);

                if (! $this->isDown()) {
                    $this->enterMaintenance();
                    $down = true;
                    $progress('Maintenance mode is on.');
                }

                $result['database'] = [
                    ...$this->replaceDatabase($connection, $config, $source['dump'], $safety, $expectedTables, $progress),
                    'safety' => $safety['name'] ?? null,
                    'migrated' => null,
                ];

                if ($migrate) {
                    $progress('Running pending migrations...');

                    try {
                        $this->migrate();
                        $result['database']['migrated'] = true;
                    } catch (Throwable $exception) {
                        $result['database']['migrated'] = false;
                        $result['warnings'][] = 'The database was restored but the migrations failed: '.$exception->getMessage().' Run "php artisan migrate --force", then "php artisan up".';
                    }
                }

                array_push($result['warnings'], ...$this->databaseWarnings($connection));
            }

            if ($files) {
                $result['files'] = $this->copyFiles($source, $progress);
            }

            $progress('Clearing caches...');

            try {
                $this->clearCaches();
            } catch (Throwable $exception) {
                $result['warnings'][] = 'Caches could not be cleared: '.$exception->getMessage().' Run "php artisan optimize:clear".';
            }

            if ($down) {
                if (($result['database']['migrated'] ?? true) === false) {
                    $result['maintenance'] = 'left_on';
                } else {
                    $this->leaveMaintenance();
                    $result['maintenance'] = 'lifted';
                    $progress('Maintenance mode is off.');
                }
            }
        } catch (Throwable $exception) {
            // The previous database is back exactly as it was: nothing is wrong, so the app need not stay closed.
            if ($down && $exception instanceof BackupRestoreException && $exception->rolledBack === true) {
                rescue(fn () => $this->leaveMaintenance(), report: false);
            }

            $this->failed($source, $what, $started, $exception);

            throw $exception;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->succeeded($source, $what, $started, $result);

        return $result;
    }

    // --- the file to restore ---------------------------------------------

    protected function locate(string $file): string
    {
        if (! str_contains($file, '/') && ! str_contains($file, '\\')) {
            $found = $this->databases->find($file) ?? $this->apps->find($file);

            if ($found !== null) {
                return $found['path'];
            }
        }

        if (is_file($file)) {
            return realpath($file) ?: $file;
        }

        throw new RuntimeException("[{$file}] was not found in the backup folders or as a file.");
    }

    /** The dump inside a full app archive (database/*.sql.gz); null when it holds none. */
    protected function extractDump(string $archive, string $directory): ?array
    {
        $zip = $this->openArchive($archive);

        try {
            $names = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);

                if (preg_match('#^database/([^/]+\.sql\.gz)$#', $entry, $match) === 1) {
                    $names[$match[1]] = $entry;
                }
            }

            if ($names === []) {
                return null;
            }

            // The date and time are in the name, so the last one is the newest.
            krsort($names);
            $name = (string) array_key_first($names);
            $stream = $zip->getStream($names[$name]);
            $path = $directory.'/'.$name;

            if ($stream === false || ! $this->writeStream($stream, $path)) {
                throw new RuntimeException('The database dump could not be taken out of the archive (is the disk full?).');
            }

            return ['name' => $name, 'path' => $path];
        } finally {
            $zip->close();
        }
    }

    protected function openArchive(string $path): ZipArchive
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP Zip extension is required to open a full app backup.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The archive could not be opened. Is the download complete?');
        }

        return $zip;
    }

    /** When the backup was made: the date in its name, else the file's modification time. */
    protected function takenAt(string $name, string $path): ?CarbonInterface
    {
        if (preg_match('/(\d{8}-\d{6})/', $name, $match) === 1) {
            $time = Carbon::createFromFormat('Ymd-His', $match[1]);

            if ($time !== false) {
                return $time;
            }
        }

        $modified = @filemtime($path);

        return $modified === false ? null : Carbon::createFromTimestamp($modified);
    }

    /** Unpacked copies left by a restore that was killed hold a whole database: remove them once a day old. */
    protected function deleteOldStaging(): void
    {
        $staging = Storage::disk('local')->path(self::STAGING_DIRECTORY);

        if (! is_dir($staging)) {
            return;
        }

        $limit = now()->subDay()->timestamp;

        foreach (File::directories($staging) as $directory) {
            if (str_starts_with(basename($directory), 'restore-') && File::lastModified($directory) < $limit) {
                File::deleteDirectory($directory);
            }
        }
    }

    // --- the database ----------------------------------------------------

    /** @return array{0: string, 1: array<string, mixed>} the default connection and its settings */
    protected function databaseTarget(): array
    {
        $name = (string) config('database.default');
        $config = (array) config("database.connections.{$name}");

        if (! in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('backup:restore replaces a MySQL/MariaDB database. For SQLite, copy the backup over the database file as described in docs/backup-restore.md.');
        }

        if (blank($config['database'] ?? null)) {
            throw new RuntimeException("Database connection [{$name}] has no database name.");
        }

        return [$name, $config];
    }

    /** @return ?array the copy of the current database (name and path), or null when none was needed or wanted */
    protected function safetyCopy(string $connection, bool $wanted, callable $progress): ?array
    {
        if (! $wanted) {
            $progress('No safety copy of the current database (you asked to skip it).');

            return null;
        }

        if (! $this->hasTables($connection)) {
            $progress('The database is empty, so there is nothing to copy first.');

            return null;
        }

        $progress('Taking a safety copy of the current database...');
        $copy = $this->databases->create($connection, BackupRun::KIND_PRERESTORE);
        $progress("Safety copy: {$copy['name']}");

        return $copy;
    }

    /** @return array{tables: int, migrations: int, latest: string} */
    protected function replaceDatabase(string $connection, array $config, array $dump, ?array $safety, ?int $expectedTables, callable $progress): array
    {
        try {
            $progress('Emptying the current database...');
            $this->wipe($connection);

            $progress('Loading the backup (this can take a while)...');
            $this->load($config, $dump['path']);

            $found = $this->summarise($connection);

            if ($expectedTables !== null && $found['tables'] !== $expectedTables) {
                throw new RuntimeException("The restored database has {$found['tables']} tables but the dump defines {$expectedTables}.");
            }

            return $found;
        } catch (Throwable $cause) {
            throw $this->rollBack($connection, $config, $safety, $cause, $progress);
        }
    }

    /** Loads the safety copy back after a failed restore, once, and says how it went. */
    protected function rollBack(string $connection, array $config, ?array $safety, Throwable $cause, callable $progress): BackupRestoreException
    {
        $message = 'Restoring failed: '.$cause->getMessage();

        if ($safety === null) {
            return new BackupRestoreException($message, null, null, $cause);
        }

        try {
            $progress('The restore failed; putting the previous database back from the safety copy...');
            $this->wipe($connection);
            $this->load($config, $safety['path']);
        } catch (Throwable $rollback) {
            return new BackupRestoreException($message.' Putting the previous database back also failed: '.$rollback->getMessage(), false, $safety['name'], $cause);
        }

        return new BackupRestoreException($message, true, $safety['name'], $cause);
    }

    protected function hasTables(string $connection): bool
    {
        return $this->tables($connection) !== [];
    }

    /**
     * The tables of the connection's own database. getTables() with no argument lists every
     * schema the user can see, which on a shared MySQL server is more than this application.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function tables(string $connection): array
    {
        $schema = Schema::connection($connection);

        return $schema->getTables($schema->getCurrentSchemaListing());
    }

    /** Drops every table and view of the connection's database (never the database itself). */
    protected function wipe(string $connection): void
    {
        $schema = Schema::connection($connection);
        $schema->dropAllViews();
        $schema->dropAllTables();
    }

    protected function load(array $config, string $path): void
    {
        $this->databases->loadDump(
            $config,
            (string) $config['database'],
            $path,
            (string) config('backup.restore.mysql_binary', 'mysql'),
            (float) config('backup.restore.timeout', 3600),
        );
    }

    /** @return array{tables: int, migrations: int, latest: string} */
    protected function summarise(string $connection): array
    {
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('migrations')) {
            throw new RuntimeException('The restored database has no migrations table.');
        }

        $migrations = DB::connection($connection)->table('migrations');

        return [
            'tables' => count($this->tables($connection)),
            'migrations' => (clone $migrations)->count(),
            'latest' => (string) ((clone $migrations)->orderByDesc('id')->value('migration') ?? ''),
        ];
    }

    /** @return array<int, string> things worth a second look after the load */
    protected function databaseWarnings(string $connection): array
    {
        $warnings = [];

        try {
            $migrator = app('migrator');
            $known = array_keys($migrator->getMigrationFiles([...$migrator->paths(), database_path('migrations')]));
            $unknown = array_values(array_diff($migrator->getRepository()->getRan(), $known));

            if ($unknown !== []) {
                $warnings[] = count($unknown).' migration(s) in the backup are not in this version of the app (latest: '.end($unknown).'). The backup was made by a newer version: deploy that version before using the app.';
            }

            if (config('queue.default') === 'database' && Schema::connection($connection)->hasTable('jobs')) {
                $jobs = DB::connection($connection)->table('jobs')->count();

                if ($jobs > 0) {
                    $warnings[] = "{$jobs} queued job(s) came back with the backup and will run again (some may have run before the backup was restored). Run \"php artisan queue:clear\" if they should not.";
                }
            }
        } catch (Throwable $exception) {
            $warnings[] = 'The restored database could not be inspected: '.$exception->getMessage();
        }

        return $warnings;
    }

    protected function migrate(): void
    {
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException(trim(Artisan::output()) ?: 'migrate exited with an error.');
        }
    }

    protected function clearCaches(): void
    {
        Artisan::call('optimize:clear');
    }

    protected function isDown(): bool
    {
        return app()->isDownForMaintenance();
    }

    protected function enterMaintenance(): void
    {
        Artisan::call('down', ['--retry' => 60]);
    }

    protected function leaveMaintenance(): void
    {
        Artisan::call('up');
    }

    // --- the files -------------------------------------------------------

    /**
     * Copies the archive's uploaded files back, overwriting the same names and deleting nothing.
     *
     * @return array{restored: int, bytes: int, skipped: int, unsafe: int}
     */
    protected function copyFiles(array $source, callable $progress): array
    {
        $zip = $this->openArchive($source['path']);
        $restored = 0;
        $bytes = 0;
        $partial = null;

        try {
            $plan = $this->planFiles($zip);
            $unsafe = $plan['unsafe'];
            $progress('Copying '.count($plan['restore']).' files back...');
            $roots = $this->fileRoots();

            foreach ($plan['restore'] as $file) {
                $target = $file['target'];
                File::ensureDirectoryExists(dirname($target));

                // A link in the way, or a folder that resolves outside the allowed ones, is not followed.
                if (is_link($target) || ! $this->isInside(dirname($target), $roots)) {
                    $unsafe++;

                    continue;
                }

                $stream = $zip->getStream($file['name']);
                $partial = $target.'.restoring';

                if ($stream === false || ! $this->writeStream($stream, $partial)) {
                    throw new RuntimeException("The file {$file['name']} could not be written (is the disk full?).");
                }

                File::move($partial, $target);
                $partial = null;
                @touch($target, $file['mtime']);
                $restored++;
                $bytes += $file['size'];
            }
        } finally {
            if ($partial !== null) {
                File::delete($partial);
            }

            $zip->close();
        }

        return ['restored' => $restored, 'bytes' => $bytes, 'skipped' => $plan['skipped'], 'unsafe' => $unsafe];
    }

    /**
     * What a restore of this archive would copy back.
     *
     * @return array{restore: array<int, array{name: string, target: string, size: int, mtime: int}>, skipped: int, unsafe: int}
     */
    protected function planFiles(ZipArchive $zip): array
    {
        $plan = ['restore' => [], 'skipped' => 0, 'unsafe' => 0];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');

            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }

            [$verdict, $target] = $this->classify($name);

            if ($verdict === 'restore') {
                $plan['restore'][] = ['name' => $name, 'target' => (string) $target, 'size' => (int) $stat['size'], 'mtime' => (int) $stat['mtime']];
            } else {
                $plan[$verdict === 'unsafe' ? 'unsafe' : 'skipped']++;
            }
        }

        return $plan;
    }

    /**
     * Whether an archive entry is copied back, and where to. Anything that is not under one of
     * backup.restore.file_paths (the code, .env, the database folder) is skipped; a name that
     * could climb out of the folder is refused.
     *
     * @return array{0: 'restore'|'skip'|'unsafe', 1: ?string}
     */
    protected function classify(string $entry): array
    {
        $name = str_replace('\\', '/', $entry);

        if (str_starts_with($name, '/') || str_contains($name, "\0") || preg_match('#(^|/)\.\.?(/|$)#', $name) === 1 || preg_match('#^[A-Za-z]:#', $name) === 1) {
            return ['unsafe', null];
        }

        $inside = collect((array) config('backup.restore.file_paths', []))
            ->contains(fn (string $path): bool => str_starts_with($name, trim($path, '/').'/'));

        if (! $inside || $this->apps->isExcluded($name)) {
            return ['skip', null];
        }

        return ['restore', $this->fileBase().'/'.$name];
    }

    /** The folder the archive's paths are relative to: the application's root, as when the archive was made. */
    protected function fileBase(): string
    {
        return base_path();
    }

    /** @return array<int, string> the real paths of the folders files may be written to */
    protected function fileRoots(): array
    {
        $roots = [];

        foreach ((array) config('backup.restore.file_paths', []) as $path) {
            File::ensureDirectoryExists($this->fileBase().'/'.$path);
            $roots[] = (string) realpath($this->fileBase().'/'.$path);
        }

        return array_filter($roots);
    }

    /** @param  array<int, string>  $roots */
    protected function isInside(string $directory, array $roots): bool
    {
        $real = realpath($directory);

        return $real !== false && collect($roots)->contains(fn (string $root): bool => $real === $root || str_starts_with($real, $root.DIRECTORY_SEPARATOR));
    }

    /** @param  resource  $stream */
    protected function writeStream($stream, string $destination): bool
    {
        $output = @fopen($destination, 'wb');

        if ($output === false) {
            fclose($stream);

            return false;
        }

        $copied = stream_copy_to_stream($stream, $output);
        fclose($stream);

        if (! fclose($output) || $copied === false) {
            File::delete($destination);

            return false;
        }

        return true;
    }

    // --- bookkeeping -----------------------------------------------------

    /** @return resource */
    protected function lock()
    {
        $path = Storage::disk('local')->path('backups/restore.lock');
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'c');

        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another restore is running on this server.');
        }

        return $handle;
    }

    protected function succeeded(array $source, string $what, CarbonInterface $started, array $result): void
    {
        $parts = array_filter([
            $result['database'] ? "database: {$result['database']['tables']} tables, {$result['database']['migrations']} migrations" : null,
            $result['files'] ? "files: {$result['files']['restored']} copied" : null,
            ($result['database']['safety'] ?? null) ? "safety copy {$result['database']['safety']}" : null,
        ]);

        Log::warning('backup:restore finished', ['file' => $source['label'], 'what' => $what, 'result' => $parts]);

        if ($result['database'] !== null) {
            $this->runs->discardUnfinished();
        }

        // After the restore, because it replaces the table this row goes in.
        $this->runs->recordFinished(BackupRun::SCOPE_RESTORE, $what, [
            'filename' => $source['label'],
            'size' => $source['size'],
            'note' => implode('; ', $parts),
            'started_at' => $started,
        ]);
    }

    protected function failed(array $source, string $what, CarbonInterface $started, Throwable $exception): void
    {
        Log::error('backup:restore failed', ['file' => $source['label'], 'what' => $what, 'error' => $exception->getMessage()]);

        // The safety copy that was loaded back carries the "running" row of its own backup.
        if ($exception instanceof BackupRestoreException && $exception->rolledBack === true) {
            $this->runs->discardUnfinished();
        }

        $this->runs->recordFinished(BackupRun::SCOPE_RESTORE, $what, [
            'status' => BackupRun::STATUS_FAILED,
            'filename' => $source['label'],
            'size' => $source['size'],
            'error' => $exception->getMessage(),
            'started_at' => $started,
        ]);

        rescue(fn () => app(DeploymentErrorReporter::class)->report('backup:restore', $exception, ['file' => $source['label'], 'what' => $what]), report: false);
    }
}
