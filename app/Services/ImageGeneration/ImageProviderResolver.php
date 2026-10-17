<?php

namespace App\Services\ImageGeneration;

use App\Services\ImageGeneration\Providers\CustomOpenAiCompatibleProvider;
use App\Services\ImageGeneration\Providers\GoogleImageProvider;
use App\Services\ImageGeneration\Providers\OpenAiImageProvider;
use App\Services\ImageGeneration\Providers\OpenRouterImageProvider;
use App\Services\ImageGeneration\Providers\StabilityImageProvider;

/**
 * Picks the image-provider adapter for a stored profile's `api_format` —
 * same "resolve an implementation by a stored key" shape as
 * App\Services\PaymentGatewayResolver. Adding a provider later is one map
 * entry plus one adapter class.
 */
class ImageProviderResolver
{
    /** @var array<string, class-string<ImageGenerationClient>> */
    protected array $providers = [
        'openai' => OpenAiImageProvider::class,
        'google' => GoogleImageProvider::class,
        'stability' => StabilityImageProvider::class,
        'openrouter' => OpenRouterImageProvider::class,
        'custom' => CustomOpenAiCompatibleProvider::class,
    ];

    public function byFormat(string $apiFormat): ImageGenerationClient
    {
        $class = $this->providers[$apiFormat] ?? null;

        if ($class === null) {
            throw new ImageGenerationException("No image provider is registered for the \"{$apiFormat}\" format.");
        }

        return app($class);
    }

    public function supports(string $apiFormat): bool
    {
        return array_key_exists($apiFormat, $this->providers);
    }

    /**
     * The `api_format`s whose adapter can run the given operation
     * (self::OP_* — 'image_to_image', 'background_removal', ...).
     *
     * @return array<int, string>
     */
    public function formatsSupporting(string $operation): array
    {
        return collect($this->providers)
            ->filter(fn (string $class): bool => app($class)->supportsOperation($operation))
            ->keys()
            ->all();
    }
}
