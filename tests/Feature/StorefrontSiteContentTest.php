<?php

namespace Tests\Feature;

use App\Filament\Resources\StorefrontPages\Pages\CreateStorefrontPage;
use App\Filament\Resources\StorefrontSettings\Pages\EditStorefrontSetting;
use App\Filament\Resources\StorefrontSlides\Pages\CreateStorefrontSlide;
use App\Models\Company;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use App\Models\StorefrontSlide;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner decision 2026-10-07: hero slides and custom pages can be limited to one
 * site ("Show on"); company-level features (WooCommerce, CRM messaging, lead
 * reminders) are read from the Next.js site's row once the company has one.
 */
class StorefrontSiteContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_the_blade_storefront_shows_shared_and_its_own_slides_but_not_the_next_js_sites(): void
    {
        [$company, $blade, $nextjs] = $this->company('twosite-slides.example.test');
        app(CompanyContext::class)->set($company);

        StorefrontSlide::query()->create(['image' => 'storefront/slides/shared-banner.jpg', 'is_active' => true]);
        StorefrontSlide::query()->create(['image' => 'storefront/slides/blade-banner.jpg', 'is_active' => true, 'storefront_setting_id' => $blade->getKey()]);
        StorefrontSlide::query()->create(['image' => 'storefront/slides/next-banner.jpg', 'is_active' => true, 'storefront_setting_id' => $nextjs->getKey()]);

        $this->get('http://twosite-slides.example.test/')
            ->assertOk()
            ->assertSee('shared-banner.jpg', false)
            ->assertSee('blade-banner.jpg', false)
            ->assertDontSee('next-banner.jpg', false);
    }

    public function test_the_blade_storefront_serves_shared_and_its_own_pages_but_not_the_next_js_sites(): void
    {
        [$company, $blade, $nextjs] = $this->company('twosite-pages.example.test');
        app(CompanyContext::class)->set($company);

        StorefrontPage::query()->create(['title' => 'Shared Terms', 'content' => 'Terms for everyone', 'is_published' => true]);
        StorefrontPage::query()->create(['title' => 'Reseller Rules', 'content' => 'Rules for resellers', 'is_published' => true, 'storefront_setting_id' => $blade->getKey()]);
        StorefrontPage::query()->create(['title' => 'Main Store Story', 'content' => 'Story of the main store', 'is_published' => true, 'storefront_setting_id' => $nextjs->getKey()]);

        $this->get('http://twosite-pages.example.test/pages/shared-terms')->assertOk()->assertSee('Terms for everyone');
        $this->get('http://twosite-pages.example.test/pages/reseller-rules')->assertOk()->assertSee('Rules for resellers');
        $this->get('http://twosite-pages.example.test/pages/main-store-story')->assertNotFound();

        // The footer links only the pages of this site.
        $this->get('http://twosite-pages.example.test/')
            ->assertOk()
            ->assertSee('Reseller Rules')
            ->assertDontSee('Main Store Story');
    }

    public function test_a_blade_menu_link_to_a_next_js_only_page_is_dropped(): void
    {
        [$company, $blade, $nextjs] = $this->company('twosite-menu.example.test');
        app(CompanyContext::class)->set($company);

        $mine = StorefrontPage::query()->create(['title' => 'Shared Terms', 'content' => 'x', 'is_published' => true]);
        $theirs = StorefrontPage::query()->create(['title' => 'Main Store Story', 'content' => 'x', 'is_published' => true, 'storefront_setting_id' => $nextjs->getKey()]);
        $blade->update(['header_menu' => [
            ['label' => 'Menu Terms', 'type' => 'page', 'page_id' => $mine->getKey()],
            ['label' => 'Menu Story', 'type' => 'page', 'page_id' => $theirs->getKey()],
        ]]);

        $this->get('http://twosite-menu.example.test/')
            ->assertOk()
            ->assertSee('Menu Terms')
            ->assertDontSee('Menu Story');
    }

    public function test_the_primary_row_is_the_next_js_site_once_there_is_one(): void
    {
        [$company, $blade, $nextjs] = $this->company('twosite-primary.example.test');

        $this->assertTrue(StorefrontSetting::primaryFor($company->getKey())->is($nextjs));
        $this->assertTrue($nextjs->isPrimary());
        $this->assertFalse($blade->isPrimary());

        $nextjs->delete();

        $this->assertTrue(StorefrontSetting::primaryFor($company->getKey())->is($blade), 'Without a Next.js site the Blade storefront is the primary row.');
        $this->assertTrue($blade->fresh()->isPrimary());
        $this->assertNull(StorefrontSetting::primaryFor(null));
    }

    public function test_deleting_a_site_returns_its_slides_and_pages_to_all_sites(): void
    {
        [$company, , $nextjs] = $this->company('twosite-delete.example.test');
        app(CompanyContext::class)->set($company);
        $slide = StorefrontSlide::query()->create(['image' => 'storefront/slides/x.jpg', 'is_active' => true, 'storefront_setting_id' => $nextjs->getKey()]);
        $page = StorefrontPage::query()->create(['title' => 'Story', 'content' => 'x', 'is_published' => true, 'storefront_setting_id' => $nextjs->getKey()]);

        $nextjs->delete();

        $this->assertNull($slide->fresh()->storefront_setting_id);
        $this->assertNull($page->fresh()->storefront_setting_id);
    }

    public function test_a_page_can_be_limited_to_one_of_the_companys_sites_but_never_another_companys(): void
    {
        [$company, $blade, $nextjs] = $this->company('twosite-form.example.test');
        [, , $stranger] = $this->company('stranger.example.test');
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $user->companies()->attach($company, ['role' => 'super_admin', 'is_default' => true]);
        app(CompanyContext::class)->set($company);
        $this->actingAs($user);

        Livewire::test(CreateStorefrontPage::class)
            ->fillForm(['title' => 'Main Story', 'slug' => 'main-story', 'content' => '<p>Hello</p>', 'is_published' => true, 'storefront_setting_id' => $nextjs->getKey()])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('storefront_pages', ['slug' => 'main-story', 'storefront_setting_id' => $nextjs->getKey()]);

        Livewire::test(CreateStorefrontPage::class)
            ->fillForm(['title' => 'Sneaky', 'slug' => 'sneaky', 'content' => '<p>Hello</p>', 'is_published' => true, 'storefront_setting_id' => $stranger->getKey()])
            ->call('create')
            ->assertHasFormErrors(['storefront_setting_id']);

        $this->assertDatabaseMissing('storefront_pages', ['slug' => 'sneaky']);

        // Left on "All sites" it stays null.
        Livewire::test(CreateStorefrontPage::class)
            ->fillForm(['title' => 'Everyone', 'slug' => 'everyone', 'content' => '<p>Hello</p>', 'is_published' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(StorefrontPage::withoutGlobalScopes()->where('slug', 'everyone')->value('storefront_setting_id'));
    }

    public function test_picking_a_site_for_a_slide_follows_that_sites_theme(): void
    {
        [$company, , $nextjs] = $this->company('twosite-slideform.example.test');
        $nextjs->update(['storefront_theme' => 'marketplace_pro']);
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $user->companies()->attach($company, ['role' => 'super_admin', 'is_default' => true]);
        app(CompanyContext::class)->set($company);
        $this->actingAs($user);

        Livewire::test(CreateStorefrontSlide::class)
            ->assertSet('data.theme', 'builtin')
            ->set('data.storefront_setting_id', $nextjs->getKey())
            ->assertSet('data.theme', 'marketplace_pro');
    }

    public function test_the_lead_reminder_settings_only_appear_on_the_row_that_is_read(): void
    {
        [$company, $blade, $nextjs] = $this->company('twosite-leads.example.test');
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $user->companies()->attach($company, ['role' => 'super_admin', 'is_default' => true]);
        app(CompanyContext::class)->set($company);
        $this->actingAs($user);

        Livewire::test(EditStorefrontSetting::class, ['record' => $nextjs->getKey()])
            ->call('selectSection', 'notifications')
            ->assertSee('Lead Follow-up Reminders');

        Livewire::test(EditStorefrontSetting::class, ['record' => $blade->getKey()])
            ->call('selectSection', 'notifications')
            ->assertDontSee('Lead Follow-up Reminders');
    }

    /** @return array{0: Company, 1: StorefrontSetting, 2: StorefrontSetting} */
    protected function company(string $domain): array
    {
        $company = Company::query()->create([
            'name' => 'Co '.$domain,
            'slug' => str($domain)->slug()->toString(),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => str($domain)->slug()->substr(0, 4)->upper()->toString(),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        $blade = StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'site_type' => StorefrontSetting::SITE_BLADE,
            'is_published' => true,
            'meta_title' => $company->name,
            'new_customer_delivery_advance_enabled' => false,
        ]);
        $nextjs = StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'site_type' => StorefrontSetting::SITE_NEXTJS,
            'is_published' => true,
            'nextjs_site_enabled' => true,
            'nextjs_site_domain' => 'main.'.$domain,
        ]);

        return [$company, $blade, $nextjs];
    }
}
