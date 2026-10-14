<?php

namespace Tests\Feature;

use App\Filament\Reseller\Pages\CreateOrder;
use App\Filament\Reseller\Pages\Dashboard;
use App\Filament\Reseller\Pages\Orders as ResellerOrdersPage;
use App\Filament\Reseller\Pages\Withdrawals;
use App\Filament\Reseller\Widgets\CommissionTrend;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ResellerCommission;
use App\Models\StockMovement;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\DynamicColorService;
use App\Support\QrCodeSvg;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The reseller portal's modernised front page (owner request 2026-10-12):
 * welcome + balance hero, overview cards with last-month comparison, quick
 * actions, store card with QR, order pipeline, recent orders with photos, an
 * area chart, and the store's own colour and font. Every number on it must come
 * from the signed-in reseller's own records only.
 */
class ResellerPortalDashboardRedesignTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_overview_cards_compare_this_month_with_the_same_days_of_last_month(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        [$company, $reseller] = $this->setUpCompany('kpi.example.test');

        // This month so far: 2 orders on the 10th and 1 on the 15th.
        $this->makeOrder($company, $reseller, 'KPI-1', Order::STATUS_COMPLETED, CourierBooking::STATUS_DELIVERED, '2026-10-10', 1000, 100);
        $this->makeOrder($company, $reseller, 'KPI-2', Order::STATUS_CONFIRMED, null, '2026-10-10', 1000, 0);
        $this->makeOrder($company, $reseller, 'KPI-3', Order::STATUS_PROCESSING, null, '2026-10-15', 500, 0);
        // Same days of last month (1st to 15th): one order. The 20th is outside that window.
        $this->makeOrder($company, $reseller, 'KPI-4', Order::STATUS_COMPLETED, CourierBooking::STATUS_DELIVERED, '2026-09-05', 1000, 50);
        $this->makeOrder($company, $reseller, 'KPI-5', Order::STATUS_COMPLETED, CourierBooking::STATUS_DELIVERED, '2026-09-20', 9000, 900);
        // A cancelled order counts for nothing.
        $this->makeOrder($company, $reseller, 'KPI-6', Order::STATUS_CANCELLED, null, '2026-10-12', 7000, 0);

        $kpis = $this->dashboard($company, $reseller)->kpis();

        $this->assertSame(3, $kpis['orders']['value']);
        $this->assertSame(200, $kpis['orders']['delta']);
        $this->assertSame(2500.0, $kpis['sales']['value']);
        $this->assertSame(150, $kpis['sales']['delta']);
        // 100 this month against 50 on the same days last month (the 900 on the 20th is outside).
        $this->assertSame(100.0, $kpis['commission']['value']);
        $this->assertSame(100, $kpis['commission']['delta']);

        // The last 14 days (2 Oct to 15 Oct), oldest first.
        $this->assertCount(14, $kpis['orders']['spark']);
        $this->assertSame(2, $kpis['orders']['spark'][8]);
        $this->assertSame(1, $kpis['orders']['spark'][13]);
        $this->assertSame(0, $kpis['orders']['spark'][0]);
    }

    public function test_there_is_no_percentage_when_last_month_has_nothing_to_compare(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        [$company, $reseller] = $this->setUpCompany('kpi-none.example.test');
        $this->makeOrder($company, $reseller, 'NONE-1', Order::STATUS_CONFIRMED, null, '2026-10-14', 800, 0);

        $kpis = $this->dashboard($company, $reseller)->kpis();

        $this->assertSame(1, $kpis['orders']['value']);
        $this->assertNull($kpis['orders']['delta']);
        $this->assertNull($kpis['sales']['delta']);
    }

    public function test_the_page_never_counts_another_resellers_orders_or_earnings(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        [$company, $reseller] = $this->setUpCompany('iso.example.test');
        $other = $this->makeReseller($company, 'Other Reseller');
        [$otherCompany, $stranger] = $this->setUpCompany('iso-other.example.test');

        $this->makeOrder($company, $reseller, 'MINE-1', Order::STATUS_CONFIRMED, null, '2026-10-14', 333, 0, productName: 'My Gadget');
        $this->makeOrder($company, $other, 'THEIRS-1', Order::STATUS_COMPLETED, CourierBooking::STATUS_DELIVERED, '2026-10-14', 777, 70, productName: 'Their Secret Gadget');

        $page = $this->dashboard($company, $reseller);

        $this->assertSame(1, $page->kpis()['orders']['value']);
        $this->assertSame(333.0, $page->kpis()['sales']['value']);
        $this->assertSame(0.0, $page->kpis()['commission']['value']);
        $this->assertSame(1, $page->pipeline()['steps'][Order::STAGE_CONFIRMED]);
        $this->assertSame(0, $page->pipeline()['steps'][Order::STAGE_COMPLETED]);
        $this->assertSame(['MINE-1'], $page->recentOrders()->pluck('order_number')->all());

        // The reseller of another company sees nothing of either.
        $this->assertSame(0, $this->dashboard($otherCompany, $stranger)->kpis()['orders']['value']);

        $html = $this->dashboard($company, $reseller, html: true);
        $this->assertStringContainsString('My Gadget', $html);
        $this->assertStringNotContainsString('Their Secret Gadget', $html);
        $this->assertStringNotContainsString('THEIRS-1', $html);
    }

    public function test_the_pipeline_counts_each_stage_the_way_the_orders_list_shows_it(): void
    {
        [$company, $reseller] = $this->setUpCompany('pipe.example.test');
        $this->makeOrder($company, $reseller, 'P-1', Order::STATUS_CONFIRMED, null, now()->toDateString(), 100, 0);
        $this->makeOrder($company, $reseller, 'P-2', Order::STATUS_PROCESSING, null, now()->toDateString(), 100, 0);
        $this->makeOrder($company, $reseller, 'P-3', Order::STATUS_PROCESSING, CourierBooking::STATUS_IN_TRANSIT, now()->toDateString(), 100, 0);
        $this->makeOrder($company, $reseller, 'P-4', Order::STATUS_CONFIRMED, CourierBooking::STATUS_PICKED_UP, now()->toDateString(), 100, 0);
        $this->makeOrder($company, $reseller, 'P-5', Order::STATUS_COMPLETED, CourierBooking::STATUS_DELIVERED, now()->toDateString(), 100, 10);
        $this->makeOrder($company, $reseller, 'P-6', Order::STATUS_CANCELLED, null, now()->toDateString(), 100, 0);
        $this->makeOrder($company, $reseller, 'P-7', Order::STATUS_RETURNED, null, now()->toDateString(), 100, 0);

        $pipeline = $this->dashboard($company, $reseller)->pipeline();

        $this->assertSame([
            Order::STAGE_CONFIRMED => 1,
            Order::STAGE_PROCESSING => 1,
            Order::STAGE_SHIPPING => 2,
            Order::STAGE_COMPLETED => 1,
        ], $pipeline['steps']);
        $this->assertSame([Order::STATUS_CANCELLED => 1, Order::STATUS_RETURNED => 1], $pipeline['closed']);
    }

    public function test_the_stage_scope_matches_workflow_stage_for_every_status_and_courier_state(): void
    {
        [$company] = $this->setUpCompany('scope.example.test');
        app(CompanyContext::class)->set($company);

        $statuses = [Order::STATUS_DRAFT, Order::STATUS_CONFIRMED, Order::STATUS_PROCESSING, Order::STATUS_COMPLETED, Order::STATUS_CANCELLED, Order::STATUS_RETURNED, Order::STATUS_REFUNDED];
        $couriers = [null, CourierBooking::STATUS_BOOKED, CourierBooking::STATUS_PICKED_UP, CourierBooking::STATUS_IN_TRANSIT, CourierBooking::STATUS_PARTIAL_DELIVERED, CourierBooking::STATUS_DELIVERED];

        $buyer = Customer::query()->create(['company_id' => $company->getKey(), 'name' => 'Buyer', 'phone' => '01800000001', 'is_active' => true]);

        $number = 0;
        foreach ($statuses as $status) {
            foreach ($couriers as $courier) {
                Order::query()->create([
                    'company_id' => $company->getKey(),
                    'order_number' => 'SCOPE-'.(++$number),
                    'customer_id' => $buyer->getKey(),
                    'customer_name' => 'Buyer',
                    'order_date' => now(),
                    'subtotal' => 10, 'discount' => 0, 'vat' => 0, 'shipping_fee' => 0, 'total_amount' => 10, 'paid_amount' => 0, 'due_amount' => 10,
                    'status' => $status,
                    'delivery_status' => $courier,
                    'source' => Order::SOURCE_STOREFRONT,
                ]);
            }
        }

        $orders = Order::query()->get();
        $this->assertCount(count($statuses) * count($couriers), $orders);

        foreach (array_keys(Order::WORKFLOW_STAGES) + [99 => Order::STATUS_DRAFT] as $stage) {
            $expected = $orders->filter(fn (Order $order): bool => $order->workflowStage() === $stage)->pluck('id')->sort()->values()->all();
            $actual = Order::query()->inWorkflowStage($stage)->pluck('id')->sort()->values()->all();

            $this->assertSame($expected, $actual, "stage {$stage}");
        }
    }

    public function test_the_orders_page_can_be_opened_on_one_stage_and_cleared(): void
    {
        [$company, $reseller] = $this->setUpCompany('stage-filter.example.test');
        $shipping = $this->makeOrder($company, $reseller, 'ST-1', Order::STATUS_CONFIRMED, CourierBooking::STATUS_IN_TRANSIT, now()->toDateString(), 100, 0);
        $confirmed = $this->makeOrder($company, $reseller, 'ST-2', Order::STATUS_CONFIRMED, null, now()->toDateString(), 100, 0);

        $this->asPortal($company, $reseller);

        Livewire::withQueryParams(['stage' => 'shipping'])
            ->test(ResellerOrdersPage::class)
            ->assertSee('Showing Shipping orders only.')
            ->assertCanSeeTableRecords([$shipping])
            ->assertCanNotSeeTableRecords([$confirmed])
            ->call('clearStage')
            ->assertDontSee('Showing Shipping orders only.')
            ->assertCanSeeTableRecords([$shipping, $confirmed]);

        // An unknown stage in the URL is ignored, not an error and not an empty list.
        Livewire::withQueryParams(['stage' => 'nonsense'])
            ->test(ResellerOrdersPage::class)
            ->assertCanSeeTableRecords([$shipping, $confirmed]);
    }

    public function test_the_stage_filter_still_only_lists_this_resellers_orders(): void
    {
        [$company, $reseller] = $this->setUpCompany('stage-iso.example.test');
        $other = $this->makeReseller($company, 'Other');
        $mine = $this->makeOrder($company, $reseller, 'SI-1', Order::STATUS_CONFIRMED, CourierBooking::STATUS_IN_TRANSIT, now()->toDateString(), 100, 0);
        $theirs = $this->makeOrder($company, $other, 'SI-2', Order::STATUS_CONFIRMED, CourierBooking::STATUS_IN_TRANSIT, now()->toDateString(), 100, 0);

        $this->asPortal($company, $reseller);

        Livewire::withQueryParams(['stage' => 'shipping'])
            ->test(ResellerOrdersPage::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_the_front_page_shows_the_hero_quick_actions_store_card_pipeline_and_recent_orders(): void
    {
        [$company, $reseller] = $this->setUpCompany('front.example.test');
        $this->makeOrder($company, $reseller, 'FRONT-1', Order::STATUS_COMPLETED, CourierBooking::STATUS_DELIVERED, now()->toDateString(), 1200, 200, productName: 'Smart Watch Pro');

        $html = $this->dashboard($company, $reseller, html: true);

        $this->assertMatchesRegularExpression('/Good (morning|afternoon|evening),/', $html);
        $this->assertStringContainsString('Test Reseller', $html);
        $this->assertStringContainsString('Available to withdraw', $html);
        $this->assertStringContainsString('Pending commission', $html);
        $this->assertStringContainsString(CreateOrder::getUrl(), $html);
        $this->assertStringContainsString(Withdrawals::getUrl(), $html);
        $this->assertStringContainsString('Add products', $html);
        $this->assertStringContainsString('Share store', $html);
        $this->assertStringContainsString('Order pipeline', $html);
        $this->assertStringContainsString('stage=shipping', $html);
        $this->assertStringContainsString('Smart Watch Pro', $html);
        $this->assertStringContainsString('FRONT-1', $html);
        // The store link, drawn as a QR code on the server (no third-party QR service).
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
        $this->assertStringContainsString('Scan to open your store', $html);
        $this->assertStringContainsString('Live', $html);
    }

    public function test_a_reseller_without_a_store_link_is_sent_to_add_products(): void
    {
        [$company, $reseller] = $this->setUpCompany('nolink.example.test');
        $reseller->forceFill(['reseller_slug' => null])->save();

        $html = $this->dashboard($company, $reseller->fresh(), html: true);

        $this->assertStringContainsString('Your store link is not ready yet', $html);
        $this->assertStringNotContainsString('Scan to open your store', $html);
        $this->assertStringContainsString('My Store', $html);
    }

    public function test_a_new_subdomain_shows_as_being_set_up_until_it_is_ready(): void
    {
        [$company, $reseller] = $this->setUpCompany('sub.example.test');
        $reseller->forceFill(['reseller_subdomain' => 'rahim', 'reseller_subdomain_changed_at' => now()])->save();

        $page = $this->dashboard($company, $reseller->fresh());
        $this->assertTrue($page->subdomainIsSettingUp());
        $this->assertStringContainsString('Setting up your store address', $this->dashboard($company, $reseller->fresh(), html: true));

        $reseller->forceFill(['reseller_subdomain_live_at' => now()])->save();
        $this->assertFalse($this->dashboard($company, $reseller->fresh())->subdomainIsSettingUp());
    }

    public function test_the_greeting_follows_the_companys_local_time(): void
    {
        [$company, $reseller] = $this->setUpCompany('greet.example.test');
        $page = $this->dashboard($company, $reseller);

        // The company is on Asia/Dhaka (UTC+6).
        Carbon::setTestNow('2026-10-15 03:00:00');
        $this->assertSame('Good morning', $page->greeting());

        Carbon::setTestNow('2026-10-15 09:00:00');
        $this->assertSame('Good afternoon', $page->greeting());

        Carbon::setTestNow('2026-10-15 15:00:00');
        $this->assertSame('Good evening', $page->greeting());
    }

    public function test_an_order_row_shows_the_first_product_and_how_many_more(): void
    {
        [$company, $reseller] = $this->setUpCompany('rows.example.test');
        $order = $this->makeOrder($company, $reseller, 'ROW-1', Order::STATUS_CONFIRMED, null, now()->toDateString(), 300, 0, productName: 'First Product', extraLines: 2);

        $summary = $this->dashboard($company, $reseller)->orderSummary($order->load('items.product'));

        $this->assertSame('First Product', $summary['name']);
        $this->assertSame(2, $summary['more']);
        $this->assertNull($summary['image']);
    }

    public function test_the_commission_chart_is_a_filled_line_in_the_brand_colour(): void
    {
        [$company, $reseller] = $this->setUpCompany('chart.example.test');
        $this->asPortal($company, $reseller);

        $chart = Livewire::test(CommissionTrend::class)->instance();
        $type = new \ReflectionMethod($chart, 'getType');
        $data = new \ReflectionMethod($chart, 'getData');

        $this->assertSame('line', $type->invoke($chart));
        $dataset = $data->invoke($chart)['datasets'][0];
        $this->assertTrue($dataset['fill']);
        // No hard-coded colour: the widget's own (brand) colour is used.
        $this->assertArrayNotHasKey('backgroundColor', $dataset);
        $this->assertArrayNotHasKey('borderColor', $dataset);
    }

    public function test_the_portal_wears_the_stores_theme_colour_and_the_self_hosted_font(): void
    {
        $this->setUpCompany('brand.example.test', themeColor: '#E11D48');

        $response = $this->get('http://reseller.brand.example.test/portal/login')->assertOk();
        $html = $response->getContent();

        $rose = app(DynamicColorService::class)->generateShades('#E11D48');
        $this->assertStringContainsString('--primary-500: '.$rose['500'], $html);
        $this->assertStringContainsString("--font-family: 'Hind Siliguri'", $html);
    }

    public function test_the_portal_keeps_the_default_colour_when_a_company_has_no_storefront_settings(): void
    {
        $this->setUpCompany('brand-none.example.test', themeColor: null);

        $html = $this->get('http://reseller.brand-none.example.test/portal/login')->assertOk()->getContent();

        $default = app(DynamicColorService::class)->generateShades(DynamicColorService::DEFAULT_COLOR);
        $this->assertStringContainsString('--primary-500: '.$default['500'], $html);
    }

    public function test_one_stores_colour_never_reaches_another_stores_portal(): void
    {
        $this->setUpCompany('brand-a.example.test', themeColor: '#E11D48');
        $this->setUpCompany('brand-b.example.test', themeColor: '#2563EB');

        $rose = app(DynamicColorService::class)->generateShades('#E11D48')['500'];
        $blue = app(DynamicColorService::class)->generateShades('#2563EB')['500'];

        $b = $this->get('http://reseller.brand-b.example.test/portal/login')->assertOk()->getContent();

        $this->assertStringContainsString('--primary-500: '.$blue, $b);
        $this->assertStringNotContainsString($rose, $b);
    }

    public function test_the_qr_code_helper_draws_an_inline_svg_or_nothing(): void
    {
        $uri = QrCodeSvg::dataUri('https://shop.example.test/store/rahim');

        $this->assertStringStartsWith('data:image/svg+xml;base64,', (string) $uri);
        $this->assertStringContainsString('<svg', base64_decode(substr((string) $uri, strlen('data:image/svg+xml;base64,'))));
        $this->assertNull(QrCodeSvg::dataUri('   '));
    }

    public function test_the_sparkline_draws_a_line_and_stays_flat_for_a_zero_series(): void
    {
        $line = view('filament.reseller.pages.partials.sparkline', ['values' => [0, 5, 10], 'class' => 'text-primary-500'])->render();
        $this->assertStringContainsString('<polyline', $line);
        $this->assertStringContainsString('text-primary-500', $line);
        // The highest point is drawn near the top, the lowest on the baseline.
        $this->assertStringContainsString('0,26 50,14 100,2', $line);

        $flat = view('filament.reseller.pages.partials.sparkline', ['values' => [0, 0, 0]])->render();
        $this->assertStringContainsString('0,26 50,26 100,26', $flat);

        $this->assertSame('', trim(view('filament.reseller.pages.partials.sparkline', ['values' => [4]])->render()));
    }

    /** @return array{0: Company, 1: Customer} */
    protected function setUpCompany(string $domain, ?string $themeColor = '#0F766E'): array
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
            'reseller_subdomains_enabled' => true,
        ])->fresh();

        if ($themeColor !== null) {
            StorefrontSetting::query()->create([
                'company_id' => $company->getKey(),
                'theme_color' => $themeColor,
                'is_published' => true,
                'new_customer_delivery_advance_enabled' => false,
            ]);
        }

        return [$company, $this->makeReseller($company, 'Test Reseller')];
    }

    protected function makeReseller(Company $company, string $name): Customer
    {
        app(CompanyContext::class)->set($company);

        return Customer::query()->create([
            'company_id' => $company->getKey(),
            'name' => $name,
            'phone' => '019'.random_int(10000000, 99999999),
            'reseller_status' => 'approved',
            'reseller_slug' => 'shop-'.Str::lower(Str::random(6)),
            'business_name' => 'Test Shop',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);
    }

    protected function asPortal(Company $company, Customer $reseller): void
    {
        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $this->actingAs($reseller, 'customer');
    }

    /** The dashboard page for this reseller: its component instance, or its rendered HTML. */
    protected function dashboard(Company $company, Customer $reseller, bool $html = false): Dashboard|string
    {
        $this->asPortal($company, $reseller);

        $component = Livewire::test(Dashboard::class);

        return $html ? $component->html() : $component->instance();
    }

    /**
     * An order credited to the reseller with a line of retail price $total.
     * The commission row is created by the order itself; $commission forces
     * its amount and, for a delivered order, its payable date, so the numbers
     * on the page are known in advance.
     */
    protected function makeOrder(
        Company $company,
        Customer $reseller,
        string $orderNumber,
        string $status,
        ?string $delivery,
        string $date,
        float $total,
        float $commission,
        string $productName = 'Ordered Product',
        int $extraLines = 0,
    ): Order {
        app(CompanyContext::class)->set($company);

        $buyer = Customer::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'phone' => '01800000000'],
            ['name' => 'Buyer', 'address' => 'Some address', 'is_active' => true],
        );

        $product = Product::query()->create([
            'company_id' => $company->getKey(),
            'name' => $productName,
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

        StockMovement::query()->create(['product_id' => $product->getKey(), 'type' => 'opening', 'quantity' => 50]);

        $order = Order::query()->create([
            'company_id' => $company->getKey(),
            'order_number' => $orderNumber,
            'customer_id' => $buyer->getKey(),
            'reseller_customer_id' => $reseller->getKey(),
            'customer_name' => $buyer->name,
            'order_date' => $date,
            'subtotal' => $total,
            'discount' => 0,
            'vat' => 0,
            'shipping_fee' => 0,
            'total_amount' => $total,
            'paid_amount' => 0,
            'due_amount' => $total,
            'status' => $status,
            'delivery_status' => $delivery,
            'source' => Order::SOURCE_STOREFRONT,
        ]);

        OrderItem::query()->create([
            'company_id' => $company->getKey(),
            'order_id' => $order->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => 1,
            'unit_price' => $total,
            'unit_cost' => 100,
            'reseller_base_price' => 100,
            'subtotal' => $total,
        ]);

        for ($i = 0; $i < $extraLines; $i++) {
            OrderItem::query()->create([
                'company_id' => $company->getKey(),
                'order_id' => $order->getKey(),
                'product_id' => $product->getKey(),
                'quantity' => 1,
                'unit_price' => 0,
                'unit_cost' => 100,
                'reseller_base_price' => 100,
                'subtotal' => 0,
            ]);
        }

        $order->save();

        $row = ResellerCommission::query()->where('order_id', $order->getKey())->first();

        if ($row) {
            $row->forceFill([
                'amount' => $commission,
                'payable_at' => $row->status === ResellerCommission::STATUS_PAYABLE ? $date : null,
            ])->save();
        }

        return $order->fresh();
    }
}
