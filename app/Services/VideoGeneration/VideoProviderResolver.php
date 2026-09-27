<?php

namespace App\Services\VideoGeneration;

use App\Services\VideoGeneration\Providers\CustomVideoProvider;
use App\Services\VideoGeneration\Providers\StabilityVideoProvider;

/**
 * Picks the video-provider adapter for a stored profile's `api_format` —
 * identical shape to ImageProviderResolver. Adding the near-term follow-up
 * named adapter (plan §8.4) is one map entry plus one class.
 */
class VideoProviderResolver
{
    /** @var array<string, class-string<VideoGenerationClient>> */
    protected array $providers = [
        'stability' => StabilityVideoProvider::class,
        'custom' => CustomVideoProvider::class,
    ];

    public function byFormat(string $apiFormat): VideoGenerationClient
    {
        $class = $this->providers[$apiFormat] ?? null;

        if ($class === null) {
            throw new VideoGenerationException("No video provider is registered for the \"{$apiFormat}\" format.");
        }

        return app($class);
    }

    public function supports(string $apiFormat): bool
    {
        return array_key_exists($apiFormat, $this->providers);
    }

    /**
     * The `api_format`s whose adapter can run the given operation.
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
