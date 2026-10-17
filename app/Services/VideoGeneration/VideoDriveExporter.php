<?php

namespace App\Services\VideoGeneration;

use App\Models\Company;
use App\Models\GeneratedVideo;
use App\Services\GoogleDriveBackupService;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * "Save to Google Drive" for finished videos (12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md
 * Phase 4). Sends a *copy* of a clip to the company's Drive folder — the
 * storefront and the Video Library keep playing the company's own public
 * storage copy (R2 or local), because Drive is not built to serve video to
 * web pages.
 *
 * Credentials: the Google service account already set up on Settings →
 * Backups → Google Drive Backup (app-wide, super admin). Folder: per company,
 * `companies.settings->ai_tools->video_drive->folder_id`, set on Settings →
 * Integrations → Video Providers. The folder must be shared with the service
 * account's email.
 */
class VideoDriveExporter
{
    public const SETTINGS_PATH = 'ai_tools.video_drive.folder_id';

    public function __construct(private readonly GoogleDriveBackupService $drive) {}

    public function folderId(Company $company): ?string
    {
        $folderId = trim((string) data_get($company->settings, self::SETTINGS_PATH, ''));

        return $folderId !== '' ? $folderId : null;
    }

    public function saveFolderId(Company $company, ?string $folderId): void
    {
        $settings = (array) $company->settings;
        data_set($settings, self::SETTINGS_PATH, $this->normalizeFolderId($folderId));
        $company->forceFill(['settings' => $settings])->save();
    }

    public function hasServiceAccount(): bool
    {
        return $this->drive->hasServiceAccount();
    }

    public function serviceAccountEmail(): ?string
    {
        return $this->drive->serviceAccountEmail();
    }

    /** Both halves are in place: a service account and this company's folder. */
    public function isAvailable(Company $company): bool
    {
        return $this->folderId($company) !== null && $this->hasServiceAccount();
    }

    /**
     * Upload the clip. Called from SaveVideoToDriveJob; throws on any
     * failure with a message fit to show the user.
     *
     * @return array{id: string, url: string}
     */
    public function export(GeneratedVideo $video, Company $company, string $diskName): array
    {
        $folderId = $this->folderId($company);

        if ($folderId === null || ! $this->hasServiceAccount()) {
            throw new RuntimeException(__('Google Drive is not set up for this company.'));
        }

        if (! $video->hasPlayableVideo()) {
            throw new RuntimeException(__('That video is no longer available.'));
        }

        $disk = Storage::disk($diskName);
        $stream = $disk->readStream((string) $video->video_path);

        if (! is_resource($stream)) {
            throw new RuntimeException(__('That video is no longer available.'));
        }

        // The Guzzle stream owns the handle from here and closes it when released.
        $file = $this->drive->uploadStream(
            Utils::streamFor($stream),
            $this->filename($video),
            $video->video_mime_type ?: 'video/mp4',
            $folderId,
        );

        $id = (string) ($file['id'] ?? '');

        if ($id === '') {
            throw new RuntimeException(__('Google Drive did not return the saved file.'));
        }

        return [
            'id' => $id,
            'url' => (string) ($file['webViewLink'] ?? 'https://drive.google.com/file/d/'.$id.'/view'),
        ];
    }

    /** e.g. "ai-video-42-product-demo-slow-push-in-on-the-mug.mp4" */
    protected function filename(GeneratedVideo $video): string
    {
        $extension = pathinfo((string) $video->video_path, PATHINFO_EXTENSION) ?: 'mp4';
        $slug = str((string) $video->prompt)->limit(50, '')->slug()->toString();

        return collect(['ai-video-'.$video->getKey(), str((string) $video->context)->slug()->toString(), $slug])
            ->filter()
            ->implode('-').'.'.$extension;
    }

    /** Accept a pasted folder URL as well as a bare id. */
    protected function normalizeFolderId(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('~/folders/([A-Za-z0-9_-]+)~', $value, $matches) === 1) {
            return $matches[1];
        }

        return $value;
    }
}
