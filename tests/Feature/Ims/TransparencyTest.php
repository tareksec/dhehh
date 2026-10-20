<?php

namespace Tests\Feature\Ims;

use App\Filament\Ims\Portal\Pages\ProjectDetail;
use App\Filament\Ims\Portal\Pages\Projects;
use App\Filament\Investor\Pages\Dashboard;
use App\Filament\Investor\Pages\MyInvestments;
use App\Filament\Investor\Pages\Payouts;
use App\Filament\Investor\Pages\Statement;
use App\Ims\Profit\ProjectFinancials;
use App\Ims\Reports\ProjectReportService;
use App\Ims\Transparency\InvestorProjectSummary;
use App\Ims\Transparency\ProjectUpdateService;
use App\Models\Customer;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProjectReport;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Shipment;
use App\Models\Supplier;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Project transparency (IMS-V2/PRD.md FR-30–36): the figures worked out from
 * the linked ERP purchases, the three visibility levels, frozen reports, and
 * the privacy rule that no portal page ever shows a customer, supplier, unit
 * price, tracking number or another investor.
 */
class TransparencyTest extends TestCase
{
    use ImsTestHelpers;
    use RefreshDatabase;

    private InvestmentProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imsCompany();
        $supplier = Supplier::query()->create(['name' => 'SECRET-SUPPLIER-XYZ']);
        $product = Product::query()->create(['name' => 'Ims Machine', 'sku' => 'IMS-1', 'price' => 1000, 'sale_price' => 1000, 'stock' => 50]);
        $purchase = Purchase::query()->create(['supplier_id' => $supplier->getKey(), 'purchase_date' => now()->subDays(30), 'status' => 'draft']);
        PurchaseItem::query()->create(['purchase_id' => $purchase->getKey(), 'product_id' => $product->getKey(), 'quantity' => 10, 'unit_cost' => 777.77]);
        Shipment::query()->create(['company_id' => $this->company->id, 'purchase_id' => $purchase->getKey(), 'tracking_number' => 'SECRET-TRACK-555', 'carrier' => 'SECRET-CARRIER', 'transport_mode' => 'sea', 'status' => 'in_transit', 'estimated_arrival' => now()->subDays(2)]);

        $customer = Customer::query()->create(['name' => 'SECRET-CUSTOMER-ABC', 'phone' => '01700000001']);
        $order = Order::query()->create(['customer_id' => $customer->getKey(), 'subtotal' => 2999.97, 'total_amount' => 2999.97, 'due_amount' => 0, 'status' => 'completed', 'order_date' => now()->subDays(5)->toDateString()]);
        // The sale itself, without the ERP's stock bookkeeping (not what is tested here).
        OrderItem::withoutEvents(fn () => OrderItem::query()->create(['company_id' => $this->company->id, 'order_id' => $order->getKey(), 'product_id' => $product->getKey(), 'quantity' => 3, 'unit_price' => 999.99, 'subtotal' => 2999.97]));

