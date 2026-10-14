<?php

namespace Tests\Feature;

use App\Filament\Reseller\Pages\MyStore;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\ResellerSubdomainProbe;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 7a of 13_RESELLER_PORTAL_PLAN.md: "name.<company domain>" opens one
 * reseller's store, when the company switched reseller subdomains on.
 */
class ResellerSubdomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_subdomain_shows_only_that_resellers_store(): void
    {
        $company = $this->company('sub-shop.example.test');
        $reseller = $this->reseller($company, 'alice', 'alice-shop');
        $other = $this->reseller($company, 'bob', 'bob-shop');
        $mine = $this->product($company, 'Alice Pick');
        $theirs = $this->product($company, 'Bob Pick');
        $this->pick($reseller, $mine);
        $this->pick($other, $theirs);

        $this->get('http://alice.sub-shop.example.test/products')
            ->assertOk()
            ->assertSee('Alice Pick')
            ->assertDontSee('Bob Pick');
    }

    public function test_the_subdomain_front_page_goes_to_the_product_list(): void
    {
        $company = $this->company('sub-front.example.test');
        $this->reseller($company, 'alice', 'alice-shop');

        $this->get('http://alice.sub-front.example.test/')->assertRedirect('/products');
    }

    public function test_store_links_on_a_subdomain_stay_on_the_subdomain_without_the_store_prefix(): void
    {
        $company = $this->company('sub-links.example.test');
        $reseller = $this->reseller($company, 'alice', 'alice-shop');
        $this->pick($reseller, $this->product($company, 'Linked Product'));

        $response = $this->get('http://alice.sub-links.example.test/products')->assertOk();

        $response->assertDontSee('/store/alice-shop', false);
    }

    public function test_unknown_pending_or_rejected_subdomains_404(): void
    {
        $company = $this->company('sub-404.example.test');
        $this->reseller($company, 'pend', 'pend-shop', 'pending');
        $this->reseller($company, 'rej', 'rej-shop', 'rejected');

        $this->get('http://nobody.sub-404.example.test/products')->assertNotFound();
        $this->get('http://pend.sub-404.example.test/products')->assertNotFound();
        $this->get('http://rej.sub-404.example.test/products')->assertNotFound();
    }

    public function test_subdomains_404_when_the_company_switch_is_off(): void
    {
        $company = $this->company('sub-off.example.test');
        $this->reseller($company, 'alice', 'alice-shop');
        $company->update(['reseller_subdomains_enabled' => false]);

        $this->get('http://alice.sub-off.example.test/products')->assertNotFound();
    }

    public function test_subdomains_404_when_the_reseller_module_is_off(): void
    {
        $company = $this->company('sub-module.example.test');
        $this->reseller($company, 'alice', 'alice-shop');
        $company->update(['reseller_module_enabled' => false]);

        $this->get('http://alice.sub-module.example.test/products')->assertNotFound();
    }

    public function test_a_subdomain_of_one_company_never_shows_another_companys_reseller(): void
    {
        $a = $this->company('company-a.example.test');
        $b = $this->company('company-b.example.test');
        $this->reseller($a, 'alice', 'alice-shop');

        $this->get('http://alice.company-b.example.test/products')->assertNotFound();
    }

    public function test_reserved_labels_never_resolve_as_reseller_stores(): void
    {
        $company = $this->company('sub-reserved.example.test');
        $this->reseller($company, 'admin', 'admin-shop');
        $this->reseller($company, 'reseller', 'reseller-shop');

        $this->assertNull(Company::forResellerSubdomainHost('admin.sub-reserved.example.test'));
        $this->assertNull(Company::forResellerSubdomainHost('reseller.sub-reserved.example.test'));
        $this->assertNull(Company::forResellerSubdomainHost('www.sub-reserved.example.test'));
    }

    public function test_the_portal_is_not_reachable_on_a_reseller_subdomain(): void
    {
        $company = $this->company('sub-portal.example.test');
        $this->reseller($company, 'alice', 'alice-shop');

        $this->get('http://alice.sub-portal.example.test/portal')->assertNotFound();
    }

    public function test_the_path_store_url_only_works_for_the_subdomains_own_reseller(): void
    {
        $company = $this->company('sub-path.example.test');
        $this->reseller($company, 'alice', 'alice-shop');
        $this->reseller($company, 'bob', 'bob-shop');

        $this->get('http://alice.sub-path.example.test/store/alice-shop/products')->assertOk();
        $this->get('http://alice.sub-path.example.test/store/bob-shop/products')->assertNotFound();
    }

    public function test_the_main_domain_store_url_keeps_working(): void
    {
        $company = $this->company('sub-main.example.test');
        $this->reseller($company, 'alice', 'alice-shop');

        $this->get('http://sub-main.example.test/store/alice-shop/products')->assertOk();
    }

    public function test_store_url_uses_the_subdomain_only_while_the_switch_is_on(): void
    {
        $company = $this->company('sub-url.example.test');
        $reseller = $this->reseller($company, 'alice', 'alice-shop');

        $this->assertSame('https://alice.sub-url.example.test', $reseller->fresh()->resellerStoreUrl());

        $company->update(['reseller_subdomains_enabled' => false]);

        $this->assertSame('https://sub-url.example.test/store/alice-shop/products', $reseller->fresh()->resellerStoreUrl());
    }

    public function test_a_reseller_can_choose_a_subdomain_in_the_portal(): void
    {
        $company = $this->company('sub-choose.example.test');
        $reseller = $this->reseller($company, null, 'alice-shop');
        $this->asPortal($company, $reseller);

        Livewire::test(MyStore::class)
            ->fillForm(['reseller_subdomain' => 'my-cool-shop'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('my-cool-shop', $reseller->fresh()->reseller_subdomain);
    }

    public function test_a_reseller_can_clear_their_subdomain(): void
    {
        $company = $this->company('sub-clear.example.test');
        $reseller = $this->reseller($company, 'alice', 'alice-shop');
        $this->asPortal($company, $reseller);

        Livewire::test(MyStore::class)
            ->fillForm(['reseller_subdomain' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($reseller->fresh()->reseller_subdomain);
    }

    public function test_subdomain_validation_rejects_reserved_taken_and_malformed_names(): void
    {
        $company = $this->company('sub-valid.example.test');
        $this->reseller($company, 'taken', 'taken-shop');
        $reseller = $this->reseller($company, null, 'alice-shop');
        $this->asPortal($company, $reseller);

        foreach (['admin', 'taken', 'Bad_Name', '-dash', 'dash-', 'ab'] as $bad) {
            Livewire::test(MyStore::class)
                ->fillForm(['reseller_subdomain' => $bad])
                ->call('save')
                ->assertHasFormErrors(['reseller_subdomain']);
        }

        $this->assertNull($reseller->fresh()->reseller_subdomain);
    }

    public function test_the_subdomain_field_is_hidden_and_ignored_when_the_switch_is_off(): void
    {
        $company = $this->company('sub-hidden.example.test');
        $company->update(['reseller_subdomains_enabled' => false]);
        $reseller = $this->reseller($company, null, 'alice-shop');
        $this->asPortal($company, $reseller);

        Livewire::test(MyStore::class)
            ->assertFormFieldIsHidden('reseller_subdomain')
            ->fillForm(['reseller_slug' => 'alice-shop'])
            ->call('save');

        $this->assertNull($reseller->fresh()->reseller_subdomain);
    }

    public function test_a_new_subdomain_counts_down_and_keeps_the_long_link_until_it_is_ready(): void
    {
        $company = $this->company('sub-setup.example.test');
        $company->storefrontSetting->update(['reseller_subdomain_setup_minutes' => 5]);
        $reseller = $this->reseller($company, null, 'alice-shop');
        $this->asPortal($company, $reseller);
        $this->fakeProbe(false);

        Livewire::test(MyStore::class)
            ->fillForm(['reseller_subdomain' => 'alice'])
            ->call('save')
            ->assertSee('Setting up alice.sub-setup.example.test (DNS and SSL)')
            ->assertSee('Expected to be live in');

        $reseller->refresh();
        $this->assertNotNull($reseller->reseller_subdomain_changed_at);
        $this->assertNull($reseller->reseller_subdomain_live_at);
        $this->assertSame(now()->addMinutes(5)->timestamp, $reseller->resellerSubdomainReadyAt()->timestamp);
        $this->assertSame('https://sub-setup.example.test/store/alice-shop/products', $reseller->resellerStoreUrl());

        $this->travel(6)->minutes();

        $this->assertSame('https://alice.sub-setup.example.test', $reseller->fresh()->resellerStoreUrl());
    }

    public function test_the_status_check_marks_the_subdomain_live_once_it_opens(): void
    {
        $company = $this->company('sub-probe.example.test');
        $reseller = $this->reseller($company, null, 'alice-shop');
        $this->asPortal($company, $reseller);
        $this->fakeProbe(false);

        $page = Livewire::test(MyStore::class)
            ->fillForm(['reseller_subdomain' => 'alice'])
            ->call('save')
            ->call('checkSubdomain');

        $this->assertNull($reseller->fresh()->reseller_subdomain_live_at);

        $this->fakeProbe(true);
        $page->call('checkSubdomain')
            ->assertSee('Your store address alice.sub-probe.example.test is live.')
            ->assertDontSee('Expected to be live in');

        $this->assertNotNull($reseller->fresh()->reseller_subdomain_live_at);
        $this->assertSame('https://alice.sub-probe.example.test', $reseller->fresh()->resellerStoreUrl());
    }

    public function test_changing_a_live_subdomain_starts_the_set_up_again(): void
    {
        $company = $this->company('sub-change.example.test');
        $reseller = $this->reseller($company, 'alice', 'alice-shop');
        $reseller->forceFill(['reseller_subdomain_changed_at' => now()->subDay(), 'reseller_subdomain_live_at' => now()->subDay()])->save();
        $this->asPortal($company, $reseller);
        $this->fakeProbe(false);

        Livewire::test(MyStore::class)->fillForm(['reseller_store_name' => 'Renamed'])->call('save');
        $this->assertNotNull($reseller->fresh()->reseller_subdomain_live_at);

        Livewire::test(MyStore::class)->fillForm(['reseller_subdomain' => 'alice-two'])->call('save');
        $reseller->refresh();
        $this->assertNull($reseller->reseller_subdomain_live_at);
        $this->assertTrue($reseller->reseller_subdomain_changed_at->isAfter(now()->subMinute()));

        Livewire::test(MyStore::class)->fillForm(['reseller_subdomain' => ''])->call('save');
        $this->assertNull($reseller->fresh()->reseller_subdomain_changed_at);
    }

    public function test_a_subdomain_chosen_before_tracking_counts_as_live(): void
    {
        $company = $this->company('sub-legacy.example.test');
        $reseller = $this->reseller($company, 'alice', 'alice-shop');
        $this->asPortal($company, $reseller);

        $this->assertTrue($reseller->fresh()->resellerSubdomainIsReady());
        Livewire::test(MyStore::class)->assertSee('Your store address alice.sub-legacy.example.test is live.');
    }

    public function test_the_tls_ask_endpoint_approves_approved_reseller_subdomains_only(): void
    {
        $company = $this->company('sub-ask.example.test');
        $this->reseller($company, 'alice', 'alice-shop');
        $this->reseller($company, 'pend', 'pend-shop', 'pending');

        $this->get('http://sub-ask.example.test/internal/tls-ask?domain=alice.sub-ask.example.test')->assertOk();
        $this->get('http://sub-ask.example.test/internal/tls-ask?domain=pend.sub-ask.example.test')->assertNotFound();
        $this->get('http://sub-ask.example.test/internal/tls-ask?domain=nobody.sub-ask.example.test')->assertNotFound();
        $this->get('http://sub-ask.example.test/internal/tls-ask?domain=admin.sub-ask.example.test')->assertNotFound();

        $company->update(['reseller_subdomains_enabled' => false]);
        $this->get('http://sub-ask.example.test/internal/tls-ask?domain=alice.sub-ask.example.test')->assertNotFound();
    }

    public function test_a_reseller_subdomain_keeps_the_private_page_rules_but_has_no_sitemap(): void
    {
        $company = $this->company('sub-seo.example.test');
        $this->reseller($company, 'alice', 'alice-shop');

        $this->get('http://alice.sub-seo.example.test/robots.txt')
            ->assertOk()
            ->assertSee('Disallow: /checkout', false)
            ->assertDontSee('Sitemap', false);

        // It repeats the company's catalog at the reseller's prices, so it is not listed.
        $this->get('http://alice.sub-seo.example.test/sitemap.xml')->assertNotFound();
    }

    public function test_a_reseller_subdomain_product_page_publishes_no_price_markup(): void
    {
        $company = $this->company('sub-price.example.test');
        $reseller = $this->reseller($company, 'alice', 'alice-shop');
        $product = $this->product($company, 'Alice Pick');
        $this->pick($reseller, $product);

        $response = $this->get('http://alice.sub-price.example.test/product/'.$product->slug)
            ->assertOk()
            ->assertSee('<link rel="canonical" href="http://alice.sub-price.example.test/product/'.$product->slug.'">', false)
            ->assertSee('"BreadcrumbList"', false);

        // The company's price is not this reseller's price.
        $response->assertDontSee('"@type":"Product"', false);
    }

    protected function fakeProbe(bool $live): void
    {
        $this->app->instance(ResellerSubdomainProbe::class, new class($live) extends ResellerSubdomainProbe
        {
            public function __construct(protected bool $live) {}

            public function isLive(string $host, Company $company): bool
            {
                return $this->live;
            }
        });
    }

    protected function company(string $domain): Company
    {
        $company = Company::query()->create([
            'name' => 'Sub Test '.$domain,
            'slug' => Str::slug($domain),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => strtoupper(Str::random(4)),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
            'reseller_module_enabled' => true,
            'reseller_subdomains_enabled' => true,
        ])->fresh();

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'theme_color' => '#0F766E',
            'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
        ]);

        return $company;
    }

    protected function reseller(Company $company, ?string $subdomain, string $slug, string $status = 'approved'): Customer
    {
        static $counter = 0;
        $counter++;

        app(CompanyContext::class)->set($company);

        return Customer::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Reseller '.$counter,
            'phone' => '0192000'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
            'reseller_status' => $status,
            'reseller_slug' => $slug,
            'reseller_subdomain' => $subdomain,
            'business_name' => 'Shop '.$counter,
            'is_active' => true,
        ]);
    }

    protected function product(Company $company, string $name): Product
    {
        static $counter = 0;
        $counter++;

        app(CompanyContext::class)->set($company);

        return Product::query()->create([
            'company_id' => $company->getKey(),
            'name' => $name,
            'sku' => 'SUB-PROD-'.$counter,
            'price' => 500,
            'sale_price' => 500,
            'cost_price' => 300,
            'stock' => 10,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);
    }

    protected function pick(Customer $reseller, Product $product): void
    {
        ResellerProduct::query()->create([
            'customer_id' => $reseller->getKey(),
            'product_id' => $product->getKey(),
            'is_active' => true,
        ]);
    }

    protected function asPortal(Company $company, Customer $reseller): void
    {
        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $this->actingAs($reseller, 'customer');
    }
}
