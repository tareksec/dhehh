<?php

namespace Tests\Feature;

use App\Filament\Pages\ResellerSettings;
use App\Filament\Resources\ResellerCommissions\Pages\ListResellerCommissions;
use App\Filament\Resources\ResellerPayoutRequests\Pages\ListResellerPayoutRequests;
use App\Models\Account;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FundSource;
use App\Models\Order;
use App\Models\Product;
use App\Models\ResellerCommission;
use App\Models\ResellerPayoutRequest;
use App\Models\ResellerProduct;
use App\Models\StockMovement;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Models\Voucher;
use App\Services\CompanyContext;
use App\Services\ResellerCommissionService;
use App\Services\VoucherService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner's reseller commission rules (2026-09-28): a reseller's cost is the
 * product's lowest wholesale tier + one company-wide cost per unit; the
 * reseller sells at any price at or above that (suggested = normal price);
 * the margin becomes payable once the order is delivered; the reseller
 * requests a withdrawal and staff approve/pay it, with an admin-set
 * minimum withdrawal.
 */
class ResellerCommissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_reseller_price_must_be_at_least_the_wholesale_rate_plus_cost(): void
    {
        [$company, $reseller, $product] = $this->setUpStore('price-floor.example.test');

        $this->actingAsCustomer($reseller)
            ->patch("http://price-floor.example.test/account/reseller/products/{$product->id}/price", ['price' => 399])
            ->assertSessionHasErrors('price');

        $this->actingAsCustomer($reseller)
            ->patch("http://price-floor.example.test/account/reseller/products/{$product->id}/price", ['price' => 550])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('550.00', ResellerProduct::query()->where('product_id', $product->id)->value('price'));
    }

    public function test_a_reseller_customer_pays_the_reseller_price_everywhere_and_the_order_records_the_margin(): void
    {
        [$company, $reseller, $product] = $this->setUpStore('margin.example.test', resellerPrice: 550);

        // Landing on the reseller's store remembers the reseller, shows
        // their price, and hides the wholesale tier table (the lowest tier
        // is the reseller's own buying rate).
        $this->get('http://margin.example.test/store/margin-shop/product/'.$product->slug)
            ->assertOk()
            ->assertSee('550')
            ->assertDontSee('Wholesale pricing');

        // The storefront's own links go to main-store routes: the session
        // keeps the reseller's price and attribution there too.
        $this->get('http://margin.example.test/product/'.$product->slug)->assertOk()->assertSee('550');
        $this->post("http://margin.example.test/cart/items/{$product->slug}", ['quantity' => 2])->assertRedirect();
        $this->post('http://margin.example.test/checkout', $this->checkoutData())->assertRedirect();

        $order = Order::query()->where('source', Order::SOURCE_STOREFRONT)->with('items')->firstOrFail();

        $this->assertSame($reseller->getKey(), $order->reseller_customer_id);
        $this->assertSame('550.00', $order->items->first()->unit_price);
        $this->assertSame('400.00', $order->items->first()->reseller_base_price);

        $commission = ResellerCommission::query()->where('order_id', $order->getKey())->firstOrFail();
        $this->assertSame(ResellerCommission::STATUS_PENDING, $commission->status);
        $this->assertSame('1100.00', $commission->sales_amount);
        $this->assertSame('800.00', $commission->base_amount);
        $this->assertSame('300.00', $commission->amount);
    }

    public function test_the_main_storefront_is_unaffected_for_a_visitor_without_a_reseller(): void
    {
        [, , $product] = $this->setUpStore('plain-visitor.example.test', resellerPrice: 550);

        $this->get('http://plain-visitor.example.test/product/'.$product->slug)
            ->assertOk()
            ->assertDontSee('550')
            ->assertSee('Wholesale pricing');
    }

    public function test_a_blank_reseller_price_sells_at_the_suggested_normal_price(): void
    {
        [, $reseller, $product] = $this->setUpStore('suggested.example.test');

        $this->get('http://suggested.example.test/store/margin-shop/products')->assertOk();
        $this->post("http://suggested.example.test/cart/items/{$product->slug}", ['quantity' => 1])->assertRedirect();
        $this->post('http://suggested.example.test/checkout', $this->checkoutData())->assertRedirect();

        $commission = ResellerCommission::query()->firstOrFail();
        $this->assertSame($reseller->getKey(), $commission->customer_id);
        $this->assertSame('500.00', $commission->sales_amount);
        $this->assertSame('100.00', $commission->amount);
    }

    public function test_a_product_without_wholesale_tiers_earns_no_commission(): void
    {
        [$company, $reseller] = $this->setUpStore('no-tiers.example.test');
        app(CompanyContext::class)->set($company);
        $plain = $this->product($company, 'No Tier Product', tiers: []);
        ResellerProduct::query()->create(['customer_id' => $reseller->getKey(), 'product_id' => $plain->getKey(), 'is_active' => true, 'price' => 900]);

        $this->get('http://no-tiers.example.test/store/margin-shop/products')->assertOk();
        $this->post("http://no-tiers.example.test/cart/items/{$plain->slug}", ['quantity' => 1])->assertRedirect();
        $this->post('http://no-tiers.example.test/checkout', $this->checkoutData())->assertRedirect();

        $order = Order::query()->where('source', Order::SOURCE_STOREFRONT)->with('items')->firstOrFail();
        $this->assertSame('500.00', $order->items->first()->unit_price);
        $this->assertNull($order->items->first()->reseller_base_price);
        $this->assertFalse(ResellerCommission::query()->exists());
    }

    public function test_commission_becomes_payable_on_delivery_and_is_cancelled_on_return(): void
    {
        [$company, $reseller, $product] = $this->setUpStore('lifecycle.example.test', resellerPrice: 450);
        $order = $this->placeResellerOrder('lifecycle.example.test', $product);
        $service = app(ResellerCommissionService::class);

        $this->assertSame(0.0, $service->balances($reseller)['available']);
        $this->assertSame(50.0, $service->balances($reseller)['pending']);

        $order->update(['status' => Order::STATUS_COMPLETED, 'delivery_status' => CourierBooking::STATUS_DELIVERED]);

        $commission = ResellerCommission::query()->firstOrFail();
        $this->assertSame(ResellerCommission::STATUS_PAYABLE, $commission->status);
        $this->assertNotNull($commission->payable_at);
        $this->assertSame(50.0, $service->balances($reseller)['available']);

        $order->update(['status' => Order::STATUS_RETURNED, 'delivery_status' => CourierBooking::STATUS_RETURNED]);

        $this->assertSame(ResellerCommission::STATUS_CANCELLED, $commission->fresh()->status);
        $this->assertSame(0.0, $service->balances($reseller)['available']);
    }

    public function test_withdrawal_requests_respect_the_minimum_and_the_available_balance(): void
    {
        [$company, $reseller, $product] = $this->setUpStore('payout.example.test', resellerPrice: 600, minWithdrawal: 100);
        $order = $this->placeResellerOrder('payout.example.test', $product);
        $order->update(['status' => Order::STATUS_COMPLETED, 'delivery_status' => CourierBooking::STATUS_DELIVERED]);

        $payout = fn (float $amount) => $this->actingAsCustomer($reseller)
            ->post('http://payout.example.test/account/reseller/payouts', [
                'amount' => $amount,
                'method' => 'bkash',
                'account_details' => '01711000000',
            ]);

        $payout(50)->assertSessionHasErrors('amount');   // below the minimum
        $payout(250)->assertSessionHasErrors('amount');  // above the 200 available
        $payout(150)->assertSessionHasNoErrors();

        $request = ResellerPayoutRequest::query()->firstOrFail();
        $this->assertSame(ResellerPayoutRequest::STATUS_REQUESTED, $request->status);
        $this->assertSame($company->getKey(), $request->company_id);
        $this->assertSame(50.0, app(ResellerCommissionService::class)->balances($reseller)['available']);

        // Only 50 is left, so a second 150 request fails.
        $payout(150)->assertSessionHasErrors('amount');
        $this->assertSame(1, ResellerPayoutRequest::query()->count());

        $this->actingAsCustomer($reseller)
            ->get('http://payout.example.test/account/reseller')
            ->assertRedirect('http://reseller.payout.example.test/portal');
    }

    public function test_staff_can_mark_paid_or_reject_and_rejection_releases_the_balance(): void
    {
        [$company, $reseller, $product] = $this->setUpStore('admin-payout.example.test', resellerPrice: 600);
        $order = $this->placeResellerOrder('admin-payout.example.test', $product);
        $order->update(['status' => Order::STATUS_COMPLETED, 'delivery_status' => CourierBooking::STATUS_DELIVERED]);
        $service = app(ResellerCommissionService::class);

        app(CompanyContext::class)->set($company);
        $paid = ResellerPayoutRequest::query()->create(['customer_id' => $reseller->getKey(), 'amount' => 120, 'method' => 'nagad', 'account_details' => '01811000000']);
        $rejected = ResellerPayoutRequest::query()->create(['customer_id' => $reseller->getKey(), 'amount' => 80, 'method' => 'bkash', 'account_details' => '01711000000']);
        $this->assertSame(0.0, $service->balances($reseller)['available']);

        $account = Account::query()->create(['name' => 'bKash Merchant', 'type' => 'bank', 'opening_balance' => 1000]);
        $fundSource = FundSource::query()->create(['name' => 'Reseller Payouts', 'type' => 'owner_investment', 'account_id' => $account->id]);
        $category = ExpenseCategory::query()->create(['name' => 'Reseller commission', 'slug' => 'reseller-commission', 'is_active' => true]);

        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        Livewire::test(ListResellerPayoutRequests::class)
            ->assertCanSeeTableRecords([$paid, $rejected])
            ->callTableAction('markPaid', $paid, data: [
                'payment_reference' => 'TRX123',
                'fund_source_id' => $fundSource->id,
                'expense_category_id' => $category->id,
            ])
            ->assertHasNoTableActionErrors()
            ->callTableAction('reject', $rejected, data: ['admin_note' => 'Wrong number']);

        $paid->refresh();
        $this->assertSame(ResellerPayoutRequest::STATUS_PAID, $paid->status);
        $this->assertSame('TRX123', $paid->payment_reference);

        // Every paid withdrawal is booked in Finance as a pending debit
        // voucher (business expense), approved through the Voucher workflow.
        $voucher = $paid->voucher;
        $this->assertNotNull($voucher);
        $this->assertSame(Voucher::TYPE_DEBIT, $voucher->type);
        $this->assertSame(Voucher::STATUS_PENDING, $voucher->status);
        $this->assertSame('business_expense', $voucher->transaction_type);
        $this->assertSame('120.00', $voucher->amount);
        $this->assertSame($fundSource->id, $voucher->fund_source_id);
        $this->assertSame($account->id, $voucher->account_id);
        $this->assertSame($category->id, $voucher->expense_category_id);
        $this->assertSame('TRX123', $voucher->transaction_id);
        $this->assertSame($company->getKey(), $voucher->company_id);
        $this->assertNull($rejected->fresh()->voucher_id);

        // Finance approval turns it into a real expense.
        app(VoucherService::class)->approve($voucher, auth()->user());
        $this->assertTrue(Expense::query()->where('reference', $voucher->voucher_number)->where('amount', 120)->exists());
        $this->assertSame(ResellerPayoutRequest::STATUS_REJECTED, $rejected->fresh()->status);

        $balances = $service->balances($reseller);
        $this->assertSame(120.0, $balances['withdrawn']);
        $this->assertSame(80.0, $balances['available']);

        Livewire::test(ListResellerCommissions::class)
            ->assertCanSeeTableRecords([ResellerCommission::query()->firstOrFail()]);
    }

    public function test_reseller_money_settings_live_in_reseller_settings_and_never_show_in_the_footer(): void
    {
        [$company] = $this->setUpStore('settings.example.test');
        $setting = $company->storefrontSetting;

        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $company->getKey()]);

        Livewire::test(ResellerSettings::class)
            ->assertSee(__('Reseller cost per unit'))
            ->assertSee(__('Minimum reseller withdrawal'))
            ->fillForm(['reseller_cost_per_unit' => 35, 'reseller_min_withdrawal' => 250])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('35.00', $setting->refresh()->reseller_cost_per_unit);
        $this->assertSame('250.00', $setting->reseller_min_withdrawal);

        // The public footer carries only the "Become a reseller" link.
        $this->get('http://settings.example.test/products')
            ->assertOk()
            ->assertSee('Become a reseller')
            ->assertDontSee('35.00')
            ->assertDontSee('250.00');
    }

    public function test_payout_requests_are_company_scoped(): void
    {
        [$companyA, $resellerA] = $this->setUpStore('scope-a.example.test');
        $companyB = $this->company('scope-b.example.test', 'OTHR');

        app(CompanyContext::class)->set($companyA);
        ResellerPayoutRequest::query()->create(['customer_id' => $resellerA->getKey(), 'amount' => 10, 'method' => 'bkash', 'account_details' => 'x']);

        app(CompanyContext::class)->set($companyB);
        $this->assertSame(0, ResellerPayoutRequest::query()->count());
    }

    /** @return array{0: Company, 1: Customer, 2: Product} */
    protected function setUpStore(string $domain, ?float $resellerPrice = null, float $minWithdrawal = 0): array
    {
        $company = $this->company($domain, strtoupper(Str::random(4)));
        $company->storefrontSetting->update([
            'reseller_cost_per_unit' => 20,
            'reseller_min_withdrawal' => $minWithdrawal,
        ]);
        app(CompanyContext::class)->set($company);

        $reseller = Customer::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Margin Reseller',
            'phone' => '01912345678',
            'reseller_status' => 'approved',
            'reseller_slug' => 'margin-shop',
            'business_name' => 'Margin Shop',
            'is_active' => true,
        ]);

        // Lowest tier 380 + cost 20 = reseller base 400; suggested 500.
        $product = $this->product($company, 'Tiered Product', tiers: [
            ['min_qty' => 10, 'price' => 420],
            ['min_qty' => 50, 'price' => 380],
        ]);

        ResellerProduct::query()->create([
            'customer_id' => $reseller->getKey(),
            'product_id' => $product->getKey(),
            'is_active' => true,
            'price' => $resellerPrice,
        ]);

        return [$company, $reseller, $product];
    }

    protected function placeResellerOrder(string $domain, Product $product): Order
    {
        $this->post("http://{$domain}/store/margin-shop/cart/items/{$product->slug}", ['quantity' => 1])->assertRedirect();
        $this->post("http://{$domain}/store/margin-shop/checkout", $this->checkoutData())->assertRedirect();

        return Order::query()->where('source', Order::SOURCE_STOREFRONT)->firstOrFail();
    }

    protected function checkoutData(): array
    {
        return [
            'name' => 'Reseller Buyer',
            'phone' => '01700999888',
            'email' => 'buyer@example.test',
            'address' => 'Mirpur, Dhaka',
        ];
    }

    protected function company(string $domain, string $prefix): Company
    {
        $company = Company::query()->create([
            'name' => 'Commission '.$domain,
            'slug' => str($domain)->slug()->toString(),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => $prefix,
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
            'reseller_module_enabled' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'theme_color' => '#0F766E',
            'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
        ]);

        return $company->fresh();
    }

    protected function product(Company $company, string $name, array $tiers): Product
    {
        $product = Product::query()->create([
            'company_id' => $company->getKey(),
            'name' => $name,
            'sku' => 'RC-'.Str::random(6),
            'price' => 500,
            'sale_price' => 500,
            'cost_price' => 300,
            'stock' => 0,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'tier_prices' => $tiers,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);

        // Real ledger stock, so moving an order to completed can deduct it.
        StockMovement::query()->create(['product_id' => $product->getKey(), 'type' => 'opening', 'quantity' => 100]);

        return $product->fresh();
    }

    /** Authenticates only the storefront 'customer' guard (see ResellerSelfServiceTest). */
    protected function actingAsCustomer(Customer $customer): static
    {
        $this->app['auth']->guard('customer')->setUser($customer);

        return $this;
    }
}
