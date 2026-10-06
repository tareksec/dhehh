<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CustomerAccountService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Security fixes from the 2026-10-03 audit: the /storefront/{slug} preview
 * must not leak another company's storefront or a customer's order details,
 * and a customer's password change must sign out their other sessions.
 */
class StorefrontPreviewAndSessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_preview_pages_reject_staff_from_another_company(): void
    {
        $ownCompany = $this->createStorefrontCompany('Own Preview Store', 'own-preview.example.test');
        $otherCompany = $this->createStorefrontCompany('Other Preview Store', 'other-preview.example.test');

        foreach ([$ownCompany, $otherCompany] as $company) {
            app(CompanyContext::class)->set($company);
            StorefrontPage::query()->create([
                'title' => 'About',
                'slug' => 'about',
                'content' => 'About page',
                'is_published' => true,
            ]);
        }
        app(CompanyContext::class)->clear();

        $user = $this->staffFor($ownCompany, 'sales_staff');

        foreach (['/pages/about', '/contact', '/complaints', '/cart', '/checkout', '/track', '/account/orders'] as $path) {
            $this->actingAs($user)
                ->get("http://127.0.0.1/storefront/{$otherCompany->slug}{$path}")
                ->assertNotFound();
        }

        $this->actingAs($user)
            ->get("http://127.0.0.1/storefront/{$ownCompany->slug}/pages/about")
            ->assertOk();
    }

    public function test_preview_order_success_page_requires_order_view_permission(): void
    {
        $company = $this->createStorefrontCompany('Success Preview Store', 'success-preview.example.test');

        app(CompanyContext::class)->set($company);
        $customer = Customer::query()->create([
            'name' => 'Private Buyer',
            'phone' => '01730303030',
            'address' => 'Secret delivery address',
            'is_active' => true,
        ]);
        $order = Order::query()->create([
            'customer_id' => $customer->getKey(),
            'customer_name' => $customer->name,
            'status' => 'draft',
            'source' => Order::SOURCE_STOREFRONT,
        ]);
        app(CompanyContext::class)->clear();

        $url = "http://127.0.0.1/storefront/{$company->slug}/checkout/success/{$order->getKey()}";

        $this->actingAs($this->staffFor($company, 'inventory_staff'))
            ->get($url)
            ->assertNotFound();

        $this->actingAs($this->staffFor($company, 'sales_staff'))
            ->get($url)
            ->assertOk()
            ->assertSee($order->order_number);
    }

    public function test_password_change_signs_out_other_sessions_but_keeps_this_one(): void
    {
        $this->createStorefrontCompany('Session Store', 'session.example.test');

        $this->post('http://session.example.test/account/register', [
            'name' => 'Session Buyer',
            'phone' => '01740404040',
            'password' => 'original-pass',
            'password_confirmation' => 'original-pass',
        ]);
        $this->assertAuthenticated('customer');

        $customer = Customer::query()->where('phone', '01740404040')->firstOrFail();
        $oldRememberToken = $customer->remember_token;
        $oldSessionStamp = session('password_hash_customer');
        $this->assertNotNull($oldSessionStamp);

        $this->put('http://session.example.test/account/password', [
            'current_password' => 'original-pass',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertRedirect('http://session.example.test/account/profile');

        // This device stays signed in after its own change.
        $this->get('http://session.example.test/account/profile')->assertOk();
        $this->assertAuthenticated('customer');

        $customer->refresh();
        $this->assertTrue(Hash::check('brand-new-pass', $customer->password));
        $this->assertNotSame($oldRememberToken, $customer->remember_token);

        // Another device: still carries the stamp of the old password.
        $this->actingAs($customer, 'customer')
            ->withSession(['password_hash_customer' => $oldSessionStamp])
            ->get('http://session.example.test/products');

        $this->assertGuest('customer');
    }

    public function test_password_reset_rotates_the_remember_token(): void
    {
        $company = $this->createStorefrontCompany('Reset Token Store', 'reset-token.example.test');

        app(CompanyContext::class)->set($company);
        $customer = Customer::query()->create([
            'name' => 'Reset Buyer',
            'phone' => '01750505050',
            'is_active' => true,
        ]);
        $customer->forceFill([
            'password' => Hash::make('old-password'),
            'remember_token' => 'old-remember-token',
            'password_reset_code' => Hash::make('123456'),
            'password_reset_expires_at' => now()->addMinutes(10),
        ])->save();

        $this->assertTrue(app(CustomerAccountService::class)->resetPassword($customer, '123456', 'new-password'));
        $this->assertNotSame('old-remember-token', $customer->fresh()->remember_token);
    }

    public function test_a_session_stamped_with_the_current_password_stays_signed_in(): void
    {
        $this->createStorefrontCompany('Stamp Store', 'stamp.example.test');

        $this->post('http://stamp.example.test/account/register', [
            'name' => 'Stamp Buyer',
            'phone' => '01760606060',
            'password' => 'stamp-pass',
            'password_confirmation' => 'stamp-pass',
        ]);

        $customer = Customer::query()->where('phone', '01760606060')->firstOrFail();

        $this->actingAs($customer, 'customer')
            ->withSession(['password_hash_customer' => Auth::guard('customer')->hashPasswordForCookie($customer->password)])
            ->get('http://stamp.example.test/products')
            ->assertOk();

        $this->assertAuthenticated('customer');
    }

    private function staffFor(Company $company, string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $user->companies()->detach();
        $user->companies()->attach($company, ['role' => $role, 'is_default' => true]);

        return $user;
    }

    private function createStorefrontCompany(string $name, string $domain): Company
    {
        $company = Company::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => str($domain)->slug('')->substr(0, 12)->upper()->toString(),
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
            'new_customer_delivery_advance_enabled' => false,
        ]);

        return $company;
    }
}
