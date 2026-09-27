<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\CompanyContext;
use App\Services\VideoGeneration\VideoProviderSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class VideoProviderSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        $company = Company::query()->create([
            'name' => 'Video Co',
            'slug' => 'video-co-'.uniqid(),
            'invoice_prefix' => 'VID'.random_int(100, 999),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);

        return $company;
    }

    public function test_it_saves_encrypts_and_reads_back_profiles(): void
    {
        $company = $this->company();
        $service = app(VideoProviderSettingsService::class);

        $service->save($company, [
            ['label' => 'Stability', 'api_format' => 'stability', 'api_key' => 'stab-secret', 'is_default' => true],
            [
                'label' => 'Self-hosted',
                'api_format' => 'custom',
                'api_key' => 'custom-secret',
                'base_url' => 'https://x.local/submit',
                'poll_url_template' => 'https://x.local/jobs/{job_id}',
                'is_default' => false,
            ],
        ]);

        $company->refresh();

        // Stored key is ciphertext, never plaintext.
        $rawKey = data_get($company->settings, 'ai_tools.video_generation.0.api_key');
        $this->assertNotSame('stab-secret', $rawKey);
        $this->assertSame('stab-secret', Crypt::decryptString($rawKey));

        $all = $service->all($company);
        $this->assertCount(2, $all);
        $this->assertSame('stab-secret', $all[0]['api_key']);
        $this->assertSame('custom-secret', $all[1]['api_key']);
        $this->assertSame('https://x.local/jobs/{job_id}', $all[1]['poll_url_template']);
        $this->assertTrue($all[0]['is_default']);

        // list() never exposes the plaintext key.
        $list = $service->list($company);
        $this->assertArrayNotHasKey('api_key', $list[0]);
        $this->assertTrue($list[0]['has_api_key']);
    }

    public function test_it_stores_a_non_negative_rounded_cost_per_video(): void
    {
        $company = $this->company();
        $service = app(VideoProviderSettingsService::class);

        $service->save($company, [
            ['label' => 'A', 'api_format' => 'stability', 'api_key' => 'k', 'cost_per_video' => 0.0421567],
            ['label' => 'B', 'api_format' => 'stability', 'api_key' => 'k', 'cost_per_video' => -3],
        ]);

        $all = $service->all($company->fresh());
        $this->assertSame(0.0422, $all[0]['cost_per_video']);
        $this->assertSame(0.0, $all[1]['cost_per_video']);
    }

    public function test_a_blank_key_on_an_existing_profile_keeps_the_stored_one(): void
    {
        $company = $this->company();
        $service = app(VideoProviderSettingsService::class);

        $service->save($company, [
            ['label' => 'Stability', 'api_format' => 'stability', 'api_key' => 'stab-original', 'is_default' => true],
        ]);

        $id = $service->list($company)[0]['id'];

        $service->save($company, [
            ['id' => $id, 'label' => 'Stability renamed', 'api_format' => 'stability', 'api_key' => '', 'is_default' => true],
        ]);

        $profile = $service->find($company, $id);
        $this->assertSame('Stability renamed', $profile['label']);
        $this->assertSame('stab-original', $profile['api_key']);
    }

    public function test_exactly_one_profile_ends_up_default(): void
    {
        $company = $this->company();
        $service = app(VideoProviderSettingsService::class);

        // None flagged → first becomes default.
        $service->save($company, [
            ['label' => 'A', 'api_format' => 'stability', 'api_key' => 'k'],
            ['label' => 'B', 'api_format' => 'stability', 'api_key' => 'k'],
        ]);
        $this->assertSame('A', $service->default($company)['label']);

        // Two flagged → first flagged wins, others cleared.
        $service->save($company, [
            ['label' => 'A', 'api_format' => 'stability', 'api_key' => 'k', 'is_default' => false],
            ['label' => 'B', 'api_format' => 'stability', 'api_key' => 'k', 'is_default' => true],
            ['label' => 'C', 'api_format' => 'stability', 'api_key' => 'k', 'is_default' => true],
        ]);
        $defaults = collect($service->all($company))->where('is_default', true);
        $this->assertCount(1, $defaults);
        $this->assertSame('B', $defaults->first()['label']);
    }

    public function test_is_configured_and_has_any_configured(): void
    {
        $company = $this->company();
        $service = app(VideoProviderSettingsService::class);

        $this->assertFalse($service->hasAnyConfigured($company));

        $service->save($company, [
            ['label' => 'No key', 'api_format' => 'stability', 'api_key' => ''],
        ]);
        $this->assertFalse($service->hasAnyConfigured($company));

        $service->save($company, [
            ['label' => 'No key', 'api_format' => 'stability', 'api_key' => ''],
            ['label' => 'Configured Stability', 'api_format' => 'stability', 'api_key' => 'stab-key'],
        ]);
        $this->assertTrue($service->hasAnyConfigured($company));
    }

    /** A custom profile needs both a base URL and a poll URL template, not just a key. */
    public function test_custom_format_requires_both_urls_to_be_configured(): void
    {
        $company = $this->company();
        $service = app(VideoProviderSettingsService::class);

        $service->save($company, [
            ['label' => 'Missing poll url', 'api_format' => 'custom', 'api_key' => '', 'base_url' => 'https://x.local/submit'],
        ]);
        $this->assertFalse($service->hasAnyConfigured($company));

        $service->save($company, [
            [
                'label' => 'Fully configured',
                'api_format' => 'custom',
                'api_key' => '',
                'base_url' => 'https://x.local/submit',
                'poll_url_template' => 'https://x.local/jobs/{job_id}',
            ],
        ]);
        $this->assertTrue($service->hasAnyConfigured($company));
    }

    public function test_default_returns_null_when_nothing_configured(): void
    {
        $company = $this->company();
        $this->assertNull(app(VideoProviderSettingsService::class)->default($company));
        $this->assertSame([], app(VideoProviderSettingsService::class)->all($company));
    }
}
