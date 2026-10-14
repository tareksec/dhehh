<?php

namespace Tests\Feature;

use App\Filament\Pages\ResellerSettings;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\ResellerDeposits\Pages\ListResellerDeposits;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ResellerDepositEntry;
use App\Models\ResellerProduct;
use App\Models\StockMovement;
use App\Models\StorefrontPaymentMethod;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\OrderStatusWorkflowService;
use App\Services\ResellerOrderPlacementService;
use App\Services\ResellerOwnCourierService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner decision (2026-10-14): an order cancelled before delivery gives the
 * reseller's deposit part back (already built); an order that comes back
 * returned costs the reseller a return charge of 50 per order, taken from
 * their deposit. The amount is set in Reseller Settings.
 */
class ResellerReturnChargeTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $reseller;

    protected StorefrontPaymentMethod $bkash;

    protected Product $ring;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('Return Co', 'return-co');
        app(CompanyContext::class)->set($this->company);
        $this->bkash = StorefrontPaymentMethod::query()->create([
            'company_id' => $this->company->getKey(), 'type' => StorefrontPaymentMethod::TYPE_MANUAL,
            'name' => 'bKash', 'account_number' => '01800000000', 'is_active' => true,
        ]);
        $this->reseller = $this->makeReseller($this->company, '01711000001');
        // A tier of 300 + the company's 10 per unit: the reseller pays 310 a piece.
        $this->ring = $this->pickProduct($this->company, $this->reseller, 'Ring');
        Queue::fake();
    }

    public function test_a_new_stores_return_charge_is_the_owners_fifty(): void
    {
        $this->assertSame('50.00', StorefrontSetting::withoutGlobalScopes()->where('company_id', $this->company->getKey())->value('reseller_return_charge'));
    }

    public function test_a_returned_store_delivered_order_takes_the_charge_from_the_deposit(): void
    {
        $this->credit(200);
        $order = $this->placeStoreOrder();

        $this->returnOrder($order);

        $entry = ResellerDepositEntry::query()->where('type', ResellerDepositEntry::TYPE_RETURN_CHARGE)->sole();
        $this->assertSame(-50.0, (float) $entry->amount);
        $this->assertSame($order->getKey(), $entry->order_id);
        $this->assertSame($this->reseller->getKey(), $entry->customer_id);
        $this->assertSame($this->company->getKey(), $entry->company_id);
        $this->assertStringContainsString($order->order_number, $entry->note);
        $service = app(ResellerOwnCourierService::class);
        $this->assertSame(150.0, $service->depositBalance($this->reseller));
        $this->assertSame(50.0, $service->returnChargeOf($order->fresh()));
    }

    public function test_an_own_courier_order_pays_the_charge_too_and_gets_no_deposit_back(): void
    {
        $this->credit(100);
        $service = app(ResellerOwnCourierService::class);
        // Owed 310: 100 comes from the deposit, 210 is sent to the store.
        $order = $this->placeOwnCourierOrder();
        $this->assertSame(0.0, $service->depositBalance($this->reseller));

        $this->returnOrder($order);

        // Only the charge: what the order used from the deposit does not come back on a return.
        $this->assertSame(-50.0, $service->depositBalance($this->reseller));
        $this->assertSame(0, ResellerDepositEntry::query()->where('type', ResellerDepositEntry::TYPE_REFUND)->count());
        $this->assertSame(1, ResellerDepositEntry::query()->where('type', ResellerDepositEntry::TYPE_RETURN_CHARGE)->count());
    }

    public function test_the_charge_is_taken_once_however_often_the_order_is_saved(): void
    {
        $order = $this->placeStoreOrder();
        $service = app(ResellerOwnCourierService::class);

        $this->returnOrder($order);
        $this->assertSame(0.0, $service->chargeReturn($order->fresh())); // asking again charges nothing
        $order->fresh()->save();
        app(OrderStatusWorkflowService::class)->transition($order->fresh(), Order::STAGE_REFUNDED, 'Refunded to the customer', 'test');
        $order->fresh()->save();

        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertSame(1, ResellerDepositEntry::query()->where('type', ResellerDepositEntry::TYPE_RETURN_CHARGE)->count());
        $this->assertSame(-50.0, $service->depositBalance($this->reseller));
    }

    public function test_only_a_returned_reseller_order_is_charged(): void
    {
        $service = app(ResellerOwnCourierService::class);

        // Cancelled before delivery: no charge (the deposit part, if any, goes back instead).
        $cancelled = $this->placeStoreOrder();
        app(OrderStatusWorkflowService::class)->transition($cancelled, Order::STAGE_CANCELLED, 'Customer cancelled', 'test');
        $this->assertSame(0.0, $service->returnChargeOf($cancelled->fresh()));

        // A delivered order that is not returned.
        $delivered = $this->placeStoreOrder('01744000002');
        app(OrderStatusWorkflowService::class)->transition($delivered, Order::STAGE_DELIVERED, null, 'test');
        $this->assertSame(0.0, $service->returnChargeOf($delivered->fresh()));

        // An order that does not belong to a reseller.
        $plain = Order::query()->create([
            'customer_name' => 'Walk-in', 'order_date' => now()->toDateString(), 'discount' => 0, 'vat' => 0, 'shipping_fee' => 0,
            'paid_amount' => 0, 'status' => Order::STATUS_DRAFT, 'source' => Order::SOURCE_ADMIN,
        ]);
        $plain->forceFill(['status' => Order::STATUS_RETURNED])->save();
        $this->assertSame(0.0, $service->chargeReturn($plain->fresh()));

        $this->assertSame(0, ResellerDepositEntry::query()->count());
    }

    public function test_the_amount_comes_from_the_companys_setting_and_zero_means_no_charge(): void
    {
        $this->setting($this->company)->update(['reseller_return_charge' => 80]);
        $first = $this->placeStoreOrder();
        $this->returnOrder($first);
        $this->assertSame(-80.0, app(ResellerOwnCourierService::class)->depositBalance($this->reseller));

        $this->setting($this->company)->update(['reseller_return_charge' => 0]);
        $second = $this->placeStoreOrder('01744000002');
        $this->returnOrder($second);
        $this->assertSame(1, ResellerDepositEntry::query()->where('type', ResellerDepositEntry::TYPE_RETURN_CHARGE)->count());
    }

    public function test_each_company_uses_its_own_charge_and_its_own_resellers_deposit(): void
    {
        $other = $this->makeCompany('Other Co', 'other-co', returnCharge: 120);
        $otherReseller = $this->makeReseller($other, '01733000001');
        app(CompanyContext::class)->set($other);
        $otherRing = $this->pickProduct($other, $otherReseller, 'Other Ring');
        $otherOrder = $this->placeStoreOrderFor($otherReseller, $otherRing);
        $this->returnOrder($otherOrder);

        app(CompanyContext::class)->set($this->company);
        $service = app(ResellerOwnCourierService::class);
        $this->assertSame(-120.0, $service->depositBalance($otherReseller));
        $this->assertSame(0.0, $service->depositBalance($this->reseller)); // this company's reseller is untouched
        $this->assertSame($other->getKey(), ResellerDepositEntry::withoutGlobalScopes()->sole()->company_id);
    }

    public function test_a_deposit_below_zero_pays_nothing_and_later_credits_make_it_up_first(): void
    {
        $service = app(ResellerOwnCourierService::class);
        $this->credit(20);
        $this->returnOrder($this->placeStoreOrder());

        // 20 - 50: the deposit is in debt, and an order cannot use a debt as money.
        $this->assertSame(-30.0, $service->depositBalance($this->reseller));
        $this->assertSame(0.0, $service->spendableDeposit($this->reseller));
        $quote = app(ResellerOrderPlacementService::class)->quote($this->reseller, [['product_id' => $this->ring->getKey(), 'quantity' => 1]], 'Dhaka, Mirpur', true);
        $this->assertSame(0.0, $quote['deposit_used']);
        $this->assertSame($quote['total'], $quote['to_send']);

        $order = $this->placeOwnCourierOrder('01755000003');
        $this->assertSame(0, $order->resellerAdvancePayments()->where('gateway', ResellerOwnCourierService::GATEWAY_DEPOSIT)->count());
        $this->assertSame(-30.0, $service->depositBalance($this->reseller));

        // A new credit of 100 first covers the 30, and only the rest can be spent.
        $this->credit(100);
        $this->assertSame(70.0, $service->depositBalance($this->reseller));
        $this->assertSame(70.0, $service->spendableDeposit($this->reseller));
    }

    public function test_staff_see_the_charge_in_the_deposit_list_and_on_the_order(): void
    {
        $order = $this->placeStoreOrder();
        $this->returnOrder($order);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $this->company->getKey()]);
        $entry = ResellerDepositEntry::query()->sole();

        Livewire::test(ListResellerDeposits::class)
            ->assertCanSeeTableRecords([$entry])
            ->assertSee('Return charge (order returned)');

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSee('Return charge')
            ->assertSee('Taken from the reseller\'s deposit');
    }

    public function test_the_reseller_sees_why_the_deposit_went_down(): void
    {
        $order = $this->placeStoreOrder();
        $this->returnOrder($order);

        $html = view('filament.reseller.pages.partials.order-detail', [
            'order' => $order->fresh()->load(['items.product', 'statusTransitions', 'resellerCommission', 'resellerAdvancePayments.paymentMethod']),
            'currency' => 'BDT',
        ])->render();

        $this->assertStringContainsString('was taken from your deposit because this order was returned', $html);

        // An order that was not returned says nothing of the sort.
        $fresh = $this->placeStoreOrder('01744000002');
        $html = view('filament.reseller.pages.partials.order-detail', [
            'order' => $fresh->load(['items.product', 'statusTransitions', 'resellerCommission', 'resellerAdvancePayments.paymentMethod']),
            'currency' => 'BDT',
        ])->render();
        $this->assertStringNotContainsString('taken from your deposit because', $html);
    }

    public function test_the_admin_sets_the_amount_in_reseller_settings_and_it_cannot_be_negative(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $this->company->getKey()]);

        Livewire::test(ResellerSettings::class)
            ->assertFormSet(['reseller_return_charge' => 50])
            ->fillForm(['reseller_return_charge' => 75])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('75.00', $this->setting($this->company)->fresh()->reseller_return_charge);

        Livewire::test(ResellerSettings::class)
            ->fillForm(['reseller_return_charge' => -5])
            ->call('save')
            ->assertHasFormErrors(['reseller_return_charge']);
        $this->assertSame('75.00', $this->setting($this->company)->fresh()->reseller_return_charge);
    }

    protected function setting(Company $company): StorefrontSetting
    {
        return StorefrontSetting::withoutGlobalScopes()->where('company_id', $company->getKey())->firstOrFail();
    }

    protected function credit(float $amount): void
    {
        ResellerDepositEntry::query()->create([
            'company_id' => $this->company->getKey(), 'customer_id' => $this->reseller->getKey(),
            'type' => ResellerDepositEntry::TYPE_OVERPAYMENT, 'amount' => $amount,
        ]);
    }

    /** An order the store delivers (cash on delivery), confirmed so it can go out and come back. */
    protected function placeStoreOrder(string $buyerPhone = '01722000009'): Order
    {
        return $this->placeStoreOrderFor($this->reseller, $this->ring, $buyerPhone);
    }

    protected function placeStoreOrderFor(Customer $reseller, Product $product, string $buyerPhone = '01722000009'): Order
    {
        $order = app(ResellerOrderPlacementService::class)->place($reseller, [
            'name' => 'Buyer', 'phone' => $buyerPhone, 'address' => 'Dhaka, Mirpur', 'note' => null,
        ], [['product_id' => $product->getKey(), 'quantity' => 1, 'price' => 500]]);
        app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_CONFIRMED, null, 'test');

        return $order->fresh();
    }

    protected function placeOwnCourierOrder(string $parcelId = 'PATHAO-123'): Order
    {
        return app(ResellerOrderPlacementService::class)->place($this->reseller, [
            'name' => 'Buyer', 'phone' => '01722000009', 'address' => 'Dhaka, Mirpur', 'note' => null,
        ], [['product_id' => $this->ring->getKey(), 'quantity' => 1, 'price' => 650]], [
            'courier_name' => 'Pathao', 'parcel_id' => $parcelId,
            'payment_method_id' => $this->bkash->getKey(), 'sender' => '01999999999', 'transaction_id' => 'TRX-'.Str::random(5),
        ]);
    }

    protected function returnOrder(Order $order): void
    {
        $order = $order->fresh();

        if ($order->status === Order::STATUS_DRAFT) {
            app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_CONFIRMED, null, 'test');
            $order = $order->fresh();
        }

        app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_RETURNED, 'Customer refused the parcel', 'test');
    }

    protected function makeCompany(string $name, string $slug, float $returnCharge = 50): Company
    {
        $company = Company::query()->create([
            'name' => $name, 'slug' => $slug, 'domain' => $slug.'.example.test', 'domain_verified' => true,
            'invoice_prefix' => strtoupper(substr($slug, 0, 3)), 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka',
            'is_active' => true, 'reseller_module_enabled' => true,
        ])->fresh();
        StorefrontSetting::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(), 'theme_color' => '#0F766E', 'is_published' => true,
            'new_customer_delivery_advance_enabled' => false, 'reseller_cost_per_unit' => 10,
            'delivery_first_kg_inside' => 70, 'delivery_first_kg_outside' => 110, 'delivery_additional_per_kg' => 20,
            ...($returnCharge === 50.0 ? [] : ['reseller_return_charge' => $returnCharge]),
        ]);

        return $company;
    }

    protected function makeReseller(Company $company, string $phone): Customer
    {
        $customer = new Customer([
            'name' => 'Reseller '.$phone, 'phone' => $phone, 'reseller_status' => 'approved',
            'reseller_slug' => 'r-'.Str::random(6), 'business_name' => 'Shop '.$phone, 'is_active' => true,
        ]);
        $customer->company_id = $company->getKey();
        $customer->forceFill(['password' => Hash::make('secret-password')])->save();

        return $customer;
    }

    protected function pickProduct(Company $company, Customer $reseller, string $name): Product
    {
        $product = Product::query()->create([
            'company_id' => $company->getKey(), 'name' => $name, 'sku' => 'RC-'.Str::random(6),
            'price' => 450, 'sale_price' => 450, 'cost_price' => 200, 'stock' => 0, 'unit' => 'pcs',
            'reorder_level' => 1, 'vat_rate' => 0, 'tier_prices' => [['min_qty' => 1, 'price' => 300]],
            'is_active' => true, 'status' => Product::STATUS_AVAILABLE,
        ])->fresh();
        StockMovement::query()->create(['product_id' => $product->getKey(), 'type' => 'opening', 'quantity' => 50]);
        ResellerProduct::query()->create([
            'customer_id' => $reseller->getKey(), 'product_id' => $product->getKey(), 'is_active' => true,
        ]);

        return $product->fresh();
    }
}
