<?php

namespace Tests\Feature;

use App\Filament\Clusters\AiTools;
use App\Filament\Pages\VideoGeneration;
use App\Filament\Pages\VideoLibrary;
use App\Jobs\SaveVideoToDriveJob;
use App\Models\Company;
use App\Models\GeneratedVideo;
use App\Models\User;
use App\Services\BackupSettingsService;
use App\Services\CompanyContext;
use App\Services\VideoGeneration\VideoDriveExporter;
use App\Services\VideoGeneration\VideoProviderSettingsService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class VideoLibraryTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/admin/ai-tools/video-library';

    private function company(string $name = 'Reel Co'): Company
    {
        $company = Company::query()->create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'invoice_prefix' => 'LIB'.random_int(100, 999),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);

        return $company;
    }

    private function user(Company $company, string $role = 'manager'): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $user->companies()->syncWithoutDetaching([$company->getKey() => ['role' => $role, 'is_default' => true]]);

        return $user;
    }

    private function video(Company $company, User $user, array $overrides = []): GeneratedVideo
    {
        return GeneratedVideo::query()->create(array_merge([
            'user_id' => $user->getKey(),
            'tool' => GeneratedVideo::TOOL_VIDEO_GENERATION,
            'context' => 'product_demo',
            'prompt' => 'slow push in on the mug',
            'source_image_path' => 'companies/'.$company->storage_key.'/public/ai-video-source-images/mug.webp',
            'provider_profile_id' => 'p1',
            'provider_label' => 'Stability',
            'api_format' => 'stability',
            'aspect_ratio' => '9:16',
            'duration_preset' => 'short',
            'video_path' => 'companies/'.$company->storage_key.'/public/ai-generated-videos/mug.mp4',
            'video_mime_type' => 'video/mp4',
            'status' => GeneratedVideo::STATUS_COMPLETED,
            'generated_at' => now(),
        ], $overrides));
    }

    private function setUpDrive(Company $company): void
    {
        app(BackupSettingsService::class)->saveGoogleDrive([
            'enabled' => false,
            'service_account_json' => '{"client_email":"videos@proj.iam.gserviceaccount.com","private_key":"secret"}',
        ]);
        app(VideoDriveExporter::class)->saveFolderId($company, 'folder-123');
    }

    public function test_manager_can_open_the_library_and_staff_cannot(): void
    {
        $company = $this->company();

        $this->actingAs($this->user($company))
            ->withSession(['current_company_id' => $company->getKey()])
            ->get($this->url)
            ->assertOk();

        $this->actingAs($this->user($company, 'sales_staff'))
            ->withSession(['current_company_id' => $company->getKey()])
            ->get($this->url)
            ->assertForbidden();
    }

    public function test_library_is_listed_in_the_ai_tools_menu(): void
    {
        $company = $this->company();
        $this->actingAs($this->user($company, 'super_admin'));

        $labels = collect(AiTools::getNavigationItems()[0]->getChildItems())
            ->map(fn ($item): string => (string) $item->getLabel())
            ->all();

        $this->assertContains('Video Library', $labels);
    }

    public function test_it_lists_only_the_current_companys_videos_and_filters_favourites(): void
    {
        $other = $this->company('Other Co');
        $foreign = $this->video($other, $this->user($other), ['prompt' => 'foreign clip']);

        $company = $this->company();
        $user = $this->user($company);
        $plain = $this->video($company, $user, ['prompt' => 'plain clip']);
        $starred = $this->video($company, $user, ['prompt' => 'starred clip', 'is_favorite' => true]);

        Livewire::actingAs($user)
            ->test(VideoLibrary::class)
            ->assertCanSeeTableRecords([$plain, $starred])
            ->assertCanNotSeeTableRecords([$foreign])
            ->filterTable('is_favorite', true)
            ->assertCanSeeTableRecords([$starred])
            ->assertCanNotSeeTableRecords([$plain]);
    }

    public function test_favourite_action_toggles_the_star(): void
    {
        $company = $this->company();
        $user = $this->user($company);
        $video = $this->video($company, $user);

        Livewire::actingAs($user)
            ->test(VideoLibrary::class)
            ->callAction(TestAction::make('toggleFavorite')->table($video))
            ->assertNotified('Added to favourites');

        $this->assertTrue($video->fresh()->is_favorite);
    }

    public function test_reuse_prefills_video_generation_with_the_same_settings_and_starting_image(): void
    {
        $company = $this->company();
        app(VideoProviderSettingsService::class)->save($company, [
            ['label' => 'Stability', 'api_format' => 'stability', 'api_key' => 'stab-live', 'is_default' => true],
        ]);
        $user = $this->user($company);
        $video = $this->video($company, $user, ['context' => 'ad_creative']);

        Livewire::actingAs($user)
            ->test(VideoLibrary::class)
            ->assertActionHasUrl(TestAction::make('reusePrompt')->table($video), VideoGeneration::getUrl(['from' => $video->getKey()]));

        $page = Livewire::actingAs($user)
            ->withQueryParams(['from' => $video->getKey()])
            ->test(VideoGeneration::class)
            ->assertSchemaStateSet([
                'prompt' => 'slow push in on the mug',
                'context' => 'ad_creative',
                'aspect_ratio' => '9:16',
                'duration_preset' => 'short',
            ]);

        $this->assertContains(
            'companies/'.$company->storage_key.'/public/ai-video-source-images/mug.webp',
            array_values((array) $page->get('data.source_image')),
        );
    }

    public function test_save_to_drive_is_hidden_until_drive_is_set_up(): void
    {
        $company = $this->company();
        $user = $this->user($company);
        $video = $this->video($company, $user);

        Livewire::actingAs($user)
            ->test(VideoLibrary::class)
            ->assertActionHidden(TestAction::make('saveToDrive')->table($video));

        $this->setUpDrive($company);

        Livewire::actingAs($user)
            ->test(VideoLibrary::class)
            ->assertActionVisible(TestAction::make('saveToDrive')->table($video));
    }

    public function test_save_to_drive_marks_the_video_saving_and_queues_the_job(): void
    {
        Queue::fake();
        $company = $this->company();
        $this->setUpDrive($company);
        $user = $this->user($company);
        $video = $this->video($company, $user);

        Livewire::actingAs($user)
            ->test(VideoLibrary::class)
            ->callAction(TestAction::make('saveToDrive')->table($video))
            ->assertNotified('Saving to Google Drive…');

        $this->assertSame(GeneratedVideo::DRIVE_SAVING, $video->fresh()->drive_status);
        Queue::assertPushed(SaveVideoToDriveJob::class, fn (SaveVideoToDriveJob $job): bool => $job->generatedVideoId === $video->getKey()
            && $job->requestedByUserId === $user->getKey());
    }

    public function test_a_saved_video_links_to_drive_instead_of_saving_again(): void
    {
        $company = $this->company();
        $this->setUpDrive($company);
        $user = $this->user($company);
        $video = $this->video($company, $user, [
            'drive_status' => GeneratedVideo::DRIVE_SAVED,
            'drive_file_id' => 'file-1',
            'drive_url' => 'https://drive.google.com/file/d/file-1/view',
        ]);

        Livewire::actingAs($user)
            ->test(VideoLibrary::class)
            ->assertActionHidden(TestAction::make('saveToDrive')->table($video))
            ->assertActionVisible(TestAction::make('openInDrive')->table($video))
            ->assertActionHasUrl(TestAction::make('openInDrive')->table($video), 'https://drive.google.com/file/d/file-1/view');
    }

    public function test_generation_gallery_can_star_a_video_and_save_it_to_drive(): void
    {
        Queue::fake();
        $company = $this->company();
        $this->setUpDrive($company);
        $user = $this->user($company);
        $video = $this->video($company, $user);

        Livewire::actingAs($user)
            ->test(VideoGeneration::class)
            ->assertSee('Save to Google Drive')
            ->callAction('toggleFavorite', [], ['generation' => $video->getKey()])
            ->assertNotified('Added to favourites')
            ->callAction('saveToDrive', [], ['generation' => $video->getKey()])
            ->assertNotified('Saving to Google Drive…')
            ->assertSee('Saving to Google Drive…');

        $video->refresh();
        $this->assertTrue($video->is_favorite);
        $this->assertSame(GeneratedVideo::DRIVE_SAVING, $video->drive_status);
        Queue::assertPushed(SaveVideoToDriveJob::class);
    }

    public function test_generation_gallery_hides_the_drive_button_when_drive_is_not_set_up(): void
    {
        $company = $this->company();
        $user = $this->user($company);
        $this->video($company, $user);

        Livewire::actingAs($user)
            ->test(VideoGeneration::class)
            ->assertDontSee('Save to Google Drive');
    }
}
