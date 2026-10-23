<?php

namespace Tests\Feature;

use App\Filament\Trade\Resources\DeliveryChallans\Pages\ViewDeliveryChallan;
use App\Filament\Trade\Resources\LcFiles\Pages\LandedCost;
use App\Filament\Trade\Resources\LcFiles\Pages\ViewLcFile;
use App\Http\Middleware\SetTradePortalLocale;
use App\Models\Company;
use App\Models\LcFile;
use App\Models\MachineSerial;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\DeliveryChallanService;
use App\Services\LandedCostService;
use App\Services\LcFileService;
use App\Services\MachineSerialService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Trade Portal phase 5 (docs/tasneem-samples/PLAN.md §3.6–3.7): the landed cost worksheet and machine serial numbers. */
class TradeLandedCostTest extends TestCase
{
    use RefreshDatabase;

    protected Purchase $purchase;

    protected Product $machine;

    protected LcFile $file;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::query()->create(['name' => 'Tasneem', 'slug' => 'tasneem-'.uniqid(), 'invoice_prefix' => 'TK'.random_int(1000, 9999), 'is_active' => true, 'trade_portal_enabled' => true]);
        app(CompanyContext::class)->set($company);

        $supplier = Supplier::query()->create(['name' => 'Matill']);
        $this->machine = Product::query()->create(['name' => 'Used circular knitting machine', 'sku' => 'UCKM', 'unit' => 'set', 'sale_price' => 750000, 'price' => 750000, 'cost_price' => 0, 'is_active' => true]);
        $this->purchase = Purchase::query()->create(['supplier_id' => $supplier->getKey(), 'purchase_date' => '2026-09-06', 'status' => 'draft']);
        $this->purchase->items()->create(['product_id' => $this->machine->getKey(), 'quantity' => 8, 'unit_cost' => 0]);
        $this->file = app(LcFileService::class)->save(null, ['purchase_id' => $this->purchase->getKey(), 'lc_value_usd' => 35600]);
    }

    public function test_the_worksheet_adds_the_goods_at_the_bank_rate_and_every_cost_and_shares_it_per_machine(): void
    {
        $service = app(LandedCostService::class);
        $service->setRate($this->file, 122);
        $service->addLine($this->file, ['kind' => 'duty', 'amount' => 43432]);
        $service->addLine($this->file, ['kind' => 'cnf', 'amount' => 35000]);
        $service->addLine($this->file, ['kind' => 'truck', 'amount' => 18000]);
        $service->addLine($this->file, ['kind' => 'loading', 'amount' => 6000]);
        $service->addLine($this->file, ['kind' => 'other', 'label' => 'Port demurrage', 'amount' => 3568, 'source' => 'cnf_agent']);

        $sheet = $service->worksheet($this->file->refresh());

        $this->assertSame(4343200.0, $sheet['goods_bdt']);
        $this->assertSame('rate', $sheet['goods_from']);
        $this->assertSame(106000.0, $sheet['costs']);
        $this->assertSame(4449200.0, $sheet['total']);
        $this->assertSame(8, $sheet['quantity']);
        $this->assertSame(556150.0, $sheet['per_unit']);
        $this->assertSame(556150.0, $sheet['items'][0]['landed_unit']);

        $lines = collect($sheet['lines']);
        $this->assertSame('cnf_agent', $lines->firstWhere('kind', 'duty')->source, 'Duty comes from the C&F agent by default.');
        $this->assertSame('owner', $lines->firstWhere('kind', 'truck')->source, 'Truck fare is the owner\'s figure by default.');
        $this->assertSame('Port demurrage', $lines->firstWhere('kind', 'other')->displayLabel());
    }

    public function test_the_costs_do_not_change_the_purchase_or_the_supplier_due_until_applied(): void
    {
        $service = app(LandedCostService::class);
        $service->setRate($this->file, 122);
        $service->addLine($this->file, ['kind' => 'duty', 'amount' => 56800]);
        $this->purchase->update(['status' => 'received']);

        $this->assertSame('0.00', $this->purchase->refresh()->total_amount);
        $this->assertSame(0.0, (float) $this->purchase->supplier->refresh()->current_balance);
        $this->assertSame('0.00', $this->machine->refresh()->cost_price);

        $this->assertSame(1, $service->applyToStock($this->file->refresh()));
        $this->assertSame('550000.00', $this->machine->refresh()->cost_price);
        $this->assertNotNull($this->file->refresh()->landed_cost_applied_at);
    }

    public function test_several_kinds_of_goods_share_the_total_by_value(): void
    {
        $feeder = Product::query()->create(['name' => 'Positive feeder', 'sku' => 'PF', 'unit' => 'pcs', 'sale_price' => 0, 'price' => 0, 'is_active' => true]);
        $this->purchase->items()->first()->update(['fob_unit_price_usd' => 4250]);
        $this->purchase->items()->create(['product_id' => $feeder->getKey(), 'quantity' => 100, 'unit_cost' => 0, 'fob_unit_price_usd' => 16]);

        $service = app(LandedCostService::class);
        $service->setRate($this->file, 100);
        $service->addLine($this->file, ['kind' => 'duty', 'amount' => 40000]);

        $sheet = $service->worksheet($this->file->refresh());

        // FOB 34,000 (machines) + 1,600 (feeders): the total 35,60,000 + 40,000 = 36,00,000 is shared 34000 : 1600.
        $this->assertSame(3600000.0, $sheet['total']);
        $this->assertSame(3438202.25, $sheet['items'][0]['landed_total']);
        $this->assertSame(161797.75, $sheet['items'][1]['landed_total']);
        $this->assertSame(1617.98, $sheet['items'][1]['landed_unit']);
    }

    public function test_bad_lines_and_rates_are_refused(): void
    {
        $service = app(LandedCostService::class);

        $this->assertThrows(fn () => $service->addLine($this->file, ['kind' => 'nonsense', 'amount' => 10]), ValidationException::class);
        $this->assertThrows(fn () => $service->addLine($this->file, ['kind' => 'other', 'amount' => 10]), ValidationException::class);
        $this->assertThrows(fn () => $service->addLine($this->file, ['kind' => 'duty', 'amount' => 0]), ValidationException::class);
        $this->assertThrows(fn () => $service->setRate($this->file, -1), ValidationException::class);
        $this->assertThrows(fn () => $service->applyToStock($this->file), ValidationException::class);
    }

    protected function owner(): User
    {
        $owner = User::factory()->create(['role' => 'trade_owner', 'is_active' => true]);
        $owner->companies()->attach($this->file->company_id, ['role' => 'trade_owner', 'is_default' => true]);

        return $owner;
    }

    public function test_the_landed_cost_page_adds_costs_sets_the_rate_and_applies_it(): void
    {
        $owner = $this->owner();
        $this->assertContains('trade.landed_cost', UserRole::query()->where('slug', 'trade_owner')->value('permissions'));
        $this->assertTrue($owner->isTradePortalOnly());

        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('trade'));

        Livewire::test(LandedCost::class, ['record' => $this->file->getKey()])
            ->callAction('setRate', ['exchange_rate' => 122])
            ->assertHasNoActionErrors()
            ->callAction('addLine', ['kind' => 'duty', 'amount' => 43432, 'source' => 'cnf_agent'])
            ->assertHasNoActionErrors()
            ->callAction('addLine', ['kind' => 'other', 'label' => 'Port demurrage', 'amount' => 3568, 'source' => 'cnf_agent'])
            ->assertHasNoActionErrors()
            ->assertSee('Port demurrage')
            ->callAction('apply')
            ->assertNotified();

        // (35,600 × 122 = 43,43,200) + 43,432 + 3,568 = 43,90,200 over 8 machines.
        $this->assertSame('548775.00', $this->machine->refresh()->cost_price);

        $line = $this->file->landedCostLines()->where('kind', 'other')->firstOrFail();
        Livewire::test(LandedCost::class, ['record' => $this->file->getKey()])
            ->callAction('deleteLine', arguments: ['line' => $line->getKey()]);
        $this->assertSame(1, $this->file->landedCostLines()->count());

        app(CompanyContext::class)->clear();
        $this->actingAs($owner)->withSession([SetTradePortalLocale::SESSION_KEY => 'en'])
            ->get('/trade/lc-files/'.$this->file->getKey().'/landed-cost')
            ->assertOk()->assertSee('Landed cost per unit')->assertSee('Customs duty, AIT, ATV, RD');
    }

    public function test_the_landed_cost_page_needs_its_permission(): void
    {
        UserRole::query()->create(['name' => 'LC clerk', 'slug' => 'lc_clerk', 'permissions' => ['trade.access', 'trade.lc'], 'is_active' => true]);
        $clerk = User::factory()->create(['role' => 'lc_clerk', 'is_active' => true]);
        $clerk->companies()->attach($this->file->company_id, ['role' => 'lc_clerk', 'is_default' => true]);
        app(CompanyContext::class)->clear();

        $this->actingAs($clerk)->get('/trade/lc-files/'.$this->file->getKey())->assertOk();
        $this->actingAs($clerk)->get('/trade/lc-files/'.$this->file->getKey().'/landed-cost')->assertForbidden();
    }

    public function test_serials_are_entered_on_the_lc_file_and_chosen_on_the_challan_page(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('trade'));

        Livewire::test(ViewLcFile::class, ['record' => $this->file->getKey()])
            ->callAction('serials', ['product_id' => $this->machine->getKey(), 'serials' => "JH-34-001\nJH-34-002"])
            ->assertHasNoActionErrors();
        $this->assertSame(2, MachineSerial::query()->count());

        $this->purchase->update(['status' => 'received']);
        $challan = app(DeliveryChallanService::class)->create([
            'buyer_name' => 'Madina Knitwear',
            'items' => [['product_id' => $this->machine->getKey(), 'description' => 'Machine', 'quantity' => 2, 'unit_price' => 750000]],
        ]);

        Livewire::test(ViewDeliveryChallan::class, ['record' => $challan->getKey()])
            ->callAction('serials', ['serials' => MachineSerial::query()->pluck('id')->map(fn ($id): string => (string) $id)->all()])
            ->assertHasNoActionErrors();

        app(CompanyContext::class)->clear();
        $this->actingAs($owner)->get(route('filament.trade.challans.print', $challan))
            ->assertOk()->assertSee('S/N: JH-34-001, JH-34-002');
        $this->actingAs($owner)->withSession([SetTradePortalLocale::SESSION_KEY => 'en'])->get('/trade/serials')
            ->assertOk()->assertSee('JH-34-002')->assertSee('Madina Knitwear');
        $this->actingAs($owner)->withSession([SetTradePortalLocale::SESSION_KEY => 'en'])->get('/trade/lc-files/'.$this->file->getKey())
            ->assertOk()->assertSee('JH-34-001')->assertSee('Sold');
    }

    public function test_serials_are_written_on_the_purchase_and_leave_with_the_challan(): void
    {
        $serials = app(MachineSerialService::class);

        $this->assertSame(2, $serials->addToPurchase($this->purchase, $this->machine->getKey(), "JH-34-001\nJH-34-002, JH-34-002"));
        $this->assertThrows(fn () => $serials->addToPurchase($this->purchase, $this->machine->getKey(), 'JH-34-001'), ValidationException::class);
        $this->assertThrows(fn () => $serials->addToPurchase($this->purchase, $this->machine->getKey(), implode("\n", range(1, 7))), ValidationException::class);

        $this->purchase->update(['status' => 'received']);
        $challan = app(DeliveryChallanService::class)->create([
            'buyer_name' => 'Madina Knitwear',
            'items' => [['product_id' => $this->machine->getKey(), 'description' => 'Machine', 'quantity' => 1, 'unit_price' => 750000]],
        ]);

        $ids = MachineSerial::query()->orderBy('serial_number')->pluck('id')->all();
        $this->assertCount(2, $serials->optionsFor($challan));
        // Only one machine is on the challan, so two serials are refused.
        $this->assertThrows(fn () => $serials->setForChallan($challan, $ids), ValidationException::class);

        $serials->setForChallan($challan, [$ids[0]]);
        $this->assertSame(['JH-34-001'], $challan->serials()->pluck('serial_number')->all());

        app(DeliveryChallanService::class)->cancel($challan, 'Written by mistake');
        $this->assertSame(0, MachineSerial::query()->whereNotNull('delivery_challan_id')->count());
    }
}
