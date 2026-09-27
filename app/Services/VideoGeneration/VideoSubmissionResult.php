<?php

namespace App\Services\VideoGeneration;

/**
 * What a provider adapter returns from submit(): the provider's own async job
 * id (stored on `generated_videos.external_job_id` and used to poll it) plus
 * a small metadata array for `generated_videos.provider_response`.
 */
final class VideoSubmissionResult
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $externalJobId,
        public readonly array $meta = [],
    ) {}
}
