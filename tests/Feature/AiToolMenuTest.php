<?php

namespace Tests\Feature;

use App\Filament\Clusters\AiTools;
use App\Filament\Pages\ExpenseScanSettings;
use App\Filament\Pages\ImageGovernance;
use App\Filament\Pages\ImageProviderSettings;
use App\Filament\Pages\Integrations;
use App\Filament\Pages\PromptEnhancerSettings;
use App\Filament\Pages\ToolMenu;
use App\Filament\Pages\VideoProviderSettings;
use App\Models\Company;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AiToolMenuTest extends TestCase
{
    use RefreshDatabase;

    private string $toolMenuUrl = '/admin/ai-tools/tool-menu';

    public function test_super_admin_can_open_the_tool_menu(): void
    {
        [$company, $admin] = $this->companyAndUser('super_admin');

        $this->actingAs($admin)
            ->withSession(['current_company_id' => $company->getKey()])
            ->get($this->toolMenuUrl)
            ->assertOk()
            ->assertSee('Image Generation')
            ->assertSee('Video Generation')
            ->assertSee('Content Creation');
    }

    public function test_manager_can_open_the_tool_menu(): void
    {
        [$company, $manager] = $this->companyAndUser('manager');

        $this->actingAs($manager)
            ->withSession(['current_company_id' => $company->getKey()])
            ->get($this->toolMenuUrl)
            ->assertOk();
    }

    public function test_staff_without_ai_permissions_cannot_open_the_tool_menu(): void
    {
        [$company, $staff] = $this->companyAndUser('sales_staff');

        $this->actingAs($staff)
            ->withSession(['current_company_id' => $company->getKey()])
            ->get($this->toolMenuUrl)
            ->assertForbidden();
    }

    public function test_custom_role_with_only_image_generation_permission_can_still_open_the_hub(): void
    {
        [$company, $user] = $this->companyAndUser('image-only');

        UserRole::query()->create([
            'name' => 'Image Only',
            'slug' => 'image-only',
            'permissions' => ['dashboard.view', 'ai_tools.image_generation'],
            'is_active' => true,
        ]);

        $this->actingAs($user);

        // No `ai_tools.menu` — the hub must still open, since it is the only
        // route to the tool this role can use.
        $this->assertTrue(ToolMenu::canAccess());

        $this->withSession(['current_company_id' => $company->getKey()])
            ->get($this->toolMenuUrl)
            ->assertOk();
    }

    public function test_ai_tools_cluster_root_redirects_to_the_tool_menu(): void
    {
        [$company, $admin] = $this->companyAndUser('super_admin');

        $this->actingAs($admin)
            ->withSession(['current_company_id' => $company->getKey()])
            ->get('/admin/ai-tools')
            ->assertRedirect(route('filament.admin.ai-tools.pages.tool-menu'));
    }

    public function test_coming_soon_tools_render_as_disabled_tiles(): void
    {
        [$company, $admin] = $this->companyAndUser('super_admin');
        app(CompanyContext::class)->set($company);

        Livewire::actingAs($admin)
            ->test(ToolMenu::class)
            ->assertOk()
            ->assertSee('Coming soon')
            ->assertSee('Image Generation')
            ->assertSee('Video Generation')
            ->assertSee('Content Creation');
    }

    public function test_permission_keys_are_registered_for_role_configuration(): void
    {
        foreach ([
            'ai_tools.menu',
            'ai_tools.image_generation',
            'ai_tools.video_generation',
            'ai_tools.content_creation',
        ] as $key) {
            $this->assertArrayHasKey($key, User::CUSTOM_PERMISSION_OPTIONS, "{$key} must be selectable on custom roles.");
        }

        $this->assertContains('ai_tools.menu', User::ROLE_PERMISSIONS['manager']);
        $this->assertContains('ai_tools.image_generation', User::ROLE_PERMISSIONS['manager']);
        $this->assertContains('ai_tools.video_generation', User::ROLE_PERMISSIONS['manager']);

        // content_creation is still reserved — not active on any built-in role yet.
        foreach (User::ROLE_PERMISSIONS as $role => $permissions) {
            if ($permissions === ['*']) {
                continue;
            }

            $this->assertNotContains('ai_tools.content_creation', $permissions);
        }
    }

    public function test_cluster_is_hidden_from_users_without_any_ai_permission(): void
    {
        [$company, $staff] = $this->companyAndUser('sales_staff');
        $this->actingAs($staff);
        app(CompanyContext::class)->set($company);

        $this->assertFalse(AiTools::canAccess());
        $this->assertFalse(AiTools::shouldRegisterNavigation());
    }

    public function test_ai_tools_menu_lists_only_the_tools(): void
    {
        [$company, $admin] = $this->companyAndUser('super_admin');
        $this->actingAs($admin);
        app(CompanyContext::class)->set($company);

        $labels = collect(AiTools::getNavigationItems()[0]->getChildItems())
            ->map(fn ($item): string => (string) $item->getLabel())
            ->all();

        $this->assertContains('Tool Menu', $labels);
        $this->assertContains('Image Generation', $labels);
        $this->assertContains('Video Generation', $labels);

        foreach (['Image Providers', 'Video Providers', 'Prompt Enhancer', 'Smart Scan', 'Expense Scan', 'Image Governance'] as $moved) {
            $this->assertNotContains($moved, $labels, "{$moved} must not be listed in the AI Tools menu.");
        }
    }

    public function test_tool_menu_header_links_image_governance_for_super_admin_only(): void
    {
        [$company, $admin] = $this->companyAndUser('super_admin');

        Livewire::actingAs($admin)
            ->test(ToolMenu::class)
            ->assertActionVisible('imageGovernance')
            ->assertActionHasUrl('imageGovernance', ImageGovernance::getUrl());

        [, $manager] = $this->companyAndUser('manager');

        Livewire::actingAs($manager)
            ->test(ToolMenu::class)
            ->assertActionHidden('imageGovernance');
    }

    public function test_integrations_page_links_the_ai_tool_provider_pages(): void
    {
        [$company, $admin] = $this->companyAndUser('super_admin');

        $this->actingAs($admin)
            ->withSession(['current_company_id' => $company->getKey()])
            ->get(Integrations::getUrl())
            ->assertOk()
            ->assertSee('AI tool providers')
            ->assertSee(ImageProviderSettings::getUrl(), escape: false)
            ->assertSee(VideoProviderSettings::getUrl(), escape: false)
            ->assertSee(PromptEnhancerSettings::getUrl(), escape: false)
            ->assertSee(ExpenseScanSettings::getUrl(), escape: false)
            ->assertSee('Smart Scan');
    }

    public function test_old_ai_tools_provider_page_urls_redirect_to_settings(): void
    {
        [$company, $admin] = $this->companyAndUser('super_admin');

        foreach (['image-provider-settings', 'video-provider-settings', 'prompt-enhancer-settings', 'expense-scan-settings'] as $slug) {
            $this->actingAs($admin)
                ->withSession(['current_company_id' => $company->getKey()])
                ->get("/admin/ai-tools/{$slug}")
                ->assertRedirect("/admin/settings/{$slug}");
        }
    }

    /**
     * @return array{0: Company, 1: User}
     */
    private function companyAndUser(string $role): array
    {
        $company = Company::query()->create([
            'name' => 'AI Tools Co',
            'slug' => 'ai-tools-co-'.uniqid(),
            'invoice_prefix' => 'AIT'.random_int(100, 999),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);

        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $user->companies()->syncWithoutDetaching([
            $company->getKey() => ['role' => $role, 'is_default' => true],
        ]);

        return [$company, $user];
    }
}
