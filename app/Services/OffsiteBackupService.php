<?php

namespace App\Services;

use App\Models\BackupRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Sends a finished backup to Google Drive: the copy that survives the server.
 *
 * - The file is encrypted first (BackupEncryptor); a database dump holds every
 *   customer and the app archive holds the .env, so by default nothing is
 *   uploaded in the clear. BACKUP_OFFSITE_REQUIRE_ENCRYPTION=false switches that
 *   guard off for an owner who accepts the risk.
 * - Afterwards Drive is trimmed to the newest copies of each kind
 *   (backup.offsite.keep), so the folder does not grow for ever. Only files
 *   named like our backups are ever considered.
 *
 * This is the one place a second destination (another bucket) would be added.
 */
class OffsiteBackupService
{
    /** Where the encrypted copy is staged while it uploads. */
    private const STAGING_DIRECTORY = 'backups/tmp';

    public function __construct(
        protected GoogleDriveBackupService $drive,
        protected BackupEncryptor $encryptor,
        protected BackupSettingsService $settings,
    ) {}

    /**
     * Whether a backup of this scope and kind is uploaded by itself: Drive is
     * switched on, "Auto upload" is on, and the kind is one of backup.offsite.
     */
    public function shouldAutoUpload(string $scope, string $kind): bool
    {
        return $this->settings->googleDriveEnabled()
            && $this->settings->googleDriveAutoUpload()
            && in_array($kind, (array) config("backup.offsite.{$scope}_kinds", []), true);
    }

    /**
     * Upload $absolutePath (encrypted) and trim the old copies.
     *
     * @return array{id?: string, name?: string, webViewLink?: string, encrypted: bool, pruned: int}
     */
    public function send(string $absolutePath): array
    {
        // Both checks come before any work: encrypting a large archive just to find out
        // that nothing can be uploaded would waste minutes and disk.
        if (blank($this->settings->googleDriveFolderId())) {
            throw new RuntimeException('Google Drive backup folder ID is required.');
        }

        $encrypt = $this->encryptor->isConfigured();

        if (! $encrypt && config('backup.offsite.require_encryption', true)) {
            throw new RuntimeException('The off-site copy was not sent because it would not be encrypted. Set BACKUP_ENCRYPTION_KEY (for example the output of: openssl rand -base64 32) and keep a copy of it outside the server.');
        }

        $filename = basename($absolutePath);
        $staged = null;

        try {
            if ($encrypt) {
                BackupFiles::deleteStalePartials(self::STAGING_DIRECTORY);

                $filename .= BackupEncryptor::EXTENSION;
                $staged = Storage::disk('local')->path(self::STAGING_DIRECTORY.'/'.$filename.'.part');
                File::ensureDirectoryExists(dirname($staged));

                $this->encryptor->encryptFile($absolutePath, $staged);
            }

            $result = $this->drive->upload(
                $staged ?? $absolutePath,
                $filename,
                $encrypt ? 'application/octet-stream' : (str_ends_with($filename, '.zip') ? 'application/zip' : 'application/gzip'),
            );
        } finally {
            if ($staged !== null) {
                File::delete($staged);
            }
        }

        return [...$result, 'encrypted' => $encrypt, 'pruned' => $this->prune()];
    }

    /**
     * The newest database dump on Drive (`.sql.gz`, encrypted or not), or null when there is none.
     *
     * @return array{id: string, name: string, scope: string, kind: string}|null
     */
    public function latestDatabaseDump(): ?array
    {
        return collect($this->drive->listBackupFiles())
            ->map(fn (array $file): ?array => $this->describe($file))
            ->filter(fn (?array $file): bool => $file !== null
                && $file['scope'] === BackupRun::SCOPE_DATABASE
                && preg_match('/\.sql\.gz(\.enc)?$/', $file['name']) === 1)
            ->sortByDesc('name')
            ->first();
    }

    /**
     * Download a copy from Drive into $directory and, when it is encrypted, decrypt it with
     * BACKUP_ENCRYPTION_KEY. Returns the path of the plain file; the caller deletes it. A wrong
     * or missing key fails here, which is exactly what a restore test of the Drive copy is for.
     *
     * @param  array{id: string, name: string}  $file
     * @param  ?string  $key  the passphrase to use instead of BACKUP_ENCRYPTION_KEY
     */
    public function fetch(array $file, string $directory, ?string $key = null): string
    {
        File::ensureDirectoryExists($directory);

        $encrypted = str_ends_with($file['name'], BackupEncryptor::EXTENSION);
        $plain = $directory.'/'.basename($encrypted ? substr($file['name'], 0, -strlen(BackupEncryptor::EXTENSION)) : $file['name']);
        $download = $encrypted ? $plain.BackupEncryptor::EXTENSION.'.part' : $plain;

        try {
            $this->drive->download($file['id'], $download);

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
     * Delete the Drive copies beyond the newest few of each scope and kind.
     * A problem here never fails the backup that was just uploaded; it is
     * reported to the Super Admins instead (Deploy Error Logs).
     *
     * @return int how many files were removed
     */
    public function prune(): int
    {
        try {
            $groups = collect($this->drive->listBackupFiles())
                ->map(fn (array $file): ?array => $this->describe($file))
                ->filter()
                ->groupBy(fn (array $file): string => $file['scope'].'|'.$file['kind']);

            $removed = 0;

            foreach ($groups as $files) {
                $keep = max(1, (int) config("backup.offsite.keep.{$files->first()['scope']}.{$files->first()['kind']}", 10));

                // The date and time are part of the name, so the name sorts by age.
                foreach ($files->sortByDesc('name')->skip($keep) as $file) {
                    $this->drive->delete($file['id']);
                    $removed++;
                }
            }

            return $removed;
        } catch (Throwable $exception) {
            rescue(fn () => app(DeploymentErrorReporter::class)->report('backup:offsite-prune', $exception), report: false);

            return 0;
        }
    }

    /**
     * @param  array{id: string, name: string}  $file
     * @return array{id: string, name: string, scope: string, kind: string}|null null for anything that is not one of our backup files
     */
    protected function describe(array $file): ?array
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
}
