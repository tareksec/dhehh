<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResellerSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_an_approved_reseller_can_reach_the_store_management_page(): void
    {
        $company = $this->company('access.example.test');
        app(CompanyContext::class)->set($company);

        $plainCustomer = $this->customer($company, 'none');
        $pending = $this->customer($company, 'pending');
        $rejected = $this->customer($company, 'rejected');
        $approved = $this->customer($company, 'approved');

        // Checked first, before any guard state is set below -- the
        // 'customer' guard instance is a singleton for the life of the
        // test, so once actingAsCustomer() sets a user on it there is no
        // clean way to simulate "never logged in" again afterwards.
        $this->get('http://access.example.test/account/reseller')
            ->assertRedirect();

        $this->actingAsCustomer($plainCustomer)
            ->get('http://access.example.test/account/reseller')
            ->assertForbidden();

        $this->actingAsCustomer($pending)
            ->get('http://access.example.test/account/reseller')
            ->assertForbidden();

        $this->actingAsCustomer($rejected)
            ->get('http://access.example.test/account/reseller')
            ->assertForbidden();

        // Approved resellers are sent to the portal (§2.1 Q5).
        $this->actingAsCustomer($approved)
            ->get('http://access.example.test/account/reseller')
            ->assertRedirect('http://reseller.access.example.test/portal');
    }

    public function test_toggling_a_product_adds_and_then_removes_it_from_the_catalog(): void
    {
        $company = $this->company('toggle.example.test');
        app(CompanyContext::class)->set($company);
        $reseller = $this->customer($company, 'approved');
        $product = $this->product($company);

        $this->actingAsCustomer($reseller)
            ->post("http://toggle.example.test/account/reseller/products/{$product->getKey()}")
            ->assertRedirect();

        $this->assertDatabaseHas('reseller_products', [
            'customer_id' => $reseller->getKey(),
            'product_id' => $product->getKey(),
            'is_active' => true,
        ]);

        $this->actingAsCustomer($reseller)
            ->post("http://toggle.example.test/account/reseller/products/{$product->getKey()}")
            ->assertRedirect();

        $this->assertDatabaseMissing('reseller_products', [
            'customer_id' => $reseller->getKey(),
            'product_id' => $product->getKey(),
        ]);
    }

    public function test_a_reseller_cannot_pick_a_product_from_another_company(): void
    {
        $companyA = $this->company('picker-a.example.test');
        $companyB = $this->company('picker-b.example.test');

        app(CompanyContext::class)->set($companyB);
        $otherCompanysProduct = $this->product($companyB);

        app(CompanyContext::class)->set($companyA);
        $reseller = $this->customer($companyA, 'approved');

        $this->actingAsCustomer($reseller)
            ->post("http://picker-a.example.test/account/reseller/products/{$otherCompanysProduct->getKey()}")
            ->assertNotFound();

        $this->assertDatabaseMissing('reseller_products', [
            'customer_id' => $reseller->getKey(),
            'product_id' => $otherCompanysProduct->getKey(),
        ]);
    }

    public function test_slug_update_enforces_uniqueness_within_the_company_only(): void
    {
        $company = $this->company('slug-unique.example.test');
        app(CompanyContext::class)->set($company);
        $taken = $this->customer($company, 'approved');
        $taken->update(['reseller_slug' => 'taken-slug']);
        $reseller = $this->customer($company, 'approved');

        $this->actingAsCustomer($reseller)
            ->from('http://slug-unique.example.test/account/reseller')
            ->patch('http://slug-unique.example.test/account/reseller', ['reseller_slug' => 'taken-slug'])
            ->assertRedirect('http://slug-unique.example.test/account/reseller');

        $this->assertNull($reseller->fresh()->reseller_slug);

        $this->actingAsCustomer($reseller)
            ->patch('http://slug-unique.example.test/account/reseller', ['reseller_slug' => 'my-own-shop'])
            ->assertRedirect();

        $this->assertSame('my-own-shop', $reseller->fresh()->reseller_slug);
    }

    public function test_a_different_companys_reseller_can_reuse_the_same_slug_text(): void
    {
        $companyA = $this->company('cross-a.example.test');
        $companyB = $this->company('cross-b.example.test');

        app(CompanyContext::class)->set($companyA);
        $resellerA = $this->customer($companyA, 'approved');
        $resellerA->update(['reseller_slug' => 'shared-name']);

        app(CompanyContext::class)->set($companyB);
        $resellerB = $this->customer($companyB, 'approved');

        $this->actingAsCustomer($resellerB)
            ->patch('http://cross-b.example.test/account/reseller', ['reseller_slug' => 'shared-name'])
            ->assertRedirect();

        $this->assertSame('shared-name', $resellerB->fresh()->reseller_slug);
    }

    public function test_the_page_is_unreachable_when_the_reseller_module_is_disabled(): void
    {
        $company = $this->company('module-off.example.test');
        $company->update(['reseller_module_enabled' => false]);
        app(CompanyContext::class)->set($company);
        $reseller = $this->customer($company, 'approved');

        $this->actingAsCustomer($reseller)
            ->get('http://module-off.example.test/account/reseller')
            ->assertNotFound();
    }

    /**
     * Authenticates only the 'customer' guard, unlike actingAs() which also
     * calls Auth::shouldUse('customer') and changes the framework's DEFAULT
     * guard for the rest of the test. That side effect doesn't happen in a
     * real request, but it does make AuditObserver's unqualified Auth::id()
     * resolve to the Customer's own key instead of null, which then fails
     * the audit_logs.user_id foreign key against the users table.
     */
    protected function actingAsCustomer(Customer $customer): static
    {
        $this->app['auth']->guard('customer')->setUser($customer);

        return $this;
    }

    protected function company(string $domain): Company
    {
        $company = Company::query()->create([
            'name' => 'Self Service '.$domain,
            'slug' => str($domain)->slug()->toString(),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => strtoupper(Str::random(4)),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
            'reseller_module_enabled' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'theme_color' => '#0F766E',
            'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
        ]);

        return $company;
    }

    protected function customer(Company $company, string $resellerStatus): Customer
    {
        static $counter = 0;
        $counter++;

        return Customer::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Reseller '.$counter,
            'phone' => '0190000'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
            'reseller_status' => $resellerStatus,
            'business_name' => $resellerStatus === 'none' ? null : 'Shop '.$counter,
            'is_active' => true,
        ]);
    }

    protected function product(Company $company): Product
    {
        static $counter = 0;
        $counter++;

        return Product::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Reseller Product '.$counter,
            'sku' => 'RESELLER-PROD-'.$counter,
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
}
