<?php

namespace App\Services;

use App\Models\BackupRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

class AppBackupService
{
    public function __construct(
        protected DatabaseBackupService $databases,
        protected OffsiteBackupService $offsite,
        protected BackupRunRecorder $runs,
    ) {}

    public function create(bool $upload = false, string $kind = BackupRun::KIND_MANUAL, bool $automatic = false): array
    {
        return $this->runs->record(
            BackupRun::SCOPE_APP,
            $kind,
            fn (): array => $this->createArchive($upload, $kind, $automatic),
        );
    }

    protected function createArchive(bool $upload, string $kind, bool $automatic): array
    {
        // The database dump comes first: a problem with the archive step (no Zip
        // extension, disk full) must not lose the dump of the day. It prunes its
        // own kind (DatabaseBackupService::create).
        $databaseBackup = $this->databases->create(config('backup.app.database_connection'), $kind);

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP Zip extension is required for full app backups.');
        }

        $filename = sprintf('app-backup-%s-%s.zip', now()->format('Ymd-His'), $kind);
        $relativePath = config('backup.app.directory', 'backups/app') . '/' . $filename;
        $absolutePath = Storage::disk('local')->path($relativePath);
        $partial = $absolutePath . '.part';

        File::ensureDirectoryExists(dirname($absolutePath));

        $zip = new ZipArchive();
        $open = false;

        try {
            // Written to a .part file and renamed once complete, so a run that
            // is killed half-way never leaves a broken zip in the backup list.
            if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create app backup archive.');
            }

            $open = true;
            $zip->addFile($databaseBackup['path'], 'database/' . $databaseBackup['name']);

            foreach (config('backup.app.include_paths', []) as $path) {
                $this->addPath($zip, base_path($path), $path);
            }

            if (! $zip->close()) {
                throw new RuntimeException('Could not write the app backup archive (is the disk full?).');
            }

            $open = false;
            File::move($partial, $absolutePath);
        } catch (Throwable $exception) {
            // The archive is only written when it is closed. A zip that is
            // still open would be closed (and written) later, when PHP frees
            // it, leaving a half-made file behind: drop its contents first.
            if ($open) {
                $zip->unchangeAll();
                $zip->close();
            }

            throw $exception;
        } finally {
            File::delete($partial);
        }

        $this->prune();

        $backup = $this->fileDetails($relativePath);
        $backup['sha256'] = hash_file('sha256', $absolutePath);

        if ($upload) {
            $backup['offsite'] = $this->offsite->send($absolutePath, $automatic);
        }

        return $backup;
    }

    public function all(): array
    {
        return collect(Storage::disk('local')->files(config('backup.app.directory', 'backups/app')))
            ->filter(fn (string $path): bool => str($path)->endsWith('.zip'))
            ->map(fn (string $path): array => $this->fileDetails($path))
            ->sortBy([['modified_at', 'desc'], ['name', 'desc']])
            ->values()
            ->all();
    }

    public function find(string $filename): ?array
    {
        $path = config('backup.app.directory', 'backups/app') . '/' . basename($filename);

        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        return $this->fileDetails($path);
    }

    /**
     * Keep the newest archives of each kind (config backup.app.keep) and delete
     * the rest, so a manual backup never pushes out a daily one.
     */
    public function prune(): void
    {
        $byKind = collect($this->all())->groupBy('kind');

        foreach (BackupRun::KINDS as $kind) {
            $keep = max(1, (int) config("backup.app.keep.{$kind}", 10));

            $byKind->get($kind, collect())
                ->skip($keep)
                ->each(fn (array $file): bool => Storage::disk('local')->delete($file['relative_path']));
        }

        BackupFiles::deleteStalePartials(config('backup.app.directory', 'backups/app'));
    }

    protected function addPath(ZipArchive $zip, string $absolutePath, string $relativePath): void
    {
        if (! File::exists($absolutePath) || $this->isExcluded($relativePath)) {
            return;
        }

        if (File::isFile($absolutePath)) {
            $zip->addFile($absolutePath, str_replace('\\', '/', $relativePath));

            return;
        }

        foreach (File::allFiles($absolutePath) as $file) {
            $fileRelativePath = str_replace('\\', '/', $relativePath . '/' . $file->getRelativePathname());

            if (! $this->isExcluded($fileRelativePath)) {
                $zip->addFile($file->getPathname(), $fileRelativePath);
            }
        }
    }

    public function isExcluded(string $relativePath): bool
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

        return collect(config('backup.app.exclude_paths', []))
            ->map(fn (string $path): string => trim(str_replace('\\', '/', $path), '/'))
            ->contains(fn (string $path): bool => $relativePath === $path || str_starts_with($relativePath, "{$path}/"));
    }

    protected function fileDetails(string $relativePath): array
    {
        $disk = Storage::disk('local');
        $modifiedAt = $disk->lastModified($relativePath);
        $size = $disk->size($relativePath);

        return [
            'name' => basename($relativePath),
            'kind' => BackupRun::kindOfFile($relativePath),
            'relative_path' => $relativePath,
            'path' => $disk->path($relativePath),
            'size' => $size,
            'size_human' => $this->humanSize($size),
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
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return number_format($bytes / 1024 / 1024, 1) . ' MB';
    }
}
