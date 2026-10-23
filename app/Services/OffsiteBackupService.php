<?php

namespace App\Services;

use App\Models\BackupRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Sends a finished backup to the off-site places that are switched on (Cloudflare R2 and/or
 * Google Drive): the copy that survives the server.
 *
 * - The file is encrypted once (BackupEncryptor) and the same encrypted file goes to every
 *   place; a database dump holds every customer and the app archive holds the .env, so by
 *   default nothing is uploaded in the clear. BACKUP_OFFSITE_REQUIRE_ENCRYPTION=false
 *   switches that guard off for an owner who accepts the risk.
 * - A place that fails does not stop the others. The backup counts as sent when at least one
 *   place got it; the places that did not are returned under `failed` and reported to the
 *   Super Admins. Only when none got it is the upload an error.
 * - Afterwards each place is trimmed to the newest copies of each kind
 *   (backup.offsite.keep), so it does not grow for ever. Only files named like our backups
 *   are ever considered.
 */
class OffsiteBackupService
{
    /** Where the encrypted copy is staged while it uploads. */
    private const STAGING_DIRECTORY = 'backups/tmp';

    public function __construct(
        protected GoogleDriveBackupService $drive,
        protected R2BackupService $r2,
        protected BackupEncryptor $encryptor,
        protected BackupSettingsService $settings,
    ) {}

    /** @return array<string, BackupDestination> every known place, by key; the first is where a restore reads from when none is named */
    public function all(): array
    {
        return [R2BackupService::KEY => $this->r2, GoogleDriveBackupService::KEY => $this->drive];
    }

    /** @return array<string, BackupDestination> the places that are switched on, by key */
    public function destinations(): array
    {
        return array_filter($this->all(), fn (BackupDestination $place): bool => $place->isEnabled());
    }

    /** @return array<string, BackupDestination> the places with everything filled in, switched on or not */
    protected function setUpDestinations(): array
    {
        return array_filter($this->all(), function (BackupDestination $place): bool {
            try {
                $place->assertReady();

                return true;
            } catch (RuntimeException) {
                return false;
            }
        });
    }

    /**
     * The place to read a copy from: the one named, else the first that is switched on, else the
     * first that is set up (a rebuilt server may have the keys but not the switch).
     *
     * @throws RuntimeException when none is usable or the name is unknown
     */
    public function destination(?string $key = null): BackupDestination
    {
        $all = $this->all();

        if ($key !== null) {
            return $all[$key] ?? throw new RuntimeException("Unknown off-site place [{$key}]; use one of: ".implode(', ', array_keys($all)).'.');
        }

        foreach ($this->destinations() as $place) {
            return $place;
        }

        foreach ($this->setUpDestinations() as $place) {
            return $place;
        }

        throw new RuntimeException('No off-site place is set up. Set up Cloudflare R2 (R2 Backups page) or Google Drive (Backups page).');
    }

    /**
     * Whether a backup of this scope and kind is uploaded by itself: some place is switched on
     * with "Auto upload" on, and the kind is one of backup.offsite.
     */
    public function shouldAutoUpload(string $scope, string $kind): bool
    {
        return in_array($kind, (array) config("backup.offsite.{$scope}_kinds", []), true)
            && $this->autoDestinations() !== [];
    }

    /** @return array<string, BackupDestination> the places that take automatic uploads */
    public function autoDestinations(): array
    {
        return array_filter($this->destinations(), fn (BackupDestination $place): bool => $place->autoUploadEnabled());
    }

