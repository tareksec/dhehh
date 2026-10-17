<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\GeneratedVideo;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\VideoGeneration\VideoGovernanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoGovernanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Gov Co',
            'slug' => 'gov-co-'.uniqid(),
            'invoice_prefix' => 'GOV'.random_int(100, 999),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $user->companies()->syncWithoutDetaching([$this->company->getKey() => ['role' => $role, 'is_default' => true]]);

        return $user;
    }

    private function video(User $user, array $overrides = []): GeneratedVideo
    {
        return GeneratedVideo::query()->create(array_merge([
            'user_id' => $user->getKey(),
            'tool' => GeneratedVideo::TOOL_VIDEO_GENERATION,
            'context' => 'general',
            'prompt' => 'pan across',
            'source_image_path' => 'x.webp',
            'provider_label' => 'Stability',
            'api_format' => 'stability',
            'aspect_ratio' => '16:9',
            'status' => GeneratedVideo::STATUS_COMPLETED,
            'estimated_cost' => 0.2,
        ], $overrides));
    }

    public function test_defaults_are_inert(): void
    {
        $service = app(VideoGovernanceService::class);
        $config = $service->all($this->company);

        $this->assertSame([], $config['review_roles']);
        $this->assertSame([0], array_values(array_unique($config['monthly_caps'])));
        $this->assertArrayNotHasKey('super_admin', $config['monthly_caps']);
        $this->assertNull($service->remainingThisMonth($this->company, $this->user('sales_staff')));
    }

    public function test_save_round_trips_and_drops_unknown_roles(): void
    {
        $service = app(VideoGovernanceService::class);

        $service->save($this->company, [
            'monthly_caps' => ['sales_staff' => 3, 'manager' => 0, 'super_admin' => 5, 'nonsense' => 9],
            'review_roles' => ['sales_staff', 'super_admin', 'nonsense'],
        ]);

        $config = $service->all($this->company->fresh());
        $this->assertSame(3, $config['monthly_caps']['sales_staff']);
        $this->assertSame(0, $config['monthly_caps']['manager']);
        $this->assertSame(['sales_staff'], $config['review_roles']);

        $service->save($this->company, ['monthly_caps' => [], 'review_roles' => []]);
        $this->assertNull(data_get($this->company->fresh()->settings, VideoGovernanceService::SETTINGS_PATH));
    }

    public function test_cap_counts_this_months_non_failed_videos(): void
    {
        $service = app(VideoGovernanceService::class);
        $service->save($this->company, ['monthly_caps' => ['sales_staff' => 2]]);
        $staff = $this->user('sales_staff');

        $this->video($staff);
        $this->video($staff, ['status' => GeneratedVideo::STATUS_FAILED]);
        $old = $this->video($staff);
        $old->forceFill(['created_at' => now()->subMonthNoOverflow()->startOfMonth()])->save();

        $this->assertSame(1, $service->videosUsedThisMonth($this->company, $staff));
        $this->assertSame(1, $service->remainingThisMonth($this->company, $staff));
        $this->assertFalse($service->wouldExceedCap($this->company, $staff));

        $this->video($staff);
        $this->assertTrue($service->wouldExceedCap($this->company, $staff));
    }

    public function test_super_admin_is_never_capped_or_reviewed(): void
    {
        $service = app(VideoGovernanceService::class);
        $service->save($this->company, ['monthly_caps' => ['manager' => 1], 'review_roles' => ['manager']]);

        $this->assertSame(0, $service->monthlyCapForRole($this->company, 'super_admin'));
        $this->assertFalse($service->roleRequiresReview($this->company, 'super_admin'));
        $this->assertTrue($service->roleRequiresReview($this->company, 'manager'));
    }

    public function test_review_approves_or_rejects_a_pending_video_and_audits_it(): void
    {
        $service = app(VideoGovernanceService::class);
        $reviewer = $this->user('manager');
        $pending = $this->video($this->user('sales_staff'), ['review_status' => GeneratedVideo::REVIEW_PENDING]);
        $other = $this->video($this->user('sales_staff'), ['review_status' => GeneratedVideo::REVIEW_PENDING]);

        $service->review($pending, $reviewer, approved: true);
        $service->review($other, $reviewer, approved: false, note: '  wrong product  ');

        $this->assertSame(GeneratedVideo::REVIEW_APPROVED, $pending->fresh()->review_status);
        $this->assertTrue($pending->fresh()->isAttachable());
        $this->assertSame(GeneratedVideo::REVIEW_REJECTED, $other->fresh()->review_status);
        $this->assertSame('wrong product', $other->fresh()->review_note);
        $this->assertSame($reviewer->getKey(), (int) $other->fresh()->reviewed_by);
        $this->assertTrue(AuditLog::query()->where('action', 'ai_video.review_approved')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'ai_video.review_rejected')->exists());
    }

    public function test_review_ignores_a_video_that_was_never_pending(): void
    {
        $video = $this->video($this->user('manager'));

        app(VideoGovernanceService::class)->review($video, $this->user('manager'), approved: false);

        $this->assertSame(GeneratedVideo::REVIEW_NOT_REQUIRED, $video->fresh()->review_status);
    }

    public function test_usage_summary_totals_by_user_and_provider(): void
    {
        $a = $this->user('manager');
        $b = $this->user('sales_staff');
        $this->video($a, ['estimated_cost' => 0.5]);
        $this->video($a, ['estimated_cost' => 0.5, 'provider_label' => 'Custom Kling']);
        $this->video($b, ['estimated_cost' => 0.25]);
        $this->video($b, ['status' => GeneratedVideo::STATUS_FAILED, 'estimated_cost' => 9]);

        $usage = app(VideoGovernanceService::class)->usageSummary($this->company);

        $this->assertSame(3, $usage['videos']);
        $this->assertSame(1.25, $usage['cost']);
        $this->assertSame(2, $usage['by_user'][0]['videos']);
        $this->assertEqualsCanonicalizing(['Stability', 'Custom Kling'], array_column($usage['by_provider'], 'label'));
    }
}
