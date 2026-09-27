<?php

namespace App\Services\VideoGeneration;

/**
 * What a provider adapter returns from poll(). Deliberately carries no
 * video-bytes field — carrying binary through a return value would mean the
 * adapter already buffered the whole clip in memory. The adapter streams
 * completed video straight into the `$sink` resource poll() was given
 * instead (via Http::sink($sink)); GenerateVideoJob hands that resource
 * directly to CompanyStorageService::putPublic() without ever reading it
 * into a PHP string.
 */
final class VideoPollResult
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * @param  self::STATUS_*  $status
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $videoMimeType = null,
        public readonly ?int $videoSizeBytes = null,
        public readonly ?float $durationSeconds = null,
        public readonly ?string $posterUrl = null,
        public readonly ?string $errorMessage = null,
        public readonly array $meta = [],
    ) {}

    public static function pending(array $meta = []): self
    {
        return new self(self::STATUS_PENDING, meta: $meta);
    }

    public static function completed(
        ?string $videoMimeType,
        ?int $videoSizeBytes,
        ?float $durationSeconds = null,
        ?string $posterUrl = null,
        array $meta = [],
    ): self {
        return new self(
            self::STATUS_COMPLETED,
            videoMimeType: $videoMimeType,
            videoSizeBytes: $videoSizeBytes,
            durationSeconds: $durationSeconds,
            posterUrl: $posterUrl,
            meta: $meta,
        );
    }

    public static function failed(string $errorMessage, array $meta = []): self
    {
        return new self(self::STATUS_FAILED, errorMessage: $errorMessage, meta: $meta);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
