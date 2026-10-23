<?php

namespace App\Services;

use Aws\Exception\AwsException;
use Aws\Exception\MultipartUploadException;
use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Cloudflare R2 as an off-site backup place, through its S3-compatible API.
 *
 * Settings come from the R2 Backups page (BackupSettingsService, secret encrypted) or the
 * BACKUP_R2_* environment. The bucket should be a private one used for nothing else, with an
 * API token limited to it; every object this app writes, lists or deletes is under one
 * folder (the prefix), and only files named like our backups are ever deleted (the caller,
 * OffsiteBackupService, filters by name).
 *
 * - Small files go up with one request, big ones in parts of backup.r2.part_bytes. A part
 *   that fails is retried from where the upload stopped (the SDK keeps the state), and an
 *   upload that is given up is aborted, so half-uploaded parts are not left behind.
 * - After the upload the object is asked for its size; a different size is an error and the
 *   object is removed.
 * - R2 does not support the SDK's newer default "flexible checksums" on every request, so
 *   they are only sent when an operation requires them.
 *
 * What an operation costs is counted in R2CostEstimator; nothing here limits anything.
 */
class R2BackupService implements BackupDestination
{
    public const KEY = 'r2';

    private const MIN_PART_BYTES = 5 * 1024 * 1024;

    /** How many times one upload is resumed before it is given up. */
    private const UPLOAD_ATTEMPTS = 3;

    private ?S3ClientInterface $client = null;

    private ?string $clientFingerprint = null;

