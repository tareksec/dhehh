<?php

namespace App\Services;

use App\Models\BackupRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class AppBackupService
{
    public function __construct(
        protected DatabaseBackupService $databases,
        protected GoogleDriveBackupService $googleDrive,
        protected BackupRunRecorder $runs,
    ) {}

    public function create(bool $uploadToGoogleDrive = false, string $kind = BackupRun::KIND_MANUAL): array
    {
        return $this->runs->record(
            BackupRun::SCOPE_APP,
            $kind,
            fn (): array => $this->createArchive($uploadToGoogleDrive, $kind),
        );
    }

    protected function createArchive(bool $uploadToGoogleDrive, string $kind): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP Zip extension is required for full app backups.');
        }

        $filename = 'app-backup-' . now()->format('Ymd-His') . '.zip';
        $relativePath = config('backup.app.directory', 'backups/app') . '/' . $filename;
        $absolutePath = Storage::disk('local')->path($relativePath);

        File::ensureDirectoryExists(dirname($absolutePath));

        // The database dump made here prunes its own kind (DatabaseBackupService::create).
        $databaseBackup = $this->databases->create(config('backup.app.database_connection'), $kind);
        $zip = new ZipArchive();

        if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create app backup archive.');
        }

        $zip->addFile($databaseBackup['path'], 'database/' . $databaseBackup['name']);

        foreach (config('backup.app.include_paths', []) as $path) {
            $this->addPath($zip, base_path($path), $path);
        }

        $zip->close();

        $this->cleanup((int) config('backup.retention', 10));

        $backup = $this->fileDetails($relativePath);
        $backup['sha256'] = hash_file('sha256', $absolutePath);

        if ($uploadToGoogleDrive) {
            $backup['google_drive'] = $this->googleDrive->upload($absolutePath, $filename);
        }

        return $backup;
    }

    public function all(): array
    {
        return collect(Storage::disk('local')->files(config('backup.app.directory', 'backups/app')))
            ->filter(fn (string $path): bool => str($path)->endsWith('.zip'))
            ->map(fn (string $path): array => $this->fileDetails($path))
            ->sortByDesc('modified_at')
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

    public function cleanup(int $keep = 10): void
    {
        collect($this->all())
            ->skip($keep)
            ->each(fn (array $file): bool => Storage::disk('local')->delete($file['relative_path']));
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

    protected function isExcluded(string $relativePath): bool
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
