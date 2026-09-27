<?php

namespace App\Jobs;

use App\Filament\Pages\VideoGeneration;
use App\Models\GeneratedVideo;
use App\Models\Media;
use App\Services\BusinessNotificationService;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\VideoGeneration\VideoGenerationClient;
use App\Services\VideoGeneration\VideoGenerationException;
use App\Services\VideoGeneration\VideoGenerationRequest;
use App\Services\VideoGeneration\VideoPollResult;
use App\Services\VideoGeneration\VideoProviderResolver;
use App\Services\VideoGeneration\VideoProviderSettingsService;
use DateTime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one Video Generation request through its full async submit -> poll
 * lifecycle (12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md §9). Unlike
 * GenerateImageJob's single synchronous HTTP call, this job re-queues itself
 * with $this->release() after every submit/poll and is popped again as a
 * fresh instance each time — nothing survives between invocations except
 * what is written to the GeneratedVideo row.
 *
 * retryUntil() is the real governor here, not $tries: Laravel's queue worker
 * ignores $tries/maxTries entirely once retryUntil() is defined. $timeout is
 * deliberately shorter than GenerateImageJob's 240s — each invocation is one
 * submit call or one poll-check-plus-download, never the whole multi-minute
 * wait, which lives in wall-clock time across releases instead.
 *
 * Sets/clears CompanyContext explicitly every invocation (CLAUDE.md rule for
 * queued jobs), exactly like GenerateImageJob — the only difference is this
 * happens N times per logical generation instead of once.
 */
class GenerateVideoJob implements ShouldQueue
{
    use Queueable;

    /** Defensive fallback only — retryUntil() below is what actually governs retries. */
    public int $tries = 200;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(public int $generatedVideoId) {}

    /**
     * Once this elapses, Laravel fails the job via its own expiry check
     * *before* handle() runs again — failed() below is the safety net for
     * that case, since the try/catch inside handle() never gets a chance to
     * run.
     */
    public function retryUntil(): DateTime
    {
        $record = GeneratedVideo::withoutGlobalScopes()->find($this->generatedVideoId);

        return $record?->poll_deadline_at ?? $record?->created_at?->addMinutes(30) ?? now()->addMinutes(30);
    }

