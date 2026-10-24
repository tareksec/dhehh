<?php

namespace App\Services;

/**
 * A place that keeps the encrypted off-site copy of a backup (Google Drive, Cloudflare R2).
 *
 * OffsiteBackupService encrypts a backup once and hands the same file to every destination
 * that is switched on, then trims each of them to the newest copies. A destination only
 * knows how to put a file somewhere, list the backup files there, fetch one and delete one;
 * what a file is called, which kinds are kept and how many is decided once, in
 * OffsiteBackupService and config backup.offsite.
 *
 * A file is identified by the `id` the destination gave it (Drive: the file id; R2: the
 * object key). Nothing here may touch a file that is not one of ours: listBackupFiles()
 * returns everything in our folder, and the caller keeps only names that look like backups.
 */
interface BackupDestination
{
    /** Short stable name used in settings, commands and records: "drive", "r2". */
    public function key(): string;

    /** The name shown to people. */
    public function label(): string;

    /** Whether the admin switched this destination on. */
    public function isEnabled(): bool;

    /** Whether new backups are sent here by themselves ("Auto upload"). */
    public function autoUploadEnabled(): bool;

    /** Switched on and complete: nothing is missing to upload. */
    public function isConfigured(): bool;

    /**
     * @throws \RuntimeException with a plain explanation when something needed is missing. Called before
     *                           a large file is encrypted, so no work is wasted on an upload that cannot happen.
     */
    public function assertReady(): void;

    /**
     * @return array{id?: string, name?: string, webViewLink?: string}
     */
    public function upload(string $absolutePath, ?string $filename = null, string $mimeType = 'application/zip'): array;

    /**
     * @return array<int, array{id: string, name: string, size?: string|int, createdTime?: string}>
     */
    public function listBackupFiles(): array;

    /** Save a stored file to $destination, streamed to disk. */
    public function download(string $fileId, string $destination): void;

    /** Remove a stored file. A file that is already gone counts as removed. */
    public function delete(string $fileId): void;
}
