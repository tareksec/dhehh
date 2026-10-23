<?php

namespace App\Services;

use App\Models\BackupRun;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The only proof that a backup works is restoring it. This loads a dump into a scratch
 * MySQL database named backup_drill_{time}_{random} on a separate, limited connection
 * (config backup.drill), checks what came back, and always drops the scratch database.
 * The live database is never touched: the connection has no default database and the
 * only names it ever creates or drops match a strict pattern.
 *
 * Two sources: the newest dump on this server, or the newest copy on Google Drive, which
 * is downloaded and decrypted first - that one also proves the encryption key is the
 * right one, and nobody finds out otherwise until the day the server is gone.
 *
 * Every run is recorded under scope "drill" (kind "local" or "drive") and a failure is
 * reported to the Super Admins like any other backup failure.
 */
class BackupRestoreDrill
{
    private const PREFIX = 'backup_drill_';

    /** backup_drill_20261023033000_ab12cd */
    private const NAME_PATTERN = '/^backup_drill_(\d{14})_[a-z0-9]{6}$/';

    /** Where a copy fetched from Drive is staged. */
    private const STAGING_DIRECTORY = 'backups/tmp';

    public function __construct(
        protected DatabaseBackupService $backups,
        protected OffsiteBackupService $offsite,
        protected BackupRunRecorder $runs,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('backup.drill.enabled')
            && is_array(config('database.connections.'.config('backup.drill.connection')));
    }

    /**
     * @param  string|null  $filename  a dump in the local backup folder; default: the newest
     * @param  bool  $fromDrive  test the newest copy on Google Drive instead
     * @return array<string, mixed> name, size, note (what was found)
     */
    public function run(?string $filename = null, bool $fromDrive = false): array
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('The restore test is not set up: set BACKUP_DRILL_DB_USERNAME (and its password) for a MySQL user that may create backup_drill_* databases. See docs/backup-restore.md.');
        }

        if ($filename !== null && $fromDrive) {
            throw new InvalidArgumentException('Choose a local file or the Google Drive copy, not both.');
        }

        return $this->runs->record(
            BackupRun::SCOPE_DRILL,
            $fromDrive ? BackupRun::DRILL_DRIVE : BackupRun::DRILL_LOCAL,
            fn (): array => $this->restoreAndCheck($filename, $fromDrive),
        );
    }

    /**
     * Load $dump into a scratch database and check it, without recording a run: backup:restore
     * calls this before it touches the live database, and the restore is the record.
     *
     * @param  array{name: string, path: string}  $dump
     * @return string what was found, e.g. "restored into a scratch database in 8 s: 162 tables, ..."
     */
    public function rehearse(array $dump, int $expectedTables): string
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('The restore test is not set up: set BACKUP_DRILL_DB_USERNAME (and its password) for a MySQL user that may create backup_drill_* databases. See docs/backup-restore.md.');
        }

        return $this->restoreIntoScratch($dump['path'], $expectedTables);
    }

    protected function restoreAndCheck(?string $filename, bool $fromDrive): array
    {
        $staged = null;

        try {
            if ($fromDrive) {
                $copy = $this->offsite->latestDatabaseDump()
                    ?? throw new RuntimeException('No database copy was found on Google Drive.');
                $staged = $this->offsite->fetch($copy, Storage::disk('local')->path(self::STAGING_DIRECTORY));
                $dump = ['name' => basename($staged), 'path' => $staged];
            } else {
                $dump = $this->localDump($filename);
            }

            // Cheap first: a dump that is cut short or does not match its checksum is easier
            // to explain from this than from a half-loaded database.
            $checked = $this->backups->verifyDump($dump);

            return [
                'name' => $dump['name'],
                'size' => (int) filesize($dump['path']),
                'note' => ucfirst($this->restoreIntoScratch($dump['path'], (int) $checked['table_count'])),
            ];
        } finally {
            if ($staged !== null) {
                File::delete($staged);
            }
        }
    }

    /** @return array{name: string, path: string} */
    protected function localDump(?string $filename): array
    {
        $dump = $filename !== null
            ? $this->backups->find($filename)
            : collect($this->backups->all())->first(fn (array $backup): bool => str_ends_with($backup['name'], '.sql.gz'));

        if ($dump === null || ! str_ends_with($dump['name'], '.sql.gz')) {
            throw new RuntimeException($filename !== null
                ? "[{$filename}] is not a compressed MySQL dump in the backup folder."
                : 'There is no compressed MySQL dump on this server to test yet.');
        }

        return ['name' => $dump['name'], 'path' => $dump['path']];
    }

    /** @return string what was found, for the backup history */
    protected function restoreIntoScratch(string $path, int $expectedTables): string
    {
        $connectionName = (string) config('backup.drill.connection');
        $config = (array) config("database.connections.{$connectionName}");
        $connection = DB::connection($connectionName);
        $scratch = self::PREFIX.now()->format('YmdHis').'_'.Str::lower(Str::random(6));
        $started = microtime(true);

        $this->dropLeftovers($connection);

        // $scratch is built from digits and lower-case letters only; it is still checked
        // before it is put into SQL, here and again before the drop.
        $this->assertScratchName($scratch);
        $connection->statement("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        try {
            $this->load($config, $scratch, $path);

            $tables = (int) $connection->scalar('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$scratch]);

            if ($tables !== $expectedTables) {
                throw new RuntimeException("The restored database has {$tables} tables but the dump defines {$expectedTables}.");
            }

            $migrations = (int) $connection->scalar("SELECT COUNT(*) FROM `{$scratch}`.`migrations`");

            if ($migrations < 1) {
                throw new RuntimeException('The restored database has an empty migrations table.');
            }

            $latest = (string) $connection->scalar("SELECT migration FROM `{$scratch}`.`migrations` ORDER BY id DESC LIMIT 1");
        } finally {
            $this->assertScratchName($scratch);
            $connection->statement("DROP DATABASE IF EXISTS `{$scratch}`");
        }

        return sprintf(
            'restored into a scratch database in %d s: %d tables, %d migrations (latest %s).',
            round(microtime(true) - $started),
            $tables,
            $migrations,
            $latest,
        );
    }

    /** Streams the dump through gunzip into the mysql client, never holding it in memory. */
    protected function load(array $config, string $scratch, string $path): void
    {
        $this->backups->loadDump(
            $config,
            $scratch,
            $path,
            (string) config('backup.drill.mysql_binary', 'mysql'),
            (float) config('backup.drill.timeout', 1800),
        );
    }

    /** A scratch database left by a run that was killed: dropped once it is a day old. */
    protected function dropLeftovers(Connection $connection): void
    {
        foreach ($connection->select("SHOW DATABASES LIKE 'backup\\_drill\\_%'") as $row) {
            $name = (string) array_values((array) $row)[0];

            if (preg_match(self::NAME_PATTERN, $name, $match) === 1
                && now()->createFromFormat('YmdHis', $match[1])->lt(now()->subDay())) {
                $connection->statement("DROP DATABASE IF EXISTS `{$name}`");
            }
        }
    }

    protected function assertScratchName(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new RuntimeException('Refusing to touch a database that is not a restore-test scratch database.');
        }
    }
}
