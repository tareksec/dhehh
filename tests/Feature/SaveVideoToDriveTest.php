<?php

namespace Tests\Feature;

use App\Filament\Pages\VideoProviderSettings;
use App\Jobs\SaveVideoToDriveJob;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\GeneratedVideo;
use App\Models\User;
use App\Services\BackupSettingsService;
use App\Services\CompanyContext;
use App\Services\GoogleDriveBackupService;
use App\Services\VideoGeneration\VideoDriveExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Save to Google Drive" end to end: the per-company folder setting, the
 * resumable upload, and SaveVideoToDriveJob's success / failure handling.
 * Google's OAuth token exchange is stubbed (accessToken() needs a real RSA
 * key); the Drive upload calls themselves go through Http::fake().
 */
class SaveVideoToDriveTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->company = Company::query()->create([
            'name' => 'Drive Co',
            'slug' => 'drive-co-'.uniqid(),
            'invoice_prefix' => 'DRV'.random_int(100, 999),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);

        $this->user = User::factory()->create(['role' => 'manager', 'is_active' => true]);
        $this->user->companies()->syncWithoutDetaching([$this->company->getKey() => ['role' => 'manager', 'is_default' => true]]);

        app(BackupSettingsService::class)->saveGoogleDrive([
            'enabled' => false,
            'service_account_json' => '{"client_email":"videos@proj.iam.gserviceaccount.com","private_key":"secret"}',
        ]);

        $this->app->bind(GoogleDriveBackupService::class, fn () => new class extends GoogleDriveBackupService
        {
            protected function accessToken(): string
            {
                return 'drive-token';
            }
        });
    }

    private function video(array $overrides = []): GeneratedVideo
    {
        $path = 'companies/'.$this->company->storage_key.'/public/ai-generated-videos/ai-video-1.mp4';
        Storage::disk('public')->put($path, 'fake-mp4-bytes');

        return GeneratedVideo::query()->create(array_merge([
            'user_id' => $this->user->getKey(),
            'tool' => GeneratedVideo::TOOL_VIDEO_GENERATION,
            'context' => 'product_demo',
            'prompt' => 'Slow push in on the mug',
            'source_image_path' => 'companies/'.$this->company->storage_key.'/public/ai-video-source-images/mug.webp',
            'provider_label' => 'Stability',
            'api_format' => 'stability',
            'aspect_ratio' => '16:9',
            'video_path' => $path,
            'video_mime_type' => 'video/mp4',
            'status' => GeneratedVideo::STATUS_COMPLETED,
            'drive_status' => GeneratedVideo::DRIVE_SAVING,
            'generated_at' => now(),
        ], $overrides));
    }

    private function fakeDrive(): void
    {
        Http::fake([
            'www.googleapis.com/upload/drive/v3/files?uploadType=resumable*' => Http::response('', 200, [
                'Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=session-1',
            ]),
            'www.googleapis.com/upload/drive/v3/files?upload_id=session-1' => Http::response([
                'id' => 'drive-file-9',
                'name' => 'ai-video-1.mp4',
                'webViewLink' => 'https://drive.google.com/file/d/drive-file-9/view',
            ], 200),
        ]);
    }

    public function test_folder_setting_accepts_a_pasted_folder_link(): void
    {
        $exporter = app(VideoDriveExporter::class);

        $exporter->saveFolderId($this->company, 'https://drive.google.com/drive/folders/1AbC-dEf_9?usp=sharing');
        $this->assertSame('1AbC-dEf_9', $exporter->folderId($this->company->fresh()));

        $exporter->saveFolderId($this->company, '  ');
        $this->assertNull($exporter->folderId($this->company->fresh()));
    }

    public function test_video_providers_page_saves_the_drive_folder_and_shows_the_share_address(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $admin->companies()->syncWithoutDetaching([$this->company->getKey() => ['role' => 'super_admin', 'is_default' => true]]);

        Livewire::actingAs($admin)
            ->test(VideoProviderSettings::class)
            ->assertSee('videos@proj.iam.gserviceaccount.com')
            ->fillForm(['drive_folder_id' => 'https://drive.google.com/drive/folders/folder-xyz'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('folder-xyz', app(VideoDriveExporter::class)->folderId($this->company->fresh()));
    }

    public function test_job_uploads_the_clip_and_records_the_drive_link(): void
    {
        $this->fakeDrive();
        app(VideoDriveExporter::class)->saveFolderId($this->company, 'folder-123');
        $video = $this->video();

        app()->call([new SaveVideoToDriveJob($video->getKey(), $this->user->getKey()), 'handle']);

        $video->refresh();
        $this->assertSame(GeneratedVideo::DRIVE_SAVED, $video->drive_status);
        $this->assertSame('drive-file-9', $video->drive_file_id);
        $this->assertSame('https://drive.google.com/file/d/drive-file-9/view', $video->drive_url);
        $this->assertNotNull($video->drive_saved_at);
        $this->assertTrue(AuditLog::query()->where('action', 'ai_video.saved_to_drive')->exists());

        Http::assertSent(function (Request $request) use ($video): bool {
            if (! str_contains($request->url(), 'uploadType=resumable')) {
                return false;
            }

            $metadata = json_decode($request->body(), true);

            return $request->hasHeader('Authorization', 'Bearer drive-token')
                && str_contains($request->url(), 'supportsAllDrives=true')
                && $metadata['parents'] === ['folder-123']
                && $metadata['name'] === 'ai-video-'.$video->getKey().'-product-demo-slow-push-in-on-the-mug.mp4';
        });
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), 'upload_id=session-1')
            && $request->body() === 'fake-mp4-bytes');

        $this->assertTrue($this->user->notifications()->get()->contains(
            fn ($notification): bool => str_contains(json_encode($notification->data), 'Saved to Google Drive'),
        ));
    }

    public function test_job_records_googles_error_when_the_upload_is_refused(): void
    {
        Http::fake([
            'www.googleapis.com/*' => Http::response(['error' => ['message' => 'Service Accounts do not have storage quota.']], 403),
        ]);
        app(VideoDriveExporter::class)->saveFolderId($this->company, 'folder-123');
        $video = $this->video();

        app()->call([new SaveVideoToDriveJob($video->getKey(), $this->user->getKey()), 'handle']);

        $video->refresh();
        $this->assertSame(GeneratedVideo::DRIVE_FAILED, $video->drive_status);
        $this->assertStringContainsString('storage quota', (string) $video->drive_error);
        $this->assertNull($video->drive_file_id);
    }

    public function test_job_fails_cleanly_when_the_company_has_no_drive_folder(): void
    {
        Http::fake();
        $video = $this->video();

        app()->call([new SaveVideoToDriveJob($video->getKey(), $this->user->getKey()), 'handle']);

        $video->refresh();
        $this->assertSame(GeneratedVideo::DRIVE_FAILED, $video->drive_status);
        $this->assertStringContainsString('not set up', (string) $video->drive_error);
        Http::assertNothingSent();
    }

    public function test_failed_safety_net_releases_a_row_stuck_saving(): void
    {
        $video = $this->video();

        (new SaveVideoToDriveJob($video->getKey()))->failed(new \RuntimeException('timeout'));

        $this->assertSame(GeneratedVideo::DRIVE_FAILED, $video->fresh()->drive_status);
    }
}
