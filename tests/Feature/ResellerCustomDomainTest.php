<?php

namespace Tests\Feature;

use App\Filament\Reseller\Pages\MyStore;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\ResellerDomainDnsChecker;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 7b of 13_RESELLER_PORTAL_PLAN.md: a reseller's own domain (e.g.
 * myshop.com) opens their store once its DNS points at us.
 */
class ResellerCustomDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_verified_custom_domain_shows_only_that_resellers_store(): void
    {
        $company = $this->company('cd-shop.example.test');
        $mine = $this->reseller($company, 'myshop.test', 'alice-shop');
        $other = $this->reseller($company, null, 'bob-shop');
        $this->pick($mine, $this->product($company, 'Alice Pick'));
        $this->pick($other, $this->product($company, 'Bob Pick'));

        $this->get('http://myshop.test/products')
            ->assertOk()
            ->assertSee('Alice Pick')
            ->assertDontSee('Bob Pick');
    }

    public function test_the_custom_domain_front_page_goes_to_the_product_list(): void
    {
        $company = $this->company('cd-front.example.test');
        $this->reseller($company, 'front-shop.test', 'alice-shop');

        $this->get('http://front-shop.test/')->assertRedirect('/products');
    }

    public function test_an_unverified_custom_domain_404s(): void
    {
        $company = $this->company('cd-unver.example.test');
        $this->reseller($company, 'unverified-shop.test', 'alice-shop', 'approved', false);

        $this->get('http://unverified-shop.test/products')->assertNotFound();
    }

    public function test_custom_domains_404_for_pending_resellers_and_when_the_switch_is_off(): void
    {
        $company = $this->company('cd-off.example.test');
        $this->reseller($company, 'pending-shop.test', 'pend-shop', 'pending');
        $this->reseller($company, 'off-shop.test', 'off-slug');
        $company->update(['reseller_custom_domains_enabled' => false]);

        $this->get('http://pending-shop.test/products')->assertNotFound();
        $this->get('http://off-shop.test/products')->assertNotFound();
    }

    public function test_the_portal_is_not_reachable_on_a_custom_domain(): void
    {
        $company = $this->company('cd-portal.example.test');
        $this->reseller($company, 'portal-shop.test', 'alice-shop');

        $this->get('http://portal-shop.test/portal')->assertNotFound();
    }

    public function test_store_url_prefers_a_live_custom_domain_and_falls_back_when_off(): void
    {
        $company = $this->company('cd-url.example.test');
        $reseller = $this->reseller($company, 'url-shop.test', 'alice-shop');

        $this->assertSame('https://url-shop.test', $reseller->fresh()->resellerStoreUrl());

        $company->update(['reseller_custom_domains_enabled' => false]);

        $this->assertSame('https://cd-url.example.test/store/alice-shop/products', $reseller->fresh()->resellerStoreUrl());
    }

    public function test_the_tls_ask_endpoint_only_approves_verified_custom_domains(): void
    {
        $company = $this->company('cd-ask.example.test');
        $this->reseller($company, 'ask-ok.test', 'ok-shop');
        $this->reseller($company, 'ask-unverified.test', 'un-shop', 'approved', false);

        $this->get('http://cd-ask.example.test/internal/tls-ask?domain=ask-ok.test')->assertOk();
        $this->get('http://cd-ask.example.test/internal/tls-ask?domain=ask-unverified.test')->assertNotFound();
        $this->get('http://cd-ask.example.test/internal/tls-ask?domain=random.test')->assertNotFound();
        $this->get('http://cd-ask.example.test/internal/tls-ask')->assertNotFound();
    }

    public function test_platform_hosts_cannot_be_claimed(): void
    {
        $company = $this->company('cd-taken.example.test');

        $this->assertTrue(Company::hostIsTakenByThePlatform('cd-taken.example.test'));
        $this->assertTrue(Company::hostIsTakenByThePlatform('reseller.cd-taken.example.test'));
        $this->assertTrue(Company::hostIsTakenByThePlatform('anything.cd-taken.example.test'));
        $this->assertFalse(Company::hostIsTakenByThePlatform('free-domain.test'));
    }

    public function test_a_reseller_saves_a_domain_and_it_starts_unverified(): void
    {
        $company = $this->company('cd-save.example.test');
        $reseller = $this->reseller($company, null, 'alice-shop');
        $this->asPortal($company, $reseller);

        Livewire::test(MyStore::class)
            ->fillForm(['reseller_custom_domain' => 'https://www.My-Shop.test/'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $reseller->fresh();
        $this->assertSame('my-shop.test', $fresh->reseller_custom_domain);
        $this->assertNull($fresh->reseller_custom_domain_verified_at);
    }

    public function test_changing_a_verified_domain_requires_verifying_again(): void
    {
        $company = $this->company('cd-change.example.test');
        $reseller = $this->reseller($company, 'old-shop.test', 'alice-shop');
        $this->asPortal($company, $reseller);

        Livewire::test(MyStore::class)
            ->fillForm(['reseller_custom_domain' => 'new-shop.test'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($reseller->fresh()->reseller_custom_domain_verified_at);
    }

    public function test_saving_other_settings_keeps_a_verified_domain_verified(): void
    {
        $company = $this->company('cd-keep.example.test');
        $reseller = $this->reseller($company, 'keep-shop.test', 'alice-shop');
        $this->asPortal($company, $reseller);

        Livewire::test(MyStore::class)
            ->fillForm(['reseller_store_name' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotNull($reseller->fresh()->reseller_custom_domain_verified_at);
    }

    public function test_domain_validation_rejects_bad_platform_and_duplicate_domains(): void
    {
        $company = $this->company('cd-valid.example.test');
        $this->reseller($company, 'taken-shop.test', 'taken-shop');
        $reseller = $this->reseller($company, null, 'alice-shop');
        $this->asPortal($company, $reseller);

        foreach (['not a domain', 'localhost', 'cd-valid.example.test', 'x.cd-valid.example.test', 'taken-shop.test', '1.2.3'] as $bad) {
            Livewire::test(MyStore::class)
                ->fillForm(['reseller_custom_domain' => $bad])
                ->call('save')
                ->assertHasFormErrors(['reseller_custom_domain']);
        }

        $this->assertNull($reseller->fresh()->reseller_custom_domain);
    }

    public function test_check_dns_verifies_only_when_the_domain_points_to_us(): void
    {
        $company = $this->company('cd-dns.example.test');
        $reseller = $this->reseller($company, 'dns-shop.test', 'alice-shop', 'approved', false);
        $this->asPortal($company, $reseller);

        $this->app->instance(ResellerDomainDnsChecker::class, new class extends ResellerDomainDnsChecker
        {
            public function pointsToUs(string $domain, Company $company): bool
            {
                return false;
            }
        });
        Livewire::test(MyStore::class)->call('verifyDomain');
        $this->assertNull($reseller->fresh()->reseller_custom_domain_verified_at);

        $this->app->instance(ResellerDomainDnsChecker::class, new class extends ResellerDomainDnsChecker
        {
            public function pointsToUs(string $domain, Company $company): bool
            {
                return true;
            }
        });
        Livewire::test(MyStore::class)->call('verifyDomain');
        $this->assertNotNull($reseller->fresh()->reseller_custom_domain_verified_at);
    }

    public function test_the_dns_checker_matches_on_a_shared_ip(): void
    {
        $company = $this->company('cd-checker.example.test');

        $checker = new class extends ResellerDomainDnsChecker
        {
            protected function resolve(string $host): array
            {
                return match ($host) {
                    'cd-checker.example.test' => ['10.0.0.1'],
                    'good.test' => ['10.0.0.1', '10.0.0.9'],
                    default => ['192.0.2.5'],
                };
            }
        };

        $this->assertTrue($checker->pointsToUs('good.test', $company));
        $this->assertFalse($checker->pointsToUs('elsewhere.test', $company));
    }

    public function test_the_domain_field_is_hidden_when_the_switch_is_off(): void
    {
        $company = $this->company('cd-hidden.example.test');
        $company->update(['reseller_custom_domains_enabled' => false]);
        $reseller = $this->reseller($company, null, 'alice-shop');
        $this->asPortal($company, $reseller);

        Livewire::test(MyStore::class)
            ->assertFormFieldIsHidden('reseller_custom_domain')
            ->call('save');

        $this->assertNull($reseller->fresh()->reseller_custom_domain);
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
            'reseller_custom_domains_enabled' => true,
        ])->fresh();

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'theme_color' => '#0F766E',
            'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
        ]);

        return $company;
    }

    protected function reseller(Company $company, ?string $customDomain, string $slug, string $status = 'approved', bool $verified = true): Customer
    {
        static $counter = 0;
        $counter++;

        app(CompanyContext::class)->set($company);

        return Customer::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Reseller '.$counter,
            'phone' => '0193000'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
            'reseller_status' => $status,
            'reseller_slug' => $slug,
            'reseller_custom_domain' => $customDomain,
            'reseller_custom_domain_verified_at' => $customDomain && $verified ? now() : null,
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
            'sku' => 'CUST-PROD-'.$counter,
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