        $this->project = $this->closedProject(['status' => 'running', 'start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->addDays(20)->toDateString()]);
        $this->project->linkedPurchases()->attach($purchase->getKey(), ['company_id' => $this->company->id]);
    }

    private function invest(string $name, float $amount, string $status = Investment::STATUS_ACTIVE): array
    {
        $investor = $this->verified($this->newInvestor(['name' => $name]));
        Investment::query()->create(['project_id' => $this->project->id, 'investor_id' => $investor->id, 'amount' => $amount, 'payment_method' => 'bank', 'invested_at' => now()->subDays(40)->toDateString(), 'status' => $status]);

        return [$investor, $this->portalAccount($investor)];
    }

    public function test_the_figures_come_from_the_linked_purchases_and_their_sales(): void
    {
        $figures = app(ProjectFinancials::class)->compute($this->project);

        $this->assertSame(10, $figures['units_bought']);
        $this->assertSame(3, $figures['units_sold']);
        $this->assertSame(7, $figures['units_in_stock']);
        $this->assertEquals(2999.97, $figures['revenue']);
        $this->assertEquals(7777.70, $figures['costs']['goods']);
        $this->assertEquals(round(2999.97 - $figures['costs']['total'], 2), $figures['net_profit']);
    }

    public function test_a_product_on_two_projects_is_not_counted_automatically(): void
    {
        $other = $this->closedProject(['status' => 'running', 'start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->addDays(20)->toDateString()]);
        $other->linkedPurchases()->attach($this->project->linkedPurchases()->first()->getKey(), ['company_id' => $this->company->id]);

        $figures = app(ProjectFinancials::class)->compute($this->project);

        $this->assertSame(1, $figures['shared_products']);
        $this->assertSame(0, $figures['units_sold'], 'which project such a sale belongs to is the owner\'s open question');
    }

    public function test_three_levels_of_what_an_investor_sees(): void
    {
        [$active] = $this->invest('Active One', 100000);
        [$waiting] = $this->invest('Waiting One', 50000, Investment::STATUS_PENDING);
        $stranger = $this->verified($this->newInvestor());
        $summaries = app(InvestorProjectSummary::class);

        $this->assertNull($summaries->for($this->project, $stranger), 'a running project is not shown to someone with no money in it');
        $this->assertNotNull($summaries->for($this->closedProject(['status' => 'open']), $stranger), 'a plan raising money is shown to everyone');

        $forWaiting = $summaries->for($this->project, $waiting);
        $this->assertTrue($forWaiting['follows']);
        $this->assertNotEmpty($forWaiting['shipments']);
        $this->assertNull($forWaiting['figures'], 'no money figures without an active investment');

        $forActive = $summaries->for($this->project, $active);
        $this->assertTrue($forActive['figures']['estimated']);
        $this->assertSame(2, $forActive['shipments'][0]['late_days']);
    }

    public function test_updates_show_only_once_switched_on(): void
    {
        [$investor] = $this->invest('Follower', 10000);
        $service = app(ProjectUpdateService::class);
        $hidden = $service->create(['project_id' => $this->project->id, 'title' => 'Hidden news', 'visible_to_investors' => false], [], $this->staff());

        $this->assertSame([], app(InvestorProjectSummary::class)->for($this->project, $investor)['updates']);

        $service->show($hidden, $this->staff());
        $this->assertSame('Hidden news', app(InvestorProjectSummary::class)->for($this->project, $investor)['updates'][0]['title']);
    }

    public function test_a_published_report_is_frozen_and_kept(): void
    {
        $report = ProjectReport::query()->create(['company_id' => $this->company->id, 'project_id' => $this->project->id, 'title' => 'Month one']);
        app(ProjectReportService::class)->publish($report, $this->staff());

        $report->refresh();
        $this->assertTrue($report->isPublished());
        $this->assertSame(3, $report->summary['figures']['units_sold']);
        $this->assertStringStartsWith('%PDF', app(ProjectReportService::class)->pdf($report));

        try {
            $report->update(['title' => 'Changed']);
            $this->fail('A published report was changed.');
        } catch (ValidationException) {
        }

        $this->expectException(ValidationException::class);
        $report->delete();
    }

    public function test_no_portal_page_shows_a_customer_supplier_price_tracking_number_or_another_investor(): void
    {
        [, $account] = $this->invest('Viewer Investor', 100000);
        $this->invest('OTHER-INVESTOR-QQQ', 200000);
        app(ProjectUpdateService::class)->create(['project_id' => $this->project->id, 'title' => 'Ship left the port', 'visible_to_investors' => true], [], $this->staff());

        Filament::setCurrentPanel(Filament::getPanel('investor'));
        $pages = [
            Dashboard::getUrl(),
            MyInvestments::getUrl(),
            Payouts::getUrl(),
            Statement::getUrl(),
            Projects::getUrl(),
            ProjectDetail::getUrl(['project' => $this->project->id]),
        ];

        foreach ($pages as $url) {
            $path = parse_url($url, PHP_URL_PATH);
            $response = $this->actingAs($account, 'investor')->get($this->imsUrl($path));
            $response->assertOk();

            foreach (['SECRET-SUPPLIER-XYZ', 'SECRET-CUSTOMER-ABC', 'SECRET-TRACK-555', 'SECRET-CARRIER', 'OTHER-INVESTOR-QQQ', '777.77', '999.99'] as $secret) {
                $response->assertDontSee($secret, false);
            }
        }

        $this->actingAs($account, 'investor')->get($this->imsUrl(parse_url(ProjectDetail::getUrl(['project' => $this->project->id]), PHP_URL_PATH)))
            ->assertSee('Ship left the port')
            ->assertSee(__('Estimate — the project is not settled yet'));
    }
}
