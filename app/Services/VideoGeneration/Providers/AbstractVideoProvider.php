<?php

namespace App\Services\VideoGeneration\Providers;

use App\Services\VideoGeneration\VideoGenerationClient;
use App\Services\VideoGeneration\VideoGenerationException;
use App\Services\VideoGeneration\VideoGenerationRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Shared plumbing for the concrete provider adapters — mirrors
 * AbstractImageProvider. Adds streamToSink(), used by every adapter's
 * poll() once a job is reported complete, so "read the finished response
 * into $sink without buffering it in a PHP string" is written once.
 */
abstract class AbstractVideoProvider implements VideoGenerationClient
{
    protected int $submitTimeoutSeconds = 60;

    protected int $pollTimeoutSeconds = 30;

    abstract protected function providerName(): string;

    /**
     * Operations this adapter can run. Image-to-video only today; every
     * adapter supports it.
     *
     * @return array<int, string>
     */
    protected function operations(): array
    {
        return [VideoGenerationRequest::OP_IMAGE_TO_VIDEO];
    }

    public function supportsOperation(string $operation): bool
    {
        return in_array($operation, $this->operations(), true);
    }

    protected function assertOperationSupported(VideoGenerationRequest $request): void
    {
        if (! $this->supportsOperation($request->operation)) {
            throw new VideoGenerationException(
                "{$this->providerName()} does not support the \"{$request->operation}\" operation."
            );
        }
    }

    /**
     * @param  callable():Response  $send
     */
    protected function send(callable $send): Response
    {
        $response = $this->attempt($send);

        if ($response->failed()) {
            throw new VideoGenerationException(
                "{$this->providerName()} rejected the request (HTTP {$response->status()}): {$this->summarize($response)}"
            );
        }

        return $response;
    }

    /**
     * Turns a transport-level failure (DNS, timeout, refused connection)
     * into a VideoGenerationException without judging the HTTP status —
     * poll() uses this directly so it can treat "202 still processing" as a
     * normal result rather than a failure, which send()'s blanket
     * `failed()` check would not distinguish from a real error.
     *
     * @param  callable():Response  $send
     */
    protected function attempt(callable $send): Response
    {
        try {
            return $send();
        } catch (VideoGenerationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new VideoGenerationException(
                "{$this->providerName()} could not be reached: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }

    /**
     * Best-effort error detail for a non-2xx response. When the response
     * body was diverted to a sink (poll()'s Http::sink() calls), $sink holds
     * whatever the provider actually sent — a small JSON error body, in
     * practice, since a real video is never returned on a failing status.
     *
     * @param  resource|null  $sink
     */
    protected function summarize(Response $response, mixed $sink = null): string
    {
        $body = $sink !== null ? $this->readSink($sink) : (string) $response->body();
        $json = rescue(fn (): mixed => json_decode($body, true, flags: JSON_THROW_ON_ERROR), rescue: null, report: false);

        foreach ([['error', 'message'], ['error'], ['message'], ['detail'], ['errors', 0, 'message']] as $path) {
            $value = data_get($json, implode('.', $path));

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return str(trim($body))->limit(300)->toString() ?: 'no error detail returned.';
    }

    /**
     * @param  resource  $sink
     */
    protected function readSink(mixed $sink): string
    {
        rewind($sink);
        $contents = stream_get_contents($sink) ?: '';
        rewind($sink);

        return $contents;
    }

    /**
     * By the time a Response exists, Http::sink($sink) has already streamed
     * every byte of the body into it (Guzzle writes to the sink as part of
     * sending the request) — this only rewinds it and reports the resulting
     * size for `video_size_bytes`.
     *
     * @param  resource  $sink
     */
    protected function streamToSink(Response $response, mixed $sink): int
    {
        rewind($sink);

        $stat = fstat($sink);

        return is_array($stat) ? (int) $stat['size'] : strlen($this->readSink($sink));
    }
}
