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
            ->assertSee('cash on delivery, so your customer pays when it arrives and you pay nothing in advance')
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

    public function test_the_standard_banner_ships_with_the_erp(): void
    {
        $this->assertFileExists(public_path('images/reseller-program-banner.png'));
        $this->assertSame([1080, 360], array_slice(getimagesize(public_path('images/reseller-program-banner.png')), 0, 2));
    }
}
