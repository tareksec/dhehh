<?php

namespace Tests\Feature;

use App\Filament\Resources\StorefrontSettings\Pages\EditStorefrontSetting;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Support\StorefrontFooterStarter;
use App\Support\StorefrontSiteDesign;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner request 2026-10-14: "design the footer too, like the reference site". The starter footer is
 * loaded from the Footer Builder (nothing is saved until Save), links to pages are matched to the
 * company's own published pages by name, and a link whose page does not exist is left out and reported.
 */
class NextjsFooterStarterTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected StorefrontSetting $nextjs;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->company = Company::query()->create([
            'name' => 'ZamZam International', 'slug' => 'zamzam-international', 'domain' => 'offer.example.test', 'domain_verified' => true,
            'invoice_prefix' => 'ZAM', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ])->fresh();
        $this->nextjs = StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(), 'site_type' => StorefrontSetting::SITE_NEXTJS, 'is_published' => true, 'nextjs_site_enabled' => true,
        ]);
        [, $this->token] = CompanyApiKey::generate($this->company);
        app(CompanyContext::class)->set($this->company);
    }

    // ---------------------------------------------------------- the layout

    public function test_the_starter_is_the_reference_footer_in_order(): void
    {
        $this->allPages();

        $footer = StorefrontFooterStarter::layout(StorefrontPage::query()->get(), 'ZamZam International');

        $this->assertSame(
            ['brand_about', 'social_links', 'quick_links', 'custom_links', 'contact_info', 'payment_methods', 'bottom_bar'],
            array_column($footer['blocks'], 'type'),
        );
        $this->assertSame(['Home', 'Shop', 'About Us', 'Contact Us', 'Promotions'], array_column($footer['menu'], 'label'));
        $this->assertSame([], $footer['skipped']);

        $blocks = collect($footer['blocks'])->keyBy('type');
        $this->assertSame('Customer Service', $blocks['custom_links']['data']['heading']);
        $this->assertSame(
            ['Delivery Information', 'Return, Refund & Exchange', 'Terms and Conditions', 'Privacy Policy'],
            array_column($blocks['custom_links']['data']['links'], 'label'),
        );
        $this->assertSame('Our Office', $blocks['contact_info']['data']['heading']);
        $this->assertSame('info@zamzamint.com', $blocks['contact_info']['data']['email']);
        $this->assertSame(['+8801325224400', '+8801894449445'], $blocks['contact_info']['data']['phones']);
        $this->assertSame('Trade License: TRAD/DNCC/049335/2025', $blocks['contact_info']['data']['trade_license']);
        $this->assertSame('Safety Payments', $blocks['payment_methods']['data']['heading']);
        $this->assertSame(['bKash', 'Nagad', 'Rocket', 'Cards'], array_column($blocks['payment_methods']['data']['items'], 'label'));
        $this->assertSame(['pink', 'orange', 'purple', 'neutral'], array_column($blocks['payment_methods']['data']['items'], 'tone'));
        $this->assertSame('© {year} {company_name}. All rights reserved.', $blocks['bottom_bar']['data']['copyright_text']);
        $this->assertSame('Developed by Bright Hive Digital', $blocks['bottom_bar']['data']['center_text']);
        $this->assertSame('https://brighthivedigital.com/', $blocks['bottom_bar']['data']['center_url']);
        $this->assertSame('Developed with {heart} for Bangladesh', $blocks['bottom_bar']['data']['right_text']);
    }

    public function test_the_starter_is_already_clean_data(): void
    {
        $this->allPages();

        foreach (StorefrontFooterStarter::layout(StorefrontPage::query()->get(), 'ZamZam International')['blocks'] as $block) {
            $this->assertArrayHasKey($block['type'], StorefrontSetting::FOOTER_BLOCK_TYPES);
            $this->assertSame($block['data'], StorefrontSiteDesign::footerBlockData($block['type'], $block['data']), $block['type'].' is unchanged by the cleaner');
        }
    }

    public function test_the_about_text_is_the_references_bangla_sentence_with_the_company_name(): void
    {
        App::setLocale('en');

        $text = StorefrontFooterStarter::layout(collect(), 'ZamZam International')['blocks'][0]['data']['text'];

        $this->assertSame('নিত্যদিনের প্রয়োজন মেটাতে মানসম্মত গ্যাজেট সাশ্রয়ী দামে — ZamZam International আপনার বিশ্বস্ত অনলাইন শপ।', $text, 'it reads the Bangla language file whatever language the admin uses');
    }

    public function test_pages_match_by_title_or_slug_however_they_are_spelled(): void
    {
        $about = $this->page('About');
        $terms = $this->page('Terms & Conditions');
        $returns = $this->page('Returns Policy', slug: 'return-refund-and-exchange');
        $privacy = $this->page('Our privacy', slug: 'privacy-policy');
        $this->page('Something else');

        $footer = StorefrontFooterStarter::layout(StorefrontPage::query()->orderBy('id')->get(), 'ZamZam International');

        $menu = collect($footer['menu'])->keyBy('label');
        $this->assertSame($about->getKey(), $menu['About Us']['page_id']);
        $service = collect($footer['blocks'])->firstWhere('type', 'custom_links')['data']['links'];
        $this->assertSame(['Return, Refund & Exchange', 'Terms and Conditions', 'Privacy Policy'], array_column($service, 'label'));
        $this->assertSame([$returns->getKey(), $terms->getKey(), $privacy->getKey()], array_column($service, 'page_id'));
        $this->assertSame(['Contact Us', 'Delivery Information'], $footer['skipped']);
    }

    public function test_a_link_with_no_page_is_left_out_and_reported(): void
    {
        $footer = StorefrontFooterStarter::layout(collect(), 'ZamZam International');

        $this->assertSame(['Home', 'Shop', 'Promotions'], array_column($footer['menu'], 'label'), 'only the links that do not need a page');
        $this->assertSame('/products?sort=discount&sale=1', $footer['menu'][2]['url']);
        $this->assertSame([], collect($footer['blocks'])->firstWhere('type', 'custom_links')['data']['links']);
        $this->assertSame(
            ['About Us', 'Contact Us', 'Delivery Information', 'Return, Refund & Exchange', 'Terms and Conditions', 'Privacy Policy'],
            $footer['skipped'],
        );
    }

    // -------------------------------------------------------------- admin

    public function test_the_starter_loads_into_the_footer_builder_without_saving_until_the_owner_saves(): void
    {
        $this->allPages();
        $this->actingAsSuperAdmin();

        $component = Livewire::test(EditStorefrontSetting::class, ['record' => $this->nextjs->getKey()])
            ->call('selectSection', 'navigation_seo')
            ->assertSee(__('Load the starter footer'))
            ->callAction(TestAction::make('loadStarterFooter')->schemaComponent('footer_builder', schema: 'form'))
            ->assertNotified(__('Starter footer loaded'));

        $this->assertNull($this->nextjs->fresh()->footer_blocks, 'loading is only a draft');
        $this->assertSame([], $this->nextjs->fresh()->footer_menu ?? []);

        $blocks = array_values($component->get('data.footer_blocks'));
        $this->assertSame(
            ['brand_about', 'social_links', 'quick_links', 'custom_links', 'contact_info', 'payment_methods', 'bottom_bar'],
            array_column($blocks, 'type'),
        );
        $this->assertSame("+8801325224400\n+8801894449445", $blocks[4]['data']['phones'], 'the phone box holds one number per line');

        $component->call('save')->assertHasNoFormErrors();

        $saved = $this->nextjs->fresh();
        $this->assertCount(7, $saved->footer_blocks);
        $this->assertSame(['Home', 'Shop', 'About Us', 'Contact Us', 'Promotions'], array_column($saved->footer_menu, 'label'));

        $site = $this->getJson('/api/v1/storefront/site', ['Authorization' => 'Bearer '.$this->token])->assertOk()->json('data');
        $this->assertSame(['Home', 'Shop', 'About Us', 'Contact Us', 'Promotions'], array_column($site['menus']['footer'], 'label'));
        $this->assertSame('about-us', $site['menus']['footer'][2]['page_slug']);

        $blocks = collect($site['footer_blocks'])->keyBy('type');
        $this->assertSame(['delivery-information', 'return-refund-exchange', 'terms-and-conditions', 'privacy-policy'], array_column($blocks['custom_links']['data']['links'], 'page_slug'));
        $this->assertSame(['+8801325224400', '+8801894449445'], $blocks['contact_info']['data']['phones']);
        $this->assertSame('https://brighthivedigital.com/', $blocks['bottom_bar']['data']['center_url']);
    }

    public function test_the_notification_names_the_pages_that_were_left_out(): void
    {
        $this->page('About Us');
        $this->actingAsSuperAdmin();

        Livewire::test(EditStorefrontSetting::class, ['record' => $this->nextjs->getKey()])
            ->call('selectSection', 'navigation_seo')
            ->callAction(TestAction::make('loadStarterFooter')->schemaComponent('footer_builder', schema: 'form'))
            ->assertNotified(
                Notification::make()
                    ->success()
                    ->title(__('Starter footer loaded'))
                    ->body(__('Check the sections, then press Save changes. Not added, because no published page has that name: :links. Create the pages under Site → Pages, then add them to the footer.', [
                        'links' => 'Contact Us, Delivery Information, Return, Refund & Exchange, Terms and Conditions, Privacy Policy',
                    ])),
            );
    }

    public function test_another_sites_or_another_companys_pages_are_never_linked(): void
    {
        $blade = StorefrontSetting::query()->create(['company_id' => $this->company->getKey(), 'site_type' => StorefrontSetting::SITE_BLADE, 'is_published' => true]);
        $this->page('About Us', siteId: $blade->getKey());
        $this->page('Privacy Policy', published: false);

        $other = Company::query()->create(['name' => 'Other', 'slug' => 'other', 'invoice_prefix' => 'OTH', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        $this->page('Terms and Conditions');
        app(CompanyContext::class)->set($this->company);

        $this->actingAsSuperAdmin();

        $component = Livewire::test(EditStorefrontSetting::class, ['record' => $this->nextjs->getKey()])
            ->call('selectSection', 'navigation_seo')
            ->callAction(TestAction::make('loadStarterFooter')->schemaComponent('footer_builder', schema: 'form'));

        $this->assertSame(['Home', 'Shop', 'Promotions'], array_column(array_values($component->get('data.footer_menu')), 'label'), 'a page limited to the Blade site, an unpublished one and another company\'s are all skipped');
        $this->assertSame([], array_values(collect($component->get('data.footer_blocks'))->firstWhere('type', 'custom_links')['data']['links']));
    }

    public function test_the_starter_footer_is_offered_on_the_nextjs_row_only(): void
    {
        $blade = StorefrontSetting::query()->create(['company_id' => $this->company->getKey(), 'site_type' => StorefrontSetting::SITE_BLADE, 'is_published' => true]);
        $this->actingAsSuperAdmin();

        Livewire::test(EditStorefrontSetting::class, ['record' => $this->nextjs->getKey()])
            ->call('selectSection', 'navigation_seo')
            ->assertSee(__('Load the starter footer'));

        Livewire::test(EditStorefrontSetting::class, ['record' => $blade->getKey()])
            ->call('selectSection', 'navigation_seo')
            ->assertDontSee(__('Load the starter footer'));
    }

    public function test_the_middle_text_link_is_cleaned_and_served(): void
    {
        $this->nextjs->update(['footer_blocks' => [
            ['type' => 'bottom_bar', 'data' => ['center_text' => 'Built by us', 'center_url' => 'javascript:alert(1)']],
        ]]);

        $bar = fn () => collect($this->getJson('/api/v1/storefront/site', ['Authorization' => 'Bearer '.$this->token])->assertOk()->json('data.footer_blocks'))->firstWhere('type', 'bottom_bar')['data'];

        $this->assertNull($bar()['center_url'], 'only paths, http(s), mailto: and tel: links are ever served');

        $this->nextjs->update(['footer_blocks' => [
            ['type' => 'bottom_bar', 'data' => ['center_text' => 'Built by us', 'center_url' => 'https://example.com/']],
        ]]);

        $this->assertSame('https://example.com/', $bar()['center_url']);
    }

    // ------------------------------------------------------------ helpers

    protected function allPages(): void
    {
        foreach (['About Us', 'Contact Us', 'Delivery Information', 'Return, Refund & Exchange', 'Terms and Conditions', 'Privacy Policy'] as $title) {
            $this->page($title);
        }
    }

    protected function page(string $title, ?string $slug = null, ?int $siteId = null, bool $published = true): StorefrontPage
    {
        return StorefrontPage::query()->create([
            'title' => $title, 'slug' => $slug ?? str($title)->slug()->toString(), 'content' => '<p>'.$title.'</p>',
            'is_published' => $published, 'storefront_setting_id' => $siteId,
        ]);
    }

    protected function actingAsSuperAdmin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        app(CompanyContext::class)->set($this->company);
    }
}
