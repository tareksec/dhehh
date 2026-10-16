<?php

namespace Tests\Feature;

use App\Filament\Reseller\Pages\CreateOrder;
use App\Filament\Reseller\Pages\Dashboard;
use App\Filament\Reseller\Pages\Earnings;
use App\Filament\Reseller\Pages\Orders;
use App\Filament\Reseller\Pages\Products;
use App\Models\Company;
use App\Models\Customer;
use App\Services\CompanyContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The phone tab bar of the reseller portal (owner request 2026-10-15): five
 * links at the bottom of the screen for approved resellers, never for guests or
 * pending applicants, and only below the large breakpoint.
 */
class ResellerPortalBottomNavTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_approved_reseller_gets_the_five_tabs_with_the_right_links(): void
    {
        [$company, $reseller] = $this->portal('tabs.example.test');
        Filament::setCurrentPanel(Filament::getPanel('reseller'));

        $html = $this->actingAs($reseller, 'customer')->get('http://reseller.tabs.example.test/portal/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('data-reseller-bottom-nav', $html);
        foreach ([
            'dashboard' => Dashboard::getUrl(),
            'products' => Products::getUrl(),
            'create-order' => CreateOrder::getUrl(),
            'orders' => Orders::getUrl(),
            'earnings' => Earnings::getUrl(),
        ] as $key => $url) {
            $path = parse_url($url, PHP_URL_PATH);
            $this->assertMatchesRegularExpression('/<a\s+href="[^"]*'.preg_quote($path, '/').'"[^>]*data-bottom-nav="'.$key.'"/s', $html, "Tab {$key} should link to {$path}");
        }

        // Only phone widths: it is hidden from the large breakpoint up.
        $this->assertMatchesRegularExpression('/<nav[^>]*reseller-bottom-nav[^>]*lg:hidden/s', $html);
        // The page being viewed is the highlighted tab.
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*data-bottom-nav="dashboard"/s', $html);
    }

    public function test_the_tab_of_the_current_page_is_the_highlighted_one(): void
    {
        [$company, $reseller] = $this->portal('tabs-current.example.test');
        Filament::setCurrentPanel(Filament::getPanel('reseller'));

        $html = $this->actingAs($reseller, 'customer')->get('http://reseller.tabs-current.example.test/portal/orders')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*data-bottom-nav="orders"/s', $html);
        $this->assertDoesNotMatchRegularExpression('/aria-current="page"[^>]*data-bottom-nav="dashboard"/s', $html);
    }

    public function test_guests_and_pending_applicants_do_not_get_it(): void
    {
        [$company, $reseller] = $this->portal('tabs-guest.example.test');

        $this->get('http://reseller.tabs-guest.example.test/portal/login')->assertOk()->assertDontSee('data-reseller-bottom-nav', false);

        $reseller->forceFill(['reseller_status' => 'pending'])->save();
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $html = $this->actingAs($reseller->fresh(), 'customer')->get('http://reseller.tabs-guest.example.test/portal/dashboard')->getContent();
        $this->assertStringNotContainsString('data-reseller-bottom-nav', $html);
    }

    /** @return array{0: Company, 1: Customer} */
    protected function portal(string $domain): array
    {
        $company = Company::query()->create([
            'name' => 'Portal '.$domain, 'slug' => Str::slug($domain), 'domain' => $domain, 'domain_verified' => true,
            'invoice_prefix' => strtoupper(Str::random(4)), 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka',
            'is_active' => true, 'reseller_module_enabled' => true,
        ])->fresh();
        app(CompanyContext::class)->set($company);

        $reseller = Customer::query()->create([
            'company_id' => $company->getKey(), 'name' => 'Tab Reseller', 'phone' => '019'.random_int(10000000, 99999999),
            'reseller_status' => 'approved', 'reseller_slug' => 'shop-'.Str::lower(Str::random(6)), 'business_name' => 'Tab Shop',
            'password' => Hash::make('secret-password'), 'is_active' => true,
        ]);

        return [$company, $reseller];
    }
}
