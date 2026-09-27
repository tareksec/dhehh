<?php

namespace App\Filament\Pages\Concerns;

use App\Jobs\SaveVideoToDriveJob;
use App\Models\GeneratedVideo;
use App\Services\CompanyContext;
use App\Services\VideoGeneration\VideoDriveExporter;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * "Save to Google Drive", shared by the Video Generation gallery and the
 * Video Library. Marks the row `saving` and queues SaveVideoToDriveJob; the
 * job flips it to `saved` / `failed` and notifies the user.
 */
trait SavesVideosToDrive
{
    public function driveAvailable(): bool
    {
        $context = app(CompanyContext::class);

        return $context->hasCompany()
            && ! $context->isAllCompanies()
            && app(VideoDriveExporter::class)->isAvailable($context->company());
    }

    protected function queueDriveSave(GeneratedVideo $video): void
    {
        if (! $this->driveAvailable()) {
            Notification::make()
                ->title(__('Google Drive is not set up'))
                ->body(__('A super admin can add the Drive folder on Settings → Integrations → Video Providers.'))
                ->warning()
                ->send();

            return;
        }

        if (! $video->hasPlayableVideo()) {
            Notification::make()->title(__('That video is no longer available.'))->danger()->send();

            return;
        }

        if ($video->isSavingToDrive()) {
            Notification::make()->title(__('This video is already being saved to Google Drive.'))->info()->send();

            return;
        }

        $video->forceFill(['drive_status' => GeneratedVideo::DRIVE_SAVING, 'drive_error' => null])->save();

        SaveVideoToDriveJob::dispatch($video->getKey(), Auth::id());

        Notification::make()
            ->title(__('Saving to Google Drive…'))
            ->body(__('You will get a notification when the copy is in Drive.'))
            ->success()
            ->send();
    }
}
