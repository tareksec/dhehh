<?php

namespace Tests\Feature;

use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Filament\Resources\ExchangeRates\Pages\CreateExchangeRate;
use App\Filament\Resources\ExchangeRates\Pages\ListExchangeRates;
use App\Filament\Resources\InvestmentProjects\Pages\EditInvestmentProject;
use App\Filament\Resources\InvestmentProjects\RelationManagers\CostItemsRelationManager;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\ProjectCostItem;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\Investment\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 17_IMS_IN_PORTAL_PLAN.md, phase R: the staff-typed daily USD / RMB rates and
 * project costs entered in a foreign currency. `amount` stays the BDT figure
 * the settlement reads.
 */
class ExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Rates Co',
            'slug' => 'rates-co',
            'invoice_prefix' => 'RTS',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Auth::login($this->admin);
    }

    public function test_the_rate_in_force_is_the_newest_one_on_or_before_the_date(): void
    {
        $this->rate('USD', '2026-10-01', 120);
        $this->rate('USD', '2026-10-05', 122.5);
        $this->rate('USD', '2026-10-10', 125);
        $this->rate('CNY', '2026-10-05', 17);

        $this->assertNull(ExchangeRate::rateOn('USD', '2026-09-30'), 'no rate yet before the first one');
        $this->assertSame(120.0, ExchangeRate::rateOn('USD', '2026-10-01'));
        $this->assertSame(120.0, ExchangeRate::rateOn('USD', '2026-10-04'));
        $this->assertSame(122.5, ExchangeRate::rateOn('USD', '2026-10-05'), 'a rate applies on its own day');
        $this->assertSame(122.5, ExchangeRate::rateOn('USD', '2026-10-09'));
        $this->assertSame(125.0, ExchangeRate::rateOn('USD', '2026-12-31'));
        $this->assertSame(17.0, ExchangeRate::rateOn('CNY', '2026-10-06'), 'each currency has its own rates');
        $this->assertNull(ExchangeRate::rateOn('CNY', '2026-10-04'));
    }

    public function test_another_companys_rate_is_never_used(): void
    {
        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OTH', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        $this->rate('USD', '2026-10-01', 999);
        app(CompanyContext::class)->set($this->company);

        $this->assertNull(ExchangeRate::rateOn('USD', '2026-10-02'));
    }

    public function test_only_one_rate_per_currency_per_day_and_a_rate_must_be_positive_and_known(): void
    {
        $this->rate('USD', '2026-10-01', 120);

        try {
            $this->rate('USD', '2026-10-01', 121);
            $this->fail('A second rate for the same day was accepted.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(1, ExchangeRate::query()->where('currency', 'USD')->count());
        }

        $this->expectException(ValidationException::class);
        $this->rate('USD', '2026-10-02', 0);
    }

    public function test_an_unsupported_currency_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->rate('EUR', '2026-10-02', 130);
    }

    public function test_a_foreign_cost_is_converted_to_bdt_by_the_model_not_the_form(): void
    {
        $project = $this->project();

        // The submitted amount is ignored: it is always original × rate.
        $item = ProjectCostItem::query()->create([
            'project_id' => $project->id, 'category' => 'landed_cost', 'label' => 'Machine (China)',
            'amount' => 1, 'currency' => 'USD', 'original_amount' => 1000, 'exchange_rate' => 122.5, 'cost_date' => '2026-10-05',
        ]);

        $this->assertTrue($item->isForeign());
        $this->assertEquals(122500, $item->fresh()->amount);

        $item->update(['original_amount' => 1200]);
        $this->assertEquals(147000, $item->fresh()->amount, 'editing the original amount re-converts it');

        $item->update(['exchange_rate' => 123.456789]);
        $this->assertEquals(148148.15, (float) $item->fresh()->amount, 'rounded to the paisa');
    }

    public function test_a_bdt_cost_is_unchanged_and_carries_no_foreign_fields(): void
    {
        $project = $this->project();

        $item = ProjectCostItem::query()->create([
            'project_id' => $project->id, 'category' => 'local_expense', 'label' => 'Delivery',
            'amount' => 5000, 'currency' => 'BDT', 'original_amount' => 99, 'exchange_rate' => 99,
        ]);

        $this->assertFalse($item->isForeign());
        $this->assertEquals(5000, $item->fresh()->amount);
        $this->assertNull($item->fresh()->currency);
        $this->assertNull($item->fresh()->original_amount);
        $this->assertNull($item->fresh()->exchange_rate);

        // Switching a foreign cost back to BDT drops the foreign figures and keeps the typed amount.
        $foreign = ProjectCostItem::query()->create(['project_id' => $project->id, 'category' => 'landed_cost', 'label' => 'Goods', 'currency' => 'CNY', 'original_amount' => 1000, 'exchange_rate' => 17, 'cost_date' => '2026-10-05']);
        $this->assertEquals(17000, $foreign->amount);
        $foreign->update(['currency' => null, 'amount' => 18000]);
        $this->assertEquals(18000, $foreign->fresh()->amount);
        $this->assertNull($foreign->fresh()->exchange_rate);
    }

    public function test_a_foreign_cost_without_an_amount_or_a_rate_is_refused(): void
    {
        $project = $this->project();

        foreach ([['original_amount' => 1000, 'exchange_rate' => null], ['original_amount' => null, 'exchange_rate' => 120], ['original_amount' => 1000, 'exchange_rate' => 0]] as $figures) {
            try {
                ProjectCostItem::query()->create(['project_id' => $project->id, 'category' => 'landed_cost', 'label' => 'Bad', 'amount' => 100, 'currency' => 'USD', 'cost_date' => '2026-10-05'] + $figures);
                $this->fail('A foreign cost without both figures was accepted.');
            } catch (ValidationException) {
                $this->assertSame(0, ProjectCostItem::query()->where('label', 'Bad')->count());
            }
        }
    }

    public function test_the_settlement_uses_the_bdt_amount_of_a_foreign_cost(): void
    {
        $project = $this->project();
        $investor = Investor::query()->create(['company_id' => $this->company->id, 'name' => 'Rate Investor', 'phone' => '01710000001']);
        Investment::query()->create(['company_id' => $this->company->id, 'project_id' => $project->id, 'investor_id' => $investor->id, 'amount' => 200000, 'payment_method' => 'bank', 'invested_at' => '2026-10-01']);
        ProjectCostItem::query()->create(['project_id' => $project->id, 'category' => 'landed_cost', 'label' => 'Machine', 'currency' => 'USD', 'original_amount' => 1000, 'exchange_rate' => 122.5, 'cost_date' => '2026-10-05']);
        ProjectCostItem::query()->create(['project_id' => $project->id, 'category' => 'local_expense', 'label' => 'Delivery', 'amount' => 7500]);

        $settlement = app(SettlementService::class)->calculateAndSettle($project, 200000, $this->admin->id, acknowledgeUnprovenCosts: true);

        $this->assertEquals(130000, $settlement->total_cost); // 122,500 + 7,500
        $this->assertEquals(70000, $settlement->net_profit);
        $this->assertEquals(122500, $project->totalLandedCost());
    }

    public function test_cost_form_converts_a_foreign_cost_and_fills_the_rate_from_the_table(): void
    {
        $project = $this->project();
        $this->rate('USD', '2026-10-01', 120);
        $this->rate('USD', '2026-10-05', 122.5);

        Livewire::test(CostItemsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditInvestmentProject::class])
            ->mountTableAction('create')
            ->fillForm(['category' => 'landed_cost', 'label' => 'Machine'])
            ->set('mountedActions.0.data.cost_date', '2026-10-07')
            ->set('mountedActions.0.data.currency', 'USD')
            ->assertSet('mountedActions.0.data.exchange_rate', 122.5)
            ->set('mountedActions.0.data.original_amount', 1000)
            ->assertSet('mountedActions.0.data.amount', 122500.0)
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $item = ProjectCostItem::query()->where('label', 'Machine')->sole();
        $this->assertSame('USD', $item->currency);
        $this->assertEquals(122500, $item->amount);
        $this->assertEquals(122.5, (float) $item->exchange_rate);
    }

    public function test_cost_form_needs_the_original_amount_and_rate_for_a_foreign_cost(): void
    {
        $project = $this->project();

        Livewire::test(CostItemsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditInvestmentProject::class])
            ->mountTableAction('create')
            ->fillForm(['category' => 'landed_cost', 'label' => 'No rate', 'currency' => 'CNY', 'cost_date' => '2026-10-07'])
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['original_amount' => 'required', 'exchange_rate' => 'required']);

        $this->assertSame(0, ProjectCostItem::query()->where('label', 'No rate')->count());
    }

    public function test_cost_table_shows_the_original_amount_and_rate(): void
    {
        $project = $this->project();
        ProjectCostItem::query()->create(['project_id' => $project->id, 'category' => 'landed_cost', 'label' => 'Machine', 'currency' => 'USD', 'original_amount' => 1000, 'exchange_rate' => 122.5, 'cost_date' => '2026-10-05']);

        Livewire::test(CostItemsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditInvestmentProject::class])
            ->assertSee('USD 1,000.00 @ 122.5');
    }

    public function test_exchange_rate_page_lists_and_creates_rates_and_refuses_a_second_one_for_the_same_day(): void
    {
        $this->rate('USD', '2026-10-01', 120);

        Livewire::test(ListExchangeRates::class)
            ->assertSuccessful()
            ->assertSee('US Dollar (USD)');

        Livewire::test(CreateExchangeRate::class)
            ->fillForm(['currency' => 'CNY', 'rate_date' => '2026-10-02', 'rate' => 17.25, 'note' => 'Bank rate'])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = ExchangeRate::query()->where('currency', 'CNY')->sole();
        $this->assertEquals(17.25, (float) $created->rate);
        $this->assertSame($this->admin->id, $created->created_by);

        Livewire::test(CreateExchangeRate::class)
            ->fillForm(['currency' => 'USD', 'rate_date' => '2026-10-01', 'rate' => 121])
            ->call('create')
            ->assertHasFormErrors(['rate_date' => 'unique']);

        $this->assertSame(1, ExchangeRate::query()->where('currency', 'USD')->count());
    }

    public function test_only_staff_who_manage_investments_may_change_rates(): void
    {
        $rate = $this->rate('USD', '2026-10-01', 120);
        UserRole::query()->create(['name' => 'Investment Viewer', 'slug' => 'investment_viewer', 'is_active' => true, 'permissions' => ['investments.view']]);
        Auth::login(User::factory()->create(['role' => 'investment_viewer', 'is_active' => true]));

        $this->assertFalse(ExchangeRateResource::canCreate());
        $this->assertFalse(ExchangeRateResource::canEdit($rate));
        $this->assertFalse(ExchangeRateResource::canDelete($rate));

        Auth::login($this->admin);
        $this->assertTrue(ExchangeRateResource::canCreate());
        $this->assertTrue(ExchangeRateResource::canEdit($rate));
    }

    private function rate(string $currency, string $date, float $rate): ExchangeRate
    {
        return ExchangeRate::query()->create(['currency' => $currency, 'rate_date' => $date, 'rate' => $rate]);
    }

    private function project(): InvestmentProject
    {
        return InvestmentProject::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Rates Project',
            'duration_type' => 'custom_days',
            'trade_cycle_days' => 60,
            'start_date' => '2026-10-01',
            'investor_share_percent' => 40,
            'channel_partner_share_percent' => 10,
            'company_share_percent' => 50,
            'status' => 'closed',
        ]);
    }
}
