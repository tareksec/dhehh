<?php

namespace Tests\Feature;

use App\Filament\Trade\Resources\DeliveryChallans\Pages\CreateDeliveryChallan;
use App\Filament\Trade\Resources\DeliveryChallans\Pages\ViewDeliveryChallan;
use App\Filament\Trade\Resources\Dues\Pages\ListDues;
use App\Filament\Trade\Resources\GoodsIn\Pages\CreateGoodsIn;
use App\Filament\Trade\Resources\GoodsIn\Pages\ListGoodsIn;
use App\Filament\Trade\Resources\StockItems\Pages\CreateStockItem;
use App\Filament\Trade\Resources\StockItems\Pages\ListStockItems;
use App\Http\Middleware\SetTradePortalLocale;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryChallan;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\DeliveryChallanService;
use App\Services\TradeImportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/** Trade Portal phase 2 (BUILD-BRIEF.md): challans, goods in, stock, dues and old data, through the portal's pages. */
class TradePortalShopWorkTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->company();
        $this->owner = $this->staff($this->company, 'trade_owner');
    }

    protected function company(array $attributes = []): Company
    {
        return Company::query()->create([
            'name' => 'Trade Co '.uniqid(), 'slug' => 'trade-'.uniqid(), 'invoice_prefix' => 'TR'.random_int(1000, 9999),
            'is_active' => true, 'trade_portal_enabled' => true, 'bin_number' => '006673859-0112', 'challan_bin_number' => '006673859-0403',
            ...$attributes,
        ]);
    }

    protected function staff(Company $company, string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $user->companies()->attach($company->getKey(), ['role' => $role, 'is_default' => true]);

        return $user;
    }

    /** Signed in on the trade panel with the company open, for Livewire tests. */
    protected function inPortal(): void
    {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('trade'));
        app(CompanyContext::class)->set($this->company);
    }

    protected function product(string $name, int $stock, string $unit = 'pcs'): Product
    {
        app(CompanyContext::class)->set($this->company);
        $product = Product::query()->create(['name' => $name, 'sku' => 'T-'.uniqid(), 'unit' => $unit, 'sale_price' => 0, 'price' => 0, 'is_active' => true]);
        $product->setStockFromProductForm($stock);

        return $product->refresh();
    }

    public function test_the_trade_owner_gets_the_shop_permissions_and_every_page_opens(): void
    {
        $permissions = UserRole::query()->where('slug', 'trade_owner')->value('permissions');
        foreach (['trade.sales', 'trade.purchases', 'trade.stock', 'trade.dues', 'trade.import'] as $permission) {
            $this->assertContains($permission, $permissions);
        }

        foreach (['/trade', '/trade/challans', '/trade/challans/create', '/trade/stock', '/trade/stock/create', '/trade/dues', '/trade/goods-in', '/trade/goods-in/create', '/trade/import'] as $url) {
            $this->actingAs($this->owner)->withSession([SetTradePortalLocale::SESSION_KEY => 'en'])->get($url)->assertOk();
        }
    }

    public function test_someone_with_only_the_portal_permission_cannot_open_the_shop_pages(): void
    {
        UserRole::query()->create(['name' => 'Viewer', 'slug' => 'trade_viewer', 'permissions' => ['trade.access'], 'is_active' => true]);
        $viewer = $this->staff($this->company, 'trade_viewer');

        foreach (['/trade/challans', '/trade/stock', '/trade/dues', '/trade/goods-in', '/trade/import'] as $url) {
            $this->actingAs($viewer)->get($url)->assertForbidden();
        }
    }

    public function test_a_challan_is_written_on_the_phone_form_and_printed_on_the_letterhead(): void
    {
        $machine = $this->product('Circular knitting machine', 4, 'set');
        $stand = $this->product('Side stand', 4, 'set');
        $this->inPortal();

        Livewire::test(CreateDeliveryChallan::class)
            ->fillForm([
                'challan_number' => '026',
                'buyer_name' => 'Madina Knitwear',
                'buyer_address' => 'BSCIC, Narayanganj',
                'items' => [
                    ['product_id' => $machine->getKey(), 'description' => 'Brand: Chuantian 34X72F', 'quantity' => 2, 'unit' => 'set', 'unit_price' => 750000],
                    ['product_id' => $stand->getKey(), 'description' => 'Used China Side Stand', 'quantity' => 2, 'unit' => 'set'],
                ],
                'advance_amount' => 100000,
                'cash_amount' => 800000,
                'cheque_number' => '3097332',
                'cheque_bank' => 'DBBL',
                'due_terms' => 'Within next 2 months',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $challan = DeliveryChallan::query()->where('challan_number', '026')->firstOrFail();
        $this->assertSame(600000.0, $challan->dueNow());
        $this->assertSame(2, (int) $machine->refresh()->stock);

        $this->actingAs($this->owner)->withSession([SetTradePortalLocale::SESSION_KEY => 'en'])->get('/trade/challans/'.$challan->getKey())
            ->assertOk()->assertSee('Madina Knitwear')->assertSee('Due now');

        $this->actingAs($this->owner)->get(route('filament.trade.challans.print', $challan))
            ->assertOk()
            ->assertSee('DELIVERY CHALLAN')
            ->assertSee('BIN NO: 006673859-0403')
            ->assertSee('Brand: Chuantian 34X72F')
            ->assertSee('6,00,000')
            ->assertSee('Within next 2 months');
    }

    public function test_another_companys_challan_is_not_shown_or_printed(): void
    {
        $other = $this->company();
        app(CompanyContext::class)->set($other);
        $theirs = app(DeliveryChallanService::class)->create(['buyer_name' => 'Their buyer', 'items' => [['description' => 'Sample']]]);

        // A request starts with no company open (the portal opens the person's own).
        app(CompanyContext::class)->clear();

        $this->actingAs($this->owner)->get('/trade/challans/'.$theirs->getKey())->assertNotFound();
        $this->actingAs($this->owner)->get('/trade/challans/'.$theirs->getKey().'/print')->assertNotFound();
    }

    public function test_money_is_received_from_the_challan_page_and_from_dues(): void
    {
        $machine = $this->product('Machine', 3, 'set');
        $challan = app(DeliveryChallanService::class)->create([
            'buyer_name' => 'Rahim Knit',
            'items' => [['product_id' => $machine->getKey(), 'description' => 'Machine', 'quantity' => 1, 'unit_price' => 500000]],
        ]);
        $this->inPortal();

        Livewire::test(ViewDeliveryChallan::class, ['record' => $challan->getKey()])
            ->callAction('receiveMoney', ['amount' => 200000, 'date' => now()->toDateString()])
            ->assertHasNoActionErrors();

        $this->assertSame(300000.0, $challan->refresh()->dueNow());

        $customer = Customer::query()->where('name', 'Rahim Knit')->firstOrFail();
        Livewire::test(ListDues::class)
            ->assertCanSeeTableRecords([$customer])
            ->callTableAction('receiveMoney', $customer, ['against' => (string) $challan->getKey(), 'amount' => 300000, 'date' => now()->toDateString()])
            ->assertHasNoTableActionErrors();

        $this->assertSame(0.0, $challan->refresh()->dueNow());
        $this->assertSame(0.0, (float) $customer->refresh()->current_balance);
    }

    public function test_goods_in_adds_stock_now_or_when_the_goods_arrive(): void
    {
        $part = $this->product('Feeder', 0);
        $supplier = Supplier::query()->create(['name' => 'Hengli']);
        $this->inPortal();

        Livewire::test(CreateGoodsIn::class)
            ->fillForm(['supplier_id' => $supplier->getKey(), 'lc_number' => 'LC-1', 'arrived' => false, 'items' => [['product_id' => $part->getKey(), 'quantity' => 10, 'unit_cost' => 50]]])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(0, (int) $part->refresh()->stock);
        $purchase = Purchase::query()->where('lc_number', 'LC-1')->firstOrFail();

        Livewire::test(ListGoodsIn::class)->callTableAction('arrived', $purchase);

        $this->assertSame('received', $purchase->refresh()->status);
        $this->assertSame(10, (int) $part->refresh()->stock);
    }

    public function test_a_new_item_with_its_opening_stock_and_a_count_correction(): void
    {
        $this->inPortal();

        Livewire::test(CreateStockItem::class)
            ->fillForm(['name' => 'Needle VO 71', 'unit' => 'pcs', 'sale_price' => 12, 'reorder_level' => 100, 'opening_stock' => 500])
            ->call('create')
            ->assertHasNoFormErrors();

        $needle = Product::query()->where('name', 'Needle VO 71')->firstOrFail();
        $this->assertSame(500, (int) $needle->stock);
        $this->assertStringStartsWith('TRD-', $needle->sku);

        Livewire::test(ListStockItems::class)
            ->callTableAction('correctStock', $needle, ['stock' => 480, 'reason' => 'Counted the shelf'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(480, (int) $needle->refresh()->stock);
    }

    public function test_old_buyers_with_dues_and_items_with_stock_come_in_from_csv_and_excel(): void
    {
        app(CompanyContext::class)->set($this->company);
        $service = app(TradeImportService::class);

        $csv = tempnam(sys_get_temp_dir(), 'buyers');
        file_put_contents($csv, "name,phone,address,opening_balance\nOld Party,01711000001,Fatullah,45000\n");
        $this->assertSame(1, $service->importBuyers($csv, 'csv')['created']);
        $this->assertSame(45000.0, (float) Customer::query()->where('name', 'Old Party')->value('current_balance'));

        $xlsx = tempnam(sys_get_temp_dir(), 'items').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($xlsx);
        $writer->addRow(Row::fromValues(['name', 'unit', 'stock', 'reorder_level']));
        $writer->addRow(Row::fromValues(['Sinker SK-2', 'pcs', 300, 50]));
        $writer->close();

        $this->assertSame(1, $service->importItems($xlsx, 'xlsx')['created']);
        $sinker = Product::query()->where('name', 'Sinker SK-2')->firstOrFail();
        $this->assertSame(300, (int) $sinker->stock);
        $this->assertSame('TRD-SINKER-SK-2', $sinker->sku);

        // The same file again updates the item instead of adding it twice.
        $this->assertSame(1, $service->importItems($xlsx, 'xlsx')['updated']);
        $this->assertSame(1, Product::query()->where('name', 'Sinker SK-2')->count());

        @unlink($csv);
        @unlink($xlsx);
    }
}
