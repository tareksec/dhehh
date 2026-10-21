<?php

namespace Tests\Feature;

use App\Filament\Pages\ResellerSalesReport;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ResellerPayoutRequest;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\ResellerCommissionService;
use App\Services\ResellerSalesReportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin "Reseller sales report" (owner request 2026-10-16): one row per
 * reseller for a period. Sales are confirmed/processing/completed orders; a
 * cancelled or returned order is only counted, never added to the money.
 */
class ResellerSalesReportTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Company $other;

    protected Customer $anna;

    protected Customer $bina;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-16 12:00:00');

        $this->company = $this->makeCompany('Report Co', 'report-co');
        $this->other = $this->makeCompany('Elsewhere Co', 'elsewhere-co');
        app(CompanyContext::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $this->company->getKey()]);

        $this->anna = $this->makeReseller($this->company, 'Anna Traders');
        $this->bina = $this->makeReseller($this->company, 'Bina Shop');
        $this->product = $this->makeProduct($this->company);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_adds_up_each_resellers_orders_and_money_for_the_period(): void
    {
        // Anna: two live orders in October (one delivered), a cancelled one and a returned one.
        $this->sell($this->anna, 'A-1', Order::STATUS_CONFIRMED, '2026-10-03', 2);
        $this->sell($this->anna, 'A-2', Order::STATUS_COMPLETED, '2026-10-10', 3, delivered: true);
        $this->sell($this->anna, 'A-3', Order::STATUS_CANCELLED, '2026-10-11', 1);
        $this->sell($this->anna, 'A-4', Order::STATUS_RETURNED, '2026-10-12', 1);
        // Bina: one order, and one that falls outside the period.
        $this->sell($this->bina, 'B-1', Order::STATUS_PROCESSING, '2026-10-05', 1);
        $this->sell($this->bina, 'B-2', Order::STATUS_CONFIRMED, '2026-09-20', 5);

        $rows = app(ResellerSalesReportService::class)
            ->rows(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))
            ->keyBy('id');

        $anna = $rows[$this->anna->getKey()];
        $this->assertSame(2, $anna['orders']);
        $this->assertSame(1, $anna['cancelled']);
        $this->assertSame(1, $anna['returned']);
        // 5 pieces sold at 200, cost 100 each; the cancelled and returned orders add nothing.
        $this->assertSame(1000.0, $anna['sales']);
        $this->assertSame(500.0, $anna['cost']);
        $this->assertSame(500.0, $anna['profit']);
        $this->assertSame(300.0, $anna['payable']);
        $this->assertSame(200.0, $anna['pending']);

        $bina = $rows[$this->bina->getKey()];
        $this->assertSame(1, $bina['orders']);
        $this->assertSame(200.0, $bina['sales']);
        $this->assertSame(100.0, $bina['profit']);

        $totals = app(ResellerSalesReportService::class)->totals($rows->values());
        $this->assertSame(2, $totals['resellers']);
        $this->assertSame(3, $totals['orders']);
        $this->assertSame(1200.0, $totals['sales']);
        $this->assertSame(600.0, $totals['profit']);
    }

    public function test_the_period_decides_which_orders_count_and_the_biggest_seller_comes_first(): void
    {
        $this->sell($this->anna, 'A-1', Order::STATUS_CONFIRMED, '2026-10-03', 1);
        $this->sell($this->bina, 'B-1', Order::STATUS_CONFIRMED, '2026-09-20', 4);

        $service = app(ResellerSalesReportService::class);

        $september = $service->rows(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
        $this->assertSame([$this->bina->getKey()], $september->pluck('id')->all());

        $both = $service->rows(Carbon::parse('2026-09-01'), Carbon::parse('2026-10-31'));
        $this->assertSame([$this->bina->getKey(), $this->anna->getKey()], $both->pluck('id')->all());

        $this->assertTrue($service->rows(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'))->isEmpty());
    }

    public function test_money_paid_out_in_the_period_is_shown_and_only_paid_requests_count(): void
    {
        $this->payout($this->anna, 150, ResellerPayoutRequest::STATUS_PAID, '2026-10-08');
        $this->payout($this->anna, 400, ResellerPayoutRequest::STATUS_REQUESTED, null);
        $this->payout($this->anna, 90, ResellerPayoutRequest::STATUS_PAID, '2026-09-08');

        $rows = app(ResellerSalesReportService::class)->rows(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

        $this->assertSame(150.0, $rows->sole()['paid_out']);
        $this->assertSame(0, $rows->sole()['orders']);
    }

    public function test_another_companys_resellers_never_appear(): void
    {
        $stranger = $this->makeReseller($this->other, 'Stranger Shop');
        $theirProduct = $this->makeProduct($this->other);
        $this->sell($stranger, 'X-1', Order::STATUS_CONFIRMED, '2026-10-03', 9, $theirProduct);
        $this->sell($this->anna, 'A-1', Order::STATUS_CONFIRMED, '2026-10-03', 1);

        app(CompanyContext::class)->set($this->company->fresh());

        $rows = app(ResellerSalesReportService::class)->rows(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

        $this->assertSame([$this->anna->getKey()], $rows->pluck('id')->all());
        $this->assertSame(200.0, $rows->sole()['sales']);
    }

    public function test_the_page_shows_this_months_rows_and_the_dates_can_be_changed(): void
    {
        $this->sell($this->anna, 'A-1', Order::STATUS_CONFIRMED, '2026-10-03', 2);
        $this->sell($this->bina, 'B-1', Order::STATUS_CONFIRMED, '2026-09-20', 1);

        $page = Livewire::test(ResellerSalesReport::class)
            ->assertSee('Anna Traders')
            ->assertDontSee('Bina Shop')
            ->assertSee('Reseller sales report');

        $page->filterTable('dates', ['from' => '2026-09-01', 'until' => '2026-09-30'])
            ->assertSee('Bina Shop')
            ->assertDontSee('Anna Traders');

        Livewire::test(ResellerSalesReport::class)
            ->filterTable('period', 'last_month')
            ->assertSee('Bina Shop')
            ->assertDontSee('Anna Traders');
    }

    public function test_the_page_is_closed_when_the_companys_reseller_module_is_off(): void
    {
        $this->assertTrue(ResellerSalesReport::canAccess());

        $this->company->forceFill(['reseller_module_enabled' => false])->save();
        app(CompanyContext::class)->set($this->company->fresh());

        $this->assertFalse(ResellerSalesReport::canAccess());
    }

    protected function makeCompany(string $name, string $slug): Company
    {
        return Company::query()->create([
            'name' => $name, 'slug' => $slug, 'domain' => $slug.'.example.test', 'domain_verified' => true,
            'invoice_prefix' => strtoupper(substr($slug, 0, 3)), 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka',
            'is_active' => true, 'reseller_module_enabled' => true,
        ])->fresh();
    }

    protected function makeReseller(Company $company, string $name): Customer
    {
        app(CompanyContext::class)->set($company);

        return Customer::query()->create([
            'company_id' => $company->getKey(), 'name' => $name, 'phone' => '019'.random_int(10000000, 99999999),
            'reseller_status' => 'approved', 'reseller_slug' => 'shop-'.Str::lower(Str::random(6)),
            'password' => Hash::make('secret-password'), 'is_active' => true,
        ]);
    }

    protected function makeProduct(Company $company): Product
    {
        app(CompanyContext::class)->set($company);

        $product = Product::query()->create([
            'company_id' => $company->getKey(), 'name' => 'Widget', 'sku' => Str::upper(Str::random(8)), 'price' => 200, 'sale_price' => 200,
            'cost_price' => 100, 'stock' => 0, 'reorder_level' => 1, 'unit' => 'pcs', 'vat_rate' => 0, 'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);
        StockMovement::query()->create(['product_id' => $product->getKey(), 'type' => 'opening', 'quantity' => 1000]);

        return $product;
    }

    /** An order credited to a reseller (sold at 200, reseller cost 100 a piece); its commission row follows the order's status. */
    protected function sell(Customer $reseller, string $number, string $status, string $date, int $quantity, ?Product $product = null, bool $delivered = false): Order
    {
        $company = Company::withoutGlobalScopes()->findOrFail($reseller->company_id);
        app(CompanyContext::class)->set($company);
        $product ??= $this->product;

        $buyer = Customer::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'phone' => '01800000000'],
            ['name' => 'Buyer', 'address' => 'Some address', 'is_active' => true],
        );

        $order = Order::withoutEvents(fn () => Order::query()->create(array_filter([
            'company_id' => $company->getKey(), 'order_number' => $number, 'customer_id' => $buyer->getKey(),
            'reseller_customer_id' => $reseller->getKey(), 'customer_name' => $buyer->name, 'order_date' => $date,
            'subtotal' => 200 * $quantity, 'discount' => 0, 'vat' => 0, 'shipping_fee' => 0, 'total_amount' => 200 * $quantity,
            'paid_amount' => 0, 'due_amount' => 200 * $quantity, 'status' => $status, 'source' => Order::SOURCE_STOREFRONT,
            // Left to the column's own default unless the parcel was delivered.
            'delivery_status' => $delivered ? CourierBooking::STATUS_DELIVERED : null,
        ], fn ($value): bool => $value !== null)));

        OrderItem::withoutEvents(fn () => OrderItem::query()->create([
            'company_id' => $company->getKey(), 'order_id' => $order->getKey(), 'product_id' => $product->getKey(),
            'quantity' => $quantity, 'unit_price' => 200, 'unit_cost' => 100, 'reseller_base_price' => 100, 'subtotal' => 200 * $quantity,
        ]));

        app(ResellerCommissionService::class)->sync($order->fresh());

        app(CompanyContext::class)->set($this->company);

        return $order;
    }

    protected function payout(Customer $reseller, float $amount, string $status, ?string $paidOn): ResellerPayoutRequest
    {
        app(CompanyContext::class)->set($this->company);

        return ResellerPayoutRequest::query()->create([
            'company_id' => $reseller->company_id, 'customer_id' => $reseller->getKey(), 'amount' => $amount, 'method' => 'bkash',
            'account_details' => '01711111111', 'status' => $status, 'paid_at' => $paidOn,
        ]);
    }
}
