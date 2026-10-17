<?php

namespace Tests\Feature;

use App\Filament\Pages\VideoGeneration;
use App\Jobs\GenerateVideoJob;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\GeneratedImage;
use App\Models\GeneratedVideo;
use App\Models\Offer;
use App\Models\Product;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\PromptEnhancement\PromptEnhancerConfigService;
use App\Services\VideoGeneration\VideoProviderSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class VideoGenerationPageTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/admin/ai-tools/video-generation';

    private function company(): Company
    {
        $company = Company::query()->create([
            'name' => 'Reel Co',
            'slug' => 'reel-co-'.uniqid(),
            'invoice_prefix' => 'REEL'.random_int(100, 999),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);

        return $company;
    }

    private function user(Company $company, string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $user->companies()->syncWithoutDetaching([$company->getKey() => ['role' => $role, 'is_default' => true]]);

        return $user;
    }

    private function configureProvider(Company $company): void
    {
        app(VideoProviderSettingsService::class)->save($company, [
            ['label' => 'Stability', 'api_format' => 'stability', 'api_key' => 'stab-live', 'is_default' => true],
        ]);
    }

    private function videoReferenceImage(Company $company, User $user): GeneratedImage
    {
        return GeneratedImage::query()->create([
            'user_id' => $user->getKey(),
            'tool' => GeneratedImage::TOOL_IMAGE_GENERATION,
            'context' => 'video_reference',
            'prompt' => 'turntable reference frame',
            'aspect_ratio' => '1:1',
            'variations_requested' => 1,
            'is_video_reference' => true,
            'output_paths' => ['companies/'.$company->storage_key.'/public/ai-generated-images/ref.webp'],
            'status' => GeneratedImage::STATUS_COMPLETED,
            'generated_at' => now(),
        ]);
    }

    public function test_manager_can_open_the_page_and_staff_cannot(): void
    {
        $company = $this->company();

        $this->actingAs($this->user($company, 'manager'))
            ->withSession(['current_company_id' => $company->getKey()])
            ->get($this->url)
            ->assertOk();

        $this->actingAs($this->user($company, 'sales_staff'))
            ->withSession(['current_company_id' => $company->getKey()])
            ->get($this->url)
            ->assertForbidden();
    }

    public function test_generate_is_blocked_without_a_configured_provider(): void
    {
        Queue::fake();
        $company = $this->company();
        $user = $this->user($company, 'manager');

        Livewire::actingAs($user)
            ->test(VideoGeneration::class)
            ->assertActionDisabled('generate');

        $this->assertSame(0, GeneratedVideo::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_generate_uploads_a_starting_image_and_queues_the_job(): void
    {
        Queue::fake();
        Storage::fake('public');
        $company = $this->company();
        $this->configureProvider($company);
        $user = $this->user($company, 'manager');

        Livewire::actingAs($user)
            ->test(VideoGeneration::class)
            ->fillForm([
                'prompt' => 'slow push in, soft studio lighting',
                'context' => 'product_demo',
                'aspect_ratio' => '16:9',
                'duration_preset' => 'standard',
                'source_image' => UploadedFile::fake()->image('bottle.jpg', 900, 900),
            ])
            ->call('generate')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $row = GeneratedVideo::query()->firstOrFail();
        $this->assertSame(GeneratedVideo::STATUS_QUEUED, $row->status);
        $this->assertSame('product_demo', $row->context);
        $this->assertSame('16:9', $row->aspect_ratio);
        $this->assertSame($user->getKey(), $row->user_id);
        $this->assertNull($row->source_generated_image_id);
        $this->assertStringContainsString(
            'companies/'.$company->storage_key.'/public/ai-video-source-images/',
            (string) $row->source_image_path,
        );
        $this->assertTrue(Storage::disk('public')->exists($row->source_image_path));

        Queue::assertPushed(GenerateVideoJob::class, fn (GenerateVideoJob $job): bool => $job->generatedVideoId === $row->getKey());
    }

    public function test_generate_records_the_source_generated_image_link_when_provided(): void
    {
        Queue::fake();
        Storage::fake('public');
        $company = $this->company();
        $this->configureProvider($company);
        $user = $this->user($company, 'manager');
        $reference = $this->videoReferenceImage($company, $user);

        Livewire::actingAs($user)
            ->test(VideoGeneration::class)
            ->fillForm(['prompt' => 'orbit around the product'])
            ->set('data.video_reference_pick', (string) $reference->getKey())
            ->assertSet('data.source_generated_image_id', $reference->getKey())
            ->call('generate')
            ->assertHasNoFormErrors();

        $row = GeneratedVideo::query()->firstOrFail();
        $this->assertSame($reference->getKey(), $row->source_generated_image_id);
        $this->assertSame($reference->output_paths[0], $row->source_image_path);
    }

    private function configureCustomProvider(Company $company): void
    {
        app(VideoProviderSettingsService::class)->save($company, [
            [
                'label' => 'Self-hosted',
                'api_format' => 'custom',
                'api_key' => 'custom-secret',
                'base_url' => 'https://video.test/submit',
                'poll_url_template' => 'https://video.test/jobs/{job_id}',
                'is_default' => true,
            ],
        ]);
    }

    private function configureEnhancer(Company $company): void
    {
        app(PromptEnhancerConfigService::class)->save($company, [
            'enabled' => true,
            'api_format' => 'openai',
            'provider' => 'Test',
            'model' => 'test-mini',
            'api_key' => 'sk-enh',
        ]);
    }

    public function test_enhance_is_offered_for_a_provider_that_sends_the_prompt(): void
    {
        $company = $this->company();
        $this->configureCustomProvider($company);
        $this->configureEnhancer($company);

        Livewire::actingAs($this->user($company, 'manager'))
            ->test(VideoGeneration::class)
            ->assertActionVisible('enhancePrompt');
    }

    public function test_enhance_is_hidden_and_the_prompt_is_flagged_unused_for_stability(): void
    {
        $company = $this->company();
        $this->configureProvider($company);
        $this->configureEnhancer($company);

        Livewire::actingAs($this->user($company, 'manager'))
            ->test(VideoGeneration::class)
            ->assertActionHidden('enhancePrompt')
            ->assertSee(__('The selected provider makes the video from the starting image only — this prompt is saved with the video but not sent to it.'));
    }

    public function test_enhance_is_hidden_when_the_enhancer_is_not_set_up(): void
    {
        $company = $this->company();
        $this->configureCustomProvider($company);

        Livewire::actingAs($this->user($company, 'manager'))
            ->test(VideoGeneration::class)
            ->assertActionHidden('enhancePrompt');
    }

    public function test_an_accepted_enhancement_is_generated_with_the_original_prompt_kept(): void
    {
        Queue::fake();
        Storage::fake('public');
        $company = $this->company();
        $this->configureCustomProvider($company);
        $this->configureEnhancer($company);
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Slow orbit around the bottle as light glides across the steel.']]], 'usage' => []], 200),
        ]);

        Livewire::actingAs($this->user($company, 'manager'))
            ->test(VideoGeneration::class)
            ->fillForm(['prompt' => 'bottle spinning', 'context' => 'product_demo'])
            ->mountAction('enhancePrompt')
            ->assertActionDataSet(['enhanced' => 'Slow orbit around the bottle as light glides across the steel.'])
            ->callMountedAction()
            ->assertSet('data.prompt', 'Slow orbit around the bottle as light glides across the steel.')
            ->assertSet('data.original_prompt', 'bottle spinning')
            ->fillForm(['source_image' => UploadedFile::fake()->image('bottle.jpg', 900, 900)])
            ->call('generate')
            ->assertHasNoFormErrors();

        Http::assertSent(function ($request): bool {
            $system = (string) (collect($request->data()['messages'] ?? [])->firstWhere('role', 'system')['content'] ?? '');

            return str_contains($system, 'PRODUCT DEMO') && str_contains($system, 'AI video generation');
        });

        $row = GeneratedVideo::query()->firstOrFail();
        $this->assertSame('Slow orbit around the bottle as light glides across the steel.', $row->prompt);
        $this->assertSame('bottle spinning', $row->original_prompt);
    }

    public function test_generate_requires_a_starting_image(): void
    {
        Queue::fake();
        $company = $this->company();
        $this->configureProvider($company);
        $user = $this->user($company, 'manager');

        Livewire::actingAs($user)
            ->test(VideoGeneration::class)
            ->fillForm(['prompt' => 'a video with no starting image'])
            ->call('generate')
            ->assertHasFormErrors(['source_image']);

        $this->assertSame(0, GeneratedVideo::query()->count());
    }

    /** An unflagged completed generation must not make the quick-pick field appear. */
    public function test_video_reference_pick_field_ignores_images_not_flagged_as_a_reference(): void
    {
        $company = $this->company();
        $user = $this->user($company, 'manager');

        GeneratedImage::query()->create([
            'user_id' => $user->getKey(),
            'tool' => GeneratedImage::TOOL_IMAGE_GENERATION,
            'context' => 'general',
            'prompt' => 'unrelated product shot',
            'aspect_ratio' => '1:1',
            'variations_requested' => 1,
            'is_video_reference' => false,
            'output_paths' => ['companies/'.$company->storage_key.'/public/ai-generated-images/other.webp'],
            'status' => GeneratedImage::STATUS_COMPLETED,
            'generated_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(VideoGeneration::class)
            ->assertFormFieldHidden('video_reference_pick');

        $this->assertSame(0, GeneratedVideo::query()->count());
    }

    public function test_video_reference_pick_field_is_hidden_when_the_pool_is_empty(): void
    {
        $company = $this->company();
        $this->configureProvider($company);
        $user = $this->user($company, 'manager');

        Livewire::actingAs($user)
            ->test(VideoGeneration::class)
            ->assertFormFieldHidden('video_reference_pick');

        $this->videoReferenceImage($company, $user);

        Livewire::actingAs($user)
            ->test(VideoGeneration::class)
            ->assertFormFieldVisible('video_reference_pick');
    }

    private function completedVideo(Company $company, User $user, array $overrides = []): GeneratedVideo
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
            'aspect_ratio' => '16:9',
            'video_path' => 'companies/'.$company->storage_key.'/public/ai-generated-videos/mug.mp4',
            'video_mime_type' => 'video/mp4',
            'status' => GeneratedVideo::STATUS_COMPLETED,
            'generated_at' => now(),
        ], $overrides));
    }

    public function test_attach_to_product_action_sets_the_product_video(): void
    {
        $company = $this->company();
        $this->configureProvider($company);
        $user = $this->user($company, 'manager');
        $video = $this->completedVideo($company, $user);

        $product = Product::query()->create([
            'name' => 'Ceramic Mug', 'sku' => 'CM-1', 'price' => 400, 'sale_price' => 400, 'stock' => 0,
        ]);

        Livewire::actingAs($user)
            ->test(VideoGeneration::class)
            ->assertSee('Not attached to a record yet.')
            ->callAction('attachToProduct', ['product_id' => $product->getKey()], ['generation' => $video->getKey()])
            ->assertHasNoActionErrors()
            ->assertNotified('Added to Ceramic Mug')
            ->assertSee('Attached to');

        $this->assertSame('companies/'.$company->storage_key.'/public/ai-generated-videos/mug.mp4', $product->fresh()->video_path);
        $this->assertSame($product->getKey(), $video->fresh()->linked_id);
        $this->assertTrue(AuditLog::query()->where('action', 'ai_video.attached')->exists());
    }

    public function test_attach_to_offer_action_sets_the_hero_video(): void
    {
        $company = $this->company();
        $this->configureProvider($company);
        $user = $this->user($company, 'manager');
        $video = $this->completedVideo($company, $user, ['context' => 'landing_hero']);

        $offer = Offer::query()->create(['type' => Offer::TYPE_SINGLE, 'title' => 'Mug Bundle']);

        Livewire::actingAs($user)
            ->test(VideoGeneration::class)
            ->callAction('attachToOffer', ['offer_id' => $offer->getKey()], ['generation' => $video->getKey()])
            ->assertHasNoActionErrors()
            ->assertNotified('Hero video updated');

        $this->assertSame('companies/'.$company->storage_key.'/public/ai-generated-videos/mug.mp4', $offer->fresh()->video_path);
    }

    public function test_attach_shows_the_reason_when_the_video_is_awaiting_review(): void
    {
        $company = $this->company();
        $this->configureProvider($company);
        $user = $this->user($company, 'manager');
        $video = $this->completedVideo($company, $user, ['review_status' => GeneratedVideo::REVIEW_PENDING]);

        $product = Product::query()->create([
            'name' => 'Held Mug', 'sku' => 'HM-1', 'price' => 400, 'sale_price' => 400, 'stock' => 0,
        ]);

        Livewire::actingAs($user)
            ->test(VideoGeneration::class)
            ->assertDontSee('Offer hero')
            ->callAction('attachToProduct', ['product_id' => $product->getKey()], ['generation' => $video->getKey()])
            ->assertNotified('Could not attach the video');

        $this->assertNull($product->fresh()->video_path);
    }

    public function test_a_user_cannot_attach_another_users_video(): void
    {
        $company = $this->company();
        $this->configureProvider($company);
        $owner = $this->user($company, 'manager');
        $video = $this->completedVideo($company, $owner);
        $otherManager = $this->user($company, 'manager');

        $product = Product::query()->create([
            'name' => 'Other Mug', 'sku' => 'OM-1', 'price' => 400, 'sale_price' => 400, 'stock' => 0,
        ]);

        Livewire::actingAs($otherManager)
            ->test(VideoGeneration::class)
            ->callAction('attachToProduct', ['product_id' => $product->getKey()], ['generation' => $video->getKey()])
            ->assertNotified('Could not attach the video');

        $this->assertNull($product->fresh()->video_path);
        $this->assertNull($video->fresh()->linked_id);
    }
}
