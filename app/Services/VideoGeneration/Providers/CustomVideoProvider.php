<?php

namespace App\Services\VideoGeneration\Providers;

use App\Services\VideoGeneration\VideoGenerationException;
use App\Services\VideoGeneration\VideoGenerationRequest;
use App\Services\VideoGeneration\VideoPollResult;
use App\Services\VideoGeneration\VideoSubmissionResult;
use App\Support\OutboundUrlGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Any self-hosted or third-party async image-to-video endpoint an admin
 * points at manually — mirrors CustomOpenAiCompatibleProvider's flexibility
 * (admin-set base URL, optional API key for keyless self-hosted servers),
 * but needs a second endpoint since a generic async provider needs a real
 * status-check URL: the profile's `poll_url_template`, with `{job_id}`
 * replaced per job (plan §6.1, §8.3).
 *
 * poll() defensively handles either shape a status endpoint might return:
 * the finished video as a direct binary/octet-stream response, or a JSON
 * body carrying a `video_url` to download separately — the same
 * defensive-both-shapes pattern OpenAiImageProvider::generate() already uses
 * for `b64_json` vs `url`.
 */
class CustomVideoProvider extends AbstractVideoProvider
{
    protected function providerName(): string
    {
        return 'Custom video provider';
    }

    public function submit(VideoGenerationRequest $request): VideoSubmissionResult
    {
        $this->assertOperationSupported($request);

        if (blank($request->baseUrl)) {
            throw new VideoGenerationException('A custom video provider needs a Base URL on its profile.');
        }

        $response = $this->send(fn () => $this->http($request, $this->submitTimeoutSeconds)
            ->attach('image', $request->sourceImage, 'source.png')
            ->post(rtrim(trim($request->baseUrl), '/'), array_filter([
                'prompt' => $request->prompt,
                'model' => $request->model,
                'aspect_ratio' => $request->aspectRatio,
                'duration_preset' => $request->durationPreset,
            ], fn ($value): bool => filled($value))));

        $id = $response->json('id') ?? $response->json('job_id');

        if (blank($id)) {
            throw new VideoGenerationException("{$this->providerName()} did not return a job id.");
        }

        return new VideoSubmissionResult(externalJobId: (string) $id, meta: ['provider' => 'custom']);
    }

    public function poll(VideoGenerationRequest $request, string $externalJobId, mixed $sink): VideoPollResult
    {
        $pollUrl = $request->pollUrlFor($externalJobId);

        if (blank($pollUrl)) {
            throw new VideoGenerationException('A custom video provider needs a Poll URL template on its profile.');
        }

        $response = $this->attempt(fn () => $this->bareHttp($request, $this->pollTimeoutSeconds)
            ->withHeaders(['Accept' => 'application/octet-stream, application/json;q=0.5'])
            ->sink($sink)
            ->get($pollUrl));

        if ($response->status() === 202) {
            return VideoPollResult::pending(['provider' => 'custom']);
        }

        if ($response->failed()) {
            throw new VideoGenerationException(
                "{$this->providerName()} rejected the poll request (HTTP {$response->status()}): {$this->summarize($response, $sink)}"
            );
        }

        if (str_contains((string) $response->header('Content-Type'), 'application/json')) {
            return $this->pollFromJsonBody($request, $sink);
        }

        $bytes = $this->streamToSink($response, $sink);

        if ($bytes === 0) {
            return VideoPollResult::failed("{$this->providerName()} returned an empty response.");
        }

        return VideoPollResult::completed(
            videoMimeType: $response->header('Content-Type') ?: 'video/mp4',
            videoSizeBytes: $bytes,
            meta: ['provider' => 'custom'],
        );
    }

    /**
     * The poll response was JSON, not a binary clip — either a still-pending
     * status, a failure, or a `video_url` to download separately. The sink
     * currently holds the JSON body itself (diverted there by Http::sink());
     * it is reused (rewound and overwritten) for the actual video download.
     *
     * @param  resource  $sink
     */
    protected function pollFromJsonBody(VideoGenerationRequest $request, mixed $sink): VideoPollResult
    {
        $json = json_decode($this->readSink($sink), true) ?: [];

        if (filled(data_get($json, 'error'))) {
            $error = data_get($json, 'error.message') ?? data_get($json, 'error');

            return VideoPollResult::failed(is_string($error) ? $error : 'The custom provider reported a failure.');
        }

        $status = (string) data_get($json, 'status', '');

        if (in_array($status, ['pending', 'processing', 'in_progress', 'queued', 'running'], true)) {
            return VideoPollResult::pending(['provider' => 'custom']);
        }

        $videoUrl = data_get($json, 'video_url') ?? data_get($json, 'url');

        if (blank($videoUrl)) {
            // No error, no recognizable "still going" status, and nothing to
            // download yet — treat as still pending rather than guessing.
            return VideoPollResult::pending(['provider' => 'custom']);
        }

        rewind($sink);
        ftruncate($sink, 0);

        $videoResponse = $this->attempt(fn () => $this->downloadHttp((string) $videoUrl, (string) ($request->pollUrlFor('') ?: $request->baseUrl))->sink($sink)->get((string) $videoUrl));

        if ($videoResponse->failed()) {
            throw new VideoGenerationException(
                "{$this->providerName()}'s video URL could not be downloaded (HTTP {$videoResponse->status()})."
            );
        }

        $bytes = $this->streamToSink($videoResponse, $sink);

        if ($bytes === 0) {
            return VideoPollResult::failed("{$this->providerName()}'s video URL returned no data.");
        }

        return VideoPollResult::completed(
            videoMimeType: $videoResponse->header('Content-Type') ?: 'video/mp4',
            videoSizeBytes: $bytes,
            meta: ['provider' => 'custom'],
        );
    }

    /**
     * The submit and poll addresses are the self-hosted service the admin chose
     * (it may well sit on the same private network), but the video link comes
     * out of that service's answer. A link on the provider's own host is fetched
     * as before; a link anywhere else must be a public address (SSRF).
     */
    protected function downloadHttp(string $videoUrl, string $pollUrl): PendingRequest
    {
        $sameHost = strcasecmp((string) parse_url($videoUrl, PHP_URL_HOST), (string) parse_url($pollUrl, PHP_URL_HOST)) === 0;

        return $sameHost
            ? Http::timeout($this->pollTimeoutSeconds)
            : OutboundUrlGuard::http($videoUrl, requireHttps: false, timeout: $this->pollTimeoutSeconds);
    }

    protected function http(VideoGenerationRequest $request, int $timeout): PendingRequest
    {
        return $this->bareHttp($request, $timeout)->acceptJson();
    }

    /** Same auth handling as http(), without forcing an Accept header — poll() sets its own. */
    protected function bareHttp(VideoGenerationRequest $request, int $timeout): PendingRequest
    {
        $http = Http::timeout($timeout);

        return filled($request->apiKey) ? $http->withToken((string) $request->apiKey) : $http;
    }
}
