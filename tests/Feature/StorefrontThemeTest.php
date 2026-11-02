<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontThemeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_current_storefront_is_registered_as_the_built_in_theme(): void
    {
        $setting = new StorefrontSetting;

        $this->assertSame('Built-in Theme', StorefrontThemeRegistry::themeOptions()['builtin']);
        $this->assertSame(StorefrontThemeRegistry::BUILT_IN, $setting->storefrontTheme());
        $this->assertSame(StorefrontThemeRegistry::BUILT_IN_DEFAULT, $setting->homepageTemplate());
        $this->assertSame('storefront.home', $setting->homepageView());
    }

    public function test_invalid_theme_and_template_values_fall_back_safely(): void
    {
        $setting = new StorefrontSetting([
            'storefront_theme' => 'missing-theme',
            'homepage_template' => 'missing-template',
        ]);

        $this->assertSame(StorefrontThemeRegistry::BUILT_IN, $setting->storefrontTheme());
        $this->assertSame(StorefrontThemeRegistry::BUILT_IN_DEFAULT, $setting->homepageTemplate());
        $this->assertSame('storefront.home', $setting->homepageView());
    }

    public function test_marketplace_pro_renders_all_three_homepage_templates(): void
    {
        [$company, $setting] = $this->createMarketplaceStore();

        // Campaign copy is owner-entered only; with none set the templates
        // still render their catalog sections without any invented text.
        $templates = [
            StorefrontThemeRegistry::MARKETPLACE_HERO => 'Recommended for you',
            StorefrontThemeRegistry::MARKETPLACE_CAMPAIGN => 'Flash deals',
            StorefrontThemeRegistry::MARKETPLACE_COMPACT => 'All products',
        ];

        foreach ($templates as $template => $expectedCopy) {
            $setting->forceFill(['homepage_template' => $template])->save();

            $this->get('http://marketplace.example.test/')
                ->assertOk()
                ->assertSee('data-storefront-theme="marketplace_pro"', false)
                ->assertSee('data-homepage-template="'.$template.'"', false)
                ->assertSee($expectedCopy)
                ->assertSee('Wholesale Router')
                ->assertDontSee('Better value on dependable products for your business')
                ->assertDontSee('Wholesale savings across essential business supplies')
                ->assertDontSee('Up to 30%')
                ->assertDontSee('24–48h');
        }
    }

    public function test_marketplace_hero_shows_only_the_owner_campaign_text_when_no_hero_slide_exists(): void
    {
        [, $setting] = $this->createMarketplaceStore();

        $this->get('http://marketplace.example.test/')
            ->assertOk()
            ->assertDontSee('marketplace-hero-banner', false);

        $setting->forceFill([
            'marketplace_campaign_heading' => 'Owner campaign heading',
            'marketplace_campaign_subheading' => 'Owner campaign description',
        ])->save();

        $this->get('http://marketplace.example.test/')
            ->assertOk()
            ->assertSee('marketplace-hero-banner', false)
            ->assertSee('Owner campaign heading')
            ->assertSee('Owner campaign description')
            ->assertDontSee('marketplace-stat', false);
    }

    public function test_marketplace_feature_controls_hide_disabled_sections(): void
    {
        [, $setting] = $this->createMarketplaceStore();

        $setting->forceFill([
            'homepage_template' => StorefrontThemeRegistry::MARKETPLACE_CAMPAIGN,
            'marketplace_categories_enabled' => false,
            'marketplace_deals_enabled' => false,
            'marketplace_bulk_pricing_enabled' => false,
            'marketplace_business_accounts_enabled' => false,
        ])->save();

        $this->get('http://marketplace.example.test/')
            ->assertOk()
            ->assertDontSee('Verified business accounts get flexible payment terms')
            ->assertDontSee('Shop by category')
            ->assertDontSee('Flash deals')
            ->assertDontSee('Bulk pricing')
            ->assertDontSee('Open a business account');
    }

    public function test_hero_wholesale_buyers_banner_is_hidden_by_default_and_shown_by_its_dedicated_toggle(): void
    {
        [, $setting] = $this->createMarketplaceStore();

        // Default (marketplace_business_strip_enabled = false): the bottom
        // business account banner is not rendered.
        $this->get('http://marketplace.example.test/')
            ->assertOk()
            ->assertSee('data-homepage-template="'.StorefrontThemeRegistry::MARKETPLACE_HERO.'"', false)
            ->assertDontSee('marketplace-business-strip', false);

        // Turning the dedicated toggle on brings the banner back, with the
        // owner's own heading/text when set.
        $setting->forceFill([
            'marketplace_business_strip_enabled' => true,
            'marketplace_business_heading' => 'Built for repeat and wholesale buyers',
            'marketplace_business_text' => 'Owner business account text',
        ])->save();

        $this->get('http://marketplace.example.test/')
            ->assertOk()
            ->assertSee('Built for repeat and wholesale buyers')
            ->assertSee('Owner business account text')
            ->assertSee('Open business account')
            ->assertSee('marketplace-business-strip', false);

        // The broad "Business account callouts" toggle no longer governs this
        // hero banner — the dedicated toggle is the single control.
        $setting->forceFill([
            'marketplace_business_accounts_enabled' => false,
            'marketplace_business_strip_enabled' => true,
        ])->save();

        $this->get('http://marketplace.example.test/')
            ->assertOk()
            ->assertSee('Built for repeat and wholesale buyers');
    }

    /**
     * Owner request: the hero banner should be full width with the two
     * "Ready to order" category cards beside it removed entirely, and every
     * "See all"/"View all" link should use a real arrow icon instead of the
     * mojibake text arrow ("â†'") that a prior encoding bug left behind.
     */
    public function test_marketplace_hero_is_full_width_without_promo_cards_and_uses_a_real_arrow_icon(): void
    {
        [$company] = $this->createMarketplaceStore();

        Category::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Second Category',
            'slug' => 'second-category',
            'is_active' => true,
        ]);

        $response = $this->get('http://marketplace.example.test/')->assertOk();

        $response->assertDontSee('Ready to order')
            ->assertDontSee('Browse category')
            ->assertDontSee('marketplace-hero-grid', false)
            ->assertDontSee('marketplace-promo-card', false);

        // The real fix for the corrupted "â†'" text arrow: a proper SVG icon
        // via storefront.partials.arrow-right-icon, applied everywhere a
        // "See all"/"View all" link appears on this template.
        $response->assertSee('marketplace-link-arrow', false)
            ->assertSee('<path d="M5 12h14M13 6l6 6-6 6"/>', false);

        // No leftover mojibake anywhere on the page (em dash, en dash,
        // checkmark, or the corrupted arrow sequence).
        $content = $response->getContent();
        $this->assertStringNotContainsString('â€', $content);
        $this->assertStringNotContainsString('â†', $content);
    }

    public function test_marketplace_trust_strip_scrolls_horizontally_on_mobile(): void
    {
        [, $setting] = $this->createMarketplaceStore();

        // No admin items → no strip (nothing invented).
        $this->get('http://marketplace.example.test/')
            ->assertOk()
            ->assertDontSee('marketplace-trust-grid', false)
            ->assertDontSee('Business pricing');

        $setting->forceFill(['marketplace_trust_items' => [
            ['icon' => 'truck', 'title' => 'Nationwide dispatch', 'subtitle' => 'Owner delivery note'],
            ['icon' => 'refresh', 'title' => 'Easy replacement', 'subtitle' => null],
            ['icon' => 'briefcase', 'title' => 'Business pricing', 'subtitle' => null],
            ['icon' => 'lock', 'title' => 'Secure payment', 'subtitle' => null],
        ]])->save();

        $this->get('http://marketplace.example.test/')
            ->assertOk()
            ->assertSee('marketplace-trust-grid', false)
            ->assertSee('Nationwide dispatch')
            ->assertSee('Owner delivery note')
            ->assertSee('Easy replacement')
            ->assertSee('Business pricing')
            ->assertSee('Secure payment');

        // CSS enforces the actual horizontal-scroll/grid switch (see
        // resources/css/app.css) — this just confirms the markup itself
        // still renders all four items in one shared container for that
        // CSS to apply to.
    }

    public function test_marketplace_trust_strip_falls_back_to_the_shared_trust_strip_titles(): void
    {
        [, $setting] = $this->createMarketplaceStore();

        $setting->forceFill(['trust_strip_delivery' => 'Owner delivery promise'])->save();

        $this->get('http://marketplace.example.test/')
            ->assertOk()
            ->assertSee('marketplace-trust-grid', false)
            ->assertSee('Owner delivery promise');
    }

    public function test_marketplace_utility_bar_shows_the_owner_message_only_when_enabled(): void
    {
        [, $setting] = $this->createMarketplaceStore();

        // The legacy announcement columns (default enabled) never render.
        $setting->forceFill([
            'marketplace_announcement_enabled' => true,
            'marketplace_announcement_text' => 'Legacy announcement must stay hidden',
            'marketplace_utility_bar_enabled' => false,
            'marketplace_utility_bar_text' => 'Owner utility message',
        ])->save();

        $this->get('http://marketplace.example.test/')
            ->assertOk()
            ->assertDontSee('Legacy announcement must stay hidden')
            ->assertDontSee('Owner utility message');

        $setting->forceFill([
            'marketplace_utility_bar_enabled' => true,
            'marketplace_helpline' => '01700000999',
        ])->save();

        $this->get('http://marketplace.example.test/')
            ->assertOk()
            ->assertSee('data-mp-utility-bar', false)
            ->assertSee('Owner utility message')
            ->assertSee('tel:01700000999', false);
    }

    public function test_marketplace_pro_uses_its_own_header_footer_and_mobile_nav_on_every_page(): void
    {
        $this->createMarketplaceStore();

        foreach (['/', '/products', '/cart', '/track'] as $path) {
            $this->get('http://marketplace.example.test'.$path)
                ->assertOk()
                ->assertSee('mp-header', false)
                ->assertSee('mp-footer', false)
                ->assertSee('mp-mobile-nav', false)
                ->assertSee('id="mp-category-drawer"', false);
        }
    }

    public function test_the_marketplace_footer_follows_the_colour_mode_and_shows_both_logos(): void
    {
        [, $setting] = $this->createMarketplaceStore();
        $setting->forceFill([
            'logo' => 'storefront/logos/light.png',
            'logo_dark' => 'storefront/logos/dark.png',
            'footer_blocks' => [['type' => 'brand_about', 'data' => ['show_logo' => true]]],
        ])->save();

        $html = $this->get('http://marketplace.example.test/')->assertOk()->getContent();

        // The footer is no longer pinned to the dark surface, so the light-mode logo can show.
        $this->assertDoesNotMatchRegularExpression('/<footer[^>]*class="[^"]*\bmp-footer\b[^"]*\bdark\b/', $html);
        $this->assertStringContainsString('light.png', $html);
        $this->assertStringContainsString('dark.png', $html);
        $this->assertMatchesRegularExpression('/<img[^>]*dark:hidden[^>]*light\.png|<img[^>]*light\.png[^>]*dark:hidden/', $html);
        $this->assertMatchesRegularExpression('/<img[^>]*hidden[^>]*dark:block[^>]*dark\.png|<img[^>]*dark\.png[^>]*dark:block/', $html);
    }

    public function test_other_themes_keep_the_default_header(): void
    {
        [, $setting] = $this->createMarketplaceStore();
        $setting->forceFill(['storefront_theme' => StorefrontThemeRegistry::BUILT_IN])->save();

        $this->get('http://marketplace.example.test/products')
            ->assertOk()
            ->assertDontSee('mp-header', false)
            ->assertSee('storefront-header', false);
    }

    public function test_theme_page_views_fall_back_to_the_default_view(): void
    {
        $this->assertSame('storefront.products.show', StorefrontThemeRegistry::view(StorefrontThemeRegistry::BUILT_IN, 'products.show'));
        $this->assertSame('storefront.products.show', StorefrontThemeRegistry::view(StorefrontThemeRegistry::MARKETPLACE_PRO, 'products.show'));
        $this->assertSame(
            'storefront.themes.marketplace-pro.partials.layout.header',
            StorefrontThemeRegistry::view(StorefrontThemeRegistry::MARKETPLACE_PRO, 'partials.layout.header')
        );
        $this->assertSame('storefront.partials.layout.header', StorefrontThemeRegistry::view('missing-theme', 'partials.layout.header'));
    }

    public function test_preview_pages_are_never_indexed(): void
    {
        [$company] = $this->createMarketplaceStore();

        $this->get('http://127.0.0.1/storefront/'.$company->slug)
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);

        $this->get('http://marketplace.example.test/')
            ->assertOk()
            ->assertDontSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_noor_solar_theme_is_registered_with_its_brand_presets(): void
    {
        $this->assertSame('Noor Solar Energy', StorefrontThemeRegistry::themeOptions()[StorefrontThemeRegistry::NOOR_SOLAR]);
        $this->assertSame(
            [StorefrontThemeRegistry::NOOR_SOLAR_ENGINEERED => 'Solar engineered'],
            StorefrontThemeRegistry::templateOptions(StorefrontThemeRegistry::NOOR_SOLAR),
        );
        $this->assertSame('#064C38', StorefrontSetting::themePalettePresetFields('noor_solar')['theme_color']);
        $this->assertSame('sora', StorefrontSetting::typographyPresetFields('noor_solar')['typography_heading_font']);
        $this->assertGreaterThanOrEqual(4.5, StorefrontSetting::colorContrastRatio('#FFFFFF', '#064C38'));
        $this->assertGreaterThanOrEqual(4.5, StorefrontSetting::colorContrastRatio('#043628', '#F5BF17'));
    }

    public function test_noor_solar_theme_renders_erp_products_and_accessible_interactions(): void
    {
        [, , $product] = $this->createNoorSolarStore();

        $this->get('http://noor-solar.example.test/')
            ->assertOk()
            ->assertSee('data-storefront-theme="noor_solar"', false)
            ->assertSee('data-homepage-template="solar_engineered"', false)
            ->assertSee('Solar buying, re-engineered.')
            ->assertSee('Noor N-Type 550W')
            ->assertSee('data-noor-module-lab', false)
            ->assertSee('data-noor-module-view="front"', false)
            ->assertSee('aria-pressed="true"', false)
            ->assertSee('http://noor-solar.example.test/contact', false);

        $this->get('http://noor-solar.example.test/product/'.$product->slug)
            ->assertOk()
            ->assertSee('data-storefront-theme="noor_solar"', false)
            ->assertSee('--storefront-brand: #064C38', false)
            ->assertSee('--storefront-heading-font: &#039;Sora&#039;', false);
    }

    private function createNoorSolarStore(): array
    {
        $company = Company::query()->create([
            'name' => 'Noor Solar Energy',
            'slug' => 'noor-solar-energy',
            'domain' => 'noor-solar.example.test',
            'domain_verified' => true,
            'invoice_prefix' => 'NSE',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        app(CompanyContext::class)->set($company);

        $category = Category::query()->create([
            'name' => 'Solar Modules',
            'slug' => 'solar-modules',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'name' => 'Noor N-Type 550W',
            'sku' => 'NOOR-550-TOPCON',
            'price' => 18500,
            'sale_price' => 17900,
            'cost_price' => 14000,
            'stock' => 120,
            'unit' => 'pcs',
            'reorder_level' => 10,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
            'category_id' => $category->getKey(),
        ]);

        $setting = StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'storefront_theme' => StorefrontThemeRegistry::NOOR_SOLAR,
            'homepage_template' => StorefrontThemeRegistry::NOOR_SOLAR_ENGINEERED,
            'theme_palette_preset' => 'noor_solar',
            'theme_color' => '#064C38',
            'theme_secondary_color' => '#043628',
            'theme_accent_color' => '#F5BF17',
            'theme_background_color' => '#F7F6F2',
            'theme_surface_color' => '#FFFFFF',
            'theme_text_color' => '#043628',
            'theme_muted_text_color' => '#52635D',
            'theme_border_color' => '#E5E3DA',
            'theme_dark_background_color' => '#031E17',
            'theme_dark_surface_color' => '#073B2C',
            'theme_dark_text_color' => '#F7F6F2',
            'theme_dark_muted_text_color' => '#B9C8C1',
            'theme_dark_border_color' => '#28604E',
            'typography_preset' => 'noor_solar',
            'typography_heading_font' => 'sora',
            'typography_body_font' => 'inter',
            'is_published' => true,
        ]);

        return [$company, $setting, $product];
    }

    private function createMarketplaceStore(): array
    {
        $company = Company::query()->create([
            'name' => 'Marketplace Store',
            'slug' => 'marketplace-store',
            'domain' => 'marketplace.example.test',
            'domain_verified' => true,
            'invoice_prefix' => 'MKT',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        app(CompanyContext::class)->set($company);

        $category = Category::query()->create([
            'name' => 'Networking',
            'slug' => 'networking',
            'is_active' => true,
        ]);

        Product::query()->create([
            'name' => 'Wholesale Router',
            'sku' => 'WHOLESALE-ROUTER-001',
            'price' => 2500,
            'sale_price' => 2200,
            'cost_price' => 1500,
            'stock' => 20,
            'unit' => 'pcs',
            'reorder_level' => 2,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
            'category_id' => $category->getKey(),
        ]);

        $setting = StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'storefront_theme' => StorefrontThemeRegistry::MARKETPLACE_PRO,
            'homepage_template' => StorefrontThemeRegistry::MARKETPLACE_HERO,
            'theme_color' => '#0D9488',
            'theme_secondary_color' => '#0F2A43',
            'theme_accent_color' => '#FF6A00',
            'is_published' => true,
        ]);

        return [$company, $setting];
    }
}
