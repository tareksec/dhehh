<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\WooCommerceDescriptionRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class WooCommerceDescriptionRepairTest extends TestCase
{
    use RefreshDatabase;

    private const FAN_HTML = '<p>Stay <b>cool</b> all day.</p><ul><li>Dual charging</li><li>3000mAh battery</li></ul>';

    /** What the old importer saved for FAN_HTML: tags removed, list items run together. */
    private const FAN_LEGACY = 'Stay cool all day.Dual charging3000mAh battery';

    private const FAN_FIXED = '<p>Stay <strong>cool</strong> all day.</p><ul><li>Dual charging</li><li>3000mAh battery</li></ul>';

    public function test_dry_run_lists_what_would_change_and_saves_nothing(): void
    {
        Storage::fake('local');
        [$company, $products] = $this->shopWithProducts();
        $this->fakeStoreApi();

        $this->artisan('woocommerce:repair-descriptions', ['company' => $company->slug])
            ->expectsOutputToContain('Dry run: 2 of 6 products would be fixed')
            ->assertSuccessful();

        $this->assertSame(self::FAN_LEGACY, $products['fan']->fresh()->description);
        $this->assertSame(self::FAN_LEGACY, $products['by-slug']->fresh()->description);
        Storage::disk('local')->assertDirectoryEmpty('/');
    }

    public function test_apply_fixes_untouched_imports_and_leaves_everything_else_alone(): void
    {
        Storage::fake('local');
        [$company, $products] = $this->shopWithProducts();
        $this->fakeStoreApi();

        $this->artisan('woocommerce:repair-descriptions', ['company' => $company->slug, '--apply' => true])
            ->expectsOutputToContain('Fixed 2 products')
            ->assertSuccessful();

        // Matched by SKU and, when the SKU differs, by slug.
        $this->assertSame(self::FAN_FIXED, $products['fan']->fresh()->description);
        $this->assertSame(self::FAN_FIXED, $products['by-slug']->fresh()->description);

        // Never touched: hand-written plain text, text saved in the editor, empty, not on the old site.
        $this->assertSame('Rewritten by the owner, no longer the old text.', $products['edited']->fresh()->description);
        $this->assertSame('<p>Written in the editor.</p>', $products['rich']->fresh()->description);
        $this->assertNull($products['blank']->fresh()->description);
        $this->assertSame('Only in the ERP.', $products['missing']->fresh()->description);

        // The replaced texts are kept for undo.
        $backups = Storage::disk('local')->files('description-repair');
        $this->assertCount(1, $backups);
        $saved = json_decode(Storage::disk('local')->get($backups[0]), true);
        $this->assertSame([self::FAN_LEGACY, self::FAN_LEGACY], array_column($saved, 'description_before'));
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        Storage::fake('local');
        [$company, $products] = $this->shopWithProducts();
        $this->fakeStoreApi();

        $this->artisan('woocommerce:repair-descriptions', ['company' => $company->slug, '--apply' => true])->assertSuccessful();
        $updatedAt = $products['fan']->fresh()->updated_at;

        $this->travel(5)->minutes();

        $this->artisan('woocommerce:repair-descriptions', ['company' => $company->slug, '--apply' => true])
            ->expectsOutputToContain('Nothing to fix')
            ->assertSuccessful();

        $this->assertSame(self::FAN_FIXED, $products['fan']->fresh()->description);
        $this->assertTrue($updatedAt->equalTo($products['fan']->fresh()->updated_at));
    }

    public function test_sku_option_limits_the_repair_to_the_products_asked_for(): void
    {
        Storage::fake('local');
        [$company, $products] = $this->shopWithProducts();
        $this->fakeStoreApi();

        $this->artisan('woocommerce:repair-descriptions', ['company' => $company->slug, '--apply' => true, '--sku' => ['FAN-1']])
            ->expectsOutputToContain('Fixed 1 products')
            ->assertSuccessful();

        $this->assertSame(self::FAN_FIXED, $products['fan']->fresh()->description);
        $this->assertSame(self::FAN_LEGACY, $products['by-slug']->fresh()->description);
    }

    public function test_other_companies_products_are_never_touched(): void
    {
        Storage::fake('local');
        [$company] = $this->shopWithProducts();
        $this->fakeStoreApi();

        $other = $this->company('other-shop', 'OTH');
        $foreign = $this->product($other, 'FAN-1', 'other-fan', self::FAN_LEGACY);

        $this->artisan('woocommerce:repair-descriptions', ['company' => $company->slug, '--apply' => true])->assertSuccessful();

        $this->assertSame(self::FAN_LEGACY, $foreign->fresh()->description);
    }

    public function test_fill_blank_option_also_fills_products_without_a_description(): void
    {
        Storage::fake('local');
        [$company, $products] = $this->shopWithProducts();
        $this->fakeStoreApi();

        $this->artisan('woocommerce:repair-descriptions', ['company' => $company->slug, '--apply' => true, '--fill-blank' => true])
            ->expectsOutputToContain('Fixed 3 products')
            ->assertSuccessful();

        $this->assertSame('<p>Blank product text.</p>', $products['blank']->fresh()->description);
    }

    public function test_a_description_edited_after_the_plan_was_made_is_not_overwritten(): void
    {
        [$company, $products] = $this->shopWithProducts();
        $woo = $this->storeApiPayload();
        $service = app(WooCommerceDescriptionRepairService::class);

        $plan = $service->plan($company, $woo);

        // Someone edits the product between the dry run and the write.
        $products['fan']->forceFill(['description' => '<p>Edited just now.</p>'])->save();

        $this->assertSame(1, $service->apply($company, $plan, $woo));
        $this->assertSame('<p>Edited just now.</p>', $products['fan']->fresh()->description);
    }

    public function test_the_old_site_address_can_be_given_on_the_command_line(): void
    {
        [$company] = $this->shopWithProducts(baseUrl: null);
        Http::fake(['elsewhere.example.test/wp-json/wc/store/v1/products*' => Http::response($this->storeApiPayload())]);

        $this->artisan('woocommerce:repair-descriptions', ['company' => $company->slug, '--base-url' => 'https://elsewhere.example.test'])
            ->expectsOutputToContain('Dry run: 2 of 6 products would be fixed')
            ->assertSuccessful();
    }

    public function test_it_fails_cleanly_without_a_site_address_or_when_the_old_site_is_down(): void
    {
        [$company] = $this->shopWithProducts(baseUrl: null);

        $this->artisan('woocommerce:repair-descriptions', ['company' => $company->slug])
            ->expectsOutputToContain('No WooCommerce site address')
            ->assertFailed();

        Http::fake(['*' => Http::response('down', 503)]);

        $this->artisan('woocommerce:repair-descriptions', ['company' => $company->slug, '--base-url' => 'https://old-shop.example.test'])
            ->expectsOutputToContain('could not be read')
            ->assertFailed();

        $this->artisan('woocommerce:repair-descriptions', ['company' => 'no-such-company'])->assertFailed();
    }

    public function test_service_requires_an_address(): void
    {
        $company = $this->company('no-url', 'NOU', null);

        $this->expectException(RuntimeException::class);

        app(WooCommerceDescriptionRepairService::class)->baseUrlFor($company);
    }

    /**
     * @return array{0: Company, 1: array<string, Product>}
     */
    private function shopWithProducts(?string $baseUrl = 'https://old-shop.example.test'): array
    {
        $company = $this->company('repair-shop', 'REP', $baseUrl);

        $products = [
            'fan' => $this->product($company, 'FAN-1', 'solar-fan', self::FAN_LEGACY),
            // The ERP SKU differs from the old site's, so it is matched by slug.
            'by-slug' => $this->product($company, 'WOO-RANDOM', 'slug-fan', self::FAN_LEGACY),
            'edited' => $this->product($company, 'EDIT-1', 'edited-light', 'Rewritten by the owner, no longer the old text.'),
            'rich' => $this->product($company, 'RICH-1', 'rich-light', '<p>Written in the editor.</p>'),
            'blank' => $this->product($company, 'BLANK-1', 'blank-light', null),
            'missing' => $this->product($company, 'ERP-ONLY', 'erp-only', 'Only in the ERP.'),
        ];

        return [$company, $products];
    }

    /** @return array<int, array<string, mixed>> */
    private function storeApiPayload(): array
    {
        return [
            ['sku' => 'FAN-1', 'slug' => 'solar-fan', 'description' => self::FAN_HTML, 'short_description' => ''],
            ['sku' => '', 'slug' => 'slug-fan', 'description' => self::FAN_HTML, 'short_description' => ''],
            ['sku' => 'EDIT-1', 'slug' => 'edited-light', 'description' => '<p>The original old site text.</p><p>Second paragraph.</p>', 'short_description' => ''],
            ['sku' => 'RICH-1', 'slug' => 'rich-light', 'description' => '<p>Old site text.</p><ul><li>One</li></ul>', 'short_description' => ''],
            ['sku' => 'BLANK-1', 'slug' => 'blank-light', 'description' => '', 'short_description' => '<p>Blank product text.</p>'],
        ];
    }

    private function fakeStoreApi(): void
    {
        Http::fake(['old-shop.example.test/wp-json/wc/store/v1/products*' => Http::response($this->storeApiPayload())]);
    }

    private function company(string $slug, string $prefix, ?string $baseUrl = 'https://old-shop.example.test'): Company
    {
        $company = Company::query()->create([
            'name' => 'Shop '.$slug,
            'slug' => $slug,
            'invoice_prefix' => $prefix,
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'theme_color' => '#0F766E',
            'is_published' => true,
            'woocommerce_base_url' => $baseUrl,
        ]);

        return $company;
    }

    private function product(Company $company, string $sku, string $slug, ?string $description): Product
    {
        app(CompanyContext::class)->clear();

        return Product::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'name' => 'Product '.$sku,
            'slug' => $slug,
            'sku' => $sku,
            'unit' => 'pcs',
            'price' => 100,
            'sale_price' => 100,
            'cost_price' => 0,
            'stock' => 0,
            'reorder_level' => 0,
            'vat_rate' => 0,
            'description' => $description,
        ]);
    }
}
