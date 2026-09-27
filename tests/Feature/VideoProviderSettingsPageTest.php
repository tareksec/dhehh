<?php

namespace Tests\Feature;

use App\Filament\Pages\VideoProviderSettings;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\VideoGeneration\VideoProviderSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VideoProviderSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/admin/settings/video-provider-settings';

    private function company(): Company
    {
        $company = Company::query()->create([
            'name' => 'Video Provider Co',
            'slug' => 'video-provider-co-'.uniqid(),
            'invoice_prefix' => 'VPC'.random_int(100, 999),
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

    public function test_only_super_admin_can_open_the_page(): void
    {
        $company = $this->company();

        $this->actingAs($this->user($company, 'super_admin'))
            ->withSession(['current_company_id' => $company->getKey()])
            ->get($this->url)
            ->assertOk();

        $this->actingAs($this->user($company, 'manager'))
            ->withSession(['current_company_id' => $company->getKey()])
            ->get($this->url)
            ->assertForbidden();
    }

    public function test_super_admin_can_add_a_stability_profile_and_it_persists_encrypted(): void
    {
        $company = $this->company();
        $admin = $this->user($company, 'super_admin');

        Livewire::actingAs($admin)
            ->test(VideoProviderSettings::class)
            ->set('data.video_provider_profiles', [
                [
                    'id' => null,
                    'has_api_key' => false,
                    'label' => 'Stability HQ',
                    'api_format' => 'stability',
                    'model' => '',
                    'cost_per_video' => 0.5,
                    'base_url' => '',
                    'poll_url_template' => '',
                    'api_key' => 'stab-super-secret',
                    'is_default' => true,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified();

        $profiles = app(VideoProviderSettingsService::class)->all($company);
        $this->assertCount(1, $profiles);
        $this->assertSame('Stability HQ', $profiles[0]['label']);
        $this->assertSame('stab-super-secret', $profiles[0]['api_key']);
        $this->assertSame(0.5, $profiles[0]['cost_per_video']);
        $this->assertTrue($profiles[0]['is_default']);

        $company->refresh();
        $this->assertNotSame('stab-super-secret', data_get($company->settings, 'ai_tools.video_generation.0.api_key'));
    }

    public function test_super_admin_can_add_a_custom_profile_with_a_poll_url_template(): void
    {
        $company = $this->company();
        $admin = $this->user($company, 'super_admin');

        Livewire::actingAs($admin)
            ->test(VideoProviderSettings::class)
            ->set('data.video_provider_profiles', [
                [
                    'id' => null,
                    'has_api_key' => false,
                    'label' => 'Self-hosted',
                    'api_format' => 'custom',
                    'model' => 'my-model',
                    'cost_per_video' => 0,
                    'base_url' => 'https://x.local/submit',
                    'poll_url_template' => 'https://x.local/jobs/{job_id}',
                    'api_key' => '',
                    'is_default' => true,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $profile = app(VideoProviderSettingsService::class)->list($company)[0];
        $this->assertSame('https://x.local/jobs/{job_id}', $profile['poll_url_template']);
        $this->assertTrue(app(VideoProviderSettingsService::class)->isConfigured(app(VideoProviderSettingsService::class)->all($company)[0]));
    }

    public function test_a_blank_key_on_resave_keeps_the_stored_key(): void
    {
        $company = $this->company();
        $admin = $this->user($company, 'super_admin');

        app(VideoProviderSettingsService::class)->save($company, [
            ['label' => 'Stability', 'api_format' => 'stability', 'api_key' => 'stab-keep-me', 'is_default' => true],
        ]);
        $id = app(VideoProviderSettingsService::class)->list($company)[0]['id'];

        Livewire::actingAs($admin)
            ->test(VideoProviderSettings::class)
            ->set('data.video_provider_profiles', [
                [
                    'id' => $id,
                    'has_api_key' => true,
                    'label' => 'Stability (renamed)',
                    'api_format' => 'stability',
                    'model' => '',
                    'cost_per_video' => 0,
                    'base_url' => '',
                    'poll_url_template' => '',
                    'api_key' => '',
                    'is_default' => true,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $profile = app(VideoProviderSettingsService::class)->find($company, $id);
        $this->assertSame('Stability (renamed)', $profile['label']);
        $this->assertSame('stab-keep-me', $profile['api_key']);
    }
}