    public function handle(
        CompanyContext $context,
        VideoProviderSettingsService $providerSettings,
        VideoProviderResolver $resolver,
        CompanyStorageService $storage,
        BusinessNotificationService $notifications,
    ): void {
        $record = GeneratedVideo::withoutGlobalScopes()->with(['company', 'user'])->find($this->generatedVideoId);

        if (! $record || ! $record->company) {
            return;
        }

        $context->set($record->company);

        try {
            if ($record->isTerminal()) {
                return;
            }

            $profile = $providerSettings->find($record->company, $record->provider_profile_id);

            if ($profile === null) {
                throw new VideoGenerationException(__('The selected video provider profile no longer exists — reconfigure it on Settings → Integrations → Video Providers.'));
            }

            if (! $providerSettings->isConfigured($profile)) {
                throw new VideoGenerationException('The selected video provider profile is missing required configuration.');
            }

            $operation = $record->operation ?: GeneratedVideo::OPERATION_IMAGE_TO_VIDEO;
            $adapter = $resolver->byFormat($profile['api_format']);

            if (! $adapter->supportsOperation($operation)) {
                throw new VideoGenerationException(sprintf(
                    'The "%s" provider cannot do %s.',
                    $profile['label'],
                    GeneratedVideo::OPERATIONS[$operation] ?? $operation,
                ));
            }

            blank($record->external_job_id)
                ? $this->submit($record, $profile, $adapter, $storage)
                : $this->poll($record, $profile, $adapter, $storage, $notifications);
        } catch (Throwable $exception) {
            report($exception);

            $record->forceFill([
                'status' => GeneratedVideo::STATUS_FAILED,
                'error_message' => $exception instanceof VideoGenerationException
                    ? $exception->getMessage()
                    : 'The video could not be generated. Please try again.',
            ])->save();

            $this->notify($notifications, $record, success: false);
        } finally {
            $context->clear();
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    protected function submit(GeneratedVideo $record, array $profile, VideoGenerationClient $adapter, CompanyStorageService $storage): void
    {
        $sourceBytes = $storage->readPublic($record->source_image_path, $record->company);

        if (blank($sourceBytes)) {
            throw new VideoGenerationException('The starting image for this video could not be found.');
        }

        $result = $adapter->submit($this->buildRequest($record, $profile, $sourceBytes));

        $record->forceFill([
            'status' => GeneratedVideo::STATUS_PROCESSING,
            'external_job_id' => $result->externalJobId,
            'provider_label' => $profile['label'],
            'api_format' => $profile['api_format'],
            'model' => $profile['model'] ?: null,
            'submitted_at' => now(),
            'poll_deadline_at' => now()->addMinutes(30),
            'next_poll_at' => now()->addSeconds(15),
            'provider_response' => $result->meta ?: null,
        ])->save();

        $this->release(15);
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    protected function poll(
        GeneratedVideo $record,
        array $profile,
        VideoGenerationClient $adapter,
        CompanyStorageService $storage,
        BusinessNotificationService $notifications,
    ): void {
        $record->forceFill(['poll_attempts' => $record->poll_attempts + 1])->save();

        // No adapter's poll() reads $request->sourceImage (only submit()
        // attaches it) — skip re-reading the file from storage on every one
        // of the potentially dozens of polls a single generation makes.
        $request = $this->buildRequest($record, $profile, sourceBytes: '');

        $sink = tmpfile();

        if ($sink === false) {
            throw new VideoGenerationException('Could not open a temporary file to receive the video.');
        }

        try {
            $result = $adapter->poll($request, (string) $record->external_job_id, $sink);

            if ($result->isPending()) {
                $delay = min(60, 15 * (1 + intdiv($record->poll_attempts, 4)));
                $record->forceFill(['next_poll_at' => now()->addSeconds($delay)])->save();
                $this->release($delay);

                return;
            }

            if ($result->isFailed()) {
                throw new VideoGenerationException($result->errorMessage ?: 'The video could not be generated.');
            }

            $this->storeCompletedVideo($record, $profile, $storage, $result, $sink);
            $this->notify($notifications, $record, success: true);
        } finally {
            fclose($sink);
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  resource  $sink
     */
    protected function storeCompletedVideo(GeneratedVideo $record, array $profile, CompanyStorageService $storage, VideoPollResult $result, mixed $sink): void
    {
        rewind($sink);

        $filename = sprintf('ai-video-%d.%s', $record->getKey(), $this->extensionFor($result->videoMimeType));
        $path = $storage->putPublic($record->company, 'ai-generated-videos', $filename, $sink);

        rescue(
            fn () => Media::recordUpload($storage->publicDiskName(), $path, companyId: $record->company_id),
            report: false,
        );

        $record->forceFill([
            'status' => GeneratedVideo::STATUS_COMPLETED,
            'video_path' => $path,
            'video_mime_type' => $result->videoMimeType,
            'video_size_bytes' => $result->videoSizeBytes,
            'duration_seconds' => $result->durationSeconds !== null ? (int) round($result->durationSeconds) : null,
            'thumbnail_path' => $result->posterUrl ?: $record->source_image_path,
            'estimated_cost' => round((float) ($profile['cost_per_video'] ?? 0), 4),
            'provider_response' => $result->meta ?: null,
            'error_message' => null,
            'generated_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    protected function buildRequest(GeneratedVideo $record, array $profile, string $sourceBytes): VideoGenerationRequest
    {
        return new VideoGenerationRequest(
            prompt: (string) $record->prompt,
            model: filled($profile['model'] ?? null) ? $profile['model'] : null,
            apiKey: $profile['api_key'] ?? null,
            baseUrl: filled($profile['base_url'] ?? null) ? $profile['base_url'] : null,
            pollUrlTemplate: filled($profile['poll_url_template'] ?? null) ? $profile['poll_url_template'] : null,
            sourceImage: $sourceBytes,
            aspectRatio: $record->aspect_ratio ?: GeneratedVideo::DEFAULT_ASPECT_RATIO,
            durationPreset: $record->duration_preset ?: GeneratedVideo::DEFAULT_DURATION_PRESET,
            operation: $record->operation ?: GeneratedVideo::OPERATION_IMAGE_TO_VIDEO,
        );
    }

    protected function extensionFor(?string $mimeType): string
    {
        return match ($mimeType) {
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            default => 'mp4',
        };
    }

    /**
     * Safety net for the edge case in retryUntil()'s docblock: when the
     * deadline elapses between releases, Laravel fails the job on its own
     * expiry check without ever calling handle() again, so the try/catch
     * there never runs and the row would otherwise be left stuck at
     * `processing` forever.
     */
    public function failed(?Throwable $exception): void
    {
        $record = GeneratedVideo::withoutGlobalScopes()->with(['company', 'user'])->find($this->generatedVideoId);

        if (! $record || ! $record->company || $record->isTerminal()) {
            return;
        }

        app(CompanyContext::class)->set($record->company);

        try {
            $record->forceFill([
                'status' => GeneratedVideo::STATUS_FAILED,
                'error_message' => 'The video did not finish in time.',
            ])->save();

            $this->notify(app(BusinessNotificationService::class), $record, success: false);
        } finally {
            app(CompanyContext::class)->clear();
        }
    }

    protected function notify(BusinessNotificationService $notifications, GeneratedVideo $record, bool $success): void
    {
        if (! $record->user) {
            return;
        }

        $url = rescue(fn (): string => VideoGeneration::getUrl(), rescue: null, report: false);

        $notifications->notifyUser(
            $record->user,
            'ai-video',
            $success ? 'Video ready' : 'Video generation failed',
            $success
                ? 'Your video is ready in the Video Generation tool.'
                : ($record->error_message ?: 'The video could not be generated.'),
            ['generated_video_id' => (string) $record->getKey()],
            $url,
            $url ? 'Open Video Generation' : null,
        );
    }
}
