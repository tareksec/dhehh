<?php

namespace App\Services;

use App\Models\BackupRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class DatabaseBackupService
{
    public const DIRECTORY = 'backups/database';

    public function __construct(
        protected BackupRunRecorder $runs,
        protected OffsiteBackupService $offsite,
    ) {}

    /**
     * File name: database-backup-{date}-{connection}-{kind}.{sqlite|sql.gz}.
     * The kind is part of the name so each kind can be pruned on its own and
     * still be told apart when the database itself is what is being restored.
     *
     * With $upload the new file is also sent, encrypted, to Google Drive as
     * part of the same recorded run, so a failed upload shows up in the backup
     * history and raises the alert even though the local file exists.
     */
    public function create(?string $connection = null, string $kind = BackupRun::KIND_MANUAL, bool $upload = false): array
    {
        if (! in_array($kind, BackupRun::KINDS, true)) {
            throw new InvalidArgumentException('Backup kind must be one of: '.implode(', ', BackupRun::KINDS).'.');
        }

        try {
            return $this->runs->record(
                BackupRun::SCOPE_DATABASE,
                $kind,
                function () use ($connection, $kind, $upload): array {
                    $backup = $this->createFile($connection, $kind);

                    if ($upload) {
                        $backup['google_drive'] = $this->offsite->send($backup['path']);
                    }

                    return $backup;
                },
            );
        } finally {
            // Also when the upload failed: Google Drive being down for a day must
            // not let the local files pile up past their limit.
            rescue(fn () => $this->prune());
        }
    }

    public function all(): array
    {
        return collect(Storage::disk('local')->files(self::DIRECTORY))
            ->filter(fn (string $path): bool => str($path)->endsWith(['.sqlite', '.sql', '.sql.gz']))
            ->map(fn (string $path): array => $this->fileDetails($path))
            ->sortBy([['modified_at', 'desc'], ['name', 'desc']])
            ->values()
            ->all();
    }

    public function find(string $filename): ?array
    {
        $filename = basename($filename);
        $path = self::DIRECTORY.'/'.$filename;

        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        return $this->fileDetails($path);
    }

    /**
     * Keep the newest files of each kind (see config/backup.php) and delete the
     * rest, plus any half-written `.part` file a killed process left behind.
     */
    public function prune(): void
    {
        $byKind = collect($this->all())->groupBy('kind');

        foreach (BackupRun::KINDS as $kind) {
            $keep = max(1, (int) config("backup.database.keep.{$kind}", 10));

            $byKind->get($kind, collect())
                ->skip($keep)
                ->each(fn (array $file): bool => Storage::disk('local')->delete($file['relative_path']));
        }

        BackupFiles::deleteStalePartials(self::DIRECTORY);
    }

    /**
     * Check a backup without touching the live database. A SQLite backup is
     * restored into a disposable file and integrity-checked; a compressed
     * MySQL dump is read to the end and checked for completeness, for a
     * migrations table and against the checksum recorded when it was made.
     * (A full restore drill into a scratch MySQL database is a later step.)
     */
    public function verifyRestore(string $filename): array
    {
        $backup = $this->find($filename);

        if ($backup && str_ends_with($backup['name'], '.sqlite')) {
            return $this->verifySqlite($backup);
        }

        if ($backup && str_ends_with($backup['name'], '.sql.gz')) {
            return $this->verifyDump($backup);
        }

        throw new RuntimeException('A valid SQLite database backup or compressed MySQL dump is required for the restore drill.');
    }

    /**
     * Run a command and hand its output to $onOutput / $onError as it arrives,
     * never holding the whole dump in memory. Returns the exit code.
     *
     * @param  array<int, string>  $command
     * @param  callable(string): void  $onOutput
     * @param  callable(string): void  $onError
     */
    protected function runDump(array $command, callable $onOutput, callable $onError): int
    {
        $process = new Process($command);
        $process->setTimeout((float) config('backup.database.timeout', 900));
        $process->disableOutput();
        $process->run(function (string $type, string $buffer) use ($onOutput, $onError): void {
            $type === Process::OUT ? $onOutput($buffer) : $onError($buffer);
        });

        return (int) $process->getExitCode();
    }

    protected function createFile(?string $connection, string $kind): array
    {
        $connection ??= config('database.default');
        $config = config("database.connections.{$connection}");

        if (! is_array($config)) {
            throw new RuntimeException("Database connection [{$connection}] is not configured.");
        }

        $driver = $config['driver'] ?? null;
        $extension = $driver === 'sqlite' ? 'sqlite' : 'sql.gz';
        $filename = sprintf(
            'database-backup-%s-%s-%s.%s',
            now()->format('Ymd-His'),
            str($connection)->slug(),
            $kind,
            $extension,
        );
        $relativePath = self::DIRECTORY.'/'.$filename;
        $absolutePath = Storage::disk('local')->path($relativePath);

        File::ensureDirectoryExists(dirname($absolutePath));

        match ($driver) {
            'sqlite' => $this->backupSqlite($config, $absolutePath),
            'mysql', 'mariadb' => $this->backupMysql($config, $absolutePath),
            default => throw new RuntimeException("Database backups are not supported for [{$driver}] connections yet."),
        };

        return [
            ...$this->fileDetails($relativePath),
            'sha256' => hash_file('sha256', $absolutePath),
        ];
    }

    protected function backupSqlite(array $config, string $absolutePath): void
    {
        $database = $config['database'] ?? null;

        if (! is_string($database) || $database === ':memory:' || ! File::exists($database)) {
            throw new RuntimeException('SQLite database file could not be found for backup.');
        }

        File::copy($database, $absolutePath);
    }

    /**
     * Streams mysqldump into a .part file through gzip and renames it only once
     * the dump ended with its "Dump completed" line, so a half-written dump
     * can never sit in the backup list looking like a good one. The password
     * goes in a temporary option file (mode 0600), not on the command line
     * where any process on the server could read it.
     */
    protected function backupMysql(array $config, string $absolutePath): void
    {
        $binary = (string) config('backup.database.mysqldump_binary', 'mysqldump');

        if (! $this->binaryExists($binary)) {
            throw new RuntimeException("[{$binary}] was not found on this server. Install the MySQL client tools (the Dockerfile does this) or set BACKUP_MYSQLDUMP_BINARY.");
        }

        $optionFile = $this->writeOptionFile($config);
        $partial = $absolutePath.'.part';

        try {
            // --defaults-extra-file must be the first option. --no-tablespaces:
            // MySQL 8 otherwise wants the global PROCESS privilege the app's
            // database user does not have and prints an access-denied error on
            // every run. No --routines/--events: the app has none, and they
            // need extra privileges that would make a backup fail.
            $command = [
                $binary,
                '--defaults-extra-file='.$optionFile,
                '--single-transaction',
                '--quick',
                '--skip-lock-tables',
                '--no-tablespaces',
                '--hex-blob',
                '--default-character-set=utf8mb4',
                (string) ($config['database'] ?? ''),
            ];

            $gzip = gzopen($partial, 'wb6');

            if ($gzip === false) {
                throw new RuntimeException('Could not create the backup file.');
            }

            $tail = '';
            $errors = '';

            try {
                $exitCode = $this->runDump(
                    $command,
                    function (string $chunk) use ($gzip, &$tail): void {
                        gzwrite($gzip, $chunk);
                        $tail = substr($tail.$chunk, -1024);
                    },
                    function (string $chunk) use (&$errors): void {
                        $errors = substr($errors.$chunk, -2000);
                    },
                );
            } finally {
                gzclose($gzip);
            }

            if ($exitCode !== 0) {
                throw new RuntimeException(trim($errors) ?: "Database backup failed (mysqldump exit code {$exitCode}).");
            }

            if (! str_contains($tail, '-- Dump completed')) {
                throw new RuntimeException('Database backup stopped before the dump was complete.');
            }

            File::move($partial, $absolutePath);
        } finally {
            File::delete($optionFile);
            File::delete($partial);
        }
    }

    public function binaryExists(string $binary): bool
    {
        if (str_contains($binary, '/')) {
            return is_file($binary) && is_executable($binary);
        }

        return (new ExecutableFinder)->find($binary) !== null;
    }

    /**
     * A client option file (mode 0600) with the connection's user, password and host,
     * so the password never appears on a command line. The caller deletes it.
     *
     * @return string path of a new option file readable only by this user
     */
    public function writeOptionFile(array $config): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dbbackup');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary option file for the backup.');
        }

        $lines = ['[client]', 'user="'.$this->optionValue((string) ($config['username'] ?? '')).'"'];

        if (filled($config['password'] ?? null)) {
            $lines[] = 'password="'.$this->optionValue((string) $config['password']).'"';
        }

        if (filled($config['unix_socket'] ?? null)) {
            $lines[] = 'socket="'.$this->optionValue((string) $config['unix_socket']).'"';
        } else {
            $lines[] = 'host="'.$this->optionValue((string) ($config['host'] ?? '127.0.0.1')).'"';
            $lines[] = 'port='.(int) ($config['port'] ?? 3306);
        }

        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }

    /**
     * Streams a compressed dump through gunzip into the mysql client for $database, never
     * holding it in memory. Used by the restore test (a scratch database) and by backup:restore
     * (the live one). The dump's own DROP TABLE / CREATE TABLE statements replace what is there.
     *
     * @param  array  $config  the connection's host, port, username and password
     */
    public function loadDump(array $config, string $database, string $path, string $binary = 'mysql', float $timeout = 1800): void
    {
        if (! $this->binaryExists($binary)) {
            throw new RuntimeException("[{$binary}] was not found on this server. Install the MySQL client tools (the Dockerfile does this) or set BACKUP_MYSQL_BINARY.");
        }

        $gzip = gzopen($path, 'rb');

        if ($gzip === false) {
            throw new RuntimeException('The dump could not be opened.');
        }

        $optionFile = $this->writeOptionFile($config);

        try {
            $input = (function () use ($gzip): \Generator {
                while (! gzeof($gzip)) {
                    $chunk = gzread($gzip, 1048576);

                    if ($chunk === false) {
                        throw new RuntimeException('The dump could not be read.');
                    }

                    if ($chunk !== '') {
                        yield $chunk;
                    }
                }
            })();

            $process = new Process([$binary, '--defaults-extra-file='.$optionFile, '--default-character-set=utf8mb4', $database]);
            $process->setTimeout($timeout);
            $process->setInput($input);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('Loading the dump failed: '.(trim(Str::limit($process->getErrorOutput(), 1000, '')) ?: "exit code {$process->getExitCode()}"));
            }
        } finally {
            gzclose($gzip);
            File::delete($optionFile);
        }
    }

    protected function optionValue(string $value): string
    {
        return str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value);
    }

    protected function verifySqlite(array $backup): array
    {
        $temporaryPath = storage_path('framework/testing/restore-drill-'.str()->uuid().'.sqlite');
        File::ensureDirectoryExists(dirname($temporaryPath));

        try {
            File::copy($backup['path'], $temporaryPath);
            $pdo = new PDO('sqlite:'.$temporaryPath);
            $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();

            if ($integrity !== 'ok') {
                throw new RuntimeException('SQLite integrity check failed: '.(string) $integrity);
            }

            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
                ->fetchAll(PDO::FETCH_COLUMN);

            if (! in_array('migrations', $tables, true)) {
                throw new RuntimeException('Restored database does not contain the migrations table.');
            }

            return [
                'backup' => $backup['name'],
                'integrity' => $integrity,
                'table_count' => count($tables),
            ];
        } finally {
            unset($pdo);
            File::delete($temporaryPath);
        }
    }

    /**
     * Read a compressed dump to the end: it finished, has a migrations table, and matches the
     * checksum recorded when it was made. $backup needs `name` and `path`.
     *
     * @param  array{name: string, path: string}  $backup
     */
    public function verifyDump(array $backup): array
    {
        $recorded = $this->runs->recordedChecksum($backup['name']);

        if ($recorded !== null && ! hash_equals($recorded, (string) hash_file('sha256', $backup['path']))) {
            throw new RuntimeException('The backup file no longer matches the checksum recorded when it was made.');
        }

        $gzip = gzopen($backup['path'], 'rb');

        if ($gzip === false) {
            throw new RuntimeException('The backup file could not be opened.');
        }

        $tables = 0;
        $hasMigrations = false;
        $completed = false;

        try {
            while (($line = gzgets($gzip)) !== false) {
                if (str_starts_with($line, 'CREATE TABLE `')) {
                    $tables++;
                    $hasMigrations = $hasMigrations || str_starts_with($line, 'CREATE TABLE `migrations`');
                } elseif (str_starts_with($line, '-- Dump completed')) {
                    $completed = true;
                }
            }
        } finally {
            gzclose($gzip);
        }

        if (! $completed) {
            throw new RuntimeException('The dump is incomplete: it does not end with the "Dump completed" line.');
        }

        if (! $hasMigrations) {
            throw new RuntimeException('The dump does not contain the migrations table.');
        }

        return [
            'backup' => $backup['name'],
            'integrity' => 'ok',
            'table_count' => $tables,
            'checksum' => $recorded === null ? 'not recorded' : 'verified',
        ];
    }

    protected function fileDetails(string $relativePath): array
    {
        $disk = Storage::disk('local');
        $modifiedAt = $disk->lastModified($relativePath);

        return [
            'name' => basename($relativePath),
            'kind' => BackupRun::kindOfFile($relativePath),
            'relative_path' => $relativePath,
            'path' => $disk->path($relativePath),
            'size' => $disk->size($relativePath),
            'size_human' => $this->humanSize($disk->size($relativePath)),
            'modified_at' => $modifiedAt,
            'modified_label' => now()->createFromTimestamp($modifiedAt)->format('d M Y, h:i A'),
        ];
    }

    protected function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / 1024 / 1024, 1).' MB';
    }
}
