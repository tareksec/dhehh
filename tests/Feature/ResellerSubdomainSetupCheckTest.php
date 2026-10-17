<?php

namespace Tests\Feature;

use App\Filament\Pages\ResellerSettings;
use App\Models\Company;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\ResellerDomainDnsChecker;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Test subdomain setup" on Reseller Settings (owner request 2026-10-15): the
 * admin can check the wildcard DNS record and wildcard SSL certificate without
 * waiting for a reseller to pick a name, and is told which one is missing.
 */
class ResellerSubdomainSetupCheckTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Check Co', 'slug' => 'check-co', 'domain' => 'offer.check-co.example.test', 'domain_verified' => true,
            'invoice_prefix' => 'CHK', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
            'reseller_module_enabled' => true, 'reseller_subdomains_enabled' => true,
        ])->fresh();
        StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(), 'theme_color' => '#0F766E', 'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
        ]);
        app(CompanyContext::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $this->company->getKey()]);
    }

    public function test_it_reports_success_when_a_made_up_subdomain_opens_over_https(): void
    {
        $this->dnsPointsToUs(true);
        Http::fake(['https://setup-test-*' => Http::response('Not found', 404)]);

        Livewire::test(ResellerSettings::class)
            ->callAction('checkSubdomainSetup')
            ->assertNotified('Subdomains open over HTTPS');

        // Any HTTP answer counts, and the name tried is a random one under the company's own domain.
        Http::assertSent(fn ($request): bool => (bool) preg_match('#^https://setup-test-[a-z0-9]{6}\.offer\.check-co\.example\.test/$#', $request->url()));
    }

    public function test_it_says_the_wildcard_dns_record_is_missing(): void
    {
        $this->dnsPointsToUs(false);
        Http::fake();

        Livewire::test(ResellerSettings::class)
            ->callAction('checkSubdomainSetup')
            ->assertNotified('Subdomains do not reach this server yet');

        Http::assertNothingSent();
    }

    public function test_it_says_the_certificate_is_missing_and_why(): void
    {
        $this->dnsPointsToUs(true);
        Http::fake(fn () => throw new ConnectionException('cURL error 60: SSL certificate problem: unable to get local issuer certificate'));

        Livewire::test(ResellerSettings::class)
            ->callAction('checkSubdomainSetup')
            ->assertNotified('Subdomains resolve but HTTPS does not work');
    }

    public function test_the_check_is_not_offered_to_a_company_without_a_domain(): void
    {
        $this->company->forceFill(['domain' => null])->save();
        app(CompanyContext::class)->set($this->company->fresh());

        Livewire::test(ResellerSettings::class)->assertActionHidden('checkSubdomainSetup');
    }

    protected function dnsPointsToUs(bool $points): void
    {
        $this->app->instance(ResellerDomainDnsChecker::class, new class($points) extends ResellerDomainDnsChecker
        {
            public function __construct(protected bool $points) {}

            public function pointsToUs(string $domain, Company $company): bool
            {
                return $this->points;
            }
        });
    }
}
