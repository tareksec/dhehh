<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\CompanyContext;
use App\Services\PromptEnhancement\PromptEnhancementService;
use App\Services\PromptEnhancement\PromptEnhancerConfigService;
use App\Services\PromptEnhancement\PromptGuideRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PromptEnhancementServiceTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        $company = Company::query()->create([
            'name' => 'Enhance Co',
            'slug' => 'enhance-co-'.uniqid(),
            'invoice_prefix' => 'ENH'.random_int(100, 999),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);

        return $company;
    }

    private function configureEnhancer(Company $company, string $format = 'openai'): void
    {
        app(PromptEnhancerConfigService::class)->save($company, [
            'enabled' => true,
            'api_format' => $format,
            'provider' => 'Test',
            'model' => 'test-mini',
            'api_key' => 'sk-enh',
        ]);
    }

    private function fakeChat(string $reply): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => $reply]]], 'usage' => []], 200),
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => $reply]], 'usage' => []], 200),
        ]);
    }

    public function test_is_available_reflects_enabled_and_key(): void
    {
        $company = $this->company();
        $service = app(PromptEnhancementService::class);

        $this->assertFalse($service->isAvailable($company));

        $this->configureEnhancer($company);
        $this->assertTrue($service->isAvailable($company));
    }

    public function test_it_throws_when_not_configured_or_empty(): void
    {
        $company = $this->company();
        $service = app(PromptEnhancementService::class);

        $this->expectException(RuntimeException::class);
        $service->enhance('a cat', 'image_generation.product_photo', 'openai', $company);
    }

    public function test_empty_prompt_is_rejected(): void
    {
        $company = $this->company();
        $this->configureEnhancer($company);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Write a prompt first/');

        app(PromptEnhancementService::class)->enhance('   ', 'image_generation.general', 'openai', $company);
    }

    public function test_the_system_prompt_carries_the_guide_provider_style_and_brand_note(): void
    {
        $company = $this->company();
        $this->configureEnhancer($company, 'openai');
        app(PromptGuideRepository::class)->save($company, [], 'Warm premium daylight, muted palette', 1);
        $this->fakeChat('An improved prompt.');

        app(PromptEnhancementService::class)->enhance(
            'a bottle',
            'image_generation.product_photo',
            'stability',
            $company,
        );

        Http::assertSent(function ($request): bool {
            $system = (string) (collect($request->data()['messages'] ?? [])->firstWhere('role', 'system')['content'] ?? '');

            return str_contains($system, 'COMMERCIAL PRODUCT PHOTO')          // context guide default
                && str_contains($system, 'comma-separated')                    // stability provider style
                && str_contains($system, 'Warm premium daylight, muted palette'); // brand note
        });
    }

    public function test_an_admin_guide_override_wins_over_the_default(): void
    {
        $company = $this->company();
        $this->configureEnhancer($company, 'openai');
        app(PromptGuideRepository::class)->save(
            $company,
            ['image_generation.product_photo' => 'ALWAYS put the product on a banana leaf.'],
            null,
            1,
        );
        $this->fakeChat('done');

        app(PromptEnhancementService::class)->enhance('a bottle', 'image_generation.product_photo', 'openai', $company);

        Http::assertSent(function ($request): bool {
            $system = (string) (collect($request->data()['messages'] ?? [])->firstWhere('role', 'system')['content'] ?? '');

            return str_contains($system, 'banana leaf') && ! str_contains($system, 'COMMERCIAL PRODUCT PHOTO');
        });
    }

    public function test_it_strips_wrapping_quotes_and_labels(): void
    {
        $company = $this->company();
        $this->configureEnhancer($company, 'openai');
        $this->fakeChat('Prompt: "A matte black steel bottle on a seamless warm background, soft daylight."');

        $out = app(PromptEnhancementService::class)->enhance('bottle', 'image_generation.general', 'openai', $company);

        $this->assertSame('A matte black steel bottle on a seamless warm background, soft daylight.', $out);
    }

    public function test_a_blank_model_response_falls_back_to_the_original(): void
    {
        $company = $this->company();
        $this->configureEnhancer($company, 'openai');
        $this->fakeChat('   ');

        $out = app(PromptEnhancementService::class)->enhance('my original prompt', 'image_generation.general', 'openai', $company);

        $this->assertSame('my original prompt', $out);
    }

    public function test_a_video_context_gets_video_wording_and_the_video_provider_style(): void
    {
        $company = $this->company();
        $this->configureEnhancer($company, 'openai');
        $this->fakeChat('Slow push-in on the mug as steam rises.');

        $out = app(PromptEnhancementService::class)->enhance(
            'mug with steam',
            'video_generation.product_demo',
            'custom',
            $company,
        );

        $this->assertSame('Slow push-in on the mug as steam rises.', $out);

        Http::assertSent(function ($request): bool {
            $system = (string) (collect($request->data()['messages'] ?? [])->firstWhere('role', 'system')['content'] ?? '');

            return str_contains($system, 'AI video generation from a starting image')
                && str_contains($system, 'PRODUCT DEMO')                  // video context guide
                && str_contains($system, 'the camera movement first')     // video custom style
                && str_contains($system, 'on-screen text')
                && ! str_contains($system, 'AI image generation');
        });
    }

    public function test_the_stability_image_style_is_not_used_for_a_video_prompt(): void
    {
        $company = $this->company();
        $service = app(PromptEnhancementService::class);

        $video = $service->systemPrompt('video_generation.general', 'stability', $company);
        $image = $service->systemPrompt('image_generation.general', 'stability', $company);

        $this->assertStringNotContainsString('comma-separated', $video);
        $this->assertStringContainsString('comma-separated', $image);
        $this->assertSame(PromptEnhancementService::MEDIUM_VIDEO, $service->medium('video_generation.ad_creative'));
        $this->assertSame(PromptEnhancementService::MEDIUM_IMAGE, $service->medium('image_generation.ad_creative'));
    }
}
