<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Support\StorefrontSeo;
use App\Support\StorefrontSitemap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What the public storefront tells search engines and link previews: each
 * page's own title, description and canonical address, which pages may be
 * indexed, the structured data, robots.txt and the sitemap.
 */
class StorefrontSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_a_product_page_has_its_own_title_description_and_canonical_address(): void
    {
        $this->createStore('seo-product.example.test', ['meta_title' => 'Acme Wholesale']);
        $product = $this->createProduct('Steel Bolt', 200, 150, description: '<p>Zinc coated.</p><p>Fits <b>M8</b> nuts.</p>');

        $this->get('http://seo-product.example.test/product/'.$product->slug.'?utm_source=ad')
            ->assertOk()
            ->assertSee('<title>Steel Bolt | Acme Wholesale</title>', false)
            ->assertSee('<meta name="description" content="Zinc coated. Fits M8 nuts.">', false)
            ->assertSee('<link rel="canonical" href="http://seo-product.example.test/product/steel-bolt">', false)
            ->assertSee('<meta property="og:url" content="http://seo-product.example.test/product/steel-bolt">', false)
            ->assertSee('<meta property="og:type" content="product">', false)
            ->assertSee('<meta property="og:site_name" content="Acme Wholesale">', false)
            ->assertDontSee('name="robots"', false);
    }

    public function test_a_product_page_publishes_its_price_and_stock_as_structured_data(): void
    {
        $this->createStore('seo-schema.example.test');
        $category = Category::query()->create(['name' => 'Hardware', 'slug' => 'hardware', 'is_active' => true]);
        $product = $this->createProduct('Steel Bolt', 200, 150, description: 'Zinc coated.', brand: 'Acme');
        $product->update(['category_id' => $category->getKey()]);

        $blocks = $this->jsonLd($this->get('http://seo-schema.example.test/product/'.$product->slug)->assertOk());
        $schema = collect($blocks)->firstWhere('@type', 'Product');

        $this->assertSame('https://schema.org', $schema['@context']);
        $this->assertSame('Steel Bolt', $schema['name']);
        $this->assertSame('Zinc coated.', $schema['description']);
        $this->assertSame('STEEL-BOLT', $schema['sku']);
        $this->assertSame('Acme', $schema['brand']['name']);
        $this->assertSame('Offer', $schema['offers']['@type']);
        $this->assertSame('150.00', $schema['offers']['price']);
        $this->assertSame('BDT', $schema['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $schema['offers']['availability']);
        $this->assertSame('http://seo-schema.example.test/product/steel-bolt', $schema['offers']['url']);
        $this->assertArrayNotHasKey('aggregateRating', $schema);

        $crumbs = collect($blocks)->firstWhere('@type', 'BreadcrumbList')['itemListElement'];
        $this->assertSame(['Home', 'All products', 'Hardware', 'Steel Bolt'], array_column($crumbs, 'name'));
        $this->assertSame([1, 2, 3, 4], array_column($crumbs, 'position'));
        $this->assertSame('http://seo-schema.example.test/category/hardware', $crumbs[2]['item']);
    }

    public function test_structured_data_follows_stock_preorder_variants_and_missing_prices(): void
    {
        $this->createStore('seo-offers.example.test');

        $soldOut = $this->createProduct('Sold Out Hinge', 300, null, stock: 0);
        $preorder = $this->createProduct('Coming Drill', 900, null, stock: 0);
        $preorder->update(['is_preorder' => true]);
        $free = $this->createProduct('Quote Only Crane', 0, null);

        $shirt = $this->createProduct('Cotton Shirt', 500, 450);
        $shirt->update(['has_variants' => true]);
        ProductVariant::query()->create(['product_id' => $shirt->getKey(), 'sku' => 'SHIRT-M', 'options' => ['Size' => 'M'], 'sale_price' => 450, 'stock' => 3, 'is_active' => true]);
        ProductVariant::query()->create(['product_id' => $shirt->getKey(), 'sku' => 'SHIRT-XL', 'options' => ['Size' => 'XL'], 'sale_price' => 525, 'stock' => 4, 'is_active' => true]);

        $offer = fn (Product $product): ?array => collect($this->jsonLd($this->get('http://seo-offers.example.test/product/'.$product->slug)->assertOk()))
            ->firstWhere('@type', 'Product')['offers'] ?? null;

        $this->assertSame('https://schema.org/OutOfStock', $offer($soldOut)['availability']);
        $this->assertSame('https://schema.org/PreOrder', $offer($preorder)['availability']);
        $this->assertNull($offer($free), 'A product with no price is not published as free.');

        $range = $offer($shirt);
        $this->assertSame('AggregateOffer', $range['@type']);
        $this->assertSame('450.00', $range['lowPrice']);
        $this->assertSame('525.00', $range['highPrice']);
        $this->assertSame(2, $range['offerCount']);
        $this->assertSame('https://schema.org/InStock', $range['availability']);
    }

    public function test_the_home_page_names_the_site_and_describes_the_organisation(): void
    {
        $this->createStore('seo-home.example.test', [
            'meta_title' => 'Acme Wholesale',
            'meta_description' => 'Bolts and tools in bulk.',
            'phone_number' => '+8801700000000',
            'contact_email' => 'hello@acme.test',
            'social_links' => [
                ['platform' => 'facebook', 'url' => 'https://facebook.com/acme'],
                ['platform' => 'whatsapp', 'url' => 'https://wa.me/8801700000000'],
                ['platform' => 'x', 'url' => 'javascript:alert(1)'],
            ],
        ]);

        $response = $this->get('http://seo-home.example.test/')
            ->assertOk()
            ->assertSee('<title>Acme Wholesale</title>', false)
            ->assertSee('<meta name="description" content="Bolts and tools in bulk.">', false)
            ->assertSee('<link rel="canonical" href="http://seo-home.example.test">', false);

        $blocks = collect($this->jsonLd($response));
        $organization = $blocks->firstWhere('@type', 'Organization');
        $website = $blocks->firstWhere('@type', 'WebSite');

        $this->assertSame('Store seo-home.example.test', $organization['name']);
        $this->assertSame(['https://facebook.com/acme'], $organization['sameAs']);
        $this->assertSame('+8801700000000', $organization['contactPoint'][0]['telephone']);
        $this->assertSame('hello@acme.test', $organization['contactPoint'][0]['email']);
        $this->assertSame('http://seo-home.example.test/products?q={search_term_string}', $website['potentialAction']['target']['urlTemplate']);
    }

    public function test_the_organisation_leaves_out_what_the_store_has_not_entered(): void
    {
        $this->createStore('seo-bare.example.test');

        $organization = collect($this->jsonLd($this->get('http://seo-bare.example.test/')->assertOk()))->firstWhere('@type', 'Organization');

        $this->assertArrayNotHasKey('sameAs', $organization);
        $this->assertArrayNotHasKey('contactPoint', $organization);
        $this->assertArrayNotHasKey('logo', $organization);
    }

    public function test_only_the_plain_first_page_of_the_shop_and_a_category_is_indexed(): void
    {
        $this->createStore('seo-listing.example.test', ['meta_title' => 'Acme Wholesale']);
        $category = Category::query()->create(['name' => 'Hardware', 'slug' => 'hardware', 'description' => '<p>Nuts and bolts.</p>', 'is_active' => true]);
        $this->createProduct('Steel Bolt', 200, null)->update(['category_id' => $category->getKey()]);

        $this->get('http://seo-listing.example.test/products?foo=bar')
            ->assertOk()
            ->assertSee('<title>All products | Acme Wholesale</title>', false)
            ->assertSee('<link rel="canonical" href="http://seo-listing.example.test/products">', false)
            ->assertDontSee('name="robots"', false);

        $this->get('http://seo-listing.example.test/category/hardware')
            ->assertOk()
            ->assertSee('<title>Hardware | Acme Wholesale</title>', false)
            ->assertSee('<meta name="description" content="Nuts and bolts.">', false)
            ->assertSee('<link rel="canonical" href="http://seo-listing.example.test/category/hardware">', false);

        foreach (['sort=price_asc', 'in_stock=1', 'min_price=100', 'q=bolt', 'page=2'] as $query) {
            $this->get('http://seo-listing.example.test/products?'.$query)
                ->assertOk()
                ->assertSee('<meta name="robots" content="noindex, follow">', false)
                ->assertDontSee('rel="canonical"', false);
        }

        $crumbs = collect($this->jsonLd($this->get('http://seo-listing.example.test/category/hardware')))->firstWhere('@type', 'BreadcrumbList');
        $this->assertSame(['Home', 'All products', 'Hardware'], array_column($crumbs['itemListElement'], 'name'));
    }

    public function test_a_search_results_page_is_titled_by_the_search_and_kept_out_of_the_index(): void
    {
        $this->createStore('seo-search.example.test');
        $this->createProduct('Steel Bolt', 200, null);

        $this->get('http://seo-search.example.test/products?q=bolt')
            ->assertOk()
            ->assertSee('Results for “bolt”', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false);
    }

    public function test_private_and_transactional_pages_are_kept_out_of_the_index(): void
    {
        $this->createStore('seo-private.example.test');
        $product = $this->createProduct('Steel Bolt', 200, null);

        $this->post('http://seo-private.example.test/cart/items/'.$product->slug, ['quantity' => 1]);

        foreach (['/cart', '/checkout', '/track', '/complaints', '/account/login'] as $path) {
            $this->get('http://seo-private.example.test'.$path)
                ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
                ->assertDontSee('rel="canonical"', false)
                ->assertDontSee('application/ld+json', false);
        }
    }

    public function test_staff_preview_pages_are_never_indexed(): void
    {
        $company = $this->createStore('seo-preview.example.test');
        $product = $this->createProduct('Steel Bolt', 200, null);

        $this->get('/storefront/'.$company->slug.'/product/'.$product->slug)
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertDontSee('rel="canonical"', false)
            ->assertDontSee('application/ld+json', false);
    }

    public function test_a_custom_page_uses_its_own_meta_title_and_description(): void
    {
        $this->createStore('seo-page.example.test', ['meta_title' => 'Acme Wholesale']);
        StorefrontPage::query()->create(['title' => 'Delivery', 'slug' => 'delivery', 'content' => '<p>We ship in 2 days.</p>', 'meta_title' => 'Delivery times', 'meta_description' => 'How fast we ship.', 'is_published' => true]);
        StorefrontPage::query()->create(['title' => 'Returns', 'slug' => 'returns', 'excerpt' => '', 'content' => '<h2>Returns</h2><p>Within 7 days.</p>', 'is_published' => true]);

        $this->get('http://seo-page.example.test/pages/delivery')
            ->assertOk()
            ->assertSee('<title>Delivery times | Acme Wholesale</title>', false)
            ->assertSee('<meta name="description" content="How fast we ship.">', false)
            ->assertSee('<link rel="canonical" href="http://seo-page.example.test/pages/delivery">', false);

        // No meta fields: the page's title and the start of its text.
        $this->get('http://seo-page.example.test/pages/returns')
            ->assertOk()
            ->assertSee('<title>Returns | Acme Wholesale</title>', false)
            ->assertSee('<meta name="description" content="Returns Within 7 days.">', false);
    }

    public function test_an_offer_page_uses_its_own_title_and_description(): void
    {
        $company = $this->createStore('seo-offer.example.test', ['meta_title' => 'Acme Wholesale']);
        $offer = $this->createOffer($company, 'Winter Combo', ['meta_description' => 'Two bolts, one price.']);

        $this->get('http://seo-offer.example.test/offers/'.$offer->slug)
            ->assertOk()
            ->assertSee('<title>Winter Combo | Acme Wholesale</title>', false)
            ->assertSee('<meta name="description" content="Two bolts, one price.">', false)
            ->assertSee('<link rel="canonical" href="http://seo-offer.example.test/offers/'.$offer->slug.'">', false);

        $this->get('http://seo-offer.example.test/offers')
            ->assertOk()
            ->assertSee('<title>Offers | Acme Wholesale</title>', false)
            ->assertDontSee('name="robots"', false);

        $this->get('http://seo-offer.example.test/offers?type=combo')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, follow">', false);
    }

    public function test_the_contact_page_is_titled_and_the_language_follows_the_visitor(): void
    {
        $this->createStore('seo-contact.example.test', ['meta_title' => 'Acme Wholesale']);

        $this->get('http://seo-contact.example.test/contact')
            ->assertOk()
            ->assertSee('<title>Contact us | Acme Wholesale</title>', false)
            ->assertSee('<meta property="og:locale" content="en_US">', false);

        app()->setLocale('bn');

        $this->get('http://seo-contact.example.test/contact')
            ->assertOk()
            ->assertSee('<title>'.__('Contact us').' | Acme Wholesale</title>', false)
            ->assertSee('<meta property="og:locale" content="bn_BD">', false)
            ->assertSee('<html lang="bn"', false);
    }

    public function test_the_default_description_is_translated(): void
    {
        $this->createStore('seo-fallback.example.test');

        $this->get('http://seo-fallback.example.test/')
            ->assertOk()
            ->assertSee('<meta name="description" content="'.e(__('Shop products from :name', ['name' => 'Store seo-fallback.example.test'])).'">', false);
    }

    public function test_structured_data_cannot_break_out_of_its_script_tag(): void
    {
        $this->createStore('seo-xss.example.test');
        $product = $this->createProduct('Bolt </script><script>alert(1)</script>', 200, null);

        $html = $this->get('http://seo-xss.example.test/product/'.$product->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertSame('Bolt </script><script>alert(1)</script>', collect($this->jsonLd($this->get('http://seo-xss.example.test/product/'.$product->slug)))->firstWhere('@type', 'Product')['name']);
    }

    public function test_robots_txt_on_a_storefront_domain_hides_private_pages_and_names_the_sitemap(): void
    {
        $this->createStore('seo-robots.example.test');

        $response = $this->get('http://seo-robots.example.test/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        foreach (['/admin', '/livewire', '/cart', '/checkout', '/account', '/track', '/complaints'] as $path) {
            $response->assertSee('Disallow: '.$path, false);
        }

        $response->assertSee('Sitemap: http://seo-robots.example.test/sitemap.xml', false);
    }

    public function test_robots_txt_on_the_app_domain_or_an_unpublished_storefront_keeps_only_the_admin_rules(): void
    {
        $company = $this->createStore('seo-robots-off.example.test');
        $company->storefrontSetting->update(['is_published' => false]);

        foreach (['http://seo-robots-off.example.test/robots.txt', 'http://app.unknown.test/robots.txt'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('Disallow: /admin', false)
                ->assertSee('Disallow: /livewire', false)
                ->assertDontSee('/cart', false)
                ->assertDontSee('Sitemap', false);
        }
    }

    public function test_the_sitemap_lists_only_what_the_storefront_publicly_serves(): void
    {
        $company = $this->createStore('seo-sitemap.example.test');
        $stocked = Category::query()->create(['name' => 'Hardware', 'slug' => 'hardware', 'is_active' => true]);
        Category::query()->create(['name' => 'Empty', 'slug' => 'empty', 'is_active' => true]);
        Category::query()->create(['name' => 'Hidden', 'slug' => 'hidden', 'is_active' => false])->products()->save($this->createProduct('Hidden Cat Bolt', 10, null));
        $this->createProduct('Steel Bolt', 200, null)->update(['category_id' => $stocked->getKey()]);
        $this->createProduct('Soon Drill', 900, null)->update(['status' => Product::STATUS_COMING_SOON]);
        $this->createProduct('Retired Saw', 400, null)->update(['is_active' => false]);
        StorefrontPage::query()->create(['title' => 'Delivery', 'slug' => 'delivery', 'content' => 'x', 'is_published' => true]);
        StorefrontPage::query()->create(['title' => 'Draft Page', 'slug' => 'draft-page', 'content' => 'x', 'is_published' => false]);
        $this->createOffer($company, 'Winter Combo');
        $this->createOffer($company, 'Held Back', ['status' => Offer::STATUS_DRAFT]);

        $response = $this->get('http://seo-sitemap.example.test/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $urls = $this->sitemapUrls($response->getContent());
        $locations = array_map(fn ($url): string => (string) $url->loc, $urls);

        $this->assertEqualsCanonicalizing([
            'http://seo-sitemap.example.test',
            'http://seo-sitemap.example.test/products',
            'http://seo-sitemap.example.test/contact',
            'http://seo-sitemap.example.test/category/hardware',
            'http://seo-sitemap.example.test/product/steel-bolt',
            'http://seo-sitemap.example.test/product/hidden-cat-bolt',
            // The offers' own component products are ordinary available products.
            'http://seo-sitemap.example.test/product/winter-combo-part',
            'http://seo-sitemap.example.test/product/held-back-part',
            'http://seo-sitemap.example.test/pages/delivery',
            'http://seo-sitemap.example.test/offers',
            'http://seo-sitemap.example.test/offers/winter-combo',
        ], $locations);

        $product = collect($urls)->first(fn ($url): bool => str_ends_with((string) $url->loc, '/product/steel-bolt'));
        $this->assertNotEmpty((string) $product->lastmod);
        $this->assertNotFalse(strtotime((string) $product->lastmod));
    }

    public function test_the_sitemap_has_no_offer_entries_without_a_published_offer(): void
    {
        $this->createStore('seo-sitemap-lean.example.test');

        $locations = array_map(
            fn ($url): string => (string) $url->loc,
            $this->sitemapUrls($this->get('http://seo-sitemap-lean.example.test/sitemap.xml')->assertOk()->getContent()),
        );

        $this->assertNotContains('http://seo-sitemap-lean.example.test/offers', $locations);
    }

    public function test_the_sitemap_follows_product_changes_and_is_kept_per_company(): void
    {
        $first = $this->createStore('seo-sitemap-a.example.test');
        $this->createProduct('Steel Bolt', 200, null);
        $second = $this->createStore('seo-sitemap-b.example.test');
        $this->createProduct('Other Washer', 20, null);

        app(CompanyContext::class)->set($first);
        $before = $this->get('http://seo-sitemap-a.example.test/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/product/steel-bolt', $before);
        $this->assertStringNotContainsString('other-washer', $before);

        // A new product bumps the company's cache generation, so the sitemap is rebuilt.
        $this->createProduct('Brass Nut', 30, null);
        $this->assertStringContainsString('/product/brass-nut', $this->get('http://seo-sitemap-a.example.test/sitemap.xml')->getContent());

        $other = $this->get('http://seo-sitemap-b.example.test/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('seo-sitemap-b.example.test/product/other-washer', $other);
        $this->assertStringNotContainsString('steel-bolt', $other);
        $this->assertSame($second->getKey(), Company::query()->where('domain', 'seo-sitemap-b.example.test')->value('id'));
    }

    public function test_the_sitemap_is_not_served_for_the_app_domain_or_an_unpublished_storefront(): void
    {
        $company = $this->createStore('seo-sitemap-off.example.test');
        $company->storefrontSetting->update(['is_published' => false]);

        $this->get('http://seo-sitemap-off.example.test/sitemap.xml')->assertNotFound();
        $this->get('http://app.unknown.test/sitemap.xml')->assertNotFound();
    }

    public function test_a_single_page_sitemap_has_no_numbered_pages(): void
    {
        $this->createStore('seo-sitemap-one.example.test');

        $this->get('http://seo-sitemap-one.example.test/sitemap-1.xml')->assertNotFound();
    }

    public function test_a_catalog_over_the_limit_is_split_behind_a_sitemap_index(): void
    {
        $entries = array_map(fn (int $number): array => ['loc' => 'https://shop.test/product/p-'.$number, 'lastmod' => null], range(1, StorefrontSitemap::MAX_URLS + 5));

        $this->assertSame(2, StorefrontSitemap::pageCount($entries));
        $this->assertSame(1, StorefrontSitemap::pageCount([]));
        $this->assertCount(StorefrontSitemap::MAX_URLS, StorefrontSitemap::page($entries, 1));
        $this->assertCount(5, StorefrontSitemap::page($entries, 2));
        $this->assertSame('https://shop.test/product/p-'.(StorefrontSitemap::MAX_URLS + 1), StorefrontSitemap::page($entries, 2)[0]['loc']);

        $index = simplexml_load_string(StorefrontSitemap::index(['https://shop.test/sitemap-1.xml', 'https://shop.test/sitemap-2.xml']));
        $this->assertSame('sitemapindex', $index->getName());
        $this->assertCount(2, $index->sitemap);
    }

    public function test_sitemap_addresses_are_xml_escaped(): void
    {
        $xml = simplexml_load_string(StorefrontSitemap::urlset([['loc' => 'https://shop.test/pages/a&b?x=1&y=<2>', 'lastmod' => null]]));

        $this->assertSame('https://shop.test/pages/a&b?x=1&y=<2>', (string) $xml->url->loc);
    }

    public function test_an_excerpt_is_plain_text_cut_at_a_word(): void
    {
        $this->assertNull(StorefrontSeo::excerpt(null));
        $this->assertNull(StorefrontSeo::excerpt('  <p> </p> '));
        $this->assertSame('One Two & three', StorefrontSeo::excerpt("<p>One</p>\n<p>Two &amp;   three</p>"));

        $long = StorefrontSeo::excerpt(str_repeat('wholesale ', 40), 50);

        $this->assertLessThanOrEqual(51, mb_strlen($long));
        $this->assertStringEndsWith('…', $long);
        $this->assertStringNotContainsString('wholesal…', $long);
    }

    /** @return list<\SimpleXMLElement> */
    private function sitemapUrls(string $xml): array
    {
        return iterator_to_array(simplexml_load_string($xml)->url, false);
    }

    /** @return list<array<string, mixed>> */
    private function jsonLd(TestResponse $response): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $response->getContent(), $matches);

        return array_map(fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
    }

    private function createOffer(Company $company, string $title, array $attributes = []): Offer
    {
        $offer = Offer::query()->create([
            'company_id' => $company->getKey(),
            'type' => Offer::TYPE_COMBO,
            'title' => $title,
            'status' => Offer::STATUS_PUBLISHED,
            'price_mode' => Offer::PRICE_MODE_AUTO_SUM,
            ...$attributes,
        ]);

        OfferItem::query()->create([
            'company_id' => $company->getKey(),
            'offer_id' => $offer->getKey(),
            'product_id' => $this->createProduct($title.' Part', 100, null)->getKey(),
            'quantity' => 1,
            'sort_order' => 0,
        ]);

        return $offer;
    }

    private function createProduct(string $name, float $price, ?float $salePrice, int $stock = 20, ?string $description = null, ?string $brand = null): Product
    {
        return Product::query()->create([
            'name' => $name,
            'sku' => str($name)->slug()->upper()->toString(),
            'price' => $price,
            'sale_price' => $salePrice,
            'cost_price' => $price * 0.5,
            'stock' => $stock,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
            'description' => $description,
            'brand' => $brand,
        ]);
    }

    private function createStore(string $domain, array $settings = []): Company
    {
        static $counter = 0;
        $counter++;

        $company = Company::query()->create([
            'name' => 'Store '.$domain,
            'slug' => str($domain)->slug()->toString(),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => 'SEO'.$counter,
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'theme_color' => '#0F766E',
            'is_published' => true,
            'storefront_theme' => 'marketplace_pro',
            ...$settings,
        ]);

        app(CompanyContext::class)->set($company);

        return $company->fresh();
    }
}