    /**
     * Upload $absolutePath (encrypted) to the places that are on and trim the old copies.
     *
     * @param  bool  $automatic  only the places with "Auto upload" on (a scheduled backup); a person
     *                           pressing Upload, or --upload, sends to every place that is on (or, when none
     *                           is switched on, to the ones that are set up: asking for it by hand is enough)
     * @return array{id?: string, name?: string, webViewLink?: string, encrypted: bool, pruned: int, destinations: array<string, array<string, mixed>>, failed: array<string, string>}
     */
    public function send(string $absolutePath, bool $automatic = false): array
    {
        $targets = $automatic ? $this->autoDestinations() : ($this->destinations() ?: $this->setUpDestinations());

        if ($targets === []) {
            throw new RuntimeException($automatic
                ? 'No off-site place has "Auto upload" switched on.'
                : 'No off-site place is set up. Set up Cloudflare R2 (R2 Backups page) or Google Drive (Backups page).');
        }

        // The checks come before any work: encrypting a large archive just to find out
        // that nothing can be uploaded would waste minutes and disk.
        $ready = [];
        $failed = [];

        foreach ($targets as $key => $place) {
            try {
                $place->assertReady();
                $ready[$key] = $place;
            } catch (Throwable $exception) {
                $failed[$key] = $exception->getMessage();
            }
        }

        if ($ready === []) {
            throw new RuntimeException($this->describeFailures($failed, $targets));
        }

        $encrypt = $this->encryptor->isConfigured();

        if (! $encrypt && config('backup.offsite.require_encryption', true)) {
            throw new RuntimeException('The off-site copy was not sent because it would not be encrypted. Set BACKUP_ENCRYPTION_KEY (for example the output of: openssl rand -base64 32) and keep a copy of it outside the server.');
        }

        $filename = basename($absolutePath);
        $staged = null;
        $sent = [];

        try {
            if ($encrypt) {
                BackupFiles::deleteStalePartials(self::STAGING_DIRECTORY);

                $filename .= BackupEncryptor::EXTENSION;
                $staged = Storage::disk('local')->path(self::STAGING_DIRECTORY.'/'.$filename.'.part');
                File::ensureDirectoryExists(dirname($staged));

                $this->encryptor->encryptFile($absolutePath, $staged);
            }

            $mime = $encrypt ? 'application/octet-stream' : (str_ends_with($filename, '.zip') ? 'application/zip' : 'application/gzip');

            foreach ($ready as $key => $place) {
                try {
                    $sent[$key] = $place->upload($staged ?? $absolutePath, $filename, $mime);
                } catch (Throwable $exception) {
                    $failed[$key] = $exception->getMessage();
                }
            }
        } finally {
            if ($staged !== null) {
                File::delete($staged);
            }
        }

        if ($sent === []) {
            throw new RuntimeException($this->describeFailures($failed, $targets));
        }

        // Some place did not get it: the backup is safe, but the owner must hear about it.
        foreach ($failed as $key => $message) {
            rescue(fn () => app(DeploymentErrorReporter::class)->report(
                "backup:offsite:{$key}",
                new RuntimeException($this->describeFailures([$key => $message], $targets)),
                ['file' => $filename],
            ), report: false);
        }

        $pruned = 0;
        $places = [];

        foreach ($sent as $key => $result) {
            $removed = $this->pruneDestination($ready[$key]);
            $pruned += $removed;
            $places[$key] = [...$result, 'pruned' => $removed];
        }

        return [...reset($sent), 'encrypted' => $encrypt, 'pruned' => $pruned, 'destinations' => $places, 'failed' => $failed];
    }

    /**
     * The newest database dump (`.sql.gz`, encrypted or not) in a place, or null when there is none.
     *
     * @return array{id: string, name: string, scope: string, kind: string, destination: string}|null
     */
    public function latestDatabaseDump(?string $destination = null): ?array
    {
        $place = $this->destination($destination);

        $dump = collect($place->listBackupFiles())
            ->map(fn (array $file): ?array => $this->describe($file))
            ->filter(fn (?array $file): bool => $file !== null
                && $file['scope'] === BackupRun::SCOPE_DATABASE
                && preg_match('/\.sql\.gz(\.enc)?$/', $file['name']) === 1)
            ->sortByDesc('name')
            ->first();

        return $dump === null ? null : [...$dump, 'destination' => $place->key()];
    }

