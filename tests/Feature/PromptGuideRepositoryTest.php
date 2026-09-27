<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\CompanyContext;
use App\Services\PromptEnhancement\PromptGuideRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromptGuideRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        $company = Company::query()->create([
            'name' => 'Guide Co',
            'slug' => 'guide-co-'.uniqid(),
            'invoice_prefix' => 'GDE'.random_int(100, 999),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);

        return $company;
    }

    public function test_context_keys_come_from_config(): void
    {
        $keys = app(PromptGuideRepository::class)->contextKeys();

        $this->assertContains('image_generation.product_photo', $keys);
        $this->assertContains('image_generation.general', $keys);
    }

    public function test_guide_falls_back_to_config_default_then_generic(): void
    {
        $repo = app(PromptGuideRepository::class);
        $company = $this->company();

        $this->assertStringContainsString('COMMERCIAL PRODUCT PHOTO', $repo->guide('image_generation.product_photo', $company));
        $this->assertStringContainsString('without changing what they asked for', $repo->guide('image_generation.unknown_context', $company));
    }

    public function test_provider_style_is_format_specific(): void
    {
        $repo = app(PromptGuideRepository::class);

        $this->assertStringContainsString('natural', strtolower((string) $repo->providerStyle('openai')));
        $this->assertStringContainsString('comma-separated', (string) $repo->providerStyle('stability'));
        $this->assertNull($repo->providerStyle(null));
        $this->assertNull($repo->providerStyle('nope'));
    }

    public function test_video_contexts_and_video_provider_styles_are_available(): void
    {
        $repo = app(PromptGuideRepository::class);

        foreach (['product_demo', 'ad_creative', 'landing_hero', 'general'] as $context) {
            $this->assertContains("video_generation.{$context}", $repo->contextKeys());
        }

        $this->assertStringContainsString('camera movement', (string) $repo->providerStyle('custom', 'video'));
        // Stability image-to-video never receives the prompt, so it has no video style.
        $this->assertNull($repo->providerStyle('stability', 'video'));
        // The image styles are unchanged.
        $this->assertStringContainsString('comma-separated', (string) $repo->providerStyle('stability'));
    }

    public function test_save_writes_and_clears_overrides_and_brand_note(): void
    {
        $repo = app(PromptGuideRepository::class);
        $company = $this->company();

        $repo->save($company, [
            'image_generation.product_photo' => '  Put it on a jute mat.  ',
            'image_generation.general' => '',
        ], 'Warm, premium, daylight.', 7);

        $company->refresh();
        $this->assertSame('Put it on a jute mat.', $repo->guide('image_generation.product_photo', $company));
        $this->assertSame('Warm, premium, daylight.', $repo->brandStyle($company));

        $settings = (array) $company->settings;
        $this->assertSame(7, $settings['prompt_guides']['image_generation.product_photo']['updated_by']);
        $this->assertArrayHasKey('updated_at', $settings['prompt_guides']['image_generation.product_photo']);

        // The override form sees only the one non-blank override.
        $overrides = $repo->overrides($company);
        $this->assertSame('Put it on a jute mat.', $overrides['image_generation.product_photo']);
        $this->assertSame('', $overrides['image_generation.general']);

        // Clearing everything removes both keys entirely.
        $repo->save($company, [], null, 7);
        $company->refresh();
        $this->assertArrayNotHasKey('prompt_guides', (array) $company->settings);
        $this->assertArrayNotHasKey('image_brand_style', (array) $company->settings);
        $this->assertNull($repo->brandStyle($company));
        $this->assertStringContainsString('COMMERCIAL PRODUCT PHOTO', $repo->guide('image_generation.product_photo', $company));
    }
}
