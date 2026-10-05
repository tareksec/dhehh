<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\StorefrontSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\TestCase;

/**
 * Drives the portal's forms through the real HTTP Livewire endpoint (host
 * middleware, the randomised "livewire-<hash>/update" URL, CSRF) instead of
 * Livewire::test(), which skips all of that. The portal shipped with its
 * Livewire requests redirected away and the company unresolved on them, so
 * applying and logging in silently did nothing -- the component tests could
 * not see it.
 */
class ResellerPortalLivewireHttpTest extends TestCase
{
    use RefreshDatabase;

    protected string $host = 'https://reseller.lw-portal.example.test';

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        // Company id 1 is the install's default company; the portal company
        // must be a different one so a wrongly-defaulted company shows up.
        $this->company = Company::query()->create([
            'name' => 'Portal Co', 'slug' => 'lw-portal', 'domain' => 'lw-portal.example.test',
            'domain_verified' => true, 'invoice_prefix' => 'LWP', 'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka', 'is_active' => true, 'reseller_module_enabled' => true,
        ]);
        StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(), 'theme_color' => '#0F766E',
            'is_published' => true, 'new_customer_delivery_advance_enabled' => false,
        ]);
    }

    public function test_the_livewire_update_url_is_not_redirected_away_on_the_portal_host(): void
    {
        $this->livewireCall('/portal/login', 'authenticate', [
            'data.email' => '01700000000', 'data.password' => 'whatever',
        ])->assertOk();
    }

    public function test_applying_through_the_portal_saves_the_reseller_to_the_portal_company(): void
    {
        $this->livewireCall('/portal/register', 'register', [
            'data.name' => 'Lina', 'data.phone' => '01711111111', 'data.business_name' => 'Lina Shop',
            'data.password' => 'Passw0rd!xyz', 'data.passwordConfirmation' => 'Passw0rd!xyz',
        ])->assertOk();

        $reseller = Customer::withoutGlobalScopes()->where('phone', '01711111111')->first();

        $this->assertNotNull($reseller, 'The application was not saved.');
        $this->assertSame((int) $this->company->getKey(), (int) $reseller->company_id);
        $this->assertSame('pending', $reseller->reseller_status);
    }

    public function test_a_wrong_password_shows_an_error_instead_of_doing_nothing(): void
    {
        $this->makeReseller('approved');

        $response = $this->livewireCall('/portal/login', 'authenticate', [
            'data.email' => '01722222222', 'data.password' => 'wrong-password',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('data.email', json_encode($response->json()));
    }

    public function test_an_approved_reseller_can_log_in_through_the_portal(): void
    {
        $reseller = $this->makeReseller('approved');

        $response = $this->livewireCall('/portal/login', 'authenticate', [
            'data.email' => '01722222222', 'data.password' => 'Passw0rd!xyz',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('redirect', json_encode($response->json()));
        $this->assertAuthenticatedAs($reseller, 'customer');
    }

    /** @param  array<string, mixed>  $updates */
    protected function livewireCall(string $pagePath, string $method, array $updates)
    {
        $page = $this->get($this->host.$pagePath)->assertOk();
        preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
        $snapshot = html_entity_decode($matches[1], ENT_QUOTES);

        return $this->withSession(['_token' => 'tok'])
            ->withHeaders(['X-Livewire' => 'true', 'X-CSRF-TOKEN' => 'tok'])
            ->postJson($this->host.EndpointResolver::updatePath(), [
                '_token' => 'tok',
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => $updates,
                    'calls' => [['path' => '', 'method' => $method, 'params' => []]],
                ]],
            ]);
    }

    protected function makeReseller(string $status): Customer
    {
        $customer = new Customer([
            'company_id' => $this->company->getKey(),
            'name' => 'Existing Reseller',
            'phone' => '01722222222',
            'reseller_status' => $status,
            'business_name' => 'Existing Shop',
            'is_active' => true,
        ]);
        $customer->company_id = $this->company->getKey();
        $customer->forceFill(['password' => Hash::make('Passw0rd!xyz')])->save();

        return $customer;
    }
}
