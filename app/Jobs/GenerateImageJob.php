<?php

namespace App\Jobs;

use App\Filament\Pages\ImageGeneration;
use App\Models\GeneratedImage;
use App\Models\Media;
use App\Services\BusinessNotificationService;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\ImageGeneration\ImageGenerationException;
use App\Services\ImageGeneration\ImageGenerationRequest;
use App\Services\ImageGeneration\ImageProviderResolver;
use App\Services\ImageGeneration\ImageProviderSettingsService;
use App\Services\ImageOptimizerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one Image Generation request off the web request (a generation
 * commonly takes 10-60s). Resolves the chosen provider adapter, optimizes
 * every returned image to WebP, stores each through CompanyStorageService,
 * registers each in the company's Media Hub (so it is pickable from every
 * "Select From Media" field), updates the GeneratedImage row, and notifies
 * the requesting user on completion or failure.
 *
 * Handles all three operations (`generate`, `image_to_image`,
 * `background_removal`); the last two load their source bytes from the row's
 * `reference_image_path`.
 *
 * Sets/clears CompanyContext explicitly (CLAUDE.md rule for queued jobs) —
 * storage, Media Hub, and the row's own CompanyScope all depend on it.
 */
class GenerateImageJob implements ShouldQueue
{
    use Queueable;

    /** A failed generation is surfaced to the user with its reason, not retried blindly. */
    public int $tries = 1;

    public int $timeout = 240;

    /** @param  ?float  $strength  image_to_image only: 0 (keep source) → 1 (ignore it) */
    public function __construct(public int $generatedImageId, public ?float $strength = null) {}

    public function handle(
        CompanyContext $context,
        ImageProviderSettingsService $providerSettings,
        ImageProviderResolver $resolver,
        ImageOptimizerService $optimizer,
        CompanyStorageService $storage,
        BusinessNotificationService $notifications,
    ): void {
        $record = GeneratedImage::withoutGlobalScopes()->with(['company', 'user'])->find($this->generatedImageId);

        if (! $record || ! $record->company) {
            return;
        }

        $context->set($record->company);

        try {
            $record->forceFill(['status' => GeneratedImage::STATUS_PROCESSING])->save();

            $profile = $providerSettings->find($record->company, $record->provider_profile_id);

            if ($profile === null) {
                throw new ImageGenerationException(__('The selected image provider profile no longer exists — reconfigure it on Settings → Integrations → Image Providers.'));
            }

            if (! $providerSettings->isConfigured($profile)) {
                throw new ImageGenerationException('The selected image provider profile is missing a model or API key.');
            }

            $operation = $record->operation ?: GeneratedImage::OPERATION_GENERATE;
            $adapter = $resolver->byFormat($profile['api_format']);

            if (! $adapter->supportsOperation($operation)) {
                throw new ImageGenerationException(__(
                    'The ":provider" provider cannot do :operation. Pick a provider that supports it on Settings → Integrations → Image Providers.',
                    [
                        'provider' => $profile['label'],
                        'operation' => GeneratedImage::OPERATIONS[$operation] ?? $operation,
                    ],
                ));
            }

            $reference = null;

            if ($operation !== GeneratedImage::OPERATION_GENERATE) {
                $reference = $storage->readPublic($record->reference_image_path, $record->company);

                if (blank($reference)) {
                    throw new ImageGenerationException('The source image for this operation could not be found.');
                }
            }

            $count = $operation === GeneratedImage::OPERATION_BACKGROUND_REMOVAL
                ? 1
                : max(1, min((int) $record->variations_requested, GeneratedImage::MAX_VARIATIONS));

            $result = $adapter->generate(new ImageGenerationRequest(
                prompt: (string) $record->prompt,
                model: (string) $profile['model'],
                apiKey: $profile['api_key'] ?? null,
                baseUrl: $profile['base_url'] ?: null,
                size: $record->pixelSize(),
                aspectRatio: $record->aspect_ratio ?: GeneratedImage::DEFAULT_ASPECT_RATIO,
                count: $count,
                operation: $operation,
                referenceImage: $reference,
                strength: $this->strength ?? 0.6,
            ));

            $paths = [];

            foreach (array_values($result->images) as $index => $bytes) {
                $webp = $optimizer->optimizeBytes($bytes);
                $filename = sprintf('ai-%s-%d-%d.webp', $operation === GeneratedImage::OPERATION_GENERATE ? $record->context : $operation, $record->getKey(), $index + 1);
                $path = $storage->putPublic($record->company, 'ai-generated-images', $filename, $webp);
                $paths[] = $path;

                rescue(
                    fn () => Media::recordUpload($storage->publicDiskName(), $path, companyId: $record->company_id),
                    report: false,
                );
            }

            $record->forceFill([
                'status' => GeneratedImage::STATUS_COMPLETED,
                'output_paths' => $paths,
                'provider_label' => $profile['label'],
                'api_format' => $profile['api_format'],
                'model' => $profile['model'],
                'estimated_cost' => round(((float) ($profile['cost_per_image'] ?? 0)) * count($paths), 4),
                'provider_response' => $result->meta ?: null,
                'error_message' => null,
                'generated_at' => now(),
            ])->save();

            $this->notify($notifications, $record, success: true);
        } catch (Throwable $exception) {
            report($exception);

            $record->forceFill([
                'status' => GeneratedImage::STATUS_FAILED,
                'error_message' => $exception instanceof ImageGenerationException
                    ? $exception->getMessage()
                    : 'The image could not be generated. Please try again.',
            ])->save();

            $this->notify($notifications, $record, success: false);
        } finally {
            $context->clear();
        }
    }

    protected function notify(BusinessNotificationService $notifications, GeneratedImage $record, bool $success): void
    {
        if (! $record->user) {
            return;
        }

        $url = rescue(fn (): string => ImageGeneration::getUrl(), rescue: null, report: false);

        $notifications->notifyUser(
            $record->user,
            'ai-image',
            $success ? 'Image ready' : 'Image generation failed',
            $success
                ? sprintf('Your %s image is ready in the Image Generation tool.',
                    $record->isDerived() ? strtolower($record->operationLabel()) : '"'.$record->contextLabel().'"')
                : ($record->error_message ?: 'The image could not be generated.'),
            ['generated_image_id' => (string) $record->getKey()],
            $url,
            $url ? 'Open Image Generation' : null,
        );
    }
}
