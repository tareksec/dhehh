<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use App\Models\StorefrontSlide;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * /api/v1/storefront/* — the read-only API the company's headless Next.js
 * site calls (server-side, with the company API key). Covers the gate (key +
 * the "Enable Next.js site API" switch), company isolation, the Blade
 * storefront's visibility rules, and the wholesale / collection fields.
 */
class StorefrontApiTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->company, $this->token] = $this->makeCompany('ZamZam International', 'zamzam-international', 'offer.example.test');
        app(CompanyContext::class)->set($this->company);
    }

    // ------------------------------------------------------------ the gate

    public function test_a_missing_or_wrong_key_is_rejected_before_anything_is_read(): void
    {
        $this->getJson('/api/v1/storefront/site')
            ->assertStatus(401)
            ->assertJsonPath('error', 'missing_api_key');

        $this->getJson('/api/v1/storefront/site', ['Authorization' => 'Bearer not-a-real-key'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'invalid_api_key');
    }

    public function test_site_branding_comes_from_the_next_js_site_row_with_a_favicon(): void
    {
        $this->company->forceFill(['logo' => 'company-logo.png'])->save();
        StorefrontSetting::query()->where('company_id', $this->company->getKey())->update([
            'logo' => 'site-logo.png',
            'logo_dark' => 'site-logo-dark.png',
            'favicon' => 'site-favicon.png',
        ]);

        $branding = $this->api('site')->assertOk()->json('data.branding');

        $this->assertStringContainsString('site-logo.png', (string) $branding['logo_url']);
        $this->assertStringContainsString('site-logo-dark.png', (string) $branding['logo_dark_url']);
        $this->assertStringContainsString('site-favicon.png', (string) $branding['favicon_url']);
        $this->assertStringNotContainsString('company-logo.png', json_encode($branding));
    }

    public function test_the_api_answers_403_until_the_owner_enables_the_next_js_site(): void
    {
        [, $disabledToken] = $this->makeCompany('Retail Co', 'retail-co', 'retail.example.test', enabled: false);

        $this->api('site', $disabledToken)
            ->assertStatus(403)
            ->assertJsonPath('error', 'nextjs_site_disabled');

        // The owner's kill switch also works on a company that was enabled.
        StorefrontSetting::query()->where('company_id', $this->company->getKey())->update(['nextjs_site_enabled' => false]);
        $this->api('categories')->assertStatus(403)->assertJsonPath('error', 'nextjs_site_disabled');
    }

    public function test_the_existing_server_to_server_api_is_not_gated_by_the_next_js_switch(): void
    {
        [, $token] = $this->makeCompany('Gated Co', 'gated-co', 'gated.example.test', enabled: false);

        $this->getJson('/api/v1/products', ['Authorization' => 'Bearer '.$token])->assertOk();
    }

    public function test_requests_are_rate_limited_per_key_not_per_ip(): void
    {
        for ($i = 0; $i < 600; $i++) {
            $this->api('categories')->assertOk();
        }

        $this->api('categories')->assertStatus(429);

        // Another company's key (same IP: it is one Next.js server) is unaffected.
        [, $otherToken] = $this->makeCompany('Other Co', 'other-co', 'other.example.test');
        $this->api('categories', $otherToken)->assertOk();
    }

    // ------------------------------------------------------- site settings

    public function test_the_site_config_comes_from_the_same_storefront_settings_as_the_blade_site(): void
    {
        $visibleCategory = $this->category('Fans');
        $hiddenCategory = $this->category('Hidden', active: false);
        $page = StorefrontPage::query()->create(['title' => 'Privacy Policy', 'content' => 'x', 'is_published' => true]);
        $draftPage = StorefrontPage::query()->create(['title' => 'Draft Page', 'content' => 'x', 'is_published' => false]);

        StorefrontSetting::query()->where('company_id', $this->company->getKey())->first()->update([
            'theme_color' => '#112233',
            'theme_mode' => 'dark',
            'whatsapp_number' => '8801678413888',
            'phone_number' => '09613000000',
            'contact_email' => 'hello@example.test',
            'hero_heading' => 'Wholesale gifts',
            'social_links' => [['platform' => 'facebook', 'url' => 'https://facebook.com/zamzam'], ['platform' => 'x', 'url' => '']],
            'marketplace_business_accounts_enabled' => true,
            'marketplace_bulk_pricing_rows' => [['label' => '50+ pcs', 'value' => '5% off'], ['label' => '', 'value' => 'ignored']],
            'offer_title' => 'Eid Offer',
            'offer_discount_percent' => 20,
            'offer_ends_at' => now()->addDay(),
            'nextjs_site_domain' => 'zamzamint.com',
            'header_menu' => [
                ['label' => 'Shop', 'type' => 'shop'],
                ['label' => 'Fans', 'type' => 'category', 'category_id' => $visibleCategory->getKey()],
                ['label' => 'Gone', 'type' => 'category', 'category_id' => $hiddenCategory->getKey()],
                ['label' => 'Privacy', 'type' => 'page', 'page_id' => $page->getKey()],
                ['label' => 'Draft', 'type' => 'page', 'page_id' => $draftPage->getKey()],
                ['label' => 'Blog', 'type' => 'custom', 'url' => 'https://blog.example.test', 'new_tab' => true],
            ],
            'footer_blocks' => [
                ['type' => 'bottom_bar', 'data' => ['copyright_text' => '© ZamZam', 'legal_links' => [['page_id' => $page->getKey()], ['page_id' => $draftPage->getKey()]]]],
            ],
        ]);

        $response = $this->api('site')->assertOk();

        $response->assertJsonPath('data.company.name', 'ZamZam International')
            ->assertJsonPath('data.company.currency', 'BDT')
            ->assertJsonPath('data.sites.blade_domain', 'offer.example.test')
            ->assertJsonPath('data.sites.nextjs_domain', 'zamzamint.com')
            ->assertJsonPath('data.theme.mode', 'dark')
            ->assertJsonPath('data.theme.colors.primary', '#112233')
            ->assertJsonPath('data.contact.whatsapp_number', '8801678413888')
            ->assertJsonPath('data.contact.email', 'hello@example.test')
            ->assertJsonPath('data.hero.heading', 'Wholesale gifts')
            ->assertJsonPath('data.business.accounts_enabled', true)
            ->assertJsonPath('data.offer_countdown.title', 'Eid Offer')
            ->assertJsonPath('data.offer_countdown.discount_percent', 20);

        $this->assertSame([['platform' => 'facebook', 'url' => 'https://facebook.com/zamzam']], $response->json('data.social_links'));
        $this->assertSame([['label' => '50+ pcs', 'value' => '5% off']], $response->json('data.business.bulk_pricing_rows'));

        // Menu ids become slugs; a category/page that is inactive or unpublished is dropped.
        $menu = collect($response->json('data.menus.header'));
        $this->assertSame(['Shop', 'Fans', 'Privacy', 'Blog'], $menu->pluck('label')->all());
        $this->assertSame('fans', $menu->firstWhere('label', 'Fans')['category_slug']);
        $this->assertSame('privacy-policy', $menu->firstWhere('label', 'Privacy')['page_slug']);
        $this->assertSame('https://blog.example.test', $menu->firstWhere('label', 'Blog')['url']);
        $this->assertTrue($menu->firstWhere('label', 'Blog')['new_tab']);

        $legal = $response->json('data.footer_blocks.0.data.legal_links');
        $this->assertSame([['label' => 'Privacy Policy', 'page_slug' => 'privacy-policy']], $legal);
    }

    public function test_an_ended_offer_countdown_is_not_reported(): void
    {
        StorefrontSetting::query()->where('company_id', $this->company->getKey())->first()
            ->update(['offer_title' => 'Old', 'offer_ends_at' => now()->subHour()]);

        $this->api('site')->assertOk()->assertJsonPath('data.offer_countdown', null);
    }

    // --------------------------------------------------- company isolation

    public function test_a_key_only_ever_sees_its_own_companys_catalog(): void
    {
        $this->product('Mine', 'MINE-1');
        $this->category('My Category');

        [$other, $otherToken] = $this->makeCompany('Other Co', 'other-co', 'other.example.test');
        app(CompanyContext::class)->set($other);
        $this->product('Theirs', 'THEIRS-1');
        $this->category('Their Category');
        StorefrontPage::query()->create(['title' => 'Their Page', 'content' => 'x', 'is_published' => true]);
        app(CompanyContext::class)->clear();

        $mine = $this->api('products')->assertOk();
        $this->assertSame(['MINE-1'], collect($mine->json('data'))->pluck('sku')->all());
        $this->assertSame(['my-category'], collect($this->api('categories')->json('data'))->pluck('slug')->all());
        $this->assertSame([], $this->api('pages')->json('data'));
        $this->api('products/theirs')->assertStatus(404);

        $theirs = $this->api('products', $otherToken)->assertOk();
        $this->assertSame(['THEIRS-1'], collect($theirs->json('data'))->pluck('sku')->all());
        $this->api('products/mine', $otherToken)->assertStatus(404);
    }

    // ------------------------------------------------------------ products

    public function test_only_active_available_products_are_listed(): void
    {
        $this->product('Visible', 'VIS-1');
        $this->product('Inactive', 'INA-1', ['is_active' => false]);
        $this->product('Coming Soon', 'SOON-1', ['status' => Product::STATUS_COMING_SOON]);

        $this->assertSame(['VIS-1'], collect($this->api('products')->assertOk()->json('data'))->pluck('sku')->all());
        $this->api('products/inactive')->assertStatus(404)->assertJsonPath('error', 'product_not_found');
        $this->api('products/coming-soon')->assertStatus(404);
    }

    public function test_listing_filters_search_sorting_and_pagination(): void
    {
        $fans = $this->category('Fans');
        $audio = $this->category('Audio');
        $this->product('Cheap Fan', 'F-1', ['price' => 300, 'sale_price' => null, 'category_id' => $fans->getKey()]);
        $this->product('Pricey Fan', 'F-2', ['price' => 900, 'sale_price' => 700, 'category_id' => $fans->getKey()]);
        $this->product('Earbuds', 'A-1', ['price' => 500, 'sale_price' => 500, 'category_id' => $audio->getKey(), 'brand' => 'Sonic']);

        $skus = fn ($response) => collect($response->json('data'))->pluck('sku')->all();

        $this->assertSame(['F-1', 'F-2'], $skus($this->api('products?category=fans&sort=price_asc')));
        $this->assertSame(['F-2', 'F-1'], $skus($this->api('products?category=fans&sort=price_desc')));
        $this->assertSame(['A-1'], $skus($this->api('products?q=sonic')), 'search also matches the brand');
        $this->assertSame(['A-1'], $skus($this->api('products?q=A-1')), 'search also matches the SKU');
        $this->api('products?category=nope')->assertStatus(404)->assertJsonPath('error', 'category_not_found');

        $page = $this->api('products?per_page=2&sort=price_asc')->assertOk();
        $this->assertSame(['F-1', 'A-1'], $skus($page));
        $page->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.per_page', 2);
        $this->assertSame(['F-2'], $skus($this->api('products?per_page=2&sort=price_asc&page=2')));

        // per_page is clamped, never trusted.
        $this->api('products?per_page=100000')->assertOk()->assertJsonPath('meta.per_page', 48);
    }

    public function test_slugs_return_fresh_cards_in_the_requested_order_for_cart_and_wishlist_refresh(): void
    {
        $this->product('Alpha', 'S-1');
        $this->product('Beta', 'S-2');
        $this->product('Gamma', 'S-3', ['is_active' => false]);

        $response = $this->api('products?slugs=beta,gamma,alpha,unknown')->assertOk();

        $this->assertSame(['beta', 'alpha'], collect($response->json('data'))->pluck('slug')->all());
    }

    public function test_a_card_carries_price_stock_discount_wholesale_and_rating_data(): void
    {
        $category = $this->category('Gifts');
        $product = $this->product('Gift Box', 'GB-1', [
            'price' => 1000,
            'sale_price' => 800,
            'stock' => 3,
            'reorder_level' => 5,
            'moq' => 12,
            'tier_prices' => [['min_qty' => 50, 'price' => 700], ['min_qty' => 12, 'price' => 750], ['min_qty' => 0, 'price' => 1]],
            'brand' => 'ZamZam',
            'category_id' => $category->getKey(),
            'image' => 'https://cdn.example.test/gift.jpg',
        ]);
        $this->review($product, 5);
        $this->review($product, 4);
        $this->review($product, 1, ProductReview::STATUS_PENDING);
        $this->review($product, 1, ProductReview::STATUS_REJECTED);

        $card = $this->api('products')->assertOk()->json('data.0');

        $this->assertSame('gift-box', $card['slug']);
        $this->assertEquals(1000, $card['price']);
        $this->assertEquals(800, $card['sale_price']);
        $this->assertEquals(800, $card['selling_price']);
        $this->assertTrue($card['has_discount']);
        $this->assertSame(20, $card['discount_percent']);
        $this->assertSame('https://cdn.example.test/gift.jpg', $card['image_url']);
        $this->assertSame(['id' => $category->getKey(), 'name' => 'Gifts', 'slug' => 'gifts'], $card['category']);
        // Wholesale: MOQ + valid tiers sorted by quantity; the malformed tier is dropped.
        $this->assertSame(12, $card['moq']);
        $this->assertEquals([['min_qty' => 12, 'price' => 750], ['min_qty' => 50, 'price' => 700]], $card['tier_prices']);
        // Stock uses the ERP's own low-stock rule (stock <= reorder level).
        $this->assertSame(3, $card['stock']);
        $this->assertSame('low_stock', $card['stock_status']);
        $this->assertTrue($card['orderable']);
        // Only approved reviews count.
        $this->assertSame(['average' => 4.5, 'count' => 2], $card['rating']);
        $this->assertNull($card['deal']);
    }

    public function test_stock_status_distinguishes_in_stock_out_of_stock_and_preorder(): void
    {
        $this->product('Plenty', 'ST-1', ['stock' => 50, 'reorder_level' => 5]);
        $this->product('Empty', 'ST-2', ['stock' => 0]);
        $this->product('Preorder', 'ST-3', ['stock' => 0, 'is_preorder' => true]);

        $cards = collect($this->api('products?slugs=plenty,empty,preorder')->json('data'))->keyBy('slug');

        $this->assertSame('in_stock', $cards['plenty']['stock_status']);
        $this->assertSame('out_of_stock', $cards['empty']['stock_status']);
        $this->assertFalse($cards['empty']['orderable']);
        $this->assertSame('preorder', $cards['preorder']['stock_status']);
        $this->assertTrue($cards['preorder']['orderable']);
    }

    public function test_the_detail_has_gallery_variants_reviews_related_and_safe_description_html(): void
    {
        Storage::fake('public');
        $category = $this->category('Gifts');
        $path = 'companies/'.$this->company->storage_key.'/public/products/main.jpg';
        $foreignPath = 'companies/'.Str::uuid().'/public/products/stolen.jpg';
        Storage::disk('public')->put($path, 'img');

        $product = $this->product('Gift Box', 'GB-1', [
            'category_id' => $category->getKey(),
            'image' => $path,
            'gallery_images' => ['https://cdn.example.test/second.jpg', $foreignPath],
            'description' => '<p>Nice <strong>box</strong></p><script>alert(1)</script>',
            'has_variants' => true,
            'moq' => 4,
            'stock' => 0,
        ]);
        ProductVariant::query()->create(['product_id' => $product->getKey(), 'sku' => 'GB-1-M', 'options' => ['Size' => 'M'], 'sale_price' => 450, 'stock' => 7, 'is_active' => true]);
        ProductVariant::query()->create(['product_id' => $product->getKey(), 'sku' => 'GB-1-L', 'options' => ['Size' => 'L'], 'sale_price' => 480, 'stock' => 9, 'is_active' => false]);
        $this->review($product, 5, ProductReview::STATUS_APPROVED, 'Great');
        $this->review($product, 1, ProductReview::STATUS_PENDING, 'Not shown');
        $this->product('Same Category', 'GB-2', ['category_id' => $category->getKey()]);
        $this->product('Other Category', 'GB-3');

        $detail = $this->api('products/gift-box')->assertOk()->json('data');

        $this->assertStringStartsWith('http://', $detail['gallery_urls'][0], 'a local storage image becomes an absolute URL on the ERP host');
        $this->assertStringContainsString($path, $detail['gallery_urls'][0]);
        $this->assertSame('https://cdn.example.test/second.jpg', $detail['gallery_urls'][1]);
        $this->assertCount(2, $detail['gallery_urls'], "another company's storage path is never turned into a URL");
        $this->assertStringContainsString('<strong>box</strong>', $detail['description_html']);
        $this->assertStringNotContainsString('<script', $detail['description_html']);
        $this->assertStringStartsWith('Nice box', $detail['description_text']);

        $this->assertCount(1, $detail['variants'], 'only active variants');
        $this->assertSame('GB-1-M', $detail['variants'][0]['sku']);
        $this->assertSame(['Size' => 'M'], $detail['variants'][0]['options']);
        $this->assertSame('Size: M', $detail['variants'][0]['label']);
        $this->assertEquals(450, $detail['variants'][0]['price']);
        $this->assertSame(7, $detail['variants'][0]['stock']);
        $this->assertTrue($detail['orderable'], 'variant stock makes the parent product orderable');

        $this->assertSame(4, $detail['min_order_quantity']);
        $this->assertSame(7, $detail['max_order_quantity']);

        $this->assertCount(1, $detail['reviews']);
        $this->assertSame('Great', $detail['reviews'][0]['comment']);
        $this->assertArrayNotHasKey('customer_id', $detail['reviews'][0]);
        $this->assertArrayNotHasKey('order_id', $detail['reviews'][0]);

        $this->assertSame(['GB-2'], collect($detail['related'])->pluck('sku')->all());
        $this->assertSame('Gift Box', $detail['seo']['title']);
    }

    public function test_plain_text_descriptions_become_escaped_paragraphs(): void
    {
        $this->product('Text', 'PL-1', ['description' => "First line\nSecond & line"]);

        $detail = $this->api('products/text')->json('data');

        $this->assertSame("<p>First line</p>\n<p>Second &amp; line</p>", $detail['description_html']);
    }

    public function test_a_preorder_product_reports_its_advance_and_a_high_order_ceiling(): void
    {
        $this->product('Coming Gadget', 'PO-1', ['is_preorder' => true, 'preorder_advance_percent' => 30, 'stock' => 2]);

        $detail = $this->api('products/coming-gadget')->json('data');

        $this->assertSame(['advance_percent' => 30], $detail['preorder']);
        $this->assertSame(100000, $detail['max_order_quantity']);
    }

    // ---------------------------------------------------------- categories

    public function test_categories_list_active_ones_with_their_visible_product_counts(): void
    {
        $fans = $this->category('Fans');
        $this->category('Hidden', active: false);
        $this->category('Empty');
        $this->product('Fan 1', 'C-1', ['category_id' => $fans->getKey()]);
        $this->product('Fan 2', 'C-2', ['category_id' => $fans->getKey()]);
        $this->product('Fan 3', 'C-3', ['category_id' => $fans->getKey(), 'is_active' => false]);

        $categories = collect($this->api('categories')->assertOk()->json('data'))->keyBy('slug');

        $this->assertSame(['empty', 'fans'], $categories->keys()->sort()->values()->all());
        $this->assertSame(2, $categories['fans']['products_count']);
        $this->assertSame(0, $categories['empty']['products_count']);
    }

    // --------------------------------------------------------- collections

    public function test_live_collections_are_returned_in_kind_order_with_their_products_in_admin_order(): void
    {
        $first = $this->product('First', 'K-1');
        $second = $this->product('Second', 'K-2');
        $hidden = $this->product('Hidden', 'K-3', ['is_active' => false]);

        $this->collection('New things', Offer::KIND_NEW_ARRIVALS, [$second]);
        $this->collection('Flash!', Offer::KIND_FLASH_SALE, [$second, $hidden, $first], ['ends_at' => now()->addHours(5)]);
        $this->collection('Trending now', Offer::KIND_TRENDING, [$first]);
        $this->collection('Draft one', Offer::KIND_TRENDING, [$first], ['status' => Offer::STATUS_DRAFT]);
        $this->collection('Ended flash', Offer::KIND_FLASH_SALE, [$first], ['ends_at' => now()->subMinute()]);
        $this->collection('Only hidden', Offer::KIND_TRENDING, [$hidden]);

        $collections = $this->api('collections')->assertOk()->json('data');

        $this->assertSame(['Flash!', 'Trending now', 'New things'], collect($collections)->pluck('title')->all());
        $this->assertSame(['flash-sale', 'trending', 'new-arrivals'], collect($collections)->pluck('kind_slug')->all());
        $this->assertSame(['K-2', 'K-1'], collect($collections[0]['products'])->pluck('sku')->all(), 'admin order; hidden product dropped');
        $this->assertNotNull($collections[0]['ends_at']);

        $only = $this->api('collections?kind=trending')->json('data');
        $this->assertSame(['Trending now'], collect($only)->pluck('title')->all());
        $this->api('collections?kind=weird')->assertStatus(422)->assertJsonPath('error', 'invalid_kind');
    }

    public function test_a_live_deal_price_is_attached_to_the_product_card_and_flash_sale_wins(): void
    {
        $product = $this->product('Deal Item', 'D-1', ['price' => 1000, 'sale_price' => null]);
        $plain = $this->product('Plain Item', 'D-2');

        $trending = $this->collection('Trending', Offer::KIND_TRENDING, [$product]);
        $trending->items()->update(['deal_price' => 900]);
        $flash = $this->collection('Flash', Offer::KIND_FLASH_SALE, [$product], ['ends_at' => now()->addDay()]);
        $flash->items()->update(['deal_price' => 750]);
        $this->collection('Ended', Offer::KIND_FLASH_SALE, [$plain], ['ends_at' => now()->subDay()])->items()->update(['deal_price' => 1]);

        $cards = collect($this->api('products?slugs=deal-item,plain-item')->json('data'))->keyBy('slug');

        $this->assertEquals(750, $cards['deal-item']['deal']['price']);
        $this->assertSame(Offer::KIND_FLASH_SALE, $cards['deal-item']['deal']['kind']);
        $this->assertNotNull($cards['deal-item']['deal']['ends_at']);
        $this->assertEquals(1000, $cards['deal-item']['selling_price'], 'the ERP selling price itself is never rewritten');
        $this->assertNull($cards['plain-item']['deal'], 'an ended collection gives no deal');
    }

    public function test_a_deal_is_reported_only_when_it_really_lowers_the_price_and_never_for_variations(): void
    {
        $dearDeal = $this->product('Dear Deal', 'DD-1', ['price' => 600, 'sale_price' => 500]);
        $equalDeal = $this->product('Equal Deal', 'DD-2', ['price' => 600, 'sale_price' => 500]);
        $variable = $this->product('Variable Deal', 'DD-3', ['has_variants' => true]);

        $collection = $this->collection('Deals', Offer::KIND_TRENDING, [$dearDeal, $equalDeal, $variable]);
        $collection->items()->where('product_id', $dearDeal->getKey())->update(['deal_price' => 800]);
        $collection->items()->where('product_id', $equalDeal->getKey())->update(['deal_price' => 500]);
        $collection->items()->where('product_id', $variable->getKey())->update(['deal_price' => 100]);

        $cards = collect($this->api('products?slugs=dear-deal,equal-deal,variable-deal')->json('data'))->keyBy('slug');

        // A "deal" above or equal to the normal price is no deal (the checkout would never charge it).
        $this->assertNull($cards['dear-deal']['deal']);
        $this->assertNull($cards['equal-deal']['deal']);
        // Variations keep their own price, so a product-level deal does not apply to them.
        $this->assertNull($cards['variable-deal']['deal']);
    }

    public function test_the_collection_filter_lists_only_products_of_a_live_collection_of_that_kind(): void
    {
        $inFlash = $this->product('In Flash', 'FL-1');
        $inTrending = $this->product('In Trending', 'FL-2');
        $this->product('In Neither', 'FL-3');
        $this->collection('Flash', Offer::KIND_FLASH_SALE, [$inFlash], ['ends_at' => now()->addDay()]);
        $this->collection('Trending', Offer::KIND_TRENDING, [$inTrending]);

        $this->assertSame(['FL-1'], collect($this->api('products?collection=flash-sale')->json('data'))->pluck('sku')->all());
        $this->assertSame(['FL-2'], collect($this->api('products?collection=trending')->json('data'))->pluck('sku')->all());
        $this->assertSame([], $this->api('products?collection=new-arrivals')->json('data'));
        $this->api('products?collection=bogus')->assertStatus(422)->assertJsonPath('error', 'invalid_collection');
    }

    // ------------------------------------------------------ slides / pages

    public function test_slides_follow_the_blade_rules_and_link_to_visible_products(): void
    {
        $product = $this->product('Slide Product', 'SL-1');
        $hiddenProduct = $this->product('Hidden Slide Product', 'SL-2', ['is_active' => false]);

        StorefrontSlide::query()->create(['image' => 'https://cdn.example.test/a.jpg', 'image_mobile' => 'https://cdn.example.test/a-m.jpg', 'heading' => 'First', 'cta_label' => 'Shop', 'cta_url' => '/products', 'product_id' => $product->getKey(), 'sort_order' => 1, 'is_active' => true]);
        StorefrontSlide::query()->create(['image' => 'https://cdn.example.test/b.jpg', 'heading' => 'Second', 'product_id' => $hiddenProduct->getKey(), 'sort_order' => 2, 'is_active' => true]);
        StorefrontSlide::query()->create(['image' => 'https://cdn.example.test/c.jpg', 'heading' => 'Off', 'sort_order' => 3, 'is_active' => false]);
        StorefrontSlide::query()->create(['image' => 'https://cdn.example.test/d.jpg', 'heading' => 'Expired', 'sort_order' => 4, 'is_active' => true, 'ends_at' => now()->subDay()]);

        $slides = $this->api('slides')->assertOk()->json('data');

        $this->assertSame(['First', 'Second'], collect($slides)->pluck('heading')->all());
        $this->assertSame('https://cdn.example.test/a-m.jpg', $slides[0]['image_mobile_url']);
        $this->assertSame(['slug' => 'slide-product', 'name' => 'Slide Product'], $slides[0]['product']);
        $this->assertNull($slides[1]['product'], 'a link to a hidden product is not exposed');
    }

    public function test_pages_are_listed_and_served_only_when_published(): void
    {
        StorefrontPage::query()->create(['title' => 'About Us', 'excerpt' => 'Who we are', 'content' => '<p>Hello</p><script>x()</script>', 'is_published' => true, 'sort_order' => 2]);
        StorefrontPage::query()->create(['title' => 'Terms', 'content' => "Line A\nLine B", 'is_published' => true, 'sort_order' => 1]);
        StorefrontPage::query()->create(['title' => 'Secret', 'content' => 'x', 'is_published' => false]);

        $this->assertSame(['terms', 'about-us'], collect($this->api('pages')->assertOk()->json('data'))->pluck('slug')->all());

        $about = $this->api('pages/about-us')->assertOk()->json('data');
        $this->assertSame('About Us', $about['title']);
        $this->assertStringContainsString('<p>Hello</p>', $about['content_html']);
        $this->assertStringNotContainsString('<script', $about['content_html']);
        $this->assertSame("<p>Line A</p>\n<p>Line B</p>", $this->api('pages/terms')->json('data.content_html'));

        $this->api('pages/secret')->assertStatus(404)->assertJsonPath('error', 'page_not_found');
    }

    // --------------------------------------------------------------- helpers

    public function test_the_api_serves_the_next_js_row_and_never_the_blade_row_of_the_company(): void
    {
        // The company's Blade storefront (the reseller store) has settings of its own.
        StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(),
            'site_type' => StorefrontSetting::SITE_BLADE,
            'is_published' => true,
            'whatsapp_number' => '01711111111',
            'nextjs_site_enabled' => true, // a stale flag on a Blade row never opens the API
        ]);
        StorefrontSetting::query()->nextjs()->where('company_id', $this->company->getKey())->update(['whatsapp_number' => '01722222222']);

        $this->api('site')->assertOk()->assertJsonPath('data.contact.whatsapp_number', '01722222222');

        // Switching the Next.js row off closes the API even though the Blade row says "on".
        StorefrontSetting::query()->nextjs()->where('company_id', $this->company->getKey())->update(['nextjs_site_enabled' => false]);
        $this->api('site')->assertStatus(403)->assertJsonPath('error', 'nextjs_site_disabled');
    }

    public function test_a_company_with_only_a_blade_row_is_refused(): void
    {
        $company = Company::query()->create([
            'name' => 'Blade Only', 'slug' => 'blade-only', 'domain' => 'blade-only.example.test', 'domain_verified' => true,
            'invoice_prefix' => 'BLO', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        StorefrontSetting::query()->create(['company_id' => $company->getKey(), 'is_published' => true, 'nextjs_site_enabled' => true]);
        [, $token] = CompanyApiKey::generate($company);

        $this->api('site', $token)->assertStatus(403)->assertJsonPath('error', 'nextjs_site_disabled');
    }

    public function test_slides_pages_and_menu_links_follow_the_site_they_are_shown_on(): void
    {
        $nextjs = StorefrontSetting::query()->nextjs()->where('company_id', $this->company->getKey())->firstOrFail();
        $blade = StorefrontSetting::query()->create(['company_id' => $this->company->getKey(), 'site_type' => StorefrontSetting::SITE_BLADE, 'is_published' => true]);

        foreach ([['Shared', null, 1], ['Only Next', $nextjs, 2], ['Only Blade', $blade, 3]] as [$heading, $site, $order]) {
            StorefrontSlide::query()->create([
                'image' => 'https://cdn.example.test/'.str($heading)->slug().'.jpg',
                'heading' => $heading,
                'storefront_setting_id' => $site?->getKey(),
                'sort_order' => $order,
                'is_active' => true,
            ]);
        }

        $this->assertSame(['Shared', 'Only Next'], collect($this->api('slides')->assertOk()->json('data'))->pluck('heading')->all());

        // The API clears the company context when a request ends.
        app(CompanyContext::class)->set($this->company);
        $shared = StorefrontPage::query()->create(['title' => 'Shared Page', 'content' => 'x', 'is_published' => true]);
        $mine = StorefrontPage::query()->create(['title' => 'Next Page', 'content' => 'x', 'is_published' => true, 'storefront_setting_id' => $nextjs->getKey()]);
        $theirs = StorefrontPage::query()->create(['title' => 'Blade Page', 'content' => 'x', 'is_published' => true, 'storefront_setting_id' => $blade->getKey()]);

        $this->assertEqualsCanonicalizing(['shared-page', 'next-page'], collect($this->api('pages')->assertOk()->json('data'))->pluck('slug')->all());
        $this->api('pages/next-page')->assertOk();
        $this->api('pages/shared-page')->assertOk();
        $this->api('pages/blade-page')->assertStatus(404)->assertJsonPath('error', 'page_not_found');

        // A menu or footer link to a page that belongs to the other site is dropped, like an unpublished one.
        $nextjs->update([
            'header_menu' => [
                ['label' => 'Mine', 'type' => 'page', 'page_id' => $mine->getKey()],
                ['label' => 'Theirs', 'type' => 'page', 'page_id' => $theirs->getKey()],
                ['label' => 'Both', 'type' => 'page', 'page_id' => $shared->getKey()],
            ],
            'footer_blocks' => [['type' => 'bottom_bar', 'data' => ['legal_links' => [['page_id' => $mine->getKey()], ['page_id' => $theirs->getKey()]]]]],
        ]);

        $site = $this->api('site')->assertOk();
        $this->assertSame(['Mine', 'Both'], collect($site->json('data.menus.header'))->pluck('label')->all());
        $this->assertSame(['next-page'], collect($site->json('data.footer.0.data.legal_links') ?? $site->json('data.footer_blocks.0.data.legal_links'))->pluck('page_slug')->all());
    }

    public function test_the_become_a_reseller_menu_item_points_at_the_reseller_portal_and_goes_when_the_module_is_off(): void
    {
        $nextjs = StorefrontSetting::query()->nextjs()->where('company_id', $this->company->getKey())->firstOrFail();
        $nextjs->update([
            'header_menu' => [
                ['label' => 'Shop', 'type' => 'shop'],
                ['label' => 'Sell with us', 'type' => 'reseller', 'new_tab' => true],
            ],
            'footer_menu' => [['label' => 'Resellers', 'type' => 'reseller']],
        ]);

        // The reseller module is off for this company: there is nowhere to send a visitor, so the item is dropped.
        $site = $this->api('site')->assertOk();
        $this->assertSame(['Shop'], collect($site->json('data.menus.header'))->pluck('label')->all());
        $this->assertSame([], $site->json('data.menus.footer'));

        Company::query()->whereKey($this->company->getKey())->update(['reseller_module_enabled' => true]);

        $site = $this->api('site')->assertOk();
        $item = collect($site->json('data.menus.header'))->firstWhere('label', 'Sell with us');
        $this->assertSame('reseller', $item['type']);
        $this->assertStringEndsWith('://reseller.offer.example.test/', $item['url']);
        $this->assertTrue($item['new_tab']);
        $this->assertStringEndsWith('://reseller.offer.example.test/', $site->json('data.menus.footer.0.url'));

        // The item also carries the program banner the website's footer shows as a button: the standard one
        // that ships with the ERP until the admin uploads their own (Reseller Settings).
        $this->assertStringEndsWith('/images/reseller-program-banner.png', $item['image_url']);
        $this->assertSame($item['image_url'], $site->json('data.menus.footer.0.image_url'));
        $this->assertNull(collect($site->json('data.menus.header'))->firstWhere('label', 'Shop')['image_url']);

        Storage::fake('public');
        $path = 'companies/'.$this->company->storage_key.'/public/storefront/design/custom-banner.webp';
        Storage::disk('public')->put($path, 'img');
        // The reseller settings live on the Blade row, not the website's own.
        StorefrontSetting::query()->create(['company_id' => $this->company->getKey(), 'site_type' => StorefrontSetting::SITE_BLADE, 'reseller_banner_image' => $path]);

        $this->assertStringContainsString('custom-banner.webp', $this->api('site')->assertOk()->json('data.menus.footer.0.image_url'));
    }

    protected function api(string $path, ?string $token = null): TestResponse
    {
        return $this->getJson('/api/v1/storefront/'.$path, ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    /** @return array{0: Company, 1: string} */
    protected function makeCompany(string $name, string $slug, string $domain, bool $enabled = true): array
    {
        $company = Company::query()->create([
            'name' => $name,
            'slug' => $slug,
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => strtoupper(substr($slug, 0, 3)),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'site_type' => StorefrontSetting::SITE_NEXTJS,
            'is_published' => true,
            'nextjs_site_enabled' => $enabled,
        ]);

        [, $token] = CompanyApiKey::generate($company);

        return [$company->fresh(), $token];
    }

    protected function product(string $name, string $sku, array $attributes = []): Product
    {
        return Product::query()->create($attributes + [
            'name' => $name,
            'sku' => $sku,
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

    protected function category(string $name, bool $active = true): Category
    {
        return Category::query()->create(['name' => $name, 'slug' => str($name)->slug()->toString(), 'is_active' => $active]);
    }

    protected function review(Product $product, int $rating, string $status = ProductReview::STATUS_APPROVED, string $comment = 'ok'): ProductReview
    {
        return ProductReview::query()->create([
            'product_id' => $product->getKey(),
            'customer_name' => 'Buyer',
            'rating' => $rating,
            'comment' => $comment,
            'status' => $status,
        ]);
    }

    /** @param  list<Product>  $products */
    protected function collection(string $title, string $kind, array $products, array $attributes = []): Offer
    {
        $offer = Offer::query()->create($attributes + [
            'company_id' => $this->company->getKey(),
            'type' => Offer::TYPE_COLLECTION,
            'collection_kind' => $kind,
            'title' => $title,
            'status' => Offer::STATUS_PUBLISHED,
        ]);

        foreach ($products as $index => $product) {
            OfferItem::query()->create([
                'company_id' => $this->company->getKey(),
                'offer_id' => $offer->getKey(),
                'product_id' => $product->getKey(),
                'quantity' => 1,
                'sort_order' => $index,
            ]);
        }

        return $offer;
    }
}
