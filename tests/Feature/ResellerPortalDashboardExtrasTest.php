<?php

namespace Tests\Feature;

use App\Filament\Pages\ResellerSettings;
use App\Filament\Reseller\Pages\Dashboard;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ResellerPayoutAccount;
use App\Models\ResellerProduct;
use App\Models\StockMovement;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The reseller dashboard's three additions (owner request 2026-10-15): the
 * company's own announcement, a first-steps checklist, and best-selling
 * products. Everything shown comes from this reseller's own records, and the
 * announcement text from the company's Reseller Settings.
 */
class ResellerPortalDashboardExtrasTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_announcement_shows_only_while_it_is_on_and_is_never_taken_as_html(): void
    {
        [$company, $reseller, $setting] = $this->setUpCompany('notice.example.test');

        $this->assertNull($this->page($company, $reseller)->announcement());
        $this->assertStringNotContainsString('data-reseller-announcement', $this->html($company, $reseller));

        $setting->forceFill(['reseller_announcement_enabled' => true, 'reseller_announcement' => "Closed on Friday\n<script>alert(1)</script>"])->save();
        $html = $this->html($company, $reseller);
        $this->assertStringContainsString('data-reseller-announcement', $html);
        $this->assertStringContainsString('Closed on Friday', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);

        // Off again: the text stays saved but is hidden.
        $setting->forceFill(['reseller_announcement_enabled' => false])->save();
        $this->assertNull($this->page($company, $reseller->fresh())->announcement());
        $this->assertSame("Closed on Friday\n<script>alert(1)</script>", $setting->fresh()->reseller_announcement);

        // On but empty shows nothing.
        $setting->forceFill(['reseller_announcement_enabled' => true, 'reseller_announcement' => '   '])->save();
        $this->assertNull($this->page($company, $reseller->fresh())->announcement());
    }

    public function test_one_companys_announcement_never_reaches_another_companys_resellers(): void
    {
        [$companyA, $resellerA, $settingA] = $this->setUpCompany('notice-a.example.test');
        [$companyB, $resellerB] = $this->setUpCompany('notice-b.example.test');
        $settingA->forceFill(['reseller_announcement_enabled' => true, 'reseller_announcement' => 'Only for A'])->save();

        $this->assertSame('Only for A', $this->page($companyA, $resellerA)->announcement());
        $this->assertNull($this->page($companyB, $resellerB)->announcement());
    }

    public function test_the_company_writes_the_announcement_in_reseller_settings(): void
    {
        [$company] = $this->setUpCompany('notice-admin.example.test');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $company->getKey()]);
        app(CompanyContext::class)->set($company);

        // Turned on without any text: the form asks for it.
        Livewire::test(ResellerSettings::class)
            ->fillForm(['reseller_announcement_enabled' => true, 'reseller_announcement' => ''])
            ->call('save')
            ->assertHasFormErrors(['reseller_announcement' => 'required']);

        Livewire::test(ResellerSettings::class)
            ->fillForm(['reseller_announcement_enabled' => true, 'reseller_announcement' => '  Eid holiday: no delivery 20-22 June.  '])
            ->call('save')
            ->assertHasNoFormErrors();

        $setting = $company->fresh()->storefrontSetting;
        $this->assertTrue($setting->reseller_announcement_enabled);
        $this->assertSame('Eid holiday: no delivery 20-22 June.', $setting->reseller_announcement);
    }

    public function test_the_checklist_marks_each_step_done_from_the_resellers_own_records(): void
    {
        [$company, $reseller] = $this->setUpCompany('steps.example.test');
        [$other] = [$this->makeReseller($company, 'Other Reseller')];

        $steps = fn (): array => collect($this->page($company, $reseller->fresh())->firstSteps())->pluck('done', 'key')->all();
        $this->assertSame(['store-name' => false, 'products' => false, 'payout-account' => false, 'first-order' => false], $steps());

        // Another reseller's records never tick this reseller's steps.
        $otherProduct = $this->product($company, 'Other product');
        ResellerProduct::query()->create(['customer_id' => $other->getKey(), 'product_id' => $otherProduct->getKey(), 'price' => 300, 'is_active' => true]);
        ResellerPayoutAccount::query()->create(['company_id' => $company->getKey(), 'customer_id' => $other->getKey(), 'method' => 'bkash', 'account_details' => '01711111111', 'is_default' => true]);
        $this->sell($company, $other, 'OTHER-1', Order::STATUS_CONFIRMED, '2026-10-14', $otherProduct, 1);
        $this->assertSame(['store-name' => false, 'products' => false, 'payout-account' => false, 'first-order' => false], $steps());

        $reseller->forceFill(['reseller_store_name' => 'My Shop'])->save();
        $this->assertTrue($steps()['store-name']);

        $product = $this->product($company, 'My product');
        $pick = ResellerProduct::query()->create(['customer_id' => $reseller->getKey(), 'product_id' => $product->getKey(), 'price' => 300, 'is_active' => false]);
        $this->assertFalse($steps()['products']); // a hidden pick does not count
        $pick->update(['is_active' => true]);
        $this->assertTrue($steps()['products']);

        ResellerPayoutAccount::query()->create(['company_id' => $company->getKey(), 'customer_id' => $reseller->getKey(), 'method' => 'nagad', 'account_details' => '01822222222', 'is_default' => true]);
        $this->assertTrue($steps()['payout-account']);

        $this->sell($company, $reseller, 'MINE-1', Order::STATUS_CONFIRMED, '2026-10-14', $product, 1);
        $this->assertTrue($steps()['first-order']);
    }

    public function test_the_checklist_is_drawn_until_every_step_is_done_and_then_disappears(): void
    {
        [$company, $reseller] = $this->setUpCompany('steps-html.example.test');

        $html = $this->html($company, $reseller);
        $this->assertStringContainsString('data-reseller-first-steps', $html);
        foreach (['store-name', 'products', 'payout-account', 'first-order'] as $key) {
            $this->assertStringContainsString('data-first-step="'.$key.'"', $html);
        }
        $this->assertStringContainsString('0 of 4 steps done', $html);

        $reseller->forceFill(['reseller_store_name' => 'My Shop'])->save();
        $product = $this->product($company, 'My product');
        ResellerProduct::query()->create(['customer_id' => $reseller->getKey(), 'product_id' => $product->getKey(), 'price' => 300, 'is_active' => true]);
        ResellerPayoutAccount::query()->create(['company_id' => $company->getKey(), 'customer_id' => $reseller->getKey(), 'method' => 'bkash', 'account_details' => '01711111111', 'is_default' => true]);
        $this->assertStringContainsString('3 of 4 steps done', $this->html($company, $reseller->fresh()));

        $this->sell($company, $reseller, 'MINE-1', Order::STATUS_CONFIRMED, '2026-10-14', $product, 1);
        $this->assertStringNotContainsString('data-reseller-first-steps', $this->html($company, $reseller->fresh()));
    }

    public function test_best_sellers_rank_this_resellers_pieces_over_the_last_30_days(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        [$company, $reseller] = $this->setUpCompany('best.example.test');
        $other = $this->makeReseller($company, 'Other Reseller');
        $cap = $this->product($company, 'Cap');
        $shirt = $this->product($company, 'Shirt');
        $mug = $this->product($company, 'Mug');
        $secret = $this->product($company, 'Rival Secret Product');

        $this->sell($company, $reseller, 'B-1', Order::STATUS_COMPLETED, '2026-10-10', $cap, 4);
        $this->sell($company, $reseller, 'B-2', Order::STATUS_CONFIRMED, '2026-10-12', $cap, 3);
        $this->sell($company, $reseller, 'B-3', Order::STATUS_PROCESSING, '2026-10-01', $shirt, 5);
        $this->sell($company, $reseller, 'B-4', Order::STATUS_COMPLETED, '2026-09-17', $mug, 2); // exactly 29 days ago: inside
        // Left out: too old, cancelled, returned, draft, and another reseller's order.
        $this->sell($company, $reseller, 'B-5', Order::STATUS_COMPLETED, '2026-09-10', $shirt, 50);
        $this->sell($company, $reseller, 'B-6', Order::STATUS_CANCELLED, '2026-10-11', $mug, 40);
        $this->sell($company, $reseller, 'B-7', Order::STATUS_RETURNED, '2026-10-11', $mug, 30);
        $this->sell($company, $reseller, 'B-8', Order::STATUS_DRAFT, '2026-10-11', $mug, 20);
        $this->sell($company, $other, 'B-9', Order::STATUS_COMPLETED, '2026-10-12', $secret, 99);

        $best = $this->page($company, $reseller)->bestSellers();

        $this->assertSame(['Cap', 'Shirt', 'Mug'], array_column($best, 'name'));
        $this->assertSame([7, 5, 2], array_column($best, 'pieces'));

        $html = $this->html($company, $reseller);
        $this->assertStringContainsString('data-reseller-best-sellers', $html);
        $this->assertStringContainsString('7 pieces', $html);
        $this->assertStringNotContainsString('Rival Secret Product', $html);
    }

    public function test_best_sellers_show_at_most_five_and_nothing_when_there_is_nothing_sold(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        [$company, $reseller] = $this->setUpCompany('best-five.example.test');

        $this->assertSame([], $this->page($company, $reseller)->bestSellers());
        $this->assertStringNotContainsString('data-reseller-best-sellers', $this->html($company, $reseller));

        foreach (range(1, 7) as $i) {
            $this->sell($company, $reseller, 'F-'.$i, Order::STATUS_COMPLETED, '2026-10-12', $this->product($company, 'Item '.$i), $i);
        }

        $best = $this->page($company, $reseller)->bestSellers();
        $this->assertCount(5, $best);
        $this->assertSame(['Item 7', 'Item 6', 'Item 5', 'Item 4', 'Item 3'], array_column($best, 'name'));
    }

    /** @return array{0: Company, 1: Customer, 2: StorefrontSetting} */
    protected function setUpCompany(string $domain): array
    {
        $company = Company::query()->create([
            'name' => 'Portal '.$domain, 'slug' => Str::slug($domain), 'domain' => $domain, 'domain_verified' => true,
            'invoice_prefix' => strtoupper(Str::random(4)), 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka',
            'is_active' => true, 'reseller_module_enabled' => true,
        ])->fresh();
        $setting = StorefrontSetting::query()->create([
            'company_id' => $company->getKey(), 'theme_color' => '#0F766E', 'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
        ]);

        return [$company, $this->makeReseller($company, 'Test Reseller'), $setting];
    }

    protected function makeReseller(Company $company, string $name): Customer
    {
        app(CompanyContext::class)->set($company);

        return Customer::query()->create([
            'company_id' => $company->getKey(), 'name' => $name, 'phone' => '019'.random_int(10000000, 99999999),
            'reseller_status' => 'approved', 'reseller_slug' => 'shop-'.Str::lower(Str::random(6)), 'business_name' => 'Test Shop',
            'password' => Hash::make('secret-password'), 'is_active' => true,
        ]);
    }

    protected function product(Company $company, string $name): Product
    {
        app(CompanyContext::class)->set($company);

        $product = Product::query()->create([
            'company_id' => $company->getKey(), 'name' => $name, 'sku' => Str::upper(Str::random(8)), 'price' => 200, 'sale_price' => 200,
            'cost_price' => 100, 'stock' => 0, 'reorder_level' => 1, 'unit' => 'pcs', 'vat_rate' => 0, 'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);
        StockMovement::query()->create(['product_id' => $product->getKey(), 'type' => 'opening', 'quantity' => 1000]);

        return $product;
    }

    /** An order credited to the reseller, with one line (created without events: only the dashboard's reading of it is tested). */
    protected function sell(Company $company, Customer $reseller, string $number, string $status, string $date, Product $product, int $quantity): Order
    {
        app(CompanyContext::class)->set($company);
        $buyer = Customer::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'phone' => '01800000000'],
            ['name' => 'Buyer', 'address' => 'Some address', 'is_active' => true],
        );

        $order = Order::withoutEvents(fn () => Order::query()->create([
            'company_id' => $company->getKey(), 'order_number' => $number, 'customer_id' => $buyer->getKey(),
            'reseller_customer_id' => $reseller->getKey(), 'customer_name' => $buyer->name, 'order_date' => $date,
            'subtotal' => 200 * $quantity, 'discount' => 0, 'vat' => 0, 'shipping_fee' => 0, 'total_amount' => 200 * $quantity,
            'paid_amount' => 0, 'due_amount' => 200 * $quantity, 'status' => $status, 'source' => Order::SOURCE_STOREFRONT,
        ]));

        OrderItem::query()->create([
            'company_id' => $company->getKey(), 'order_id' => $order->getKey(), 'product_id' => $product->getKey(),
            'quantity' => $quantity, 'unit_price' => 200, 'unit_cost' => 100, 'reseller_base_price' => 100, 'subtotal' => 200 * $quantity,
        ]);

        return $order;
    }

    protected function page(Company $company, Customer $reseller): Dashboard
    {
        app(CompanyContext::class)->set($company->fresh()); // a fresh company, as on a new request
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $this->actingAs($reseller, 'customer');

        return Livewire::test(Dashboard::class)->instance();
    }

    protected function html(Company $company, Customer $reseller): string
    {
        app(CompanyContext::class)->set($company->fresh());
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $this->actingAs($reseller, 'customer');

        return Livewire::test(Dashboard::class)->html();
    }
}
