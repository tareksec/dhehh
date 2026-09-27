<?php

namespace Tests\Feature;

use App\Filament\Pages\PromptEnhancerSettings;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\PromptEnhancement\PromptEnhancerConfigService;
use App\Services\PromptEnhancement\PromptGuideRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

class PromptEnhancerSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/admin/settings/prompt-enhancer-settings';

    private function company(): Company
    {
        $company = Company::query()->create([
            'name' => 'Enh Page Co',
            'slug' => 'enh-page-'.uniqid(),
            'invoice_prefix' => 'EPC'.random_int(100, 999),
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

    public function test_it_saves_the_enhancer_provider_encrypted_plus_guide_and_brand_overrides(): void
    {
        $company = $this->company();
        $admin = $this->user($company, 'super_admin');

        Livewire::actingAs($admin)
            ->test(PromptEnhancerSettings::class)
            ->fillForm([
                'enabled' => true,
                'api_format' => 'openai',
                'provider' => 'Groq',
                'base_url' => 'https://api.groq.com/openai/v1/chat/completions',
                'model' => 'openai/gpt-oss-20b',
                'api_key' => 'gsk-secret',
                'brand_style' => 'Warm daylight, muted earthy palette.',
                'guide__image_generation__product_photo' => 'Shoot on a jute mat.',
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified();

        $config = app(PromptEnhancerConfigService::class)->all($company->fresh());
        $this->assertTrue($config['enabled']);
        $this->assertSame('openai', $config['api_format']);
        $this->assertSame('openai/gpt-oss-20b', $config['model']);
        $this->assertSame('gsk-secret', $config['api_key']);

        // Ciphertext at rest.
        $raw = data_get($company->fresh()->settings, 'ai_tools.prompt_enhancer.api_key');
        $this->assertSame('gsk-secret', Crypt::decryptString($raw));

        $repo = app(PromptGuideRepository::class);
        $this->assertSame('Shoot on a jute mat.', $repo->guide('image_generation.product_photo', $company->fresh()));
        $this->assertSame('Warm daylight, muted earthy palette.', $repo->brandStyle($company->fresh()));
    }

    public function test_a_blank_api_key_on_resave_keeps_the_stored_one(): void
    {
        $company = $this->company();
        $admin = $this->user($company, 'super_admin');

        app(PromptEnhancerConfigService::class)->save($company, [
            'enabled' => true, 'api_format' => 'anthropic', 'provider' => 'Anthropic',
            'model' => 'claude-haiku-4-5-20251001', 'api_key' => 'sk-keep',
        ]);

        Livewire::actingAs($admin)
            ->test(PromptEnhancerSettings::class)
            ->fillForm([
                'enabled' => true,
                'api_format' => 'anthropic',
                'provider' => 'Anthropic',
                'model' => 'claude-haiku-4-5-20251001',
                'api_key' => '',
                'brand_style' => '',
            ])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('sk-keep', app(PromptEnhancerConfigService::class)->all($company->fresh())['api_key']);
    }
}
