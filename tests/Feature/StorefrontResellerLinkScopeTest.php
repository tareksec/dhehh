<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\StockMovement;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 1 of 13_RESELLER_PORTAL_PLAN.md: a customer inside a reseller's
 * store (/store/{slug}/...) must stay inside it -- every storefront link
 * rendered on those pages has to point back under /store/{slug}, never at
 * the main-store catalog. This crawls the real rendered HTML rather than
 * asserting on the StorefrontUrl helper directly, so it catches a view
 * that was missed rather than only proving the helper itself works.
 */
class StorefrontResellerLinkScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_rendered_link_on_the_reseller_store_pages_stays_scoped_to_the_store(): void
    {
        foreach (array_keys(StorefrontThemeRegistry::themes()) as $theme) {
            [$domain, $slug, $product] = $this->setUpStore($theme);

            $pages = [
                "http://{$domain}/store/{$slug}",
                "http://{$domain}/store/{$slug}/products",
                "http://{$domain}/store/{$slug}/product/{$product->slug}",
                "http://{$domain}/store/{$slug}/cart",
            ];

            foreach ($pages as $page) {
                $response = $this->get($page)->assertOk();

                $this->assertLinksStayInsideStore($response->getContent(), $domain, $slug, $page);
            }
        }
    }

    protected function assertLinksStayInsideStore(string $html, string $domain, string $slug, string $page): void
    {
        preg_match_all('/(?:href|action)="([^"]+)"/i', $html, $matches);

        $storeContentPaths = ['/product', '/products', '/category', '/cart', '/checkout'];

        foreach ($matches[1] as $rawUrl) {
            $url = html_entity_decode($rawUrl);

            if (! str_starts_with($url, 'http://'.$domain) && ! str_starts_with($url, '/')) {
                continue; // external link (e.g. WhatsApp share, CDN asset) -- out of scope here
            }

            $path = parse_url($url, PHP_URL_PATH) ?? '';

            $touchesStoreContent = collect($storeContentPaths)->contains(fn (string $needle) => str_contains($path, $needle));

            if (! $touchesStoreContent) {
                continue; // account/track/offers/contact links intentionally stay on the main domain (no reseller_store route exists for them yet)
            }

            $this->assertStringContainsString(
                "/store/{$slug}",
                $path,
                "Link [{$url}] rendered on [{$page}] leaves the reseller store /store/{$slug}."
            );
        }
    }

    /**
     * @return array{0: string, 1: string, 2: Product}
     */
    protected function setUpStore(string $theme): array
    {
        $domain = Str::slug($theme).'.example.test';

        $company = Company::query()->create([
            'name' => 'Link Scope '.$theme,
            'slug' => Str::slug($domain),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => strtoupper(Str::random(4)),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
            'reseller_module_enabled' => true,
        ]);
        $company = $company->fresh();

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'theme_color' => '#0F766E',
            'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
            'storefront_theme' => $theme,
            'reseller_cost_per_unit' => 20,
        ]);

        app(CompanyContext::class)->set($company);

        $slug = 'scope-shop';
        $reseller = Customer::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Scope Reseller',
            'phone' => '01912345000',
            'reseller_status' => 'approved',
            'reseller_slug' => $slug,
            'business_name' => 'Scope Shop',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Scope Product',
            'sku' => 'LS-'.Str::random(6),
            'price' => 500,
            'sale_price' => 500,
            'cost_price' => 300,
            'stock' => 0,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'tier_prices' => [['min_qty' => 10, 'price' => 420]],
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ])->fresh();

        StockMovement::query()->create(['product_id' => $product->getKey(), 'type' => 'opening', 'quantity' => 50]);

        ResellerProduct::query()->create([
            'customer_id' => $reseller->getKey(),
            'product_id' => $product->getKey(),
            'is_active' => true,
        ]);

        return [$domain, $slug, $product];
    }
}
