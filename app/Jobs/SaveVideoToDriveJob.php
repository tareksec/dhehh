<?php

namespace App\Jobs;

use App\Filament\Pages\VideoLibrary;
use App\Models\GeneratedVideo;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\BusinessNotificationService;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\VideoGeneration\VideoDriveExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * "Save to Google Drive" (12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md Phase 4):
 * copies one finished clip into the company's Drive folder. Queued because
 * the clip is read from the company's public storage (often R2) and streamed
 * on to Google — too slow to hold a Livewire request open for.
 *
 * One try: a Drive refusal (folder not shared, no storage quota, …) will not
 * fix itself on a retry, and the user can simply press the button again.
 * Sets/clears CompanyContext explicitly (CLAUDE.md rule for queued jobs).
 */
class SaveVideoToDriveJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 360;

    public function __construct(public int $generatedVideoId, public ?int $requestedByUserId = null) {}

    public function handle(
        CompanyContext $context,
        VideoDriveExporter $exporter,
        CompanyStorageService $storage,
        BusinessNotificationService $notifications,
    ): void {
        $record = GeneratedVideo::withoutGlobalScopes()->with('company')->find($this->generatedVideoId);

        if (! $record || ! $record->company) {
            return;
        }

        $context->set($record->company);

        try {
            try {
                $file = $exporter->export($record, $record->company, $storage->publicDiskName());
            } catch (Throwable $exception) {
                $record->forceFill([
                    'drive_status' => GeneratedVideo::DRIVE_FAILED,
                    'drive_error' => $exception->getMessage(),
                ])->save();

                $this->notify($notifications, $record, success: false);

                return;
            }

            $record->forceFill([
                'drive_status' => GeneratedVideo::DRIVE_SAVED,
                'drive_file_id' => $file['id'],
                'drive_url' => $file['url'],
                'drive_saved_at' => now(),
                'drive_error' => null,
            ])->save();

            app(AuditLogService::class)->record('ai_video.saved_to_drive', $record, null, [
                'drive_file_id' => $file['id'],
            ]);

            $this->notify($notifications, $record, success: true);
        } finally {
            $context->clear();
        }
    }

    /** Safety net for a timeout or crash outside handle()'s own catch. */
    public function failed(?Throwable $exception): void
    {
        $record = GeneratedVideo::withoutGlobalScopes()->find($this->generatedVideoId);

        if (! $record || $record->drive_status !== GeneratedVideo::DRIVE_SAVING) {
            return;
        }

        $record->forceFill([
            'drive_status' => GeneratedVideo::DRIVE_FAILED,
            'drive_error' => __('The upload to Google Drive did not finish.'),
        ])->save();
    }

    protected function notify(BusinessNotificationService $notifications, GeneratedVideo $record, bool $success): void
    {
        $user = User::query()->find($this->requestedByUserId ?? $record->user_id);

        if (! $user) {
            return;
        }

        $url = rescue(fn (): string => VideoLibrary::getUrl(), rescue: null, report: false);

        $notifications->notifyUser(
            $user,
            'ai-video',
            $success ? __('Saved to Google Drive') : __('Could not save to Google Drive'),
            $success
                ? __('A copy of your video is in the company’s Google Drive folder.')
                : ($record->drive_error ?: __('The upload to Google Drive failed.')),
            ['generated_video_id' => (string) $record->getKey()],
            $success ? $record->drive_url : $url,
            $success ? __('Open in Google Drive') : ($url ? __('Open Video Library') : null),
        );
    }
}
