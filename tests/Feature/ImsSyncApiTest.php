<?php

namespace Tests\Feature;

use App\Livewire\WebsiteApiKeysTable;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Sync\ImsSyncFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The read-only data feed for IMS (docs/api/ims-sync-api.md). The critical
 * parts: a key only opens its own API, one company never sees another's rows,
 * paging by cursor never skips or repeats a record, and the money a profit
 * share runs on (payment amount + its own date) arrives exactly.
 */
class ImsSyncApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $other;

    private string $token;

    private string $otherToken;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-01 10:00:00');
        $this->company = $this->makeCompany('Sync Co', 'SYN');
        $this->other = $this->makeCompany('Other Co', 'OTH');
        [, $this->token] = CompanyApiKey::generate($this->company, 'IMS', null, CompanyApiKey::SCOPE_SYNC);
        [, $this->otherToken] = CompanyApiKey::generate($this->other, 'IMS', null, CompanyApiKey::SCOPE_SYNC);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------ key scopes

    public function test_the_feed_needs_a_key(): void
    {
        $this->getJson('/api/v1/sync/ping')->assertStatus(401)->assertJson(['error' => 'missing_api_key']);
        $this->getJson('/api/v1/sync/products', ['Authorization' => 'Bearer nope'])->assertStatus(401)->assertJson(['error' => 'invalid_api_key']);
    }

    public function test_a_website_key_cannot_read_the_feed_and_a_sync_key_cannot_use_the_website_api(): void
    {
        [, $websiteToken] = CompanyApiKey::generate($this->company);

        $this->getJson('/api/v1/sync/orders', ['Authorization' => "Bearer {$websiteToken}"])
            ->assertStatus(403)->assertJson(['error' => 'wrong_key_scope']);

        $this->getJson('/api/v1/products', $this->auth())->assertStatus(403)->assertJson(['error' => 'wrong_key_scope']);
        $this->patchJson('/api/v1/products/ANY', ['price' => 1], $this->auth())->assertStatus(403);
        $this->postJson('/api/v1/orders', [], $this->auth())->assertStatus(403);
    }

    public function test_an_existing_key_without_a_scope_is_still_a_website_key(): void
    {
        [$key, $token] = CompanyApiKey::generate($this->company);
        DB::table('company_api_keys')->where('id', $key->id)->update(['scope' => 'website']);

        $this->getJson('/api/v1/products', ['Authorization' => "Bearer {$token}"])->assertOk();
    }

    public function test_a_revoked_sync_key_is_refused(): void
    {
        CompanyApiKey::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->update(['revoked_at' => now()]);

        $this->getJson('/api/v1/sync/ping', $this->auth())->assertStatus(401);
    }

    public function test_the_feed_is_read_only(): void
    {
        $this->postJson('/api/v1/sync/products', [], $this->auth())->assertStatus(405);
        $this->putJson('/api/v1/sync/orders', [], $this->auth())->assertStatus(405);
        $this->deleteJson('/api/v1/sync/orders', [], $this->auth())->assertStatus(405);
    }

    public function test_ping_tells_the_client_the_currency_and_the_resources(): void
    {
        $this->getJson('/api/v1/sync/ping', $this->auth())
            ->assertOk()
            ->assertJsonPath('company.name', 'Sync Co')
            ->assertJsonPath('currency', 'BDT')
            ->assertJsonPath('resources', fn (array $names): bool => in_array('orders', $names, true) && in_array('purchases', $names, true) && in_array('order-payments', $names, true));
    }

    public function test_an_unknown_resource_is_a_404(): void
    {
        $this->getJson('/api/v1/sync/secrets', $this->auth())->assertNotFound();
        $this->getJson('/api/v1/sync/users', $this->auth())->assertNotFound();
    }

    // ----------------------------------------------------- company isolation

    public function test_a_key_only_ever_sees_its_own_companys_records(): void
    {
        $this->asCompany($this->company, fn () => $this->product('MINE-1'));
        $this->asCompany($this->other, fn () => $this->product('THEIRS-1'));
        $this->asCompany($this->company, fn () => $this->saleOn('2026-09-01', 500));
        $this->asCompany($this->other, fn () => $this->saleOn('2026-09-01', 900));

        $products = $this->getJson('/api/v1/sync/products', $this->auth())->assertOk()->json('data');
        $skus = array_column($products, 'sku');
        $this->assertContains('MINE-1', $skus);
        $this->assertNotContains('THEIRS-1', $skus);
        $this->assertCount(count($skus), array_unique(array_column($products, 'id')));

        $orders = $this->getJson('/api/v1/sync/orders', $this->auth())->json('data');
        $this->assertCount(1, $orders);
        $this->assertSame('500.00', $orders[0]['total_amount']);

        $theirs = $this->getJson('/api/v1/sync/orders', $this->auth($this->otherToken))->json('data');
        $this->assertSame(['900.00'], array_column($theirs, 'total_amount'));
    }

    // ------------------------------------------------------------- sales data

    public function test_a_sale_arrives_with_its_lines_and_every_payment_with_its_own_date(): void
    {
        $order = $this->asCompany($this->company, function () {
            $order = $this->saleOn('2026-09-01', 1000, status: Order::STATUS_COMPLETED);
            $this->pay($order, 300, '2026-09-02', OrderPayment::TYPE_ADVANCE);
            $this->pay($order, 700, '2026-09-20', OrderPayment::TYPE_FINAL);

            return $order->fresh();
        });

        $row = $this->getJson('/api/v1/sync/orders', $this->auth())->assertOk()->json('data.0');

        $this->assertSame($order->order_number, $row['order_number']);
        $this->assertSame('completed', $row['status']);
        $this->assertSame('1000.00', $row['total_amount']);
        $this->assertSame('1000.00', $row['paid_amount']);
        $this->assertSame('0.00', $row['due_amount']);
        $this->assertCount(1, $row['items']);
        $this->assertSame('SKU-1', $row['items'][0]['sku']);
        $this->assertSame('1000.00', $row['items'][0]['unit_price']);
        $this->assertSame('1000.00', $row['items'][0]['subtotal']);

        $payments = collect($row['payments'])->sortBy('paid_at')->values();
        $this->assertSame(['2026-09-02', '2026-09-20'], $payments->pluck('paid_at')->all());
        $this->assertSame(['300.00', '700.00'], $payments->pluck('amount')->all());
        $this->assertSame(['advance', 'final'], $payments->pluck('type')->all());
    }

    public function test_the_payments_also_come_as_their_own_feed_with_the_order_number(): void
    {
        $order = $this->asCompany($this->company, function () {
            $order = $this->saleOn('2026-09-01', 1000);
            $this->pay($order, 250, '2026-09-05', OrderPayment::TYPE_PARTIAL);

            return $order->fresh();
        });

        $payments = $this->getJson('/api/v1/sync/order-payments', $this->auth())->assertOk()->json('data');

        $this->assertCount(1, $payments);
        $this->assertSame($order->order_number, $payments[0]['order_number']);
        $this->assertSame('250.00', $payments[0]['amount']);
        $this->assertSame('2026-09-05', $payments[0]['paid_at']);
    }

    public function test_a_deleted_order_stays_in_the_feed_flagged_as_deleted(): void
    {
        $order = $this->asCompany($this->company, fn () => $this->saleOn('2026-09-01', 400));
        $this->travel(5)->minutes();
        $this->asCompany($this->company, fn () => $order->fresh()->delete());

        $rows = $this->getJson('/api/v1/sync/orders', $this->auth())->json('data');

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['deleted']);
        $this->assertNotNull($rows[0]['deleted_at']);
    }

    public function test_money_is_always_a_two_decimal_string_and_times_carry_their_offset(): void
    {
        $this->asCompany($this->company, fn () => $this->product('M-1', price: 1234.5, cost: 99));

        $row = $this->getJson('/api/v1/sync/products', $this->auth())->json('data.0');

        $this->assertSame('1234.50', $row['price']);
        $this->assertSame('99.00', $row['cost_price']);
        $this->assertSame('2026-10-01T10:00:00+00:00', $row['updated_at']);
    }

    public function test_a_purchase_carries_its_lines_landed_cost_and_supplier(): void
    {
        $this->asCompany($this->company, function () {
            $supplier = Supplier::query()->create(['name' => 'Yarn Ltd', 'is_active' => true]);
            $product = $this->product('YARN-1');
            $purchase = Purchase::query()->create(['supplier_id' => $supplier->id, 'purchase_date' => '2026-09-03', 'status' => 'received', 'freight_to_ctg' => 100]);
            $purchase->items()->create(['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 50]);
        });

        $row = $this->getJson('/api/v1/sync/purchases', $this->auth())->assertOk()->json('data.0');

        $this->assertSame('Yarn Ltd', $row['supplier']['name']);
        $this->assertSame('2026-09-03', $row['purchase_date']);
        $this->assertSame('100.00', $row['cost_components']['freight_to_ctg']);
        $this->assertCount(1, $row['items']);
        $this->assertSame('YARN-1', $row['items'][0]['sku']);
        $this->assertSame(10, $row['items'][0]['quantity']);
        $this->assertSame('500.00', $row['items'][0]['subtotal']);
        $this->assertArrayHasKey('landed_unit_cost', $row['items'][0]);
    }

    public function test_expenses_come_with_category_amount_and_date(): void
    {
        $this->asCompany($this->company, function () {
            $category = ExpenseCategory::query()->create(['name' => 'Freight', 'slug' => 'freight', 'is_active' => true]);
            $account = Account::query()->create(['name' => 'Cash', 'opening_balance' => 5000]);
            Expense::query()->create(['expense_category_id' => $category->id, 'account_id' => $account->id, 'amount' => 750.5, 'expense_date' => '2026-09-10']);
        });

        $row = $this->getJson('/api/v1/sync/expenses', $this->auth())->assertOk()->json('data.0');

        $this->assertSame('Freight', $row['category']['name']);
        $this->assertSame('750.50', $row['amount']);
        $this->assertSame('2026-09-10', $row['expense_date']);
    }

    // ------------------------------------------------------- paging (critical)

    public function test_paging_by_cursor_returns_every_record_exactly_once_even_when_many_share_a_timestamp(): void
    {
        // 7 products, all stamped with the SAME second: the boundary case a
        // timestamp-only cursor gets wrong (skips or repeats rows).
        $this->asCompany($this->company, function (): void {
            foreach (range(1, 7) as $n) {
                $this->product("P-{$n}");
            }
        });

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $query = '?per_page=3'.($cursor ? '&cursor='.$cursor : '');
            $body = $this->getJson('/api/v1/sync/products'.$query, $this->auth())->assertOk()->json();
            array_push($seen, ...array_column($body['data'], 'sku'));
            $cursor = $body['meta']['next_cursor'];
            $pages++;
            $this->assertLessThan(10, $pages, 'the cursor must advance');
        } while ($cursor);

        $this->assertSame(3, $pages);
        $this->assertCount(7, $seen);
        $this->assertSame(['P-1', 'P-2', 'P-3', 'P-4', 'P-5', 'P-6', 'P-7'], $seen);
    }

    public function test_a_record_changed_between_two_pages_is_not_lost_or_repeated(): void
    {
        $this->asCompany($this->company, function (): void {
            foreach (['A', 'B', 'C', 'D'] as $i => $sku) {
                Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00')->addMinutes($i));
                $this->product($sku);
            }
        });

        $first = $this->getJson('/api/v1/sync/products?per_page=2', $this->auth())->json();
        $this->assertSame(['A', 'B'], array_column($first['data'], 'sku'));

        // A (already delivered) is edited after page one; it re-appears at the END, once.
        Carbon::setTestNow('2026-10-01 11:00:00');
        $this->asCompany($this->company, fn () => Product::query()->where('sku', 'A')->firstOrFail()->update(['name' => 'A edited']));

        $second = $this->getJson('/api/v1/sync/products?per_page=5&cursor='.$first['meta']['next_cursor'], $this->auth())->json();

        $this->assertSame(['C', 'D', 'A'], array_column($second['data'], 'sku'));
        $this->assertFalse($second['meta']['has_more']);
        $this->assertNull($second['meta']['next_cursor']);
    }

    public function test_updated_since_is_inclusive_and_returns_only_changed_records(): void
    {
        $this->asCompany($this->company, function (): void {
            Carbon::setTestNow('2026-10-01 08:00:00');
            $this->product('OLD');
            Carbon::setTestNow('2026-10-01 09:00:00');
            $this->product('EDGE');
            Carbon::setTestNow('2026-10-01 09:30:00');
            $this->product('NEW');
        });

        $skus = fn (string $since): array => array_column($this->getJson('/api/v1/sync/products?updated_since='.urlencode($since), $this->auth())->json('data'), 'sku');

        $this->assertSame(['EDGE', 'NEW'], $skus('2026-10-01T09:00:00Z'), 'a record exactly at the boundary is included');
        $this->assertSame(['NEW'], $skus('2026-10-01T09:00:01Z'));
        // 15:00 in +06:00 is 09:00 UTC: the offset is honoured, not read as UTC.
        $this->assertSame(['EDGE', 'NEW'], $skus('2026-10-01T15:00:00+06:00'));
    }

    public function test_status_and_date_filters_narrow_the_orders(): void
    {
        $this->asCompany($this->company, function (): void {
            $this->saleOn('2026-08-15', 100, status: Order::STATUS_COMPLETED);
            $this->saleOn('2026-09-15', 200, status: Order::STATUS_COMPLETED);
            $this->saleOn('2026-09-20', 300, status: Order::STATUS_CANCELLED);
        });

        $totals = fn (string $query): array => array_column($this->getJson('/api/v1/sync/orders?'.$query, $this->auth())->json('data'), 'total_amount');

        $this->assertEqualsCanonicalizing(['200.00'], $totals('status=completed&date_from=2026-09-01&date_to=2026-09-30'));
        $this->assertEqualsCanonicalizing(['300.00'], $totals('status=cancelled'));
        $this->assertCount(3, $totals(''));
    }

    public function test_ids_only_lists_every_id_so_the_client_can_spot_hard_deleted_rows(): void
    {
        $this->asCompany($this->company, function (): void {
            $this->product('X-1');
            $this->product('X-2');
        });

        $body = $this->getJson('/api/v1/sync/products?ids_only=1', $this->auth())->assertOk()->json();

        $this->assertCount(2, $body['data']);
        $this->assertSame(['id', 'updated_at', 'deleted'], array_keys($body['data'][0]));
    }

    public function test_per_page_is_capped_and_a_bad_cursor_is_a_clear_error(): void
    {
        $this->assertSame(ImsSyncFeed::MAX_PER_PAGE, $this->getJson('/api/v1/sync/products?per_page=99999', $this->auth())->json('meta.per_page'));

        $this->getJson('/api/v1/sync/products?cursor=garbage', $this->auth())
            ->assertStatus(422)->assertJson(['error' => 'invalid_cursor']);
    }

    // ----------------------------------------------------------- key creation

    public function test_staff_choose_the_key_type_when_they_generate_a_key(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $admin->companies()->attach($this->company, ['role' => 'super_admin', 'is_default' => true]);
        $this->actingAs($admin)->withSession(['current_company_id' => $this->company->id, 'current_company_selection_explicit' => true]);
        app(CompanyContext::class)->set($this->company);
        CompanyApiKey::query()->withoutGlobalScopes()->delete();

        // Left alone, the type is a website key: the safe default.
        Livewire::test(WebsiteApiKeysTable::class)
            ->mountTableAction('generateApiKey')
            ->setTableActionData(['label' => 'Site'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        Livewire::test(WebsiteApiKeysTable::class)
            ->mountTableAction('generateApiKey')
            ->setTableActionData(['label' => 'IMS', 'scope' => CompanyApiKey::SCOPE_SYNC])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $scopes = CompanyApiKey::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->pluck('scope', 'label')->all();
        $this->assertSame(['Site' => 'website', 'IMS' => 'sync'], $scopes);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, string> */
    private function auth(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token)];
    }

    private function makeCompany(string $name, string $prefix): Company
    {
        return Company::query()->create([
            'name' => $name, 'slug' => Str::slug($name), 'invoice_prefix' => $prefix,
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
    }

    /** Runs $callback with the data-entry company context set, as staff would. */
    private function asCompany(Company $company, callable $callback): mixed
    {
        $context = app(CompanyContext::class);
        $context->set($company);

        try {
            return $callback();
        } finally {
            $context->clear();
        }
    }

    private function product(string $sku, float $price = 100, float $cost = 60): Product
    {
        $product = Product::query()->create([
            'name' => "Product {$sku}", 'sku' => $sku, 'price' => $price, 'cost_price' => $cost, 'is_active' => true,
        ]);
        // Stock lives in the movement ledger; a bare `stock` column is overwritten from it.
        $product->setStockFromProductForm(1000);

        return $product->fresh();
    }

    private function saleOn(string $date, float $total, string $status = Order::STATUS_CONFIRMED): Order
    {
        $customer = Customer::query()->create(['name' => 'Buyer', 'phone' => '01712345678', 'is_active' => true]);
        $product = Product::query()->where('sku', 'SKU-1')->first() ?? $this->product('SKU-1');

        $order = Order::query()->create([
            'customer_id' => $customer->id, 'customer_name' => $customer->name, 'order_date' => $date,
            'status' => Order::STATUS_DRAFT, 'source' => Order::SOURCE_API,
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => $total, 'unit_cost' => 600,
        ]);
        $order->update(['status' => $status]);

        return $order->fresh();
    }

    private function pay(Order $order, float $amount, string $date, string $type): OrderPayment
    {
        return OrderPayment::query()->create([
            'order_id' => $order->id, 'type' => $type, 'method' => 'cash', 'amount' => $amount, 'paid_at' => $date,
        ]);
    }
}
