<?php

namespace Tests\Feature;

use App\Filament\Trade\Resources\GoodsIn\Pages\ListGoodsIn;
use App\Filament\Trade\Resources\LcFiles\Pages\EditLcFile;
use App\Filament\Trade\Resources\LcFiles\Pages\LandedCost;
use App\Filament\Trade\Resources\LcFiles\Pages\ViewLcFile;
use App\Http\Middleware\SetTradePortalLocale;
use App\Models\Company;
use App\Models\DeploymentError;
use App\Models\LcFile;
use App\Models\MachineSerial;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\LandedCostService;
use App\Services\LcFileService;
use App\Services\TradePortalHome;
use App\Services\TradeReportService;
use App\Support\TradeReportFormat;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * The live QA run of 2026-10-23 on the Trade Portal (Tasneem Knitting
 * Industry, TEST- data only): an LC file with no bank rate, no DRL date and
 * no TIN / BIN / IRC in the business details, whose goods are already in.
 */
class TradePortalQaRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected LcFile $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create(['name' => 'Tasneem Knitting Industry', 'slug' => 'tasneem-'.uniqid(), 'invoice_prefix' => 'TK'.random_int(1000, 9999), 'is_active' => true, 'trade_portal_enabled' => true]);
        $this->owner = User::factory()->create(['role' => 'trade_owner', 'is_active' => true]);
        $this->owner->companies()->attach($this->company->getKey(), ['role' => 'trade_owner', 'is_default' => true]);
        app(CompanyContext::class)->set($this->company);

        $supplier = Supplier::query()->create(['name' => 'TEST-Supplier-001']);
        $purchase = Purchase::query()->create(['supplier_id' => $supplier->getKey(), 'purchase_date' => now()->toDateString(), 'status' => 'received']);

        foreach ([['TEST-Machine-001', 2, 40000], ['TEST-Stand-001', 5, 2000], ['TEST-Feeder-001', 5, 500]] as [$name, $quantity, $cost]) {
            $product = Product::query()->create(['name' => $name, 'sku' => $name, 'unit' => 'pcs', 'sale_price' => 0, 'price' => 0, 'is_active' => true]);
            $purchase->items()->create(['product_id' => $product->getKey(), 'quantity' => $quantity, 'unit_cost' => $cost]);
        }

        $this->file = app(LcFileService::class)->save(null, [
            'purchase_id' => $purchase->getKey(), 'lc_number' => 'TEST-LC-001', 'lc_date' => now()->toDateString(), 'pi_number' => 'TEST-PI-001', 'pi_date' => now()->toDateString(),
            'lc_value_usd' => 1000, 'margin_amount_bdt' => 10000, 'lc_bank' => 'TEST Bank', 'lc_account_number' => 'TEST-AC-001', 'bank_bin' => 'TEST-BANKBIN-001',
            'insurer_name' => 'TEST Insurance Co Ltd', 'cover_note_number' => 'TEST-CN-001', 'cover_note_date' => now()->subDay()->toDateString(),
            'ci_number' => 'TEST-CI-001', 'bl_number' => 'TEST-BL-001', 'container_number' => 'TEST-CONT-001', 'seal_number' => 'TEST-SEAL-001',
            'certificate_date' => now()->toDateString(), 'stage' => 'lc_opened',
        ]);
        app(LandedCostService::class)->addLine($this->file, ['kind' => 'duty', 'amount' => 12000]);
        app(CompanyContext::class)->clear();
    }

    protected function inPortal(): void
    {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('trade'));
        app(CompanyContext::class)->set($this->company);
    }

    public function test_every_report_opens_and_downloads_with_an_lc_file(): void
    {
        foreach (array_keys(TradeReportService::REPORTS) as $key) {
            $this->actingAs($this->owner)->get('/trade/report?type='.$key)->assertOk();
            $this->actingAs($this->owner)->withSession([SetTradePortalLocale::SESSION_KEY => 'en'])->get('/trade/report?type='.$key)->assertOk();

            foreach (['pdf', 'xlsx', 'print'] as $format) {
                $this->actingAs($this->owner)->get(route('filament.trade.reports.download', ['report' => $key, 'format' => $format]))->assertOk();
            }
        }
    }

    public function test_the_english_pdf_writes_tk_for_the_taka_sign(): void
    {
        app()->setLocale('en');

        $this->assertSame('Tk 1,04,500', TradeReportFormat::pdf(104500, 'money'));
        $this->assertSame('৳ 1,04,500', TradeReportFormat::text(104500, 'money'));
    }

    public function test_the_drl_prints_without_its_date_and_the_lc_pages_open(): void
    {
        $this->actingAs($this->owner)->get('/trade/lc-files/'.$this->file->getKey())->assertOk();
        $this->actingAs($this->owner)->get('/trade/lc-files/'.$this->file->getKey().'/edit')->assertOk();
        $this->actingAs($this->owner)->get('/trade/lc-files/'.$this->file->getKey().'/landed-cost')->assertOk();
        $this->actingAs($this->owner)->get('/trade/serials')->assertOk();

        $this->actingAs($this->owner)->get(route('filament.trade.lc-files.document', ['lcFile' => $this->file, 'document' => 'drl']))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_lc_edit_landed_cost_and_serial_actions_save_and_show_the_page_again(): void
    {
        $this->inPortal();

        Livewire::test(EditLcFile::class, ['record' => $this->file->getKey()])
            ->fillForm(['vessel_name' => 'TEST-VESSEL'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('TEST-VESSEL', $this->file->refresh()->vessel_name);

        // The confirmation names each item's own new price, which is what is applied.
        Livewire::test(LandedCost::class, ['record' => $this->file->getKey()])
            ->callAction('addLine', ['kind' => 'port', 'amount' => 500, 'source' => 'other'])->assertHasNoActionErrors()
            ->mountAction('apply')
            ->assertMountedActionModalSee('TEST-Machine-001 ৳ 45,405.41')
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame(45405.41, (float) Product::query()->where('name', 'TEST-Machine-001')->value('cost_price'));

        $machine = Product::query()->where('name', 'TEST-Machine-001')->value('id');
        Livewire::test(ViewLcFile::class, ['record' => $this->file->getKey()])
            ->callAction('serials', ['product_id' => $machine, 'serials' => "TEST-SN-001\nTEST-SN-002"])->assertHasNoActionErrors();
        $this->assertSame(['TEST-SN-001', 'TEST-SN-002'], MachineSerial::query()->orderBy('serial_number')->pluck('serial_number')->all());
    }

    public function test_an_lc_still_open_at_the_bank_counts_on_home_after_its_goods_are_in(): void
    {
        app(CompanyContext::class)->set($this->company);

        $this->assertSame(1, app(TradePortalHome::class)->openLcs()['count']);

        $this->file->update(['stage' => 'cleared']);
        $this->assertSame(0, app(TradePortalHome::class)->openLcs()['count']);
    }

    public function test_goods_in_shows_its_new_purchase_button(): void
    {
        $this->inPortal();

        Livewire::test(ListGoodsIn::class)->assertActionVisible('create');
    }

    public function test_a_crashing_trade_page_shows_the_module_page_and_reaches_the_super_admins(): void
    {
        config(['app.isolate_module_errors' => true]);
        Route::middleware('web')->get('/trade/qa-crash', fn () => throw new RuntimeException('trade page failed'));

        $this->get('/trade/qa-crash')->assertStatus(500)->assertSee('href="'.url('/trade').'"', false)->assertDontSee('trade page failed');
        $this->assertTrue(DeploymentError::query()->where('source', 'module:page:trade/qa-crash')->exists());
    }
}
