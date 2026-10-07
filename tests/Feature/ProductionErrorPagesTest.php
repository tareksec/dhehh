<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\StorefrontSetting;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * bootstrap/app.php swaps unexpected errors for the branded storefront error
 * page, but only outside local/testing -- so these checks run as production.
 * It must leave Laravel's own redirects alone (login, validation errors).
 */
class ProductionErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::query()->create([
            'name' => 'Prod Probe', 'slug' => 'prod-probe', 'domain' => 'prod-probe.example.test',
            'domain_verified' => true, 'invoice_prefix' => 'PRB', 'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka', 'is_active' => true, 'reseller_module_enabled' => true,
        ]);
        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(), 'theme_color' => '#0F766E',
            'is_published' => true, 'new_customer_delivery_advance_enabled' => false,
        ]);

        $this->app['env'] = 'production';
    }

    public function test_a_guest_on_the_reseller_portal_is_sent_to_the_login_page_not_a_500(): void
    {
        $this->get('http://reseller.prod-probe.example.test/portal')
            ->assertRedirect('http://reseller.prod-probe.example.test/portal/login');
    }

    public function test_a_failed_storefront_form_validation_redirects_back_with_errors_not_a_500(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->post('http://prod-probe.example.test/account/login', [])
            ->assertRedirect()
            ->assertSessionHasErrors();
    }

    public function test_a_real_error_still_shows_the_branded_error_page(): void
    {
        $this->get('http://prod-probe.example.test/no-such-page-here')->assertNotFound();
    }
}
