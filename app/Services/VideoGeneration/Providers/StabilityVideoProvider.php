<?php

namespace App\Services\VideoGeneration\Providers;

use App\Services\VideoGeneration\VideoGenerationException;
use App\Services\VideoGeneration\VideoGenerationRequest;
use App\Services\VideoGeneration\VideoPollResult;
use App\Services\VideoGeneration\VideoSubmissionResult;
use Illuminate\Support\Facades\Http;

/**
 * Stability AI v2beta image-to-video — `POST /v2beta/image-to-video` (submit)
 * / `GET /v2beta/image-to-video/result/{id}` (poll). Confirmed against
 * Stability's current public API reference at implementation time
 * (12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md §8.2's "honest caveat"):
 *
 * - The endpoint accepts exactly three input-frame sizes — 1024x576, 768x768,
 *   576x1024 — which is exactly GeneratedVideo::ASPECT_RATIOS.
 * - It has no duration parameter: every job returns one short, fixed-length
 *   clip regardless of $request->durationPreset — see
 *   GeneratedVideo::DURATION_PRESETS's own docblock for why the preset still
 *   exists on the schema/form.
 * - submit() returns `{"id": "..."}`. poll() returns HTTP 202 while the job
 *   is still running and HTTP 200 with the finished clip once done;
 *   requesting `Accept: video/*` gets raw MP4 bytes on success (rather than a
 *   base64-JSON wrapper), which is what lets poll() stream straight into
 *   `$sink` without ever buffering the clip as a PHP string (plan §9.3).
 */
class StabilityVideoProvider extends AbstractVideoProvider
{
    public const SUBMIT_ENDPOINT = 'https://api.stability.ai/v2beta/image-to-video';

    public const RESULT_ENDPOINT = 'https://api.stability.ai/v2beta/image-to-video/result/';

    protected function providerName(): string
    {
        return 'Stability AI';
    }

    public function submit(VideoGenerationRequest $request): VideoSubmissionResult
    {
        $this->assertOperationSupported($request);

        if (blank($request->apiKey)) {
            throw new VideoGenerationException("{$this->providerName()} needs an API key on the provider profile.");
        }

        $response = $this->send(fn () => Http::withToken((string) $request->apiKey)
            ->timeout($this->submitTimeoutSeconds)
            ->acceptJson()
            ->attach('image', $request->sourceImage, 'source.png')
            ->post($request->endpoint(self::SUBMIT_ENDPOINT), [
                'seed' => 0,
                'cfg_scale' => 1.8,
                'motion_bucket_id' => 127,
            ]));

        $id = $response->json('id');

        if (blank($id)) {
            throw new VideoGenerationException("{$this->providerName()} did not return a job id.");
        }

        return new VideoSubmissionResult(externalJobId: (string) $id, meta: ['provider' => 'stability']);
    }

    public function poll(VideoGenerationRequest $request, string $externalJobId, mixed $sink): VideoPollResult
    {
        $response = $this->attempt(fn () => Http::withToken((string) $request->apiKey)
            ->timeout($this->pollTimeoutSeconds)
            ->withHeaders(['Accept' => 'video/*'])
            ->sink($sink)
            ->get(self::RESULT_ENDPOINT.$externalJobId));

        if ($response->status() === 202) {
            return VideoPollResult::pending(['provider' => 'stability']);
        }

        if ($response->failed()) {
            throw new VideoGenerationException(
                "{$this->providerName()} rejected the poll request (HTTP {$response->status()}): {$this->summarize($response, $sink)}"
            );
        }

        $bytes = $this->streamToSink($response, $sink);

        if ($bytes === 0) {
            return VideoPollResult::failed("{$this->providerName()} returned an empty video.");
        }

        return VideoPollResult::completed(
            videoMimeType: 'video/mp4',
            videoSizeBytes: $bytes,
            meta: ['provider' => 'stability'],
        );
    }
}
