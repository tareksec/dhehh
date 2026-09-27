<?php

namespace Tests\Feature;

use App\Jobs\GenerateVideoJob;
use App\Models\Company;
use App\Models\GeneratedVideo;
use App\Models\Media;
use App\Models\User;
use App\Notifications\BusinessAlert;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\VideoGeneration\VideoProviderSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateVideoJobTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->company = Company::query()->create([
            'name' => 'Video Gen Co',
            'slug' => 'video-gen-co-'.uniqid(),
            'invoice_prefix' => 'VGC'.random_int(100, 999),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);

        $this->user = User::factory()->create(['role' => 'manager', 'is_active' => true]);
        $this->user->companies()->syncWithoutDetaching([
            $this->company->getKey() => ['role' => 'manager', 'is_default' => true],
        ]);
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(40, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 20, 30));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function configureStabilityProfile(float $costPerVideo = 0): string
    {
        app(VideoProviderSettingsService::class)->save($this->company, [
            ['label' => 'Stability', 'api_format' => 'stability', 'api_key' => 'stab-live', 'cost_per_video' => $costPerVideo, 'is_default' => true],
        ]);

        return app(VideoProviderSettingsService::class)->list($this->company)[0]['id'];
    }

    private function storeSourceImage(): string
    {
        return app(CompanyStorageService::class)->putPublic($this->company, 'ai-video-source-images', 'source.webp', $this->pngBytes());
    }

    private function queueRow(array $overrides = []): GeneratedVideo
    {
        return GeneratedVideo::query()->create(array_merge([
            'user_id' => $this->user->getKey(),
            'tool' => GeneratedVideo::TOOL_VIDEO_GENERATION,
            'context' => 'product_demo',
            'prompt' => 'slow push in on the product',
            'source_image_path' => $this->storeSourceImage(),
            'provider_profile_id' => 'missing',
            'aspect_ratio' => '16:9',
            'status' => GeneratedVideo::STATUS_QUEUED,
        ], $overrides));
    }

    private function runJob(GeneratedVideo $row): GenerateVideoJob
    {
        $job = new GenerateVideoJob($row->getKey());
        $job->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        return $job;
    }

    public function test_a_queued_row_submits_and_releases_for_the_first_poll(): void
    {
        Notification::fake();
        Http::fake(['api.stability.ai/v2beta/image-to-video' => Http::response(['id' => 'job-abc'], 200)]);

        $row = $this->queueRow(['provider_profile_id' => $this->configureStabilityProfile()]);
        $job = $this->runJob($row);

        app(CompanyContext::class)->set($this->company);
        $row->refresh();

        $this->assertSame(GeneratedVideo::STATUS_PROCESSING, $row->status);
        $this->assertSame('job-abc', $row->external_job_id);
        $this->assertNotNull($row->poll_deadline_at);
        $this->assertNotNull($row->next_poll_at);
        $job->assertReleased(15);
    }

    public function test_a_still_processing_poll_bumps_attempts_and_releases_again(): void
    {
        Notification::fake();
        Http::fake(['api.stability.ai/v2beta/image-to-video/result/*' => Http::response('', 202)]);

        $row = $this->queueRow([
            'provider_profile_id' => $this->configureStabilityProfile(),
            'status' => GeneratedVideo::STATUS_PROCESSING,
            'external_job_id' => 'job-abc',
            'poll_deadline_at' => now()->addMinutes(20),
        ]);

        $job = $this->runJob($row);

        app(CompanyContext::class)->set($this->company);
        $row->refresh();

        $this->assertSame(GeneratedVideo::STATUS_PROCESSING, $row->status);
        $this->assertSame(1, $row->poll_attempts);
        $job->assertReleased(15);
    }

    public function test_a_completed_poll_stores_the_video_and_notifies(): void
    {
        Notification::fake();
        $video = random_bytes(4096);
        Http::fake(['api.stability.ai/v2beta/image-to-video/result/*' => Http::response($video, 200)]);

        $row = $this->queueRow([
            'provider_profile_id' => $this->configureStabilityProfile(costPerVideo: 0.5),
            'status' => GeneratedVideo::STATUS_PROCESSING,
            'external_job_id' => 'job-abc',
            'poll_deadline_at' => now()->addMinutes(20),
        ]);

        $job = $this->runJob($row);

        app(CompanyContext::class)->set($this->company);
        $row->refresh();

        $this->assertSame(GeneratedVideo::STATUS_COMPLETED, $row->status);
        $this->assertNotNull($row->video_path);
        Storage::disk('public')->assertExists($row->video_path);
        $this->assertSame(strlen($video), $row->video_size_bytes);
        $this->assertEqualsWithDelta(0.5, (float) $row->estimated_cost, 0.0001);
        $this->assertNotNull($row->generated_at);
        $this->assertSame($row->source_image_path, $row->thumbnail_path);
        $job->assertNotReleased();

        $this->assertSame(1, Media::withoutGlobalScopes()->where('company_id', $this->company->getKey())->count());
        Notification::assertSentTo($this->user, BusinessAlert::class);
    }

    public function test_a_failed_poll_marks_the_row_failed(): void
    {
        Notification::fake();
        Http::fake(['api.stability.ai/v2beta/image-to-video/result/*' => Http::response(['errors' => ['content policy violation']], 400)]);

        $row = $this->queueRow([
            'provider_profile_id' => $this->configureStabilityProfile(),
            'status' => GeneratedVideo::STATUS_PROCESSING,
            'external_job_id' => 'job-abc',
            'poll_deadline_at' => now()->addMinutes(20),
        ]);

        $job = $this->runJob($row);

        app(CompanyContext::class)->set($this->company);
        $row->refresh();

        $this->assertSame(GeneratedVideo::STATUS_FAILED, $row->status);
        $this->assertStringContainsString('content policy violation', (string) $row->error_message);
        $job->assertNotReleased();

        Notification::assertSentTo($this->user, BusinessAlert::class);
    }

    public function test_a_missing_source_image_fails_the_submit_cleanly(): void
    {
        Notification::fake();
        Http::fake();

        $row = $this->queueRow([
            'provider_profile_id' => $this->configureStabilityProfile(),
            'source_image_path' => 'companies/'.$this->company->storage_key.'/public/ai-video-source-images/gone.webp',
        ]);

        $this->runJob($row);

        app(CompanyContext::class)->set($this->company);
        $this->assertSame(GeneratedVideo::STATUS_FAILED, $row->fresh()->status);
        $this->assertStringContainsString('starting image', (string) $row->fresh()->error_message);
        Http::assertNothingSent();
    }

    public function test_a_deleted_provider_profile_fails_cleanly(): void
    {
        Notification::fake();
        Http::fake();

        $row = $this->queueRow(['provider_profile_id' => 'does-not-exist']);

        $this->runJob($row);

        app(CompanyContext::class)->set($this->company);
        $this->assertSame(GeneratedVideo::STATUS_FAILED, $row->fresh()->status);
        $this->assertStringContainsString('no longer exists', (string) $row->fresh()->error_message);
        Http::assertNothingSent();
    }

    public function test_retry_until_reads_the_rows_poll_deadline(): void
    {
        $deadline = now()->addMinutes(12)->startOfSecond();
        $row = $this->queueRow(['poll_deadline_at' => $deadline]);

        $job = new GenerateVideoJob($row->getKey());

        $this->assertEquals($deadline, $job->retryUntil());
    }

    public function test_retry_until_falls_back_to_created_at_plus_30_minutes_when_unset(): void
    {
        $row = $this->queueRow();

        $job = new GenerateVideoJob($row->getKey());

        $this->assertEquals($row->created_at->addMinutes(30), $job->retryUntil());
    }

    public function test_failed_hook_marks_a_timed_out_row_failed_and_notifies(): void
    {
        Notification::fake();

        $row = $this->queueRow([
            'provider_profile_id' => $this->configureStabilityProfile(),
            'status' => GeneratedVideo::STATUS_PROCESSING,
            'external_job_id' => 'job-abc',
            'poll_deadline_at' => now()->subMinute(),
        ]);

        $job = new GenerateVideoJob($row->getKey());
        $job->failed(null);

        app(CompanyContext::class)->set($this->company);
        $row->refresh();

        $this->assertSame(GeneratedVideo::STATUS_FAILED, $row->status);
        $this->assertStringContainsString('did not finish in time', (string) $row->error_message);
        Notification::assertSentTo($this->user, BusinessAlert::class);
    }

    public function test_failed_hook_is_a_no_op_on_an_already_terminal_row(): void
    {
        Notification::fake();

        $row = $this->queueRow(['status' => GeneratedVideo::STATUS_COMPLETED, 'error_message' => null]);

        $job = new GenerateVideoJob($row->getKey());
        $job->failed(null);

        app(CompanyContext::class)->set($this->company);
        $this->assertNull($row->fresh()->error_message);
        Notification::assertNothingSent();
    }

    public function test_the_job_clears_company_context_afterwards(): void
    {
        Notification::fake();
        Http::fake(['api.stability.ai/v2beta/image-to-video' => Http::response(['id' => 'job-abc'], 200)]);

        $row = $this->queueRow(['provider_profile_id' => $this->configureStabilityProfile()]);
        $this->runJob($row);

        $this->assertFalse(app(CompanyContext::class)->hasCompany());
    }
}
