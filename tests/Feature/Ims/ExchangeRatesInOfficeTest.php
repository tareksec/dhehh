<?php

namespace Tests\Feature\Ims;

use App\Filament\Ims\Office\OfficeNavigation;
use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Models\ExchangeRate;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 17_IMS_IN_PORTAL_PLAN.md phase R inside IMS v2: once a company runs IMS on its
 * own domain the ERP's Investments pages live in the office, so the exchange
 * rate page must be reachable there too and the old ERP address must lead to it.
 */
class ExchangeRatesInOfficeTest extends TestCase
{
    use ImsTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imsCompany();
    }

    public function test_the_exchange_rates_page_opens_in_the_office_and_is_in_its_menu(): void
    {
        ExchangeRate::query()->create(['currency' => 'USD', 'rate_date' => '2026-10-01', 'rate' => 122.5]);
        $staff = $this->staff();

        Filament::setCurrentPanel(Filament::getPanel('office'));

        $this->actingAs($staff)->get($this->imsUrl(parse_url(ExchangeRateResource::getUrl(panel: 'office'), PHP_URL_PATH)))
            ->assertOk()
            ->assertSee('US Dollar (USD)');

        $items = collect(OfficeNavigation::layout())->flatMap(fn (array $section): array => $section['items'])->pluck(0);
        $this->assertTrue($items->contains(ExchangeRateResource::class));
    }

    public function test_the_old_erp_address_of_the_page_goes_to_the_office(): void
    {
        $this->actingAs($this->staff())->withSession(['current_company_id' => $this->company->id])
            ->get('http://localhost/admin/investments/exchange-rates')
            ->assertRedirect('http://'.$this->company->ims_domain.'/office/investments/exchange-rates?office_company='.$this->company->id);
    }
}
