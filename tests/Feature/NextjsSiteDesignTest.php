<?php

namespace Tests\Feature;

use App\Filament\Resources\StorefrontSettings\Pages\EditStorefrontSetting;
use App\Jobs\DispatchWebsiteWebhookJob;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\Product;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Support\StorefrontSiteDesign;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner request 2026-10-08: the Next.js home page, header and footer follow
 * the approved design and every part of it is customised from the dashboard —
 * Home Page Builder, top bar, header, floating help button, extra Footer
 * Builder blocks — on the Next.js row only. The API hands the website cleaned,
 * bounded data (never raw admin input), and the Blade storefront is untouched.
 */
class NextjsSiteDesignTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected string $token;

    protected StorefrontSetting $nextjs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->company = Company::query()->create([
            'name' => 'ZamZam International',
            'slug' => 'zamzam-international',
            'domain' => 'offer.example.test',
            'domain_verified' => true,
            'invoice_prefix' => 'ZAM',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
            'address' => 'House 38, Road 5/A, Uttara, Dhaka',
            'email' => 'office@example.test',
        ])->fresh();
        $this->nextjs = StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(),
            'site_type' => StorefrontSetting::SITE_NEXTJS,
            'is_published' => true,
            'nextjs_site_enabled' => true,
        ]);
        [, $this->token] = CompanyApiKey::generate($this->company);
        app(CompanyContext::class)->set($this->company);
    }

    // ------------------------------------------------------- defaults

    public function test_a_site_with_nothing_saved_gets_the_default_layout_and_design(): void
    {
        $site = $this->site();

        $this->assertSame(
            ['hero', 'trust_strip', 'category_grid', 'deals_banner', 'product_row', 'flash_sale', 'product_row', 'product_row'],
            collect($site->json('data.home_sections'))->pluck('type')->all(),
        );
        $this->assertSame(['latest', 'trending', 'new_arrivals'], collect($site->json('data.home_sections'))->where('type', 'product_row')->pluck('data.source')->values()->all());
        $this->assertSame(12, $site->json('data.home_sections.4.data.limit'));

        $site->assertJsonPath('data.design.topbar.enabled', true)
            ->assertJsonPath('data.design.topbar.show_social', true)
            ->assertJsonPath('data.design.topbar.background', '#0A1B33')
            ->assertJsonPath('data.design.topbar.links', [])
            ->assertJsonPath('data.design.header.show_search', true)
            ->assertJsonPath('data.design.header.show_categories_menu', true)
            ->assertJsonPath('data.design.header.sticky', true)
            ->assertJsonPath('data.design.header.tagline', null)
            ->assertJsonPath('data.design.footer.background', '#09090B')
            ->assertJsonPath('data.design.footer.background_light', '#F4F4F5')
            ->assertJsonPath('data.design.floating_help.enabled', true)
            ->assertJsonPath('data.design.floating_help.url', null);
    }

    public function test_the_default_layout_carries_no_copy_of_its_own(): void
    {
        $sections = collect($this->site()->json('data.home_sections'));

        foreach ($sections as $section) {
            foreach (['title', 'subtitle', 'heading', 'description', 'badge', 'link_label', 'button_label'] as $copy) {
                $this->assertNull($section['data'][$copy] ?? null, "{$section['type']} must not invent a {$copy}");
            }
        }
    }

    // ------------------------------------------------- home page builder

    public function test_saved_sections_are_cleaned_clamped_and_resolved_to_slugs(): void
    {
        $audio = $this->category('Audio');
        $hidden = $this->category('Hidden', active: false);
        StorefrontPage::query()->create(['title' => 'About', 'content' => 'x', 'is_published' => true]);

        $this->nextjs->update(['home_sections' => [
            ['type' => 'hero', 'data' => ['autoplay_seconds' => 999, 'height' => 'weird', 'show_arrows' => '1']],
            ['type' => 'not_a_section', 'data' => ['title' => 'x']],
            ['type' => 'category_grid', 'data' => ['category_ids' => [$audio->getKey(), $hidden->getKey(), 'junk', 9999], 'limit' => 0, 'style' => 'circles', 'title' => "  Shop \n by   category  "]],
            ['type' => 'product_row', 'data' => ['title' => 'Best Audio Picks', 'source' => 'category', 'category_id' => $audio->getKey(), 'limit' => 500, 'columns' => 5]],
            ['type' => 'product_row', 'data' => ['source' => 'category', 'category_id' => $hidden->getKey()]],
            ['type' => 'product_row', 'data' => ['source' => 'nonsense', 'columns' => 9, 'link_url' => 'javascript:alert(1)']],
            ['type' => 'deals_banner', 'data' => ['source' => 'flash_sale', 'color_from' => 'teal', 'color_to' => '#abcdef', 'link_url' => '//evil.test/x']],
            ['type' => 'flash_sale', 'data' => ['enabled' => false]],
        ]]);

        $sections = collect($this->site()->assertOk()->json('data.home_sections'));

        $this->assertSame(['hero', 'category_grid', 'product_row', 'product_row', 'deals_banner'], $sections->pluck('type')->all(), 'unknown, empty-category and switched-off sections are dropped');

        $hero = $sections[0]['data'];
        $this->assertSame(20, $hero['autoplay_seconds']);
        $this->assertSame('standard', $hero['height']);
        $this->assertTrue($hero['show_arrows']);

        $grid = $sections[1]['data'];
        $this->assertSame(['audio'], $grid['category_slugs'], 'ids become slugs; an inactive or unknown category is dropped');
        $this->assertArrayNotHasKey('category_ids', $grid);
        $this->assertSame(1, $grid['limit']);
        $this->assertSame('circles', $grid['style']);
        $this->assertSame('Shop by category', $grid['title']);

        $row = $sections[2]['data'];
        $this->assertSame('audio', $row['category_slug']);
        $this->assertSame(24, $row['limit']);
        $this->assertSame(5, $row['columns']);

        $fallbackRow = $sections[3]['data'];
        $this->assertSame('latest', $fallbackRow['source']);
        $this->assertSame(6, $fallbackRow['columns']);
        $this->assertNull($fallbackRow['link_url'], 'a javascript: link is dropped');

        $deals = $sections[4]['data'];
        $this->assertNull($deals['color_from']);
        $this->assertSame('#ABCDEF', $deals['color_to']);
        $this->assertNull($deals['link_url'], 'a protocol-relative link is dropped');
    }

    public function test_text_banner_and_call_to_action_sections_need_something_to_show(): void
    {
        $this->nextjs->update(['home_sections' => [
            ['type' => 'text', 'data' => []],
            ['type' => 'text', 'data' => ['heading' => 'Wholesale', 'body' => "Line one\nLine two", 'tone' => 'soft', 'align' => 'center']],
            ['type' => 'banners', 'data' => ['items' => []]],
            ['type' => 'mobile_cta', 'data' => ['text' => 'No heading, so hidden']],
            ['type' => 'mobile_cta', 'data' => ['heading' => 'Order on WhatsApp', 'call_label' => 'Call: {phone}']],
            ['type' => 'seo_text', 'data' => ['heading' => 'About']],
        ]]);

        $sections = collect($this->site()->json('data.home_sections'));

        $this->assertSame(['text', 'mobile_cta'], $sections->pluck('type')->all());
        $this->assertSame("Line one\nLine two", $sections[0]['data']['body']);
        $this->assertSame('soft', $sections[0]['data']['tone']);
        $this->assertSame('center', $sections[0]['data']['align']);
        $this->assertSame('Call: {phone}', $sections[1]['data']['call_label']);
        $this->assertSame('#F5F9FC', $sections[1]['data']['background']);
    }

    public function test_banner_images_only_resolve_inside_the_companys_own_folder(): void
    {
        $own = 'companies/'.$this->company->storage_key.'/public/storefront/design/banner.webp';

        $this->nextjs->update(['home_sections' => [
            ['type' => 'banners', 'data' => ['columns' => 9, 'items' => [
                ['image' => $own, 'alt' => 'Eid', 'url' => '/category/audio', 'new_tab' => true],
                ['image' => 'companies/'.Str::uuid().'/public/storefront/design/other.webp', 'alt' => 'Not mine'],
                ['image' => '../../.env'],
            ]]],
        ]]);

        $banners = $this->site()->json('data.home_sections.0.data');

        $this->assertSame(4, $banners['columns']);
        $this->assertCount(1, $banners['items']);
        $this->assertStringEndsWith('/storefront/design/banner.webp', $banners['items'][0]['image_url']);
        $this->assertSame('/category/audio', $banners['items'][0]['url']);
        $this->assertArrayNotHasKey('image', $banners['items'][0]);
    }

    public function test_the_trust_strip_uses_its_own_items_or_falls_back_to_the_site_messages(): void
    {
        $this->nextjs->update([
            'trust_strip_delivery' => 'Fast delivery nationwide',
            'home_sections' => [['type' => 'trust_strip', 'data' => []]],
        ]);

        $this->assertSame(
            [['icon' => 'truck', 'title' => 'Fast delivery nationwide', 'subtitle' => null]],
            $this->site()->json('data.home_sections.0.data.items'),
        );

        $this->nextjs->update(['home_sections' => [['type' => 'trust_strip', 'data' => ['items' => [
            ['icon' => 'wallet', 'title' => 'Easy Payment', 'subtitle' => 'COD · bKash · Nagad'],
            ['icon' => 'not-an-icon', 'title' => 'Quality Check'],
            ['icon' => 'gift', 'title' => '   '],
        ]]]]]);

        $this->assertSame([
            ['icon' => 'wallet', 'title' => 'Easy Payment', 'subtitle' => 'COD · bKash · Nagad'],
            ['icon' => 'shield', 'title' => 'Quality Check', 'subtitle' => null],
        ], $this->site()->json('data.home_sections.0.data.items'));
    }

    public function test_seo_text_tokens_are_filled_from_the_live_catalog(): void
    {
        $audio = $this->category('Audio');
        $this->category('Empty');
        $this->product('Buds', 'B-1', $audio);
        $this->product('Fan', 'F-1', $audio);
        $this->product('Hidden', 'H-1', $audio, ['is_active' => false]);

        $this->nextjs->update(['home_sections' => [['type' => 'seo_text', 'data' => [
            'heading' => '{company_name} wholesale',
            'body' => '{products_count} products across {categories_count} categories. Visit {address}.',
        ]]]]);

        $seo = $this->site()->json('data.home_sections.0.data');

        $this->assertSame('ZamZam International wholesale', $seo['heading']);
        $this->assertSame('2 products across 1 categories. Visit House 38, Road 5/A, Uttara, Dhaka.', $seo['body']);
    }

    // ------------------------------------------------- top bar & header

    public function test_the_top_bar_links_resolve_like_menu_links_and_unsafe_ones_are_dropped(): void
    {
        $audio = $this->category('Audio');
        $page = StorefrontPage::query()->create(['title' => 'Help', 'content' => 'x', 'is_published' => true]);
        $draft = StorefrontPage::query()->create(['title' => 'Draft', 'content' => 'x', 'is_published' => false]);

        $this->nextjs->update(['site_design' => [
            'topbar' => [
                'background' => 'not-a-colour',
                'message' => str_repeat('x', 400),
                'links' => [
                    ['label' => 'Track Order', 'type' => 'track'],
                    ['label' => 'Audio', 'type' => 'category', 'category_id' => $audio->getKey()],
                    ['label' => 'Help', 'type' => 'page', 'page_id' => $page->getKey(), 'new_tab' => true],
                    ['label' => 'Draft', 'type' => 'page', 'page_id' => $draft->getKey()],
                    ['label' => 'App', 'type' => 'custom', 'url' => 'https://play.example.test/app'],
                    ['label' => 'Bad', 'type' => 'custom', 'url' => 'javascript:alert(1)'],
                    ['label' => '', 'type' => 'shop'],
                ],
            ],
            'header' => ['tagline' => 'Gadgets for a smarter life', 'show_wishlist' => '0', 'support_phone' => '01700-000000'],
            'floating_help' => ['enabled' => false, 'title' => 'Need help?', 'url' => 'https://wa.me/8801000000000'],
            'footer' => ['background' => '#111111'],
        ]]);

        $design = $this->site()->json('data.design');

        $this->assertSame('#0A1B33', $design['topbar']['background']);
        $this->assertSame(160, mb_strlen($design['topbar']['message']));
        $this->assertSame(['Track Order', 'Audio', 'Help', 'App'], collect($design['topbar']['links'])->pluck('label')->all());
        $this->assertSame('audio', $design['topbar']['links'][1]['category_slug']);
        $this->assertSame('help', $design['topbar']['links'][2]['page_slug']);
        $this->assertTrue($design['topbar']['links'][2]['new_tab']);
        $this->assertSame('Gadgets for a smarter life', $design['header']['tagline']);
        $this->assertFalse($design['header']['show_wishlist']);
        $this->assertSame('01700-000000', $design['header']['support_phone']);
        $this->assertFalse($design['floating_help']['enabled']);
        $this->assertSame('https://wa.me/8801000000000', $design['floating_help']['url']);
        $this->assertSame('#111111', $design['footer']['background']);
    }

    // ------------------------------------------------------ footer builder

    public function test_the_new_footer_blocks_are_cleaned_and_resolved(): void
    {
        $audio = $this->category('Audio');
        $terms = StorefrontPage::query()->create(['title' => 'Terms and Conditions', 'content' => 'x', 'is_published' => true]);
        $own = 'companies/'.$this->company->storage_key.'/public/storefront/design/license.webp';

        $this->nextjs->update([
            'contact_email' => 'hello@example.test',
            'phone_number' => '+8801325224408',
            'social_links' => [['platform' => 'facebook', 'url' => 'https://facebook.com/zamzam']],
            'footer_blocks' => [
                ['type' => 'brand_about', 'data' => ['text' => 'About us', 'tagline' => 'Gadgets']],
                ['type' => 'custom_links', 'data' => ['heading' => 'Customer Service', 'links' => [
                    ['label' => 'Delivery Information', 'type' => 'page', 'page_id' => $terms->getKey()],
                    ['label' => 'Audio', 'type' => 'category', 'category_id' => $audio->getKey()],
                    ['label' => 'Gone', 'type' => 'page', 'page_id' => 9999],
                    ['label' => 'Evil', 'type' => 'custom', 'url' => 'data:text/html,x'],
                    ['label' => 'Blog', 'type' => 'custom', 'url' => 'https://blog.example.test', 'new_tab' => true],
                ]]],
                ['type' => 'contact_info', 'data' => ['heading' => 'Our Office', 'phones' => "01700000000\n\n01800000000", 'trade_license' => 'Trade License: TRAD/DNCC/048111/2025']],
                ['type' => 'payment_methods', 'data' => ['heading' => 'Safe payments', 'items' => [
                    ['label' => 'bKash', 'tone' => 'pink'],
                    ['label' => 'Cards', 'tone' => 'rainbow'],
                    ['label' => ''],
                ]]],
                ['type' => 'custom_text', 'data' => ['heading' => 'Note', 'text' => 'Cash on delivery all over Bangladesh', 'button_label' => 'Order', 'button_url' => '/products']],
                ['type' => 'image', 'data' => ['heading' => 'Licence', 'image' => $own, 'alt' => 'Trade licence']],
                ['type' => 'image', 'data' => ['image' => 'companies/'.Str::uuid().'/public/storefront/design/other.webp']],
                ['type' => 'mystery', 'data' => []],
                ['type' => 'bottom_bar', 'data' => ['copyright_text' => '© {year}', 'center_text' => 'Developed by Bright Hive Digital', 'right_text' => 'Developed with {heart} for Bangladesh']],
            ],
        ]);

        $blocks = collect($this->site()->assertOk()->json('data.footer_blocks'));

        $this->assertSame(
            ['brand_about', 'custom_links', 'contact_info', 'payment_methods', 'custom_text', 'image', 'bottom_bar'],
            $blocks->pluck('type')->all(),
            'unknown blocks and an image that is not the company\'s own are dropped',
        );

        $links = collect($blocks->firstWhere('type', 'custom_links')['data']['links']);
        $this->assertSame(['Delivery Information', 'Audio', 'Blog'], $links->pluck('label')->all());
        $this->assertSame('terms-and-conditions', $links[0]['page_slug']);
        $this->assertSame('audio', $links[1]['category_slug']);
        $this->assertTrue($links[2]['new_tab']);

        $contact = $blocks->firstWhere('type', 'contact_info')['data'];
        $this->assertSame('House 38, Road 5/A, Uttara, Dhaka', $contact['address'], 'blank fields fall back to the company\'s own details');
        $this->assertSame('hello@example.test', $contact['email']);
        $this->assertSame(['01700000000', '01800000000'], $contact['phones']);
        $this->assertSame('Trade License: TRAD/DNCC/048111/2025', $contact['trade_license']);
        $this->assertNull($contact['hours']);

        $this->assertSame(
            [['label' => 'bKash', 'tone' => 'pink'], ['label' => 'Cards', 'tone' => 'neutral']],
            $blocks->firstWhere('type', 'payment_methods')['data']['items'],
        );
        $this->assertSame('Gadgets', $blocks->firstWhere('type', 'brand_about')['data']['tagline']);
        $this->assertSame('/products', $blocks->firstWhere('type', 'custom_text')['data']['button_url']);
        $this->assertStringEndsWith('/storefront/design/license.webp', $blocks->firstWhere('type', 'image')['data']['image_url']);

        $bar = $blocks->firstWhere('type', 'bottom_bar')['data'];
        $this->assertSame('Developed by Bright Hive Digital', $bar['center_text']);
        $this->assertSame('Developed with {heart} for Bangladesh', $bar['right_text']);
    }

    public function test_the_contact_block_uses_a_phone_when_none_is_typed(): void
    {
        $this->nextjs->update([
            'phone_number' => '+8801325224408',
            'marketplace_helpline' => '16000',
            'footer_blocks' => [['type' => 'contact_info', 'data' => []]],
        ]);

        $this->assertSame(['+8801325224408', '16000'], $this->site()->json('data.footer_blocks.0.data.phones'));
    }

    // ------------------------------------------------------------- catalog

    public function test_categories_carry_their_tagline(): void
    {
        $this->category('Audio')->update(['tagline' => 'Earbuds · TWS · Headsets']);
        $this->category('Fans');

        $categories = collect($this->api('categories')->assertOk()->json('data'))->keyBy('slug');

        $this->assertSame('Earbuds · TWS · Headsets', $categories['audio']['tagline']);
        $this->assertNull($categories['fans']['tagline']);
    }

    public function test_a_card_lists_the_live_collections_its_product_is_in(): void
    {
        $inCollection = $this->product('Fresh', 'N-1', null);
        $this->product('Plain', 'N-2', null);

        $offer = Offer::query()->create([
            'company_id' => $this->company->getKey(),
            'type' => Offer::TYPE_COLLECTION,
            'collection_kind' => Offer::KIND_NEW_ARRIVALS,
            'title' => 'New',
            'status' => Offer::STATUS_PUBLISHED,
        ]);
        OfferItem::query()->create([
            'company_id' => $this->company->getKey(),
            'offer_id' => $offer->getKey(),
            'product_id' => $inCollection->getKey(),
            'quantity' => 1,
            'sort_order' => 0,
        ]);

        $cards = collect($this->api('products')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(['new_arrivals'], $cards['Fresh']['collection_kinds']);
        $this->assertSame([], $cards['Plain']['collection_kinds']);
    }

    public function test_products_can_be_sorted_by_discount_and_filtered_to_those_on_sale(): void
    {
        $this->product('Plain', 'P-1', null, ['price' => 1000, 'sale_price' => null]);
        $this->product('Small', 'P-2', null, ['price' => 1000, 'sale_price' => 900]);
        $this->product('Big', 'P-3', null, ['price' => 1000, 'sale_price' => 500]);
        $this->product('Same', 'P-4', null, ['price' => 1000, 'sale_price' => 1000]);

        $sorted = $this->api('products?sort=discount')->assertOk();
        $this->assertSame(['Big', 'Small'], collect($sorted->json('data'))->take(2)->pluck('name')->all(), 'the biggest percentage off comes first');
        $this->assertSame(4, $sorted->json('meta.total'), 'sorting alone does not hide full-price products');

        $onSale = $this->api('products?sort=discount&on_sale=1')->assertOk();
        $this->assertSame(['Big', 'Small'], collect($onSale->json('data'))->pluck('name')->all());
        $this->assertSame(2, $onSale->json('meta.total'));
    }

    // ------------------------------------------------------ site isolation

    public function test_the_design_belongs_to_the_next_js_row_and_never_leaks_between_companies(): void
    {
        $blade = StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(),
            'site_type' => StorefrontSetting::SITE_BLADE,
            'is_published' => true,
            'home_sections' => [['type' => 'text', 'data' => ['heading' => 'Blade only']]],
        ]);
        $this->nextjs->update(['home_sections' => [['type' => 'text', 'data' => ['heading' => 'Main site']]]]);

        $this->assertSame('Main site', $this->site()->json('data.home_sections.0.data.heading'));
        $this->assertSame('Blade only', $blade->homeSections()[0]['data']['heading']);

        $other = Company::query()->create(['name' => 'Other', 'slug' => 'other', 'domain' => 'other.example.test', 'domain_verified' => true, 'invoice_prefix' => 'OTH', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        StorefrontSetting::query()->create(['company_id' => $other->getKey(), 'site_type' => StorefrontSetting::SITE_NEXTJS, 'is_published' => true, 'nextjs_site_enabled' => true]);
        [, $otherToken] = CompanyApiKey::generate($other);
        app(CompanyContext::class)->set($other);

        $this->assertCount(8, $this->site($otherToken)->json('data.home_sections'), 'another company still has the default layout');
        $this->assertNotContains('Main site', collect($this->site($otherToken)->json('data.home_sections'))->pluck('data.heading')->all());
    }

    // ----------------------------------------------------------- admin form

    public function test_the_next_js_row_opens_with_the_layout_the_site_really_uses_and_saves_changes(): void
    {
        $this->actingAsSuperAdmin();

        $component = Livewire::test(EditStorefrontSetting::class, ['record' => $this->nextjs->getKey()])
            ->call('selectSection', 'nextjs_design')
            ->assertSee(__('Home Page Builder'))
            ->assertSee(__('Top Bar'))
            ->assertSee(__('Floating Help Button'));

        $sections = array_values($component->get('data.home_sections'));
        $this->assertSame(
            ['hero', 'trust_strip', 'category_grid', 'deals_banner', 'product_row', 'flash_sale', 'product_row', 'product_row'],
            array_column($sections, 'type'),
        );
        $this->assertTrue($sections[0]['data']['show_dots'], 'a switch opens at its effective value, not blank');
        $this->assertTrue($component->get('data.site_design.header.show_search'));
        $this->assertSame('#0A1B33', $component->get('data.site_design.topbar.background'));

        $component
            ->set('data.site_design.topbar.message', 'Free delivery over ৳5,000')
            ->set('data.site_design.header.tagline', 'Gadgets for a smarter life')
            ->set('data.site_design.header.show_wishlist', false)
            ->set('data.site_design.floating_help.title', 'Need help?')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->nextjs->refresh();
        $this->assertSame('Free delivery over ৳5,000', $this->nextjs->site_design['topbar']['message']);
        $this->assertFalse($this->nextjs->siteDesign()['header']['show_wishlist']);
        $this->assertSame('Gadgets for a smarter life', $this->nextjs->siteDesign()['header']['tagline']);
        $this->assertSame(8, count($this->nextjs->home_sections), 'the builder\'s layout is saved as arranged');
    }

    public function test_the_footer_builder_offers_the_custom_blocks_and_saves_them(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(EditStorefrontSetting::class, ['record' => $this->nextjs->getKey()])
            ->call('selectSection', 'navigation_seo')
            ->assertSee(__('Custom links column'))
            ->assertSee(__('Payment badges'))
            ->assertSee(__('Footer background colour (dark mode)'))
            ->assertSee(__('Footer background colour (light mode)'))
            ->set('data.site_design.footer.background', '#101010')
            ->set('data.site_design.footer.background_light', '#EEF2FF')
            ->set('data.footer_blocks', [
                ['type' => 'payment_methods', 'data' => ['heading' => 'Safe payments', 'items' => [['label' => 'bKash', 'tone' => 'pink']]]],
                ['type' => 'bottom_bar', 'data' => ['center_text' => 'Built by us']],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->nextjs->refresh();
        $this->assertSame('#101010', $this->nextjs->siteDesign()['footer']['background']);
        $this->assertSame('#EEF2FF', $this->nextjs->siteDesign()['footer']['background_light']);
        $this->assertSame('payment_methods', $this->nextjs->footer_blocks[0]['type']);
        $this->assertSame('Built by us', $this->nextjs->footer_blocks[1]['data']['center_text']);
    }

    public function test_the_blade_row_has_no_website_design_tab_and_never_stores_one(): void
    {
        $blade = StorefrontSetting::query()->create(['company_id' => $this->company->getKey(), 'site_type' => StorefrontSetting::SITE_BLADE, 'is_published' => true]);
        $this->actingAsSuperAdmin();

        Livewire::test(EditStorefrontSetting::class, ['record' => $blade->getKey()])
            ->assertDontSee(__('Website Design'))
            ->call('selectSection', 'nextjs_design')
            ->assertSet('activeSection', 'publishing')
            ->set('data.whatsapp_number', '01711111111')
            ->call('save')
            ->assertHasNoFormErrors();

        $blade->refresh();
        $this->assertNull($blade->home_sections);
        $this->assertNull($blade->site_design);
    }

    // ------------------------------------------------------- blade footer

    public function test_the_blade_storefront_renders_the_new_footer_blocks_too(): void
    {
        $audio = $this->category('Audio');
        StorefrontPage::query()->create(['title' => 'Delivery Information', 'content' => 'x', 'is_published' => true]);
        $blade = StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(),
            'site_type' => StorefrontSetting::SITE_BLADE,
            'is_published' => true,
            'footer_blocks' => [
                ['type' => 'custom_links', 'data' => ['heading' => 'Customer Service', 'links' => [
                    ['label' => 'Delivery Information', 'type' => 'page', 'page_id' => StorefrontPage::query()->value('id')],
                    ['label' => 'Audio picks', 'type' => 'category', 'category_id' => $audio->getKey()],
                    ['label' => 'Evil', 'type' => 'custom', 'url' => 'javascript:alert(1)'],
                ]]],
                ['type' => 'payment_methods', 'data' => ['heading' => 'Safe payments', 'items' => [['label' => 'bKash', 'tone' => 'pink']]]],
                ['type' => 'custom_text', 'data' => ['heading' => 'Heads up', 'text' => "Line one\n<script>x</script>"]],
                ['type' => 'contact_info', 'data' => ['address' => 'Uttara, Dhaka', 'phones' => "01700000000\n01800000000", 'trade_license' => 'TL-123']],
                ['type' => 'bottom_bar', 'data' => ['center_text' => 'Developed by Bright Hive Digital', 'right_text' => 'Made with {heart} <b>x</b>']],
            ],
        ]);

        $html = $this->get('http://offer.example.test/')->assertOk()->getContent();

        $this->assertStringContainsString('Customer Service', $html);
        $this->assertStringContainsString('Delivery Information', $html);
        $this->assertStringContainsString('Audio picks', $html);
        $this->assertStringNotContainsString('javascript:alert(1)', $html);
        $this->assertStringContainsString('Safe payments', $html);
        $this->assertStringContainsString('bKash', $html);
        $this->assertStringContainsString('Heads up', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('Uttara, Dhaka', $html);
        $this->assertStringContainsString('tel:01800000000', $html);
        $this->assertStringContainsString('TL-123', $html);
        $this->assertStringContainsString('Developed by Bright Hive Digital', $html);
        $this->assertStringContainsString('Made with', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertSame($blade->getKey(), StorefrontSetting::bladeFor($this->company->getKey())->getKey());
    }

    // ------------------------------------------------------- website refresh

    public function test_a_storefront_change_tells_the_website_once_and_a_moment_later(): void
    {
        Queue::fake();
        $this->nextjs->update(['website_api_credentials' => ['webhook_url' => 'https://site.example.test/api/erp/webhook', 'webhook_secret' => 'secret', 'is_enabled' => true]]);
        Cache::flush();

        $this->nextjs->update(['home_sections' => [['type' => 'text', 'data' => ['heading' => 'One']]]]);
        $this->category('Audio');
        StorefrontPage::query()->create(['title' => 'Help', 'content' => 'x', 'is_published' => true]);

        Queue::assertPushed(DispatchWebsiteWebhookJob::class, 1);
        Queue::assertPushed(DispatchWebsiteWebhookJob::class, fn (DispatchWebsiteWebhookJob $job): bool => $job->event === 'storefront.updated'
            && $job->url === 'https://site.example.test/api/erp/webhook'
            && $job->delay !== null);

        // After the quiet period a new change is announced again.
        Cache::flush();
        $this->category('Fans');
        Queue::assertPushed(DispatchWebsiteWebhookJob::class, 2);
    }

    public function test_nothing_is_sent_without_a_website_webhook(): void
    {
        Queue::fake();
        Cache::flush();

        $this->category('Audio');

        Queue::assertNotPushed(DispatchWebsiteWebhookJob::class);
    }

    // ------------------------------------------------------------- pure

    public function test_the_default_sections_are_all_known_types(): void
    {
        foreach (StorefrontSiteDesign::DEFAULT_HOME_SECTIONS as $section) {
            $this->assertArrayHasKey($section['type'], StorefrontSiteDesign::HOME_SECTION_TYPES);
        }

        $this->assertCount(count(StorefrontSiteDesign::DEFAULT_HOME_SECTIONS), StorefrontSiteDesign::homeSections(null));
        $this->assertCount(count(StorefrontSiteDesign::DEFAULT_HOME_SECTIONS), StorefrontSiteDesign::homeSections([]), 'an emptied builder falls back to the default layout');
    }

    public function test_urls_must_be_a_path_http_mailto_or_tel(): void
    {
        foreach (['/products', '/category/audio?x=1', 'https://example.test/a', 'http://example.test', 'mailto:a@b.test', 'tel:+8801700000000', '#top'] as $ok) {
            $this->assertSame($ok, StorefrontSiteDesign::url($ok), $ok);
        }

        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', '//evil.test', 'ftp://x', '/a b', "/a\nb", '', '   ', null, 42, str_repeat('/a', 300)] as $bad) {
            $this->assertNull(StorefrontSiteDesign::url($bad), var_export($bad, true));
        }
    }

    // ----------------------------------------------------------- helpers

    protected function site(?string $token = null): TestResponse
    {
        return $this->api('site', $token);
    }

    protected function api(string $path, ?string $token = null): TestResponse
    {
        return $this->getJson('/api/v1/storefront/'.$path, ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    protected function category(string $name, bool $active = true): Category
    {
        return Category::query()->create(['name' => $name, 'slug' => str($name)->slug()->toString(), 'is_active' => $active]);
    }

    protected function product(string $name, string $sku, ?Category $category, array $attributes = []): Product
    {
        return Product::query()->create($attributes + [
            'name' => $name,
            'sku' => $sku,
            'category_id' => $category?->getKey(),
            'price' => 500,
            'sale_price' => 500,
            'cost_price' => 300,
            'stock' => 20,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);
    }

    protected function actingAsSuperAdmin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        app(CompanyContext::class)->set($this->company);
    }
}
