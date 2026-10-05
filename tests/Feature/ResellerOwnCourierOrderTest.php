<?php

namespace Tests\Feature;

use App\Filament\Reseller\Pages\CreateOrder;
use App\Filament\Reseller\Pages\Orders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Jobs\CheckExternalCourierFraudJob;
use App\Models\Company;
use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\ResellerCommission;
use App\Models\ResellerProduct;
use App\Models\StockMovement;
use App\Models\StorefrontPayment;
use App\Models\StorefrontPaymentMethod;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\ResellerOrderPlacementService;
use App\Services\ResellerOwnCourierService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner's process: a reseller books the parcel with their own courier, gives
 * the parcel ID and pays the full amount in advance; the company checks the
 * payment, packs the parcel and puts the reseller's parcel ID on it.
 */
class ResellerOwnCourierOrderTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $reseller;

    protected StorefrontPaymentMethod $bkash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Parcel Co', 'slug' => 'parcel-co', 'domain' => 'parcel-co.example.test',
            'domain_verified' => true, 'invoice_prefix' => 'PCL', 'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka', 'is_active' => true, 'reseller_module_enabled' => true,
        ])->fresh();
        StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(), 'theme_color' => '#0F766E', 'is_published' => true,
            'new_customer_delivery_advance_enabled' => false, 'reseller_cost_per_unit' => 10,
            'delivery_first_kg_inside' => 70, 'delivery_first_kg_outside' => 110, 'delivery_additional_per_kg' => 20,
        ]);
        app(CompanyContext::class)->set($this->company);
        $this->bkash = StorefrontPaymentMethod::query()->create([
            'company_id' => $this->company->getKey(), 'type' => StorefrontPaymentMethod::TYPE_MANUAL,
            'name' => 'bKash', 'account_number' => '01800000000', 'instructions' => 'Send money', 'is_active' => true,
        ]);
        $this->reseller = $this->makeReseller('01711000001');
        Queue::fake();
    }

    public function test_an_own_courier_order_charges_the_base_price_with_no_delivery_charge_or_commission(): void
    {
        CourierProvider::query()->create([
            'company_id' => $this->company->getKey(), 'name' => 'Default courier',
            'driver' => CourierProvider::DRIVER_MANUAL, 'is_active' => true, 'is_default' => true,
        ]);
        $product = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $plain = $this->pickProduct('Plain', tier: null, stock: 20, price: 200);

        $order = $this->place([
            ['product_id' => $product->getKey(), 'quantity' => 2, 'price' => 900],
            ['product_id' => $plain->getKey(), 'quantity' => 1],
        ]);

        $this->assertTrue($order->reseller_own_courier);
        $this->assertSame('PATHAO-123', $order->reseller_parcel_id);
        $this->assertSame('Pathao', $order->reseller_courier_name);
        $this->assertSame(0.0, (float) $order->shipping_fee);
        $this->assertNull($order->courier_provider_id);
        // Base 310 (tier 300 + cost 10) x 2, and the normal 200 for the product without a wholesale rate.
        $this->assertSame(820.0, (float) $order->total_amount);
        $this->assertSame(0.0, (float) $order->paid_amount);
        $this->assertFalse(ResellerCommission::query()->where('order_id', $order->getKey())->exists());

        $payment = $order->resellerAdvancePayment;
        $this->assertSame(StorefrontPayment::STATUS_PENDING, $payment->status);
        $this->assertSame(820.0, (float) $payment->amount);
        $this->assertSame('TRX1', $payment->transaction_id);
        $this->assertSame('01999999999', $payment->payment_method);
        $this->assertSame((int) $this->bkash->getKey(), (int) $payment->storefront_payment_method_id);

        Queue::assertNotPushed(CheckExternalCourierFraudJob::class);
    }

    public function test_the_parcel_id_and_payment_details_are_required_and_a_parcel_id_is_used_once(): void
    {
        $product = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $line = [['product_id' => $product->getKey(), 'quantity' => 1]];

        $errors = $this->errorsFor(fn () => $this->place($line, ['parcel_id' => ' ', 'payment_method_id' => 999, 'sender' => '', 'transaction_id' => '']));
        $this->assertEqualsCanonicalizing(['data.parcel_id', 'data.payment_method_id', 'data.sender', 'data.transaction_id'], array_keys($errors));

        $this->place($line);
        $errors = $this->errorsFor(fn () => $this->place($line));
        $this->assertSame(['data.parcel_id'], array_keys($errors));

        // Another reseller may have the same ID from their own courier.
        $this->reseller = $this->makeReseller('01711000002');
        $this->pickProduct('Ring 2', tier: 300, stock: 20, price: 450);
        $this->assertNotNull($this->place([['product_id' => $this->reseller->resellerCatalog()->first()->getKey(), 'quantity' => 1]]));
    }

    public function test_verifying_the_payment_marks_the_order_paid_and_allows_packing(): void
    {
        $product = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $order = $this->place([['product_id' => $product->getKey(), 'quantity' => 1]]);
        $service = app(ResellerOwnCourierService::class);

        $this->assertFalse($service->canMarkPacked($order));
        $this->expectsValidation(fn () => $service->markPacked($order));

        $service->verifyPayment($order->resellerAdvancePayment);
        $order->refresh();

        $this->assertSame('verified', $service->paymentState($order));
        $this->assertSame(310.0, (float) $order->paid_amount);
        $this->assertSame(0.0, (float) $order->due_amount);
        $payment = OrderPayment::query()->where('order_id', $order->getKey())->sole();
        $this->assertSame(OrderPayment::TYPE_ADVANCE, $payment->type);
        $this->assertStringContainsString('TRX1', $payment->note);

        // A second verify is a no-op.
        $service->verifyPayment($order->resellerAdvancePayment);
        $this->assertSame(1, OrderPayment::query()->where('order_id', $order->getKey())->count());

        $service->markPacked($order);
        $this->assertNotNull($order->fresh()->reseller_parcel_packed_at);
        $this->assertFalse($service->canMarkPacked($order->fresh()));
    }

    public function test_a_rejected_payment_can_be_sent_again_but_not_while_one_is_waiting(): void
    {
        $product = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $order = $this->place([['product_id' => $product->getKey(), 'quantity' => 1]]);
        $service = app(ResellerOwnCourierService::class);
        $again = ['payment_method_id' => $this->bkash->getKey(), 'sender' => '01999999999', 'transaction_id' => 'TRX2'];

        $this->expectsValidation(fn () => $service->submitPayment($order, $again));

        $service->rejectPayment($order->resellerAdvancePayment);
        $this->assertSame('rejected', $service->paymentState($order->fresh()));

        $service->submitPayment($order, $again);
        $order->refresh();
        $this->assertSame('waiting', $service->paymentState($order));
        $this->assertSame('TRX2', $order->resellerAdvancePayment->transaction_id);
        $this->assertSame(2, StorefrontPayment::query()->where('order_id', $order->getKey())->count());
    }

    public function test_the_portal_form_places_an_own_courier_order(): void
    {
        $product = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $this->asPortal();

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'delivery_method' => 'own',
                'name' => 'Buyer One', 'phone' => '01722000001', 'address' => 'House 1, Mirpur, Dhaka',
                'items' => [['product_id' => $product->getKey(), 'quantity' => 2]],
            ])
            ->assertSee('You pay in advance')
            ->assertDontSee('Your commission')
            ->fillForm([
                'delivery_method' => 'own',
                'name' => 'Buyer One', 'phone' => '01722000001', 'address' => 'House 1, Mirpur, Dhaka',
                'courier_name' => 'Steadfast', 'parcel_id' => 'SF-77',
                'payment_method_id' => $this->bkash->getKey(), 'sender' => '01999999999', 'transaction_id' => 'TRX9',
                'items' => [['product_id' => $product->getKey(), 'quantity' => 2]],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $order = Order::query()->where('reseller_customer_id', $this->reseller->getKey())->sole();
        $this->assertTrue($order->reseller_own_courier);
        $this->assertSame('SF-77', $order->reseller_parcel_id);
        $this->assertSame(620.0, (float) $order->total_amount);
        $this->assertSame('TRX9', $order->resellerAdvancePayment->transaction_id);
    }

    public function test_the_portal_form_requires_the_parcel_id_for_an_own_courier_order(): void
    {
        $product = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $this->asPortal();

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'delivery_method' => 'own',
                'name' => 'Buyer One', 'phone' => '01722000001', 'address' => 'Mirpur',
                'items' => [['product_id' => $product->getKey(), 'quantity' => 1]],
            ])
            ->call('create')
            ->assertHasFormErrors(['parcel_id', 'payment_method_id', 'sender', 'transaction_id']);

        $this->assertFalse(Order::query()->exists());
    }

    public function test_own_courier_is_disabled_when_the_store_has_no_payment_method(): void
    {
        $this->bkash->update(['is_active' => false]);
        $this->asPortal();

        Livewire::test(CreateOrder::class)
            ->assertSee('Own courier orders are not available yet');
    }

    public function test_the_reseller_sees_the_payment_state_and_can_resend_a_rejected_payment(): void
    {
        $product = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $order = $this->place([['product_id' => $product->getKey(), 'quantity' => 1]]);
        $this->asPortal();

        Livewire::test(Orders::class)
            ->assertSee('PATHAO-123')
            ->assertSee('Waiting for verification')
            ->assertTableActionHidden('resendPayment', $order);

        app(ResellerOwnCourierService::class)->rejectPayment($order->resellerAdvancePayment);

        Livewire::test(Orders::class)
            ->assertSee('Rejected')
            ->callTableAction('resendPayment', $order, [
                'payment_method_id' => $this->bkash->getKey(), 'sender' => '01999999999', 'transaction_id' => 'TRX-NEW',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('TRX-NEW', $order->fresh()->resellerAdvancePayment->transaction_id);
    }

    public function test_staff_verify_the_payment_and_mark_the_parcel_packed_from_the_order_page(): void
    {
        $product = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $order = $this->place([['product_id' => $product->getKey(), 'quantity' => 1]]);
        $this->asStaff();

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSee('PATHAO-123')
            ->assertActionHidden('bookCourier')
            ->assertActionHidden('markParcelPacked')
            ->assertActionVisible('rejectResellerPayment')
            ->callAction('verifyResellerPayment')
            ->assertHasNoActionErrors()
            ->assertActionHidden('verifyResellerPayment')
            ->callAction('markParcelPacked')
            ->assertActionHidden('markParcelPacked');

        $order->refresh();
        $this->assertSame(310.0, (float) $order->paid_amount);
        $this->assertNotNull($order->reseller_parcel_packed_at);
    }

    public function test_staff_can_reject_the_payment_from_the_order_page(): void
    {
        $product = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $order = $this->place([['product_id' => $product->getKey(), 'quantity' => 1]]);
        $this->asStaff();

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('rejectResellerPayment')
            ->assertActionHidden('verifyResellerPayment');

        $this->assertSame(StorefrontPayment::STATUS_FAILED, $order->resellerAdvancePayment()->first()->status);
        $this->assertSame(0.0, (float) $order->fresh()->paid_amount);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $ownCourier
     */
    protected function place(array $lines, array $ownCourier = []): Order
    {
        return app(ResellerOrderPlacementService::class)->place($this->reseller, [
            'name' => 'Buyer', 'phone' => '01722000009', 'address' => 'Dhaka, Mirpur', 'note' => null,
        ], $lines, $ownCourier + [
            'courier_name' => 'Pathao', 'parcel_id' => 'PATHAO-123',
            'payment_method_id' => $this->bkash->getKey(), 'sender' => '01999999999', 'transaction_id' => 'TRX1',
        ]);
    }

    /** @return array<string, array<int, string>> */
    protected function errorsFor(callable $callback): array
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('Expected a validation error.');
    }

    protected function expectsValidation(callable $callback): void
    {
        $this->assertNotSame([], $this->errorsFor($callback));
    }

    protected function asPortal(): void
    {
        app(CompanyContext::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $this->actingAs($this->reseller, 'customer');
    }

    protected function asStaff(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($user)->withSession(['current_company_id' => $this->company->getKey()]);
    }

    protected function makeReseller(string $phone): Customer
    {
        $customer = new Customer([
            'name' => 'Reseller '.$phone, 'phone' => $phone, 'reseller_status' => 'approved',
            'reseller_slug' => 'r-'.Str::random(6), 'business_name' => 'Shop', 'is_active' => true,
        ]);
        $customer->company_id = $this->company->getKey();
        $customer->forceFill(['password' => Hash::make('secret-password')])->save();

        return $customer;
    }

    protected function pickProduct(string $name, ?float $tier, int $stock, float $price): Product
    {
        $product = Product::query()->create([
            'company_id' => $this->company->getKey(), 'name' => $name, 'sku' => 'OC-'.Str::random(6),
            'price' => $price, 'sale_price' => $price, 'cost_price' => 200, 'stock' => 0, 'unit' => 'pcs',
            'reorder_level' => 1, 'vat_rate' => 0,
            'tier_prices' => $tier === null ? [] : [['min_qty' => 1, 'price' => $tier]],
            'is_active' => true, 'status' => Product::STATUS_AVAILABLE,
        ])->fresh();
        StockMovement::query()->create(['product_id' => $product->getKey(), 'type' => 'opening', 'quantity' => $stock]);
        ResellerProduct::query()->create([
            'customer_id' => $this->reseller->getKey(), 'product_id' => $product->getKey(), 'is_active' => true,
        ]);

        return $product->fresh();
    }
}
