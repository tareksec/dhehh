<?php

namespace Tests\Feature;

use App\Services\ImageGeneration\ImageGenerationException;
use App\Services\ImageGeneration\ImageGenerationRequest;
use App\Services\ImageGeneration\ImageProviderResolver;
use App\Services\ImageGeneration\Providers\CustomOpenAiCompatibleProvider;
use App\Services\ImageGeneration\Providers\GoogleImageProvider;
use App\Services\ImageGeneration\Providers\OpenAiImageProvider;
use App\Services\ImageGeneration\Providers\OpenRouterImageProvider;
use App\Services\ImageGeneration\Providers\StabilityImageProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImageGenerationProviderAdaptersTest extends TestCase
{
    private function pngBytes(int $w = 24, int $h = 24): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, imagecolorallocate($image, 12, 34, 56));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function request(array $overrides = []): ImageGenerationRequest
    {
        $get = fn (string $key, mixed $default): mixed => array_key_exists($key, $overrides) ? $overrides[$key] : $default;

        return new ImageGenerationRequest(
            prompt: $get('prompt', 'a red bicycle'),
            model: $get('model', 'gpt-image-1'),
            apiKey: $get('apiKey', 'sk-test'),
            baseUrl: $get('baseUrl', null),
            size: $get('size', '1024x1024'),
            aspectRatio: $get('aspectRatio', '1:1'),
            count: $get('count', 1),
            operation: $get('operation', ImageGenerationRequest::OP_GENERATE),
            referenceImage: $get('referenceImage', null),
            strength: $get('strength', 0.6),
        );
    }

    public function test_openai_adapter_decodes_base64_images(): void
    {
        $png = $this->pngBytes();
        Http::fake([
            'api.openai.com/*' => Http::response(['data' => [['b64_json' => base64_encode($png)]], 'usage' => ['total_tokens' => 5]], 200),
        ]);

        $result = app(OpenAiImageProvider::class)->generate($this->request());

        $this->assertCount(1, $result->images);
        $this->assertSame($png, $result->images[0]);
        $this->assertSame('OpenAI', $result->meta['provider']);

        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer sk-test')
            && $request['prompt'] === 'a red bicycle');
    }

    public function test_openai_adapter_downloads_a_url_response(): void
    {
        $png = $this->pngBytes();
        Http::fake([
            'api.openai.com/*' => Http::response(['data' => [['url' => 'https://cdn.example/img.png']]], 200),
            'cdn.example/*' => Http::response($png, 200),
        ]);

        $result = app(OpenAiImageProvider::class)->generate($this->request());

        $this->assertSame($png, $result->images[0]);
    }

    public function test_openai_adapter_turns_an_http_error_into_a_readable_exception(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);

        $this->expectException(ImageGenerationException::class);
        $this->expectExceptionMessageMatches('/Invalid API key/');

        app(OpenAiImageProvider::class)->generate($this->request());
    }

    public function test_openai_adapter_rejects_an_empty_image_list(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => []], 200)]);

        $this->expectException(ImageGenerationException::class);
        $this->expectExceptionMessageMatches('/no image data/');

        app(OpenAiImageProvider::class)->generate($this->request());
    }

    public function test_openai_adapter_requires_an_api_key(): void
    {
        Http::fake();

        $this->expectException(ImageGenerationException::class);
        $this->expectExceptionMessageMatches('/needs an API key/');

        app(OpenAiImageProvider::class)->generate($this->request(['apiKey' => null]));
    }

    public function test_custom_adapter_requires_a_base_url_but_not_a_key(): void
    {
        $png = $this->pngBytes();
        Http::fake(['self-hosted.local/*' => Http::response(['data' => [['b64_json' => base64_encode($png)]]], 200)]);

        $result = app(CustomOpenAiCompatibleProvider::class)->generate($this->request([
            'apiKey' => null,
            'baseUrl' => 'https://self-hosted.local/v1/images/generations',
            'model' => 'sdxl',
        ]));

        $this->assertSame($png, $result->images[0]);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://self-hosted.local/'));

        $this->expectException(ImageGenerationException::class);
        app(CustomOpenAiCompatibleProvider::class)->generate($this->request(['baseUrl' => null]));
    }

    public function test_google_adapter_sends_the_key_as_a_header_not_a_query_param(): void
    {
        $png = $this->pngBytes();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'predictions' => [['bytesBase64Encoded' => base64_encode($png), 'mimeType' => 'image/png']],
            ], 200),
        ]);

        $result = app(GoogleImageProvider::class)->generate($this->request([
            'model' => 'imagen-4.0-generate-001',
            'apiKey' => 'goog-key',
        ]));

        $this->assertSame($png, $result->images[0]);
        Http::assertSent(fn ($request): bool => $request->hasHeader('x-goog-api-key', 'goog-key')
            && ! str_contains($request->url(), 'goog-key'));
    }

    public function test_stability_adapter_loops_for_each_variation(): void
    {
        $png = $this->pngBytes();
        Http::fake(['api.stability.ai/*' => Http::response(['image' => base64_encode($png), 'seed' => 7], 200)]);

        $result = app(StabilityImageProvider::class)->generate($this->request([
            'model' => 'core',
            'apiKey' => 'stab-key',
            'count' => 3,
        ]));

        $this->assertCount(3, $result->images);
        Http::assertSentCount(3);
    }

    public function test_resolver_maps_formats_to_adapters(): void
    {
        $resolver = app(ImageProviderResolver::class);

        $this->assertInstanceOf(OpenAiImageProvider::class, $resolver->byFormat('openai'));
        $this->assertInstanceOf(GoogleImageProvider::class, $resolver->byFormat('google'));
        $this->assertInstanceOf(StabilityImageProvider::class, $resolver->byFormat('stability'));
        $this->assertInstanceOf(OpenRouterImageProvider::class, $resolver->byFormat('openrouter'));
        $this->assertInstanceOf(CustomOpenAiCompatibleProvider::class, $resolver->byFormat('custom'));
        $this->assertFalse($resolver->supports('midjourney'));

        $this->expectException(ImageGenerationException::class);
        $resolver->byFormat('midjourney');
    }

    public function test_operation_support_matrix(): void
    {
        $i2i = ImageGenerationRequest::OP_IMAGE_TO_IMAGE;
        $bg = ImageGenerationRequest::OP_BACKGROUND_REMOVAL;

        $this->assertTrue(app(OpenAiImageProvider::class)->supportsOperation($i2i));
        $this->assertFalse(app(OpenAiImageProvider::class)->supportsOperation($bg));
        $this->assertTrue(app(CustomOpenAiCompatibleProvider::class)->supportsOperation($i2i));
        $this->assertTrue(app(StabilityImageProvider::class)->supportsOperation($i2i));
        $this->assertTrue(app(StabilityImageProvider::class)->supportsOperation($bg));
        $this->assertFalse(app(GoogleImageProvider::class)->supportsOperation($i2i));
        $this->assertTrue(app(OpenRouterImageProvider::class)->supportsOperation($i2i));
        $this->assertFalse(app(OpenRouterImageProvider::class)->supportsOperation($bg));

        $resolver = app(ImageProviderResolver::class);
        $this->assertEqualsCanonicalizing(['openai', 'stability', 'openrouter', 'custom'], $resolver->formatsSupporting($i2i));
        $this->assertSame(['stability'], $resolver->formatsSupporting($bg));
    }

    public function test_openai_image_to_image_posts_the_source_to_the_edits_endpoint(): void
    {
        $png = $this->pngBytes();
        Http::fake(['api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($png)]]], 200)]);

        $result = app(OpenAiImageProvider::class)->generate($this->request([
            'operation' => ImageGenerationRequest::OP_IMAGE_TO_IMAGE,
            'referenceImage' => $this->pngBytes(8, 8),
            'prompt' => 'make it night',
        ]));

        $this->assertSame($png, $result->images[0]);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/v1/images/edits')
            && $request->hasHeader('Authorization', 'Bearer sk-test')
            && collect($request->data())->contains(fn ($part) => ($part['name'] ?? null) === 'prompt' && $part['contents'] === 'make it night'));
    }

    public function test_openai_image_to_image_needs_a_source_image(): void
    {
        Http::fake();

        $this->expectException(ImageGenerationException::class);
        $this->expectExceptionMessageMatches('/needs a source image/');

        app(OpenAiImageProvider::class)->generate($this->request([
            'operation' => ImageGenerationRequest::OP_IMAGE_TO_IMAGE,
            'referenceImage' => null,
        ]));
    }

    public function test_custom_provider_derives_its_edits_endpoint_from_the_base_url(): void
    {
        $png = $this->pngBytes();
        Http::fake(['self-hosted.local/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($png)]]], 200)]);

        $result = app(CustomOpenAiCompatibleProvider::class)->generate($this->request([
            'operation' => ImageGenerationRequest::OP_IMAGE_TO_IMAGE,
            'referenceImage' => $this->pngBytes(8, 8),
            'apiKey' => null,
            'baseUrl' => 'https://self-hosted.local/v1/images/generations',
            'model' => 'sdxl',
        ]));

        $this->assertSame($png, $result->images[0]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://self-hosted.local/v1/images/edits');
    }

    public function test_openrouter_adapter_posts_to_the_images_endpoint_and_decodes_base64(): void
    {
        $png = $this->pngBytes();
        Http::fake(['openrouter.ai/api/v1/images' => Http::response(['data' => [['b64_json' => base64_encode($png), 'media_type' => 'image/png']], 'usage' => ['cost' => 0.01]], 200)]);

        $result = app(OpenRouterImageProvider::class)->generate($this->request([
            'model' => 'google/gemini-2.5-flash-image',
            'apiKey' => 'sk-or-test',
        ]));

        $this->assertSame($png, $result->images[0]);
        $this->assertSame('OpenRouter', $result->meta['provider']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://openrouter.ai/api/v1/images'
            && $request->hasHeader('Authorization', 'Bearer sk-or-test')
            && $request['model'] === 'google/gemini-2.5-flash-image'
            && $request['aspect_ratio'] === '1:1'
            && ! array_key_exists('input_references', $request->data()));
    }

    public function test_openrouter_image_to_image_sends_the_source_as_an_input_reference(): void
    {
        $png = $this->pngBytes();
        $reference = $this->pngBytes(8, 8);
        Http::fake(['openrouter.ai/api/v1/images' => Http::response(['data' => [['b64_json' => base64_encode($png)]]], 200)]);

        $result = app(OpenRouterImageProvider::class)->generate($this->request([
            'operation' => ImageGenerationRequest::OP_IMAGE_TO_IMAGE,
            'referenceImage' => $reference,
            'apiKey' => 'sk-or-test',
        ]));

        $this->assertSame($png, $result->images[0]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://openrouter.ai/api/v1/images'
            && data_get($request->data(), 'input_references.0.url') === 'data:image/png;base64,'.base64_encode($reference));
    }

    /**
     * Admins commonly enter the bare API root (the convention every
     * OpenAI-SDK-compatible client documents), not the full `/images` path —
     * confirms that override is completed rather than used verbatim.
     */
    public function test_openrouter_adapter_appends_images_to_a_bare_base_url_override(): void
    {
        $png = $this->pngBytes();
        Http::fake(['openrouter.ai/api/v1/images' => Http::response(['data' => [['b64_json' => base64_encode($png)]]], 200)]);

        app(OpenRouterImageProvider::class)->generate($this->request([
            'apiKey' => 'sk-or-test',
            'baseUrl' => 'https://openrouter.ai/api/v1',
        ]));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://openrouter.ai/api/v1/images');
    }

    public function test_openrouter_adapter_requires_an_api_key(): void
    {
        Http::fake();

        $this->expectException(ImageGenerationException::class);
        $this->expectExceptionMessageMatches('/needs an API key/');

        app(OpenRouterImageProvider::class)->generate($this->request(['apiKey' => null]));
    }

    public function test_stability_image_to_image_uses_the_sd3_endpoint_with_strength(): void
    {
        $png = $this->pngBytes();
        Http::fake(['api.stability.ai/*' => Http::response(['image' => base64_encode($png), 'seed' => 3], 200)]);

        $result = app(StabilityImageProvider::class)->generate($this->request([
            'operation' => ImageGenerationRequest::OP_IMAGE_TO_IMAGE,
            'referenceImage' => $this->pngBytes(8, 8),
            'apiKey' => 'stab-key',
            'model' => 'sd3.5-medium',
            'strength' => 0.4,
            'count' => 2,
        ]));

        $this->assertCount(2, $result->images);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/stable-image/generate/sd3')
            && collect($request->data())->contains(fn ($part) => ($part['name'] ?? null) === 'mode' && $part['contents'] === 'image-to-image')
            && collect($request->data())->contains(fn ($part) => ($part['name'] ?? null) === 'strength' && (string) $part['contents'] === '0.4'));
    }

    public function test_stability_background_removal_hits_the_remove_background_endpoint(): void
    {
        $png = $this->pngBytes();
        Http::fake(['api.stability.ai/*' => Http::response(['image' => base64_encode($png)], 200)]);

        $result = app(StabilityImageProvider::class)->generate($this->request([
            'operation' => ImageGenerationRequest::OP_BACKGROUND_REMOVAL,
            'referenceImage' => $this->pngBytes(8, 8),
            'apiKey' => 'stab-key',
            'count' => 4,
        ]));

        $this->assertCount(1, $result->images); // background removal is always a single image
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/stable-image/edit/remove-background'));
    }

    public function test_google_rejects_an_unsupported_operation(): void
    {
        Http::fake();

        $this->expectException(ImageGenerationException::class);
        $this->expectExceptionMessageMatches('/does not support/');

        app(GoogleImageProvider::class)->generate($this->request([
            'operation' => ImageGenerationRequest::OP_IMAGE_TO_IMAGE,
            'referenceImage' => $this->pngBytes(8, 8),
            'apiKey' => 'goog-key',
            'model' => 'imagen-4.0-generate-001',
        ]));
    }
}
