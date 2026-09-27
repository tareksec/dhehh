<?php

namespace App\Services\VideoGeneration;

/**
 * The normalized input a provider adapter receives. Assembled by
 * GenerateVideoJob from the chosen provider profile plus the GeneratedVideo
 * row. One operation only today (image-to-video); the field carries no
 * `count` — every request produces exactly one clip (plan §2).
 */
final class VideoGenerationRequest
{
    public const OP_IMAGE_TO_VIDEO = 'image_to_video';

    public function __construct(
        public readonly string $prompt,
        public readonly ?string $model,
        public readonly ?string $apiKey,
        public readonly ?string $baseUrl,
        public readonly ?string $pollUrlTemplate,
        /** Raw bytes of the starting frame. */
        public readonly string $sourceImage,
        /** Aspect ratio, "W:H" (e.g. "16:9") — see GeneratedVideo::ASPECT_RATIOS. */
        public readonly string $aspectRatio,
        /** GeneratedVideo::DURATION_PRESETS key. Adapters without duration control ignore it. */
        public readonly string $durationPreset,
        public readonly string $operation = self::OP_IMAGE_TO_VIDEO,
    ) {}

    public function endpoint(string $default): string
    {
        return $this->baseUrl !== null && trim($this->baseUrl) !== ''
            ? rtrim(trim($this->baseUrl), '/')
            : $default;
    }

    /** The poll URL for one external job id, "custom" format only. */
    public function pollUrlFor(string $externalJobId): ?string
    {
        if (blank($this->pollUrlTemplate)) {
            return null;
        }

        return str_replace('{job_id}', $externalJobId, $this->pollUrlTemplate);
    }
}