    /**
     * Download a copy into $directory and, when it is encrypted, decrypt it with
     * BACKUP_ENCRYPTION_KEY. Returns the path of the plain file; the caller deletes it. A wrong
     * or missing key fails here, which is exactly what a restore test of the off-site copy is for.
     *
     * @param  array{id: string, name: string, destination?: string}  $file
     * @param  ?string  $key  the passphrase to use instead of BACKUP_ENCRYPTION_KEY
     */
    public function fetch(array $file, string $directory, ?string $key = null): string
    {
        File::ensureDirectoryExists($directory);

        $place = $this->destination($file['destination'] ?? null);
        $encrypted = str_ends_with($file['name'], BackupEncryptor::EXTENSION);
        $plain = $directory.'/'.basename($encrypted ? substr($file['name'], 0, -strlen(BackupEncryptor::EXTENSION)) : $file['name']);
        $download = $encrypted ? $plain.BackupEncryptor::EXTENSION.'.part' : $plain;

        try {
            $place->download($file['id'], $download);

            if ($encrypted) {
                $this->encryptor->decryptFile($download, $plain, $key);
            }
        } catch (Throwable $exception) {
            File::delete($plain);

            throw $exception;
        } finally {
            if ($encrypted) {
                File::delete($download);
            }
        }

        return $plain;
    }

    /**
     * Delete the copies beyond the newest few of each scope and kind, in every place that is on.
     * A problem here never fails the backup that was just uploaded; it is reported to the Super
     * Admins (Deploy Error Logs) instead.
     *
     * @return int how many files were removed
     */
    public function prune(): int
    {
        return array_sum(array_map(fn (BackupDestination $place): int => $this->pruneDestination($place), $this->destinations()));
    }

    public function pruneDestination(BackupDestination $place): int
    {
        try {
            $groups = collect($place->listBackupFiles())
                ->map(fn (array $file): ?array => $this->describe($file))
                ->filter()
                ->groupBy(fn (array $file): string => $file['scope'].'|'.$file['kind']);

            $removed = 0;

            foreach ($groups as $files) {
                $keep = max(1, (int) config("backup.offsite.keep.{$files->first()['scope']}.{$files->first()['kind']}", 10));

                // The date and time are part of the name, so the name sorts by age.
                foreach ($files->sortByDesc('name')->skip($keep) as $file) {
                    $place->delete($file['id']);
                    $removed++;
                }
            }

            return $removed;
        } catch (Throwable $exception) {
            rescue(fn () => app(DeploymentErrorReporter::class)->report('backup:offsite-prune', $exception, ['destination' => $place->key()]), report: false);

            return 0;
        }
    }

    /**
     * Name a backup file the way our backups are named; null for anything that is not one.
     *
     * @param  array{id: string, name: string}  $file
     * @return array{id: string, name: string, scope: string, kind: string}|null
     */
    public function describe(array $file): ?array
    {
        $name = (string) ($file['name'] ?? '');
        $base = str_ends_with($name, BackupEncryptor::EXTENSION) ? substr($name, 0, -strlen(BackupEncryptor::EXTENSION)) : $name;

        $scope = match (true) {
            (bool) preg_match('/^database-backup-\d{8}-\d{6}-.+\.(sqlite|sql\.gz|sql)$/', $base) => BackupRun::SCOPE_DATABASE,
            (bool) preg_match('/^app-backup-\d{8}-\d{6}(-.+)?\.zip$/', $base) => BackupRun::SCOPE_APP,
            default => null,
        };

        if ($scope === null || blank($file['id'] ?? null)) {
            return null;
        }

        return ['id' => (string) $file['id'], 'name' => $name, 'scope' => $scope, 'kind' => BackupRun::kindOfFile($base)];
    }

    /**
     * @param  array<string, string>  $failed  message by place key
     * @param  array<string, BackupDestination>  $places
     */
    protected function describeFailures(array $failed, array $places): string
    {
        // One place: its own message, as it is. Several: say which one each belongs to.
        if (count($places) === 1) {
            return (string) reset($failed);
        }

        return implode(' ', array_map(
            fn (string $key, string $message): string => '['.($places[$key]?->label() ?? $key).'] '.$message,
            array_keys($failed),
            $failed,
        ));
    }
}
