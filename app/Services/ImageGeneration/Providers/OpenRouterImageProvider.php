<?php

namespace App\Services\ImageGeneration\Providers;

use App\Services\ImageGeneration\ImageGenerationException;
use App\Services\ImageGeneration\ImageGenerationRequest;
use App\Services\ImageGeneration\ImageGenerationResult;
use Illuminate\Support\Facades\Http;

/**
 * OpenRouter's Unified Image API — a single `POST /images` endpoint for both
 * text-to-image and image-to-image. Unlike OpenAI, there is no separate
 * `/images/edits` route: an uploaded reference is sent as a base64 data URL
 * in `input_references` on the same call. The response envelope
 * (`data[].b64_json`) matches OpenAI's, so images decode the same way.
 *
 * This exists as its own format (not routed through the generic "custom"
 * OpenAI-compatible adapter) because OpenRouter's request shape
 * (`aspect_ratio`/`resolution` instead of `size`, `input_references` instead
 * of a multipart file) and endpoint layout genuinely differ from the OpenAI
 * Images API that "custom" assumes.
 */
class OpenRouterImageProvider extends AbstractImageProvider
{
    public const DEFAULT_ENDPOINT = 'https://openrouter.ai/api/v1/images';

    protected function providerName(): string
    {
        return 'OpenRouter';
    }

    protected function operations(): array
    {
        return [ImageGenerationRequest::OP_GENERATE, ImageGenerationRequest::OP_IMAGE_TO_IMAGE];
    }

    public function generate(ImageGenerationRequest $request): ImageGenerationResult
    {
        $this->assertOperationSupported($request);

        if (blank($request->apiKey)) {
            throw new ImageGenerationException("{$this->providerName()} needs an API key on the provider profile.");
        }

        $payload = array_filter([
            'model' => $request->model,
            'prompt' => $request->prompt,
            'aspect_ratio' => $request->aspectRatio,
            'resolution' => '1K',
            'n' => max(1, min($request->count, 10)),
        ], fn ($value): bool => $value !== null && $value !== '');

        if ($request->operation === ImageGenerationRequest::OP_IMAGE_TO_IMAGE) {
            $payload['input_references'] = [[
                'url' => 'data:image/png;base64,'.base64_encode((string) $request->referenceImage),
            ]];
        }

        $response = $this->send(fn () => Http::withToken((string) $request->apiKey)
            ->timeout($this->timeoutSeconds)
            ->acceptJson()
            ->post($this->endpointFor($request), $payload));

        $data = (array) $response->json('data', []);

        $images = collect($data)
            ->map(fn ($entry): ?string => $this->decodeBase64(data_get($entry, 'b64_json')))
            ->all();

        return new ImageGenerationResult(
            images: $this->requireImages($images),
            meta: array_filter([
                'provider' => $this->providerName(),
                'model' => $request->model,
                'usage' => $response->json('usage'),
            ]),
        );
    }

    /**
     * The profile's Base URL is commonly the bare API root (the convention
     * every OpenAI-SDK-compatible client uses, e.g. `https://openrouter.ai/api/v1`)
     * rather than the full `/images` endpoint, so it is appended here when
     * missing instead of requiring admins to type the exact path.
     */
    protected function endpointFor(ImageGenerationRequest $request): string
    {
        $endpoint = $request->endpoint(self::DEFAULT_ENDPOINT);

        return str_ends_with($endpoint, '/images') ? $endpoint : rtrim($endpoint, '/').'/images';
    }
}
