<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\StorefrontSetting;
use App\Models\StorefrontSlide;
use App\Services\CompanyContext;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Homepage banners are hero slides (the old Storefront Settings banner
 * repeaters were merged into StorefrontSlide in v1.20.0).
 */
class StorefrontBannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_product_tagged_slide_links_to_the_products_page(): void
    {
        $company = $this->createPublishedStorefrontCompany('Gadget Store', 'banners.example.test');

        app(CompanyContext::class)->set($company);

        $product = Product::query()->create([
            'name' => 'Fast Charger',
            'sku' => 'FAST-CHARGER-001',
            'slug' => 'fast-charger',
            'price' => 1200,
            'sale_price' => 1100,
            'cost_price' => 700,
            'stock' => 10,
            'unit' => 'pcs',
            'reorder_level' => 2,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);

        StorefrontSlide::query()->create([
            'company_id' => $company->getKey(),
            'image' => 'storefront/slides/one.jpg',
            'product_id' => $product->getKey(),
            'is_active' => true,
        ]);

        $this->get('http://banners.example.test/')
            ->assertOk()
            ->assertSee('storage/storefront/slides/one.jpg', false)
            ->assertSee('href="'.route('storefront.products.show', 'fast-charger').'"', false);
    }

    public function test_cta_url_wins_over_the_product_link(): void
    {
        $company = $this->createPublishedStorefrontCompany('Gadget Store', 'banners-cta.example.test');

        app(CompanyContext::class)->set($company);

        $product = Product::query()->create([
            'name' => 'Power Bank',
            'sku' => 'POWER-BANK-001',
            'slug' => 'power-bank',
            'price' => 2200,
            'cost_price' => 1500,
            'stock' => 5,
            'unit' => 'pcs',
            'reorder_level' => 2,
            'vat_rate' => 0,
            // Hidden from the product grid so the only possible product link
            // on the page would come from the slide itself.
            'is_active' => false,
            'status' => Product::STATUS_AVAILABLE,
        ]);

        StorefrontSlide::query()->create([
            'company_id' => $company->getKey(),
            'image' => 'storefront/slides/cta.jpg',
            'cta_url' => 'https://example.com/offer',
            'product_id' => $product->getKey(),
            'is_active' => true,
        ]);

        $this->get('http://banners-cta.example.test/')
            ->assertOk()
            ->assertSee('href="https://example.com/offer"', false)
            ->assertDontSee('href="'.route('storefront.products.show', 'power-bank').'"', false);
    }

    public function test_untagged_slide_renders_without_a_product_link(): void
    {
        $company = $this->createPublishedStorefrontCompany('Gadget Store', 'banners-plain.example.test');

        StorefrontSlide::query()->create([
            'company_id' => $company->getKey(),
            'image' => 'storefront/slides/plain.jpg',
            'is_active' => true,
        ]);

        $this->get('http://banners-plain.example.test/')
            ->assertOk()
            ->assertSee('storage/storefront/slides/plain.jpg', false);
    }

    public function test_mobile_image_renders_as_a_picture_source(): void
    {
        $company = $this->createPublishedStorefrontCompany('Gadget Store', 'banners-mobile.example.test');

        StorefrontSlide::query()->create([
            'company_id' => $company->getKey(),
            'image' => 'storefront/slides/desktop.jpg',
            'image_mobile' => 'storefront/slides/mobile.jpg',
            'is_active' => true,
        ]);

        $this->get('http://banners-mobile.example.test/')
            ->assertOk()
            ->assertSee('storefront/slides/desktop.jpg', false)
            ->assertSee('storefront/slides/mobile.jpg', false);
    }

    public function test_multiple_banners_render_as_a_smooth_image_only_carousel(): void
    {
        $company = $this->createPublishedStorefrontCompany('Gadget Store', 'banners-carousel.example.test');

        foreach (['first', 'second'] as $index => $name) {
            StorefrontSlide::query()->create([
                'company_id' => $company->getKey(),
                'image' => "storefront/slides/{$name}.jpg",
                'heading' => "{$name} visible heading",
                'subheading' => "{$name} visible subheading",
                'cta_label' => "{$name} visible CTA",
                'sort_order' => $index,
                'is_active' => true,
            ]);
        }

        $this->get('http://banners-carousel.example.test/')
            ->assertOk()
            ->assertSee('storefront/slides/first.jpg', false)
            ->assertSee('storefront/slides/second.jpg', false)
            ->assertSee('transform 700ms cubic-bezier(0.22, 1, 0.36, 1)', false)
            ->assertSee('Pause banners')
            ->assertSee('first visible heading')
            ->assertSee('first visible subheading')
            ->assertSee('first visible CTA');
    }

    public function test_marketplace_pro_uses_the_same_full_width_image_banner_with_overlay(): void
    {
        $company = $this->createPublishedStorefrontCompany('Marketplace Store', 'marketplace-banner.example.test');

        StorefrontSetting::query()
            ->where('company_id', $company->getKey())
            ->update([
                'storefront_theme' => StorefrontThemeRegistry::MARKETPLACE_PRO,
                'homepage_template' => StorefrontThemeRegistry::MARKETPLACE_HERO,
            ]);

        StorefrontSlide::query()->create([
            'company_id' => $company->getKey(),
            'image' => 'storefront/slides/marketplace.jpg',
            'heading' => 'Overlay this heading',
            'cta_label' => 'Overlay this CTA',
            'is_active' => true,
        ]);

        $this->get('http://marketplace-banner.example.test/')
            ->assertOk()
            ->assertSee('storefront/slides/marketplace.jpg', false)
            ->assertSee('storefront-image-banner', false)
            ->assertSee('Overlay this heading')
            ->assertSee('Overlay this CTA');
    }

    public function test_banner_height_is_driven_by_its_declared_aspect_ratio_not_viewport_height(): void
    {
        // Regression guard: the banner container must size itself from the
        // image's own ratio (matching StorefrontThemeRegistry::BANNER_SPECS),
        // not a viewport-height fraction — a vh-based height ignores the
        // image's actual width:height ratio, so object-cover ends up
        // zooming in and cropping off the banner's own headline/icons on
        // any window whose height doesn't happen to match that fraction.
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('aspect-ratio: 45 / 16;', $css);
        $this->assertStringContainsString('aspect-ratio: 3 / 1;', $css);
        $this->assertStringNotContainsString('100vh / 6', $css);
        $this->assertStringNotContainsString('100vh / 3', $css);
    }

    public function test_marketplace_pro_desktop_banner_is_shorter_than_built_in_so_the_category_row_stays_in_view(): void
    {
        // Owner request 2026-09-08 (screenshot): on desktop the Marketplace
        // Pro banner should be short enough that the "Shop by category" row
        // below it is visible without scrolling. Only Marketplace Pro is
        // scoped down (4:1, capped) — Built-in keeps its 3:1 — and mobile
        // is untouched.
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(
            "body[data-storefront-theme='marketplace_pro'] .storefront-image-banner {",
            $css,
        );
        // The frame follows the uploaded image (owner screenshot 2026-10-19:
        // a fixed 4:1 `cover` frame cut off slides of any other ratio).
        $this->assertStringContainsString('aspect-ratio: auto;', $css);
        $this->assertStringContainsString('max-height: 30rem;', $css);
        $this->assertStringContainsString(
            "body[data-storefront-theme='marketplace_pro'] .storefront-image-banner img {\n        height: auto;\n        max-height: 30rem;\n        object-fit: contain;",
            $css,
        );

        $marketplace = StorefrontThemeRegistry::bannerSpec(StorefrontThemeRegistry::MARKETPLACE_PRO);
        $builtIn = StorefrontThemeRegistry::bannerSpec(StorefrontThemeRegistry::BUILT_IN);

        // Same width, shorter height than Built-in on desktop...
        $this->assertSame(1920, $marketplace['desktop']['width']);
        $this->assertSame(480, $marketplace['desktop']['height']);
        $this->assertLessThan($builtIn['desktop']['height'], $marketplace['desktop']['height']);
        $this->assertStringContainsString('4:1', $marketplace['desktop']['note']);

        // ...and the mobile slot is identical to Built-in / unchanged.
        $this->assertSame($builtIn['mobile'], $marketplace['mobile']);
        $this->assertSame(900, $marketplace['mobile']['width']);
        $this->assertSame(320, $marketplace['mobile']['height']);
    }

    public function test_banner_fits_to_screen_on_mobile_without_cropping(): void
    {
        // Mobile uses object-fit: contain ("fit to screen") so a slide
        // image is never cropped on a narrow screen, even one uploaded
        // before the ratio-locked image editor existed; desktop keeps
        // object-fit: cover since the wide banner has room to fill
        // edge-to-edge.
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.storefront-image-banner img {', $css);
        $this->assertStringContainsString('object-fit: contain;', $css);
        $this->assertStringContainsString('object-fit: cover;', $css);
    }

    public function test_fit_to_frame_forces_the_whole_image_to_show_at_every_breakpoint(): void
    {
        // Owner request 2026-09-08: a per-slide "Fit to frame" toggle so a
        // banner image that isn't the exact ratio is shown in full (never
        // cropped). The rule must sit outside every media query — its extra
        // class raises specificity above both the base and the desktop
        // `object-fit: cover` rule, so it wins at all sizes with no !important.
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(
            '.storefront-image-banner img.storefront-image-banner-fit {',
            $css,
        );

        $fitRuleAt = strpos($css, '.storefront-image-banner img.storefront-image-banner-fit {');
        $firstMediaAt = strpos($css, '@media');

        $this->assertNotFalse($firstMediaAt);
        $this->assertLessThan(
            $firstMediaAt,
            $fitRuleAt,
            'The fit-to-frame rule must be a top-level rule, not nested in a media query.',
        );
    }

    public function test_banner_pagination_shows_on_both_displays_by_default(): void
    {
        $company = $this->createPublishedStorefrontCompany('Gadget Store', 'banner-nav-default.example.test');

        foreach (['one', 'two'] as $index => $name) {
            StorefrontSlide::query()->create([
                'company_id' => $company->getKey(),
                'image' => "storefront/slides/{$name}.jpg",
                'sort_order' => $index,
                'is_active' => true,
            ]);
        }

        $this->get('http://banner-nav-default.example.test/')
            ->assertOk()
            ->assertSee('storefront-image-banner-nav', false)
            ->assertDontSee('storefront-image-banner-hide-nav-desktop', false)
            ->assertDontSee('storefront-image-banner-hide-nav-mobile', false);
    }

    public function test_owner_can_hide_the_banner_pagination_per_display_type(): void
    {
        // Owner request 2026-09-09 (screenshot): hide the banner's dot
        // pagination, with independent control for desktop and mobile. Both
        // default on, so the pagination keeps rendering exactly as before
        // until turned off. The hide is CSS-only, per breakpoint, so one
        // rendered page stays correct at every width.
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('@media (max-width: 1023.98px) {', $css);
        $this->assertStringContainsString(
            '.storefront-image-banner-hide-nav-mobile .storefront-image-banner-nav {',
            $css,
        );
        $this->assertStringContainsString(
            '.storefront-image-banner-hide-nav-desktop .storefront-image-banner-nav {',
            $css,
        );

        // Desktop hide lives in the >=1024px query, mobile hide below it —
        // matching the banner's own desktop breakpoint.
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 1023\.98px\) \{\s*\.storefront-image-banner-hide-nav-mobile /',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 1024px\) \{\s*\.storefront-image-banner-hide-nav-desktop /',
            $css,
        );

        $company = $this->createPublishedStorefrontCompany('Gadget Store', 'banner-nav.example.test');

        foreach (['one', 'two'] as $index => $name) {
            StorefrontSlide::query()->create([
                'company_id' => $company->getKey(),
                'image' => "storefront/slides/{$name}.jpg",
                'sort_order' => $index,
                'is_active' => true,
            ]);
        }

        StorefrontSetting::query()
            ->where('company_id', $company->getKey())
            ->update(['banner_pagination_desktop' => false, 'banner_pagination_mobile' => true]);

        $this->get('http://banner-nav.example.test/')
            ->assertOk()
            ->assertSee('storefront-image-banner-nav', false)
            ->assertSee('storefront-image-banner-hide-nav-desktop', false)
            ->assertDontSee('storefront-image-banner-hide-nav-mobile', false);
    }

    public function test_hiding_pagination_on_both_displays_marks_the_banner_for_both(): void
    {
        $company = $this->createPublishedStorefrontCompany('Gadget Store', 'banner-nav-both.example.test');

        foreach (['one', 'two'] as $index => $name) {
            StorefrontSlide::query()->create([
                'company_id' => $company->getKey(),
                'image' => "storefront/slides/{$name}.jpg",
                'sort_order' => $index,
                'is_active' => true,
            ]);
        }

        StorefrontSetting::query()
            ->where('company_id', $company->getKey())
            ->update(['banner_pagination_desktop' => false, 'banner_pagination_mobile' => false]);

        $this->get('http://banner-nav-both.example.test/')
            ->assertOk()
            ->assertSee('storefront-image-banner-hide-nav-desktop', false)
            ->assertSee('storefront-image-banner-hide-nav-mobile', false);
    }

    private function createPublishedStorefrontCompany(string $name, string $domain): Company
    {
        $company = Company::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString().'-'.str($domain)->slug()->toString(),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => str($name)->substr(0, 3)->upper()->toString(),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'theme_color' => '#0F766E',
            'whatsapp_number' => '+8801700000000',
            'meta_title' => $name,
            'is_published' => true,
        ]);

        return $company;
    }
}
