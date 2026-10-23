<?php

namespace Tests\Feature;

use App\Filament\Pages\ResellerSettings;
use App\Http\Middleware\SetResellerPortalLocale;
use App\Models\Company;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner request (2026-10-22): reseller.<company domain> opens the reseller landing page with its
 * sign-in / apply buttons, and the page ends with the footer of the company's Next.js website. The
 * website's footer shows the "Become a reseller" item as a banner button; its picture is managed on
 * Reseller Settings (see StorefrontApiTest for the API side).
 */
class ResellerLandingPageTest extends TestCase
{
    use RefreshDatabase;

    protected string $portal = 'https://reseller.landing-co.example.test';

    protected Company $company;

    protected StorefrontSetting $blade;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Landing Co', 'slug' => 'landing-co', 'domain' => 'landing-co.example.test',
            'domain_verified' => true, 'invoice_prefix' => 'LND', 'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka', 'is_active' => true, 'reseller_module_enabled' => true,
        ]);
        $this->blade = StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(), 'theme_color' => '#0F766E', 'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
        ]);
        app(CompanyContext::class)->set($this->company);
    }

    protected function website(array $attributes = []): StorefrontSetting
    {
        return StorefrontSetting::query()->create($attributes + [
            'company_id' => $this->company->getKey(), 'site_type' => StorefrontSetting::SITE_NEXTJS,
            'is_published' => true, 'nextjs_site_enabled' => true, 'nextjs_site_domain' => 'new.landing-co.example.test',
        ]);
    }

    public function test_the_portal_host_front_page_is_the_landing_page_with_sign_in_and_apply_buttons(): void
    {
        $page = $this->get($this->portal.'/')->assertOk()->assertSee('data-reseller-guide', false);

        $page->assertSee($this->portal.'/portal/login', false)
            ->assertSee($this->portal.'/portal/register', false)
            ->assertSee('data-guide-cta', false);

        // The old address keeps working.
        $this->get($this->portal.'/portal/guide')->assertOk()->assertSee('data-reseller-guide', false);
    }

    public function test_the_landing_page_is_drawn_in_the_neo_brutalist_style_with_its_own_stylesheet(): void
    {
        $this->get($this->portal.'/')
            ->assertOk()
            ->assertSee('reseller-landing', false)
            ->assertSee('class="nb ', false)
            ->assertSee('nb-box', false)
            ->assertSee('nb-btn', false)
            // The brand colour and a readable text colour on it reach the page's styles.
            ->assertSee('--brand: #0F766E', false)
            ->assertSee('--on-brand: #FFFFFF', false);
    }

    public function test_switching_the_guide_off_sends_the_front_page_to_the_sign_in_page(): void
    {
        $this->blade->forceFill(['reseller_guide_enabled' => false])->save();

        $this->get($this->portal.'/')->assertRedirect('/portal');
        $this->get($this->portal.'/portal/guide')->assertNotFound();
    }

    public function test_the_shop_front_page_and_other_hosts_are_not_touched(): void
    {
        $this->get('https://landing-co.example.test/')->assertOk()->assertDontSee('data-reseller-guide', false);
        $this->get('https://unknown.example.test/')->assertOk()->assertDontSee('data-reseller-guide', false);
    }

    public function test_with_the_reseller_module_off_the_portal_host_does_not_exist(): void
    {
        $this->company->forceFill(['reseller_module_enabled' => false])->save();

        $this->get($this->portal.'/')->assertOk()->assertDontSee('data-reseller-guide', false);
    }

    public function test_the_landing_page_ends_with_the_footer_of_the_next_js_website(): void
    {
        $page = StorefrontPage::query()->create(['title' => 'Privacy Policy', 'content' => 'x', 'is_published' => true]);

        $this->website([
            'social_links' => [['platform' => 'facebook', 'url' => 'https://facebook.com/landingco']],
            'footer_menu' => [
                ['label' => 'All products', 'type' => 'shop'],
                ['label' => 'Join as seller', 'type' => 'reseller'],
                ['label' => 'Privacy', 'type' => 'page', 'page_id' => $page->getKey()],
                ['label' => 'Partner site', 'type' => 'custom', 'url' => 'https://partner.example.test'],
            ],
            'site_design' => ['footer' => ['background' => '#FFFFFF']],
            'footer_blocks' => [
                ['type' => 'brand_about', 'data' => ['text' => 'We supply gadgets to resellers.']],
                ['type' => 'quick_links', 'data' => ['heading' => 'Explore']],
                ['type' => 'contact_info', 'data' => ['address' => 'Uttara, Dhaka', 'email' => 'hello@landing-co.example.test', 'phones' => ['01711111111']]],
                ['type' => 'social_links', 'data' => []],
                ['type' => 'bottom_bar', 'data' => ['copyright_text' => '© {year} {company_name} Ltd', 'legal_links' => [['page_id' => $page->getKey()]]]],
            ],
        ]);

        $this->get($this->portal.'/')
            ->assertOk()
            ->assertSee('data-reseller-footer', false)
            ->assertSee('background-color: #FFFFFF', false)
            ->assertSee('We supply gadgets to resellers.')
            ->assertSee('Explore')
            ->assertSee('Uttara, Dhaka')
            ->assertSee('tel:01711111111', false)
            ->assertSee('https://facebook.com/landingco', false)
            ->assertSee('© '.now()->year.' Landing Co Ltd')
            // Links to the website's own pages are built on the website's domain.
            ->assertSee('href="https://new.landing-co.example.test/products"', false)
            ->assertSee('href="https://new.landing-co.example.test/pages/privacy-policy"', false)
            ->assertSee('href="https://partner.example.test"', false)
            // This footer sits on the reseller program, so the item that points at it is left out.
            ->assertDontSee('Join as seller');
    }

    public function test_without_a_website_domain_only_absolute_footer_links_are_kept(): void
    {
        $this->website([
            'nextjs_site_domain' => null,
            'footer_menu' => [
                ['label' => 'All products', 'type' => 'shop'],
                ['label' => 'Partner site', 'type' => 'custom', 'url' => 'https://partner.example.test'],
            ],
            'footer_blocks' => [['type' => 'quick_links', 'data' => []]],
        ]);

        $this->get($this->portal.'/')
            ->assertOk()
            ->assertSee('Partner site')
            ->assertDontSee('All products');
    }

    public function test_without_a_website_the_landing_page_keeps_its_simple_footer(): void
    {
        $this->get($this->portal.'/')
            ->assertOk()
            ->assertDontSee('data-reseller-footer', false)
            ->assertSee('© '.now()->year.' Landing Co');
    }

    public function test_the_banner_button_picture_is_managed_on_reseller_settings(): void
    {
        Storage::fake('public');

        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $this->company->getKey()]);
        app(CompanyContext::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ResellerSettings::class)
            ->assertFormFieldExists('reseller_banner_image')
            ->fillForm(['reseller_banner_image' => UploadedFile::fake()->image('banner.png', 1080, 360)])
            ->call('save')
            ->assertHasNoFormErrors();

        $path = (string) $this->blade->fresh()->reseller_banner_image;
        $this->assertNotSame('', $path);
        $this->assertStringContainsString('companies/'.$this->company->storage_key.'/', $path);
        Storage::disk('public')->assertExists($path);

        // Clearing it goes back to the standard banner.
        Livewire::test(ResellerSettings::class)
            ->fillForm(['reseller_banner_image' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($this->blade->fresh()->reseller_banner_image);
    }

    public function test_the_landing_page_answers_the_questions_new_resellers_ask(): void
    {
        $page = $this->get($this->portal.'/')->assertOk();

        $page->assertSee('id="faq"', false)
            ->assertSeeInOrder([
                'Can I just market and sell while you pack and deliver?',
                'Can I list your products on my Facebook page and website with photos, details and prices?',
                'A customer confirmed an order. What do I send you?',
                'How is the parcel sent, and who collects the money?',
                'What is deducted from the money collected, and how much is my profit?',
                'When and how do I get my profit? How do payment and profit settlement work?',
            ])
            // The answers state what the system does: the profit rule, cash on delivery, the withdrawal route.
            ->assertSee('Your profit is your selling price minus your cost, for every piece.')
            ->assertSee('there is no separate packaging charge on orders the store delivers')
            // The parcel answer names the company and its couriers (owner wording, 2026-10-23).
            ->assertSee('Landing Co sends the parcel to your customer by courier (Steadfast, Pathao, RedX) on cash on delivery, so your customer pays when it arrives and you pay nothing in advance')
            ->assertSee('Open Withdrawals in the portal and request any amount up to your available balance, to bKash, Nagad, Rocket or a bank account.');

        $this->assertSame(6, substr_count($page->getContent(), 'data-reseller-faq'));
    }

    public function test_figures_in_the_answers_follow_the_settings(): void
    {
        $this->blade->forceFill(['reseller_min_withdrawal' => 500, 'reseller_return_charge' => 50])->save();

        $hidden = $this->get($this->portal.'/')->assertOk()->getContent();
        $this->assertStringNotContainsString('The smallest withdrawal is', $hidden);
        $this->assertStringNotContainsString('The return charge is', $hidden);
        // The charge itself is told without its amount.
        $this->assertStringContainsString('a return charge is taken from your deposit', $hidden);

        $this->blade->forceFill(['reseller_guide_show_figures' => true])->save();

        $shown = $this->get($this->portal.'/')->assertOk()->getContent();
        // Once in the money section and once in the answer to the last question.
        $this->assertSame(2, substr_count($shown, 'The smallest withdrawal is'));
        $this->assertSame(2, substr_count($shown, 'The return charge is'));
    }

    public function test_the_landing_page_has_a_bangla_version_that_the_visitor_chooses(): void
    {
        $bangla = $this->get($this->portal.'/?lang=bn')->assertOk();

        $bangla->assertSee('<html lang="bn"', false)
            ->assertSee(__('What is deducted from the money collected, and how much is my profit?', [], 'bn'))
            ->assertSee(__('Apply now', [], 'bn'))
            ->assertDontSee('What is deducted from the money collected')
            ->assertCookie(SetResellerPortalLocale::COOKIE, 'bn');

        // The switch is on the page, with each language named in itself.
        // In Bangla the same answer carries the company's name and the same three couriers.
        $bangla->assertSee('Landing Co কুরিয়ারের (Steadfast, Pathao, RedX) মাধ্যমে ক্যাশ অন ডেলিভারিতে');

        $bangla->assertSee('data-reseller-language-switch', false)->assertSee('?lang=en', false)->assertSee('?lang=bn', false)
            ->assertSee(__('Language name: Bangla', [], 'bn'))
            ->assertSee('English');

        $this->get($this->portal.'/?lang=en')->assertOk()
            ->assertSee('<html lang="en"', false)
            ->assertSee('What is deducted from the money collected')
            ->assertCookie(SetResellerPortalLocale::COOKIE, 'en');
    }

    public function test_the_chosen_language_is_kept_on_the_landing_and_application_pages(): void
    {
        // Back on the page later, without "?lang", the stored choice still applies.
        $this->withCookie(SetResellerPortalLocale::COOKIE, 'bn')->get($this->portal.'/')
            ->assertOk()
            ->assertSee('<html lang="bn"', false)
            ->assertSee(__('Questions resellers ask', [], 'bn'));

        // The same on the portal's application page.
        $this->withCookie(SetResellerPortalLocale::COOKIE, 'bn')->get($this->portal.'/portal/register')
            ->assertOk()
            ->assertSee('data-reseller-language-switch', false)
            ->assertSee(__('Read the reseller guide', [], 'bn'));

    }

    public function test_a_language_the_page_does_not_have_is_ignored(): void
    {
        $this->get($this->portal.'/?lang=fr')->assertOk()
            ->assertSee('<html lang="en"', false)
            ->assertCookieMissing(SetResellerPortalLocale::COOKIE);
    }

    public function test_the_language_choice_never_reaches_the_shop(): void
    {
        $this->withCookie(SetResellerPortalLocale::COOKIE, 'bn')->get('https://landing-co.example.test/?lang=bn')
            ->assertOk()
            ->assertSee('<html lang="en"', false)
            ->assertCookieMissing(SetResellerPortalLocale::COOKIE);
    }

    public function test_the_landing_page_is_set_in_anek_bangla_which_ships_with_the_erp(): void
    {
        foreach (['bengali', 'latin', 'latin-ext'] as $subset) {
            $this->assertFileExists(resource_path("fonts/anek-bangla/anek-bangla-{$subset}-wght-normal.woff2"));
        }

        $this->assertFileExists(resource_path('fonts/anek-bangla/OFL.txt'));
        $this->assertStringContainsString("font-family: 'Anek Bangla'", (string) file_get_contents(resource_path('css/reseller-landing.css')));
    }

    public function test_the_landing_page_carries_its_own_stylesheet_and_does_not_depend_on_the_global_app_css(): void
    {
        // Owner report 2026-10-23: on a phone the page came up with no layout because the global
        // app.css (a new file name on every deploy) did not load while the landing stylesheet did.
        $this->get($this->portal.'/')
            ->assertOk()
            ->assertSee('/build/assets/reseller-landing-', false)
            ->assertDontSee('/build/assets/app-', false);

        $css = (string) file_get_contents(resource_path('css/reseller-landing.css'));
        $this->assertStringContainsString("@import 'tailwindcss' source(none);", $css);

        // Every view the page is made of is scanned for utility classes.
        $this->assertStringContainsString("@source '../views/reseller/guide.blade.php';", $css);
        $this->assertStringContainsString("@source '../views/reseller/partials/*.blade.php';", $css);
        $this->assertStringContainsString("@source '../views/storefront/partials/social-icon.blade.php';", $css);

        foreach (array_merge(['guide'], array_map(fn ($file) => 'partials/'.basename($file, '.blade.php'), glob(resource_path('views/reseller/partials/*.blade.php')))) as $view) {
            preg_match_all("/@include\\('([a-z.\\-]+)'/", (string) file_get_contents(resource_path('views/reseller/'.$view.'.blade.php')), $included);

            foreach ($included[1] as $name) {
                $this->assertTrue(
                    str_starts_with($name, 'reseller.partials.') || $name === 'storefront.partials.social-icon',
                    "reseller/{$view} includes [{$name}], which the landing stylesheet does not scan for classes: add it to the @source lines of resources/css/reseller-landing.css.",
                );
            }
        }
    }

    public function test_every_grid_on_the_landing_page_starts_as_one_column_so_long_text_cannot_widen_a_phone(): void
    {
        // A grid column is 1fr = minmax(auto, 1fr): a long unbroken line (a store link, a long Bangla
        // word) stretches it, and the whole page scrolls sideways on a phone. grid-cols-1 is
        // minmax(0, 1fr), which lets the text truncate or wrap instead.
        foreach (['guide.blade.php', 'partials/footer.blade.php'] as $view) {
            preg_match_all('/class="([^"]*)"/', (string) file_get_contents(resource_path('views/reseller/'.$view)), $matches);

            foreach ($matches[1] as $classes) {
                $tokens = preg_split('/\s+/', trim($classes));

                if (! in_array('grid', $tokens, true)) {
                    continue;
                }

                $this->assertTrue(
                    count(array_filter($tokens, fn ($token) => str_starts_with($token, 'grid-cols-'))) > 0,
                    "A grid in reseller/{$view} has no base column count: [{$classes}]. Add grid-cols-1.",
                );
            }
        }
    }

    public function test_the_header_wears_the_websites_logo_pair_so_each_background_gets_its_own_logo(): void
    {
        // Owner request 2026-10-23: the header logo is the Next.js website's, the white-background logo
        // in light mode and the dark-background one in dark mode.
        $this->company->forceFill(['logo' => 'logos/company-own.png'])->save();
        $this->website(['logo' => 'logos/site-light.png', 'logo_dark' => 'logos/site-dark.png']);

        $page = $this->get($this->portal.'/')->assertOk();
        $html = $page->getContent();

        $page->assertSee('site-light.png', false)
            ->assertSee('site-dark.png', false)
            ->assertSee('nb-logo--light', false)
            ->assertSee('nb-logo--dark', false)
            // The website's logo replaces the company's own one.
            ->assertDontSee('company-own.png', false);

        // The light logo comes first in the header, the dark one right after it.
        $this->assertLessThan(strpos($html, 'site-dark.png'), strpos($html, 'site-light.png'));

        $css = (string) file_get_contents(resource_path('css/reseller-landing.css'));
        $this->assertStringContainsString('.dark .nb-logo--dark { display: block; }', $css);
        $this->assertStringContainsString('.dark .nb-logo--light { display: none; }', $css);
    }

    public function test_a_website_logo_without_a_dark_version_shows_in_both_modes(): void
    {
        $this->website(['logo' => 'logos/site-light.png']);

        $this->get($this->portal.'/')
            ->assertOk()
            ->assertSee('site-light.png', false)
            ->assertDontSee('nb-logo--light', false)
            ->assertDontSee('nb-logo--dark', false);
    }

    public function test_the_company_logo_is_the_fallback_when_the_website_has_none(): void
    {
        $this->company->forceFill(['logo' => 'logos/company-own.png'])->save();

        // No website row at all.
        $this->get($this->portal.'/')->assertOk()->assertSee('company-own.png', false);

        // A website row without a logo.
        $this->website();

        $this->get($this->portal.'/')->assertOk()->assertSee('company-own.png', false);
    }

    public function test_the_header_has_a_dark_mode_toggle_that_remembers_the_visitors_choice(): void
    {
        $page = $this->get($this->portal.'/')->assertOk();

        $page->assertSee('data-theme-toggle', false)
            ->assertSee('data-light-label="'.__('Switch to light mode').'"', false)
            ->assertSee('data-dark-label="'.__('Switch to dark mode').'"', false)
            ->assertSee('nb-theme-icon--moon', false)
            ->assertSee('nb-theme-icon--sun', false)
            // The choice is read before the page paints (no flash) and written on every press.
            ->assertSee("localStorage.getItem('resellerLandingTheme')", false)
            ->assertSee("localStorage.setItem('resellerLandingTheme'", false);

        // Without a saved choice the visitor's device setting decides, as before.
        $page->assertSee('prefers-color-scheme: dark', false);
    }

    public function test_the_standard_banner_ships_with_the_erp(): void
    {
        $this->assertFileExists(public_path('images/reseller-program-banner.png'));
        $this->assertSame([1080, 360], array_slice(getimagesize(public_path('images/reseller-program-banner.png')), 0, 2));
    }
}
