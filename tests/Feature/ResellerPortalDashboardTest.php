<?php

namespace Tests\Feature;

use App\Filament\Reseller\Pages\Orders as ResellerOrdersPage;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ResellerCommission;
use App\Models\StockMovement;
use App\Services\CompanyContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 3 of 13_RESELLER_PORTAL_PLAN.md: the portal's Overview (Dashboard)
 * and Orders pages -- balances, this-month stats, recent orders, the store
 * link card, and a full read-only order list scoped to the reseller's own
 * orders. The owner confirmed (2026-09-28) that resellers see full buyer
 * info (name, phone, address), not masked.
 */
class ResellerPortalDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_renders_with_balances_and_recent_orders_for_an_approved_reseller(): void
    {
        [$company, $reseller] = $this->setUpCompany('dashboard.example.test');
        $this->makeDeliveredOrder($company, $reseller, 'ORD-DASH-1');

        $this->actingAs($reseller, 'customer');

        $this->followingRedirects()
            ->get('http://reseller.dashboard.example.test/portal')
            ->assertOk()
            ->assertSee('ORD-DASH-1')
            ->assertSee($reseller->reseller_slug);
    }

    public function test_the_orders_page_only_lists_orders_credited_to_this_reseller(): void
    {
        [$company, $reseller] = $this->setUpCompany('orders-scope.example.test');
        $ownOrder = $this->makeDeliveredOrder($company, $reseller, 'ORD-OWN-1');

        [$otherCompany, $otherReseller] = $this->setUpCompany('orders-scope-other.example.test');
        $otherOrder = $this->makeDeliveredOrder($otherCompany, $otherReseller, 'ORD-OTHER-1');

        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $this->actingAs($reseller, 'customer');

        Livewire::test(ResellerOrdersPage::class)
            ->assertCanSeeTableRecords([$ownOrder])
            ->assertCanNotSeeTableRecords([$otherOrder]);
    }

    public function test_the_orders_page_shows_full_customer_info_and_commission(): void
    {
        [$company, $reseller] = $this->setUpCompany('orders-detail.example.test');
        $order = $this->makeDeliveredOrder($company, $reseller, 'ORD-DETAIL-1', customerName: 'Karim Buyer', customerPhone: '01711112222', customerAddress: 'House 12, Mirpur');

        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $this->actingAs($reseller, 'customer');

        Livewire::test(ResellerOrdersPage::class)
            ->assertTableColumnStateSet('customer.name', 'Karim Buyer', $order)
            ->assertTableColumnStateSet('customer.phone', '01711112222', $order)
            ->assertTableColumnStateSet('customer.address', 'House 12, Mirpur', $order);

        $commission = ResellerCommission::query()->where('order_id', $order->getKey())->first();
        $this->assertNotNull($commission);
        $this->assertSame(ResellerCommission::STATUS_PAYABLE, $commission->status);
    }

    public function test_a_pending_or_rejected_reseller_cannot_open_the_orders_page(): void
    {
        [$company, $pending] = $this->setUpCompany('orders-pending.example.test', status: 'pending');

        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('reseller'));

        $this->assertFalse(ResellerOrdersPage::canAccess());

        $this->actingAs($pending, 'customer');
        $this->assertFalse(ResellerOrdersPage::canAccess());
    }

    /** @return array{0: Company, 1: Customer} */
    protected function setUpCompany(string $domain, string $status = 'approved'): array
    {
        $company = Company::query()->create([
            'name' => 'Portal '.$domain,
            'slug' => Str::slug($domain),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => strtoupper(Str::random(4)),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
            'reseller_module_enabled' => true,
        ])->fresh();

        $reseller = Customer::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Test Reseller',
            'phone' => '01900000'.random_int(100, 999),
            'reseller_status' => $status,
            'reseller_slug' => 'test-reseller-'.Str::random(6),
            'business_name' => 'Test Shop',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);

        return [$company, $reseller];
    }

    protected function makeDeliveredOrder(
        Company $company,
        Customer $reseller,
        string $orderNumber,
        string $customerName = 'Buyer',
        string $customerPhone = '01800000000',
        string $customerAddress = 'Some address',
    ): Order {
        app(CompanyContext::class)->set($company);

        $buyer = Customer::query()->create([
            'company_id' => $company->getKey(),
            'name' => $customerName,
            'phone' => $customerPhone,
            'address' => $customerAddress,
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Ordered Product',
            'sku' => Str::upper(Str::random(8)),
            'price' => 200,
            'sale_price' => 200,
            'cost_price' => 100,
            'stock' => 0,
            'reorder_level' => 1,
            'unit' => 'pcs',
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
            'tier_prices' => [['min_qty' => 1, 'price' => 100]],
        ]);

        StockMovement::query()->create([
            'product_id' => $product->getKey(),
            'type' => 'opening',
            'quantity' => 10,
        ]);

        $order = Order::query()->create([
            'company_id' => $company->getKey(),
            'order_number' => $orderNumber,
            'customer_id' => $buyer->getKey(),
            'reseller_customer_id' => $reseller->getKey(),
            'customer_name' => $customerName,
            'order_date' => now(),
            'subtotal' => 200,
            'discount' => 0,
            'vat' => 0,
            'shipping_fee' => 0,
            'total_amount' => 200,
            'paid_amount' => 200,
            'due_amount' => 0,
            'status' => Order::STATUS_COMPLETED,
            'delivery_status' => CourierBooking::STATUS_DELIVERED,
            'source' => Order::SOURCE_STOREFRONT,
        ]);

        OrderItem::query()->create([
            'company_id' => $company->getKey(),
            'order_id' => $order->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => 1,
            'unit_price' => 150,
            'unit_cost' => 100,
            'reseller_base_price' => 100,
            'subtotal' => 150,
        ]);

        $order->save();

        return $order->fresh();
    }
}