    public function __construct(protected BackupSettingsService $settings) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Cloudflare R2';
    }

    public function isEnabled(): bool
    {
        return $this->settings->r2Enabled();
    }

    public function autoUploadEnabled(): bool
    {
        return $this->settings->r2AutoUpload();
    }

    public function isConfigured(): bool
    {
        return $this->isEnabled() && $this->missingSettings() === [];
    }

    /** @return array<int, string> what is still to be filled in, in plain words */
    public function missingSettings(): array
    {
        $missing = [];

        if ($this->settings->r2Endpoint() === null) {
            $missing[] = filled($this->settings->r2Account())
                ? 'Account ID (32 letters and digits) or the endpoint address (https://...)'
                : 'Account ID';
        }

        if (blank($this->settings->r2Bucket())) {
            $missing[] = 'Bucket name';
        }

        if (blank($this->settings->r2AccessKeyId())) {
            $missing[] = 'Access Key ID';
        }

        if (! $this->settings->hasR2SecretAccessKey()) {
            $missing[] = 'Secret Access Key';
        }

        return $missing;
    }

    public function assertReady(): void
    {
        $missing = $this->missingSettings();

        if ($missing !== []) {
            throw new RuntimeException('Cloudflare R2 backup is not set up: '.implode(', ', $missing).' still to be filled in.');
        }
    }

    /** Use this client instead of building one from the settings (tests, or another S3 service). */
    public function withClient(S3ClientInterface $client): static
    {
        $this->client = $client;
        $this->clientFingerprint = '*';

        return $this;
    }

    /**
     * @return array{id: string, name: string, size: int, bucket: string}
     */
    public function upload(string $absolutePath, ?string $filename = null, string $mimeType = 'application/octet-stream'): array
    {
        $this->assertReady();

        if (! File::exists($absolutePath)) {
            throw new RuntimeException('Backup file was not found for Cloudflare R2 upload.');
        }

        $filename = basename($filename ?? $absolutePath);
        $key = $this->objectKey($filename);
        $bucket = (string) $this->settings->r2Bucket();
        $size = (int) filesize($absolutePath);
        $partBytes = $this->partBytes();

        try {
            if ($size <= $partBytes) {
                $this->client()->putObject([
                    'Bucket' => $bucket,
                    'Key' => $key,
                    'SourceFile' => $absolutePath,
                    'ContentType' => $mimeType,
                ]);
            } else {
                $this->uploadInParts($bucket, $key, $absolutePath, $mimeType, $partBytes);
            }

            $stored = (int) $this->client()->headObject(['Bucket' => $bucket, 'Key' => $key])['ContentLength'];
        } catch (Throwable $exception) {
            throw new RuntimeException($this->explain($exception), previous: $exception);
        }

        if ($stored !== $size) {
            rescue(fn () => $this->client()->deleteObject(['Bucket' => $bucket, 'Key' => $key]), report: false);

            throw new RuntimeException("Cloudflare R2 stored {$stored} bytes of {$size}; the upload was removed. Try again.");
        }

        return ['id' => $key, 'name' => $filename, 'size' => $size, 'bucket' => $bucket];
    }

    /**
     * The backup files in the folder (not the folders inside it), with their size and time.
     *
     * @return array<int, array{id: string, name: string, size: int, createdTime: string}>
     */
    public function listBackupFiles(): array
    {
        $this->assertReady();

        $prefix = $this->folder();
        $files = [];

        try {
            $pages = $this->client()->getPaginator('ListObjectsV2', [
                'Bucket' => (string) $this->settings->r2Bucket(),
                'Prefix' => $prefix,
                'Delimiter' => '/',
            ]);

            foreach ($pages as $page) {
                foreach ((array) ($page['Contents'] ?? []) as $object) {
                    $key = (string) $object['Key'];
                    $name = substr($key, strlen($prefix));

                    if ($name === '' || str_contains($name, '/')) {
                        continue;
                    }

                    $modified = $object['LastModified'] ?? null;

                    $files[] = [
                        'id' => $key,
                        'name' => $name,
                        'size' => (int) ($object['Size'] ?? 0),
                        'createdTime' => $modified instanceof \DateTimeInterface ? $modified->format(DATE_ATOM) : (string) $modified,
                    ];
                }
            }
        } catch (Throwable $exception) {
            throw new RuntimeException($this->explain($exception), previous: $exception);
        }

        return $files;
    }

    public function download(string $fileId, string $destination): void
    {
        $this->assertReady();
        $this->assertOwnKey($fileId);

        try {
            File::ensureDirectoryExists(dirname($destination));
            $this->client()->getObject([
                'Bucket' => (string) $this->settings->r2Bucket(),
                'Key' => $fileId,
                'SaveAs' => $destination,
            ]);
        } catch (Throwable $exception) {
            File::delete($destination);

            throw new RuntimeException($this->explain($exception), previous: $exception);
        }
    }

    public function delete(string $fileId): void
    {
        $this->assertReady();
        $this->assertOwnKey($fileId);

        try {
            $this->client()->deleteObject(['Bucket' => (string) $this->settings->r2Bucket(), 'Key' => $fileId]);
        } catch (Throwable $exception) {
            throw new RuntimeException($this->explain($exception), previous: $exception);
        }
    }

    /**
     * Prove the settings work the way backups need them: write a small object, read it back,
     * list the folder and delete the object (deleting is how old copies are trimmed).
     *
     * @return array{ok: bool, message: string, milliseconds: int}
     */
    public function testConnection(): array
    {
        $started = microtime(true);

        try {
            $this->assertReady();

            $bucket = (string) $this->settings->r2Bucket();
            $key = $this->folder().'_health/connection-test-'.bin2hex(random_bytes(4)).'.txt';
            $client = $this->client();

            $client->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'ok', 'ContentType' => 'text/plain']);

            try {
                $read = (string) $client->getObject(['Bucket' => $bucket, 'Key' => $key])['Body'];

                if ($read !== 'ok') {
                    throw new RuntimeException('Cloudflare R2 returned something other than what was written.');
                }

                $client->listObjectsV2(['Bucket' => $bucket, 'Prefix' => $this->folder(), 'MaxKeys' => 1]);
            } finally {
                $client->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            }

            return ['ok' => true, 'message' => 'Connected: wrote, read, listed and deleted a test file in the bucket.', 'milliseconds' => (int) round((microtime(true) - $started) * 1000)];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $this->explain($exception), 'milliseconds' => (int) round((microtime(true) - $started) * 1000)];
        }
    }

    /** Upload in parts; a part that fails is retried from the saved state, an upload given up is aborted. */
    protected function uploadInParts(string $bucket, string $key, string $path, string $mimeType, int $partBytes): void
    {
        $config = [
            'bucket' => $bucket,
            'key' => $key,
            'part_size' => $partBytes,
            'concurrency' => 1,
            'before_initiate' => static function ($command) use ($mimeType): void {
                $command['ContentType'] = $mimeType;
            },
        ];

        $uploader = new MultipartUploader($this->client(), $path, $config);

        for ($attempt = 1; ; $attempt++) {
            try {
                $uploader->upload();

                return;
            } catch (MultipartUploadException $exception) {
                if ($attempt >= self::UPLOAD_ATTEMPTS) {
                    $this->abort($bucket, $key, $exception);

                    throw $exception;
                }

                Sleep::for($attempt * 2)->seconds();

                $uploader = new MultipartUploader($this->client(), $path, ['state' => $exception->getState()] + $config);
            }
        }
    }

    protected function abort(string $bucket, string $key, MultipartUploadException $exception): void
    {
        $uploadId = $exception->getState()->getId()['UploadId'] ?? null;

        if ($uploadId === null) {
            return;
        }

        rescue(fn () => $this->client()->abortMultipartUpload(['Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId]), report: false);
    }

    protected function client(): S3ClientInterface
    {
        $fingerprint = hash('sha256', implode('|', [
            $this->settings->r2Endpoint(),
            $this->settings->r2AccessKeyId(),
            $this->settings->r2SecretAccessKey(),
        ]));

        if ($this->client === null || ($this->clientFingerprint !== '*' && $this->clientFingerprint !== $fingerprint)) {
            $this->client = new S3Client([
                'version' => 'latest',
                'region' => 'auto',
                'endpoint' => $this->settings->r2Endpoint(),
                'use_path_style_endpoint' => true,
                'credentials' => [
                    'key' => (string) $this->settings->r2AccessKeyId(),
                    'secret' => (string) $this->settings->r2SecretAccessKey(),
                ],
                // R2 answers the SDK's default integrity-checksum trailers with an error on some calls.
                'request_checksum_calculation' => 'when_required',
                'response_checksum_validation' => 'when_required',
                'use_aws_shared_config_files' => false,
                'retries' => 3,
                'http' => ['connect_timeout' => 15, 'timeout' => 900],
            ]);
            $this->clientFingerprint = $fingerprint;
        }

        return $this->client;
    }

    /** "folder/" or "" - what every key starts with. */
    protected function folder(): string
    {
        $prefix = $this->settings->r2Prefix();

        return $prefix === '' ? '' : $prefix.'/';
    }

    protected function objectKey(string $filename): string
    {
        return $this->folder().$filename;
    }

    /** Never act on something outside the backup folder, whatever id is passed in. */
    protected function assertOwnKey(string $key): void
    {
        $folder = $this->folder();

        if (($folder !== '' && ! str_starts_with($key, $folder)) || str_contains(substr($key, strlen($folder)), '/') || str_contains($key, '..')) {
            throw new RuntimeException('That file is not in the backup folder of the Cloudflare R2 bucket.');
        }
    }

    protected function partBytes(): int
    {
        return max(self::MIN_PART_BYTES, (int) config('backup.r2.part_bytes', 16 * 1024 * 1024));
    }

    /** What went wrong, in words an admin can act on. */
    protected function explain(Throwable $exception): string
    {
        $cause = $exception instanceof MultipartUploadException ? ($exception->getPrevious() ?? $exception) : $exception;

        if (! $cause instanceof AwsException) {
            return 'Cloudflare R2: '.$cause->getMessage();
        }

        $bucket = (string) $this->settings->r2Bucket();

        return match ($cause->getAwsErrorCode()) {
            'NoSuchBucket' => "Cloudflare R2: the bucket \"{$bucket}\" was not found. Check the bucket name and that the Account ID is the one the bucket belongs to.",
            'InvalidAccessKeyId' => 'Cloudflare R2: the Access Key ID is not known. Check it was copied completely.',
            'SignatureDoesNotMatch' => 'Cloudflare R2: the Secret Access Key is wrong.',
            'AccessDenied', 'Unauthorized', 'Forbidden' => "Cloudflare R2: access was refused. The API token needs \"Object Read & Write\" on the bucket \"{$bucket}\".",
            'ExpiredToken', 'InvalidToken' => 'Cloudflare R2: the API token has expired.',
            'EntityTooLarge' => 'Cloudflare R2: the file is larger than the largest upload allowed.',
            null, '' => $cause->getResponse() === null
                ? 'Could not reach Cloudflare R2. Check the Account ID or endpoint address and that this server can reach the internet.'
                : 'Cloudflare R2: '.$cause->getMessage(),
            default => 'Cloudflare R2: '.($cause->getAwsErrorMessage() ?: $cause->getAwsErrorCode()),
        };
    }
}
