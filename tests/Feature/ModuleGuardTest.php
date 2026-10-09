<?php

namespace Tests\Feature;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Filament\Concerns\HasGuardedNavigationBadge;
use App\Filament\Resources\InvestorRequests\InvestorRequestResource;
use App\Filament\Resources\Investors\InvestorResource;
use App\Models\Company;
use App\Models\DeploymentError;
use App\Models\Investor;
use App\Models\InvestorRequest;
use App\Models\User;
use App\Services\CompanyContext;
use App\Support\ModuleGuard;
use App\Support\ModuleHealth;
use App\Support\PanelErrorPages;
use Filament\Pages\Page;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\Widget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Livewire\Component;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * One module's failure must stay in that module (owner request 2026-10-08,
 * after a missing table behind one sidebar badge made every admin page 500).
 */
class ModuleGuardTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        $company = Company::query()->create([
            'name' => 'Guard Co',
            'slug' => 'guard-co-'.uniqid(),
            'invoice_prefix' => 'GRD'.random_int(100, 999),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);

        return $company;
    }

    private function superAdmin(Company $company): User
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $user->companies()->syncWithoutDetaching([$company->getKey() => ['role' => 'super_admin', 'is_default' => true]]);

        return $user;
    }

    public function test_it_rethrows_when_isolation_is_off(): void
    {
        config(['app.isolate_module_errors' => false]);

        $this->expectException(RuntimeException::class);

        ModuleGuard::run('test.section', fn () => throw new RuntimeException('boom'));
    }

    public function test_it_returns_the_fallback_and_reports_once_when_isolating(): void
    {
        config(['app.isolate_module_errors' => true]);

        $this->assertSame('ok', ModuleGuard::run('test.section', fn () => 'ok'));
        $this->assertNull(ModuleGuard::run('test.section', fn () => throw new RuntimeException('boom')));
        $this->assertSame('fallback', ModuleGuard::run('test.section', fn () => throw new RuntimeException('boom'), 'fallback'));
        $this->assertSame('boom!', ModuleGuard::run('test.section', fn () => throw new RuntimeException('boom'), fn ($e) => $e->getMessage().'!'));

        // Three failures of the same error: one report, not three.
        $this->assertSame(1, DeploymentError::query()->where('source', 'module:test.section')->count());

        ModuleGuard::run('test.section', fn () => throw new RuntimeException('another'));
        $this->assertSame(2, DeploymentError::query()->where('source', 'module:test.section')->count());
    }

    public function test_a_failing_navigation_badge_shows_no_badge_and_is_reported(): void
    {
        config(['app.isolate_module_errors' => true]);

        $this->assertNull(ModuleGuardTestBadgePage::getNavigationBadge());
        $this->assertTrue(DeploymentError::query()->where('source', 'module:module-guard-test-badge-page.badge')->exists());
    }

    public function test_a_missing_table_turns_off_only_its_pages_and_the_panel_keeps_working(): void
    {
        config(['app.isolate_module_errors' => true]);
        $company = $this->company();
        $admin = $this->superAdmin($company);

        // The 2026-10-08 production state: the table behind Investor Requests is missing.
        Schema::drop('investor_requests');
        ModuleHealth::forget();

        $this->actingAs($admin)->withSession(['current_company_id' => $company->getKey()]);

        $this->get('/admin')->assertOk();
        $this->get(InvestorResource::getUrl('index'))->assertOk();

        $page = $this->get(InvestorRequestResource::getUrl('index'))->assertStatus(503);
        $this->assertSame('panel-errors.module', $page->original->name());
        $page->assertSee('This section is temporarily unavailable');

        $this->assertFalse($admin->can('viewAny', InvestorRequest::class));
        $this->assertTrue($admin->can('viewAny', Investor::class));
        $this->assertSame(['investor_requests' => InvestorRequest::class], app(ModuleHealth::class)->missingTables());
        $this->assertSame(1, DeploymentError::query()->where('source', 'module:health:investments.investor_requests')->count());

        // A migration run re-checks at once.
        Schema::create('investor_requests', fn ($table) => $table->id());
        event(new MigrationsEnded('up'));
        $this->assertTrue($admin->fresh()->can('viewAny', InvestorRequest::class));
    }

    public function test_every_navigation_badge_goes_through_the_guard(): void
    {
        $unguarded = [];

        foreach (File::allFiles(app_path('Filament')) as $file) {
            $source = $file->getContents();

            if (str_contains($source, 'function getNavigationBadge(') && ! str_contains($source, 'trait HasGuardedNavigationBadge')) {
                $unguarded[] = $file->getRelativePathname();
            }

            if (str_contains($source, 'function navigationBadge(') && ! str_contains($source, 'use HasGuardedNavigationBadge;') && ! str_contains($source, 'trait HasGuardedNavigationBadge')) {
                $unguarded[] = $file->getRelativePathname().' (navigationBadge() without the trait)';
            }
        }

        $this->assertSame([], $unguarded, 'Write sidebar badges as navigationBadge() with '.class_basename(HasGuardedNavigationBadge::class).', not getNavigationBadge().');
    }

    public function test_a_failing_render_hook_outputs_nothing_instead_of_breaking_every_page(): void
    {
        config(['app.isolate_module_errors' => true]);
        $company = $this->company();

        FilamentView::registerRenderHook(PanelsRenderHook::BODY_END, fn (): string => throw new RuntimeException('hook failed'));
        FilamentView::registerRenderHook(PanelsRenderHook::BODY_END, fn (): string => '<span id="healthy-hook">still here</span>');

        $this->actingAs($this->superAdmin($company))
            ->withSession(['current_company_id' => $company->getKey()])
            ->get('/admin')
            ->assertOk()
            ->assertSee('healthy-hook', false);

        $this->assertTrue(DeploymentError::query()->where('source', 'module:render-hook:'.PanelsRenderHook::BODY_END)->exists());
    }

    public function test_a_failing_widget_shows_a_note_and_a_working_one_renders_normally(): void
    {
        config(['app.isolate_module_errors' => true]);
        $views = sys_get_temp_dir().'/module-guard-test-views';
        File::ensureDirectoryExists($views);
        File::put($views.'/working-widget.blade.php', '<div>working widget body {{ $this->getColumnSpan() }}</div>');
        app('view')->addNamespace('module-guard-test', $views);

        Livewire::test(ModuleGuardTestFailingWidget::class)
            ->assertOk()
            ->assertSee('This section could not be loaded.');

        Livewire::test(ModuleGuardTestWorkingWidget::class)
            ->assertOk()
            ->assertSee('working widget body')
            ->assertDontSee('This section could not be loaded.');

        $this->assertTrue(DeploymentError::query()->where('source', 'module:widget:module-guard-test-failing-widget')->exists());
    }

    public function test_an_inline_widget_failing_mid_render_leaves_the_page_around_it_intact(): void
    {
        config(['app.isolate_module_errors' => true]);
        $views = sys_get_temp_dir().'/module-guard-test-views';
        File::ensureDirectoryExists($views);
        File::put($views.'/exploding-widget.blade.php', '<div>{{ $this->explode() }}</div>');
        app('view')->addNamespace('module-guard-test', $views);

        // The widget fails while the page is inside a Blade component; a failed
        // render flushes Laravel's view state, which must be put back.
        Livewire::test(ModuleGuardTestHostPage::class)
            ->assertOk()
            ->assertSee('before the widget')
            ->assertSee('This section could not be loaded.')
            ->assertSee('after the widget');
    }

    public function test_a_crashing_panel_page_shows_the_module_error_page(): void
    {
        config(['app.isolate_module_errors' => true]);
        Route::middleware('web')->get('/admin/module-guard-crash', fn () => throw new RuntimeException('page failed'));

        $this->get('/admin/module-guard-crash')
            ->assertStatus(500)
            ->assertSee('This page could not be opened')
            ->assertSee('The other sections keep working', false)
            ->assertSee('href="'.url('/admin').'"', false)
            ->assertDontSee('page failed');

        // Portals are only served on their own host, so their mapping is checked directly.
        $this->assertSame('/portal', PanelErrorPages::panelHome(Request::create('/portal/orders')));
        $this->assertSame('/investor', PanelErrorPages::panelHome(Request::create('/investor')));
        $this->assertNull(PanelErrorPages::panelHome(Request::create('/products/phone')));

        $this->assertTrue(DeploymentError::query()->where('source', 'module:page:admin/module-guard-crash')->exists());
    }

    public function test_a_failing_livewire_update_from_a_panel_page_gets_the_same_page(): void
    {
        config(['app.isolate_module_errors' => true]);
        Route::middleware('web')->post('/module-guard-livewire/update', fn () => throw new RuntimeException('action failed'));

        $this->withHeaders(['X-Livewire' => '1', 'Referer' => url('/admin/orders'), 'Accept' => 'application/json'])
            ->post('/module-guard-livewire/update')
            ->assertStatus(500)
            ->assertSee('This page could not be opened')
            ->assertSee('href="'.url('/admin/orders').'"', false);
    }

    public function test_the_module_error_page_leaves_other_responses_alone(): void
    {
        config(['app.isolate_module_errors' => true]);
        Route::middleware('web')->get('/admin/module-guard-forbidden', fn () => abort(403));
        Route::middleware('web')->get('/module-guard-storefront-crash', fn () => throw new RuntimeException('storefront failed'));
        Route::middleware('web')->get('/admin/module-guard-missing-record', fn () => Company::query()->findOrFail(999999));
        Route::middleware('web')->get('/admin/module-guard-denied', fn () => throw new AuthorizationException);

        $this->assertNotModuleErrorPage($this->get('/admin/module-guard-forbidden')->assertStatus(403));
        $this->assertNotModuleErrorPage($this->get('/admin/module-guard-no-such-page')->assertStatus(404));
        $this->assertNotModuleErrorPage($this->get('/module-guard-storefront-crash')->assertStatus(500));
        $this->assertNotModuleErrorPage($this->get('/admin/module-guard-missing-record')->assertStatus(404));
        $this->assertNotModuleErrorPage($this->get('/admin/module-guard-denied')->assertStatus(403));
        $this->getJson('/admin/module-guard-forbidden')->assertStatus(403);

        config(['app.isolate_module_errors' => false]);
        Route::middleware('web')->get('/admin/module-guard-crash-dev', fn () => throw new RuntimeException('dev failure'));
        $this->assertNotModuleErrorPage($this->get('/admin/module-guard-crash-dev')->assertStatus(500));
    }

    private function assertNotModuleErrorPage(TestResponse $response): void
    {
        // Checked by view name: Laravel's debug page quotes this test's source, phrases included.
        $this->assertFalse(
            $response->original instanceof ViewContract && $response->original->name() === 'panel-errors.module',
            'The module error page must not be used here.',
        );
    }

    public function test_every_widget_goes_through_the_guard(): void
    {
        $unguarded = [];

        foreach (File::allFiles(app_path()) as $file) {
            $source = $file->getContents();

            if (preg_match('/\\bextends (StatsOverviewWidget|ChartWidget|TableWidget|Widget|BaseWidget)\\b/', $source) && ! str_contains($source, 'use GuardsWidgetRendering;')) {
                $unguarded[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $unguarded, 'Every widget must use '.class_basename(GuardsWidgetRendering::class).'.');
    }
}

class ModuleGuardTestFailingWidget extends Widget
{
    use GuardsWidgetRendering;

    protected string $view = 'module-guard-test::working-widget';

    protected function getViewData(): array
    {
        throw new RuntimeException('widget failed');
    }
}

class ModuleGuardTestWorkingWidget extends Widget
{
    use GuardsWidgetRendering;

    protected string $view = 'module-guard-test::working-widget';
}

class ModuleGuardTestExplodingWidget extends Widget
{
    use GuardsWidgetRendering;

    protected static bool $isLazy = false;

    protected string $view = 'module-guard-test::exploding-widget';

    public function explode(): string
    {
        throw new RuntimeException('failed while rendering');
    }
}

class ModuleGuardTestHostPage extends Component
{
    public function render(): string
    {
        return <<<'BLADE'
            <div>
                <x-filament::section>
                    before the widget
                    @livewire(\Tests\Feature\ModuleGuardTestExplodingWidget::class)
                    after the widget
                </x-filament::section>
            </div>
            BLADE;
    }
}

class ModuleGuardTestBadgePage extends Page
{
    use HasGuardedNavigationBadge;

    protected static function navigationBadge(): ?string
    {
        throw new RuntimeException('badge failed');
    }
}
