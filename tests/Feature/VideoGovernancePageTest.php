<?php

namespace Tests\Feature;

use App\Filament\Pages\ToolMenu;
use App\Filament\Pages\VideoGeneration;
use App\Filament\Pages\VideoGovernance;
use App\Filament\Pages\VideoLibrary;
use App\Jobs\GenerateVideoJob;
use App\Models\Company;
use App\Models\GeneratedVideo;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\VideoGeneration\VideoGovernanceService;
use App\Services\VideoGeneration\VideoProviderSettingsService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class VideoGovernancePageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Gov Page Co',
            'slug' => 'gov-page-'.uniqid(),
            'invoice_prefix' => 'GVP'.random_int(100, 999),
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
            'prompt' => 'orbit around the lamp',
            'source_image_path' => 'companies/'.$this->company->storage_key.'/public/ai-video-source-images/lamp.webp',
            'provider_label' => 'Stability',
            'api_format' => 'stability',
            'aspect_ratio' => '16:9',
            'video_path' => 'companies/'.$this->company->storage_key.'/public/ai-generated-videos/lamp.mp4',
            'status' => GeneratedVideo::STATUS_COMPLETED,
            'generated_at' => now(),
        ], $overrides));
    }

    private function generateAs(User $user)
    {
        return Livewire::actingAs($user)
            ->test(VideoGeneration::class)
            ->fillForm([
                'prompt' => 'slow orbit',
                'source_image' => UploadedFile::fake()->image('lamp.jpg', 900, 900),
            ])
            ->call('generate');
    }

    public function test_only_super_admin_can_open_the_page(): void
    {
        $this->actingAs($this->user('super_admin'))
            ->withSession(['current_company_id' => $this->company->getKey()])
            ->get(VideoGovernance::getUrl())
            ->assertOk()
            ->assertSee('Monthly video cap per role');

        $this->actingAs($this->user('manager'))
            ->withSession(['current_company_id' => $this->company->getKey()])
            ->get(VideoGovernance::getUrl())
            ->assertForbidden();
    }

    public function test_tool_menu_links_video_governance_for_super_admin_only(): void
    {
        Livewire::actingAs($this->user('super_admin'))
            ->test(ToolMenu::class)
            ->assertActionVisible('videoGovernance')
            ->assertActionHasUrl('videoGovernance', VideoGovernance::getUrl());

        Livewire::actingAs($this->user('manager'))
            ->test(ToolMenu::class)
            ->assertActionHidden('videoGovernance');
    }

    public function test_saving_caps_and_review_roles(): void
    {
        Livewire::actingAs($this->user('super_admin'))
            ->test(VideoGovernance::class)
            ->fillForm(['cap_sales_staff' => 4, 'review_roles' => ['sales_staff']])
            ->call('save')
            ->assertNotified('Governance settings saved');

        $config = app(VideoGovernanceService::class)->all($this->company->fresh());
        $this->assertSame(4, $config['monthly_caps']['sales_staff']);
        $this->assertSame(['sales_staff'], $config['review_roles']);
    }

    public function test_usage_shows_this_months_videos(): void
    {
        $this->video($this->user('manager'), ['estimated_cost' => 0.4]);

        Livewire::actingAs($this->user('super_admin'))
            ->test(VideoGovernance::class)
            ->assertSee('Videos generated')
            ->assertSee('0.40');
    }

    public function test_generate_is_blocked_once_the_monthly_cap_is_used(): void
    {
        Queue::fake();
        Storage::fake('public');
        app(VideoProviderSettingsService::class)->save($this->company, [
            ['label' => 'Stability', 'api_format' => 'stability', 'api_key' => 'k', 'is_default' => true],
        ]);
        app(VideoGovernanceService::class)->save($this->company, ['monthly_caps' => ['manager' => 1]]);
        $manager = $this->user('manager');
        $this->video($manager);

        $this->generateAs($manager)->assertNotified('Monthly video allowance used');

        $this->assertSame(1, GeneratedVideo::query()->count());
        Queue::assertNotPushed(GenerateVideoJob::class);
    }

    public function test_a_review_role_gets_its_video_marked_pending(): void
    {
        Queue::fake();
        Storage::fake('public');
        app(VideoProviderSettingsService::class)->save($this->company, [
            ['label' => 'Stability', 'api_format' => 'stability', 'api_key' => 'k', 'is_default' => true],
        ]);
        app(VideoGovernanceService::class)->save($this->company, ['monthly_caps' => ['manager' => 5], 'review_roles' => ['manager']]);
        $manager = $this->user('manager');

        $this->generateAs($manager)
            ->assertHasNoFormErrors()
            ->assertSee('4 video(s) left this month');

        $this->assertSame(GeneratedVideo::REVIEW_PENDING, GeneratedVideo::query()->sole()->review_status);
        Queue::assertPushed(GenerateVideoJob::class);
    }

    public function test_gallery_flags_a_pending_video_and_hides_attach(): void
    {
        $manager = $this->user('manager');
        $this->video($manager, ['review_status' => GeneratedVideo::REVIEW_PENDING]);

        Livewire::actingAs($manager)
            ->test(VideoGeneration::class)
            ->assertSee('Pending review — cannot be attached yet')
            ->assertDontSee('Offer hero');
    }

    public function test_reviewer_can_approve_and_reject_in_the_library(): void
    {
        $creator = $this->user('sales_staff');
        $toApprove = $this->video($creator, ['review_status' => GeneratedVideo::REVIEW_PENDING]);
        $toReject = $this->video($creator, ['review_status' => GeneratedVideo::REVIEW_PENDING]);

        Livewire::actingAs($this->user('manager'))
            ->test(VideoLibrary::class)
            ->callAction(TestAction::make('approveReview')->table($toApprove))
            ->assertNotified('Video approved')
            ->callAction(TestAction::make('rejectReview')->table($toReject), ['note' => 'Wrong colour'])
            ->assertNotified('Video rejected');

        $this->assertSame(GeneratedVideo::REVIEW_APPROVED, $toApprove->fresh()->review_status);
        $this->assertSame(GeneratedVideo::REVIEW_REJECTED, $toReject->fresh()->review_status);
        $this->assertSame('Wrong colour', $toReject->fresh()->review_note);
    }

    public function test_review_actions_need_the_review_permission(): void
    {
        UserRole::query()->create([
            'name' => 'Video Only',
            'slug' => 'video-only',
            'permissions' => ['dashboard.view', 'ai_tools.video_generation'],
            'is_active' => true,
        ]);
        $viewer = $this->user('video-only');
        $pending = $this->video($this->user('manager'), ['review_status' => GeneratedVideo::REVIEW_PENDING]);

        $this->assertTrue($viewer->hasPermission('ai_tools.video_generation'));
        $this->assertFalse($viewer->hasPermission('ai_tools.video_generation.review'));

        Livewire::actingAs($viewer)
            ->test(VideoLibrary::class)
            ->assertActionHidden(TestAction::make('approveReview')->table($pending));
    }

    public function test_manager_role_holds_the_review_permission_and_the_snapshot_is_updated(): void
    {
        $this->assertTrue($this->user('manager')->hasPermission('ai_tools.video_generation.review'));

        DB::table('user_roles')->updateOrInsert(['slug' => 'manager'], [
            'name' => 'Manager',
            'permissions' => json_encode(['dashboard.view', 'ai_tools.video_generation', 'owner.custom']),
            'is_active' => true,
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_09_28_100000_grant_video_review_permission_to_manager_role.php');
        $migration->up();
        $migration->up();

        $stored = json_decode(DB::table('user_roles')->where('slug', 'manager')->value('permissions'), true);
        $this->assertSame(['dashboard.view', 'ai_tools.video_generation', 'owner.custom', 'ai_tools.video_generation.review'], $stored);
    }
}
