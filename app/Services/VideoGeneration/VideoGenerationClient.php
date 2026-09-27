<?php

namespace App\Services\VideoGeneration;

/**
 * Thin contract for an async image-to-video provider. Split into submit() +
 * poll() rather than one generate() call: video-generation APIs are async
 * (submit -> poll a status endpoint over 1-10+ minutes), and an adapter that
 * blocked inside a single generate() call would have to sleep-and-poll
 * itself — defeating the point of moving the wait into GenerateVideoJob's own
 * release() loop (plan §7.1).
 *
 * Every adapter is Http::fake()-mocked in tests — never called live there,
 * matching the existing rule for ImageGenerationClient / AiLlmClient.
 */
interface VideoGenerationClient
{
    /**
     * Starts one generation and returns the provider's async job id.
     *
     * @throws VideoGenerationException on bad config or an HTTP failure
     */
    public function submit(VideoGenerationRequest $request): VideoSubmissionResult;

    /**
     * Checks one in-flight job. When the provider reports it done, the
     * finished video is streamed into `$sink` (a resource from tmpfile()) —
     * never returned as bytes.
     *
     * @param  resource  $sink
     *
     * @throws VideoGenerationException on an HTTP failure talking to the provider
     */
    public function poll(VideoGenerationRequest $request, string $externalJobId, mixed $sink): VideoPollResult;

    /** @param  VideoGenerationRequest::OP_*  $operation */
    public function supportsOperation(string $operation): bool;
}
