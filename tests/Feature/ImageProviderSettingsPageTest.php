<?php

namespace Tests\Feature;

use App\Filament\Pages\ImageProviderSettings;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\ImageGeneration\ImageProviderSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ImageProviderSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/admin/settings/image-provider-settings';

    private function company(): Company
    {
        $company = Company::query()->create([
            'name' => 'Provider Co',
            'slug' => 'provider-co-'.uniqid(),
            'invoice_prefix' => 'PRV'.random_int(100, 999),
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

    public function test_super_admin_can_add_a_provider_profile_and_it_persists_encrypted(): void
    {
        $company = $this->company();
        $admin = $this->user($company, 'super_admin');

        Livewire::actingAs($admin)
            ->test(ImageProviderSettings::class)
            ->set('data.image_provider_profiles', [
                [
                    'id' => null,
                    'has_api_key' => false,
                    'label' => 'OpenAI HQ',
                    'api_format' => 'openai',
                    'model' => 'gpt-image-1',
                    'default_size' => '1024x1024',
                    'cost_per_image' => 0.04,
                    'base_url' => '',
                    'api_key' => 'sk-super-secret',
                    'is_default' => true,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified();

        $profiles = app(ImageProviderSettingsService::class)->all($company);
        $this->assertCount(1, $profiles);
        $this->assertSame('OpenAI HQ', $profiles[0]['label']);
        $this->assertSame('sk-super-secret', $profiles[0]['api_key']);
        $this->assertSame(0.04, $profiles[0]['cost_per_image']);
        $this->assertTrue($profiles[0]['is_default']);

        $company->refresh();
        $this->assertNotSame('sk-super-secret', data_get($company->settings, 'ai_tools.image_generation.0.api_key'));
    }

    public function test_a_blank_key_on_resave_keeps_the_stored_key(): void
    {
        $company = $this->company();
        $admin = $this->user($company, 'super_admin');

        app(ImageProviderSettingsService::class)->save($company, [
            ['label' => 'OpenAI', 'api_format' => 'openai', 'model' => 'gpt-image-1', 'api_key' => 'sk-keep-me', 'is_default' => true],
        ]);
        $id = app(ImageProviderSettingsService::class)->list($company)[0]['id'];

        Livewire::actingAs($admin)
            ->test(ImageProviderSettings::class)
            ->set('data.image_provider_profiles', [
                [
                    'id' => $id,
                    'has_api_key' => true,
                    'label' => 'OpenAI (renamed)',
                    'api_format' => 'openai',
                    'model' => 'gpt-image-1',
                    'default_size' => '1024x1024',
                    'cost_per_image' => 0,
                    'base_url' => '',
                    'api_key' => '',
                    'is_default' => true,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $profile = app(ImageProviderSettingsService::class)->find($company, $id);
        $this->assertSame('OpenAI (renamed)', $profile['label']);
        $this->assertSame('sk-keep-me', $profile['api_key']);
    }
}
