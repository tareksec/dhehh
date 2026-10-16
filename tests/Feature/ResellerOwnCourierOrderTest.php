<?php

namespace Tests\Feature;

use App\Filament\Reseller\Pages\CreateOrder;
use App\Filament\Reseller\Pages\EditOrder as EditOrderPage;
use App\Filament\Reseller\Pages\Orders;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Jobs\CheckExternalCourierFraudJob;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\ResellerCommission;
use App\Models\ResellerDepositEntry;
use App\Models\ResellerProduct;
use App\Models\StockMovement;
use App\Models\StorefrontPayment;
use App\Models\StorefrontPaymentMethod;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CompanySettingsService;
use App\Services\CourierService;
use App\Services\OrderStatusWorkflowService;
use App\Services\OrderSummaryCardService;
use App\Services\OrderWorkflowService;
use App\Services\ResellerOrderPlacementService;
use App\Services\ResellerOwnCourierService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
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

    public function test_the_packaging_charge_per_piece_is_added_to_the_advance(): void
    {
        $this->company->storefrontSetting->update(['reseller_packaging_cost_per_unit' => 5]);
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $plain = $this->pickProduct('Plain', tier: null, stock: 20, price: 200);
        $lines = [
            ['product_id' => $ring->getKey(), 'quantity' => 2],
            ['product_id' => $plain->getKey(), 'quantity' => 1],
        ];

        $quote = app(ResellerOrderPlacementService::class)->quote($this->reseller, $lines, 'Dhaka', ownCourier: true);
        $this->assertSame(15.0, $quote['packaging_fee']); // 3 pieces x 5
        $this->assertSame(835.0, $quote['total']);

        $order = $this->place($lines);
        $this->assertSame(15.0, (float) $order->packaging_fee);
        $this->assertSame(835.0, (float) $order->total_amount);
        $this->assertSame(835.0, (float) $order->resellerAdvancePayment->amount);

        // The store's own delivery never carries the charge.
        $storeOrder = app(ResellerOrderPlacementService::class)->place($this->reseller, [
            'name' => 'Buyer', 'phone' => '01722000009', 'address' => 'Dhaka, Mirpur', 'note' => null,
        ], [['product_id' => $ring->getKey(), 'quantity' => 2, 'price' => 400]]);
        $this->assertSame(0.0, (float) $storeOrder->packaging_fee);

        // Totals stay right when the order is recalculated (e.g. edited later).
        app(OrderWorkflowService::class)->sync($order->fresh());
        $this->assertSame(835.0, (float) $order->fresh()->total_amount);

        $this->asStaff();
        // The admin sees the charge; the buyer's invoice never shows it.
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])->assertSee('Packaging charge');
        $this->get(route('orders.print', ['order' => $order]))
            ->assertOk()
            ->assertDontSee('Packaging');

        // Saving the order from the admin edit form keeps the charge in the total.
        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->assertFormFieldIsVisible('packaging_fee')
            ->assertFormSet(['packaging_fee' => 15.0, 'total_amount' => 835.0])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(835.0, (float) $order->fresh()->total_amount);
        $this->assertSame(15.0, (float) $order->fresh()->packaging_fee);
    }

    public function test_the_buyer_only_ever_sees_the_resellers_retail_price(): void
    {
        $this->company->storefrontSetting->update(['reseller_packaging_cost_per_unit' => 5]);
        $this->company->update(['email' => 'office@parcel-co.example.test']);
        $this->reseller->update(['reseller_store_name' => 'Rima Fashion', 'reseller_logo' => 'resellers/rima-logo.png']);
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $order = $this->place([['product_id' => $ring->getKey(), 'quantity' => 2, 'price' => 650]]);

        // The store keeps its own price; the reseller's price is kept for the buyer.
        $item = $order->items()->sole();
        $this->assertSame(310.0, (float) $item->unit_price);
        $this->assertSame(650.0, (float) $item->reseller_retail_price);
        $this->assertSame(630.0, (float) $order->total_amount); // 2 x 310 + packaging 10
        $this->assertSame(1300.0, $order->fresh()->buyerTotal());

        $this->asStaff();
        app(ResellerOwnCourierService::class)->verifyPayment($order->resellerAdvancePayment);

        $html = $this->get(route('orders.print', ['order' => $order]))->assertOk()->getContent();
        $this->assertStringContainsString('650', $html);
        $this->assertStringContainsString('1,300', $html);
        $this->assertStringContainsString('PATHAO-123', $html);
        $this->assertStringNotContainsString('310', $html);
        $this->assertStringNotContainsString('630', $html);
        $this->assertStringNotContainsString('Paid', $html);
        $this->assertStringNotContainsString('Reseller portal order', $html); // internal note
        // The reseller's store name and logo head the invoice, never the company's
        // name or contact details; the customer owes the whole retail total.
        $this->assertStringContainsString('<h1>Rima Fashion</h1>', $html);
        $this->assertStringContainsString('rima-logo.png', $html);
        $this->assertStringNotContainsString('Parcel Co', $html);
        $this->assertStringNotContainsString('office@parcel-co', $html);
        $this->assertMatchesRegularExpression('/Due Amount<\/td>\s*<td class="num">[^<]*1,300/', $html);
        $this->assertMatchesRegularExpression('/Due Amount: [^<]*1,300/', $html);

        $card = app(OrderSummaryCardService::class)->build($order->fresh());
        $this->assertStringContainsString('1,300', $card['text']);
        $this->assertStringNotContainsString('310', $card['text']);
        $this->assertStringNotContainsString('630', $card['text']);
        $this->assertSame(['total', 'due'], array_column($card['totals'], 'key'));
        $this->assertSame('Rima Fashion', $card['company']);
        $this->assertNull($card['company_phone']);

        // The PDF invoice follows the same rules.
        $pdf = view('orders.pdf', [
            'order' => $order->fresh()->load(['company', 'customer', 'items.product', 'reseller']),
            'company' => app(CompanySettingsService::class)->profile($this->company),
            'invoice' => app(CompanySettingsService::class)->invoice($this->company),
            'productImages' => [],
            'resellerLogoPath' => null,
        ])->render();
        $this->assertStringContainsString('<h1>Rima Fashion</h1>', $pdf);
        $this->assertStringContainsString('BDT 650<', $pdf);
        $this->assertMatchesRegularExpression('/<td>Due<\/td><td class="right">BDT 1,300</', $pdf);
        $this->assertStringNotContainsString('310', $pdf);
        $this->assertStringNotContainsString('630', $pdf);
        $this->assertStringNotContainsString('Parcel Co', $pdf);
        $this->assertStringNotContainsString('<td>Paid</td>', $pdf);

        // The reseller sees their own sale and, separately, what they pay the store.
        $this->asPortal();
        Livewire::test(Orders::class)->assertSee('1,300')->assertDontSee('630.00');
    }

    public function test_the_resellers_price_cannot_be_below_the_store_price_on_an_own_courier_order(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $plain = $this->pickProduct('Plain', tier: null, stock: 20, price: 200);

        $errors = $this->errorsFor(fn () => $this->place([
            ['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 300],
            ['product_id' => $plain->getKey(), 'quantity' => 1, 'price' => 150],
        ]));

        $this->assertEqualsCanonicalizing(['data.items.0.price', 'data.items.1.price'], array_keys($errors));

        // A product without a wholesale rate can still be sold above its normal price.
        $order = $this->place([['product_id' => $plain->getKey(), 'quantity' => 1, 'price' => 260]]);
        $this->assertSame(200.0, (float) $order->items()->sole()->unit_price);
        $this->assertSame(260.0, (float) $order->items()->sole()->reseller_retail_price);
    }

    public function test_store_delivered_portal_orders_are_invoiced_in_the_resellers_name(): void
    {
        // Owner rule (2026-10-07): every order from the reseller portal carries the
        // reseller's store name and logo, also the cash-on-delivery ones.
        $this->company->update(['email' => 'office@parcel-co.example.test']);
        $this->reseller->update(['reseller_store_name' => 'Rima Fashion', 'reseller_logo' => 'resellers/rima-logo.png']);
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $order = app(ResellerOrderPlacementService::class)->place($this->reseller, [
            'name' => 'Buyer', 'phone' => '01722000009', 'address' => 'Dhaka, Mirpur', 'note' => null,
        ], [['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 500]]);

        // Prices stay as they are: the customer pays the reseller's price plus delivery.
        $this->assertFalse($order->showsResellerPricesToBuyer());
        $this->assertTrue($order->showsResellerBranding());
        $this->assertSame((float) $order->total_amount, $order->buyerTotal());

        $this->asStaff();
        $html = $this->get(route('orders.print', ['order' => $order]))->assertOk()->getContent();
        $this->assertStringContainsString('<h1>Rima Fashion</h1>', $html);
        $this->assertStringContainsString('rima-logo.png', $html);
        $this->assertStringNotContainsString('Parcel Co', $html);
        $this->assertStringNotContainsString('office@parcel-co', $html);
        $this->assertStringNotContainsString('Reseller portal order', $html); // internal note
        $this->assertStringContainsString('500', $html);
        $this->assertStringContainsString('Due Amount', $html);

        // The Orders list's "Print invoices" bulk action too.
        $this->get(route('orders.print.bulk', ['orders' => (string) $order->getKey()]))
            ->assertOk()->assertSee('<h1>Rima Fashion</h1>', false)->assertDontSee('Parcel Co');

        $card = app(OrderSummaryCardService::class)->build($order->fresh());
        $this->assertSame('Rima Fashion', $card['company']);
        $this->assertNull($card['company_phone']);

        $pdf = view('orders.pdf', [
            'order' => $order->fresh()->load(['company', 'customer', 'items.product', 'reseller']),
            'company' => app(CompanySettingsService::class)->profile($this->company),
            'invoice' => app(CompanySettingsService::class)->invoice($this->company),
            'productImages' => [],
            'resellerLogoPath' => null,
        ])->render();
        $this->assertStringContainsString('<h1>Rima Fashion</h1>', $pdf);
        $this->assertStringNotContainsString('Parcel Co', $pdf);
    }

    public function test_orders_without_a_reseller_keep_the_company_invoice(): void
    {
        $this->reseller->update(['reseller_store_name' => 'Rima Fashion']);
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $order = app(ResellerOrderPlacementService::class)->place($this->reseller, [
            'name' => 'Buyer', 'phone' => '01722000009', 'address' => 'Dhaka, Mirpur', 'note' => null,
        ], [['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 500]]);
        $order->forceFill(['reseller_customer_id' => null])->save();

        $this->assertFalse($order->fresh()->showsResellerBranding());

        $this->asStaff();
        $this->get(route('orders.print', ['order' => $order]))->assertOk()->assertDontSee('<h1>Rima Fashion</h1>', false);
    }

    public function test_confirming_a_reseller_draft_with_short_stock_names_the_product_instead_of_doing_nothing(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 2, price: 450);
        $order = app(ResellerOrderPlacementService::class)->place($this->reseller, [
            'name' => 'Buyer', 'phone' => '01722000009', 'address' => 'Dhaka, Mirpur', 'note' => null,
        ], [['product_id' => $ring->getKey(), 'quantity' => 2, 'price' => 500]]);
        // Stock sold elsewhere before staff confirm the portal draft.
        StockMovement::query()->create(['product_id' => $ring->getKey(), 'type' => 'adjustment', 'quantity' => -2, 'reason' => 'Sold in shop']);
        $this->asStaff();

        $page = Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('changeWorkflowStatus', ['stage' => 'confirmed']);
        $this->assertStringContainsString(
            'Not enough stock: Ring (ordered 2, in stock 0)',
            collect($page->errors()->toArray())->flatten()->implode(' '),
        );
        $this->assertSame('draft', $order->fresh()->status);

        Livewire::test(ListOrders::class)
            ->callTableBulkAction('changeWorkflowStatus', [$order], ['stage' => 'confirmed'])
            ->assertNotified(Notification::make()
                ->title('0 order(s) updated')
                ->body('1 order(s) were skipped: '.$order->order_number.': Not enough stock: Ring (ordered 2, in stock 0). Add stock or change the order first.')
                ->danger());
        $this->assertSame('draft', $order->fresh()->status);
    }

    public function test_packing_a_draft_own_courier_order_confirms_it_so_the_stock_is_taken(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 5, price: 450);
        $order = $this->place([['product_id' => $ring->getKey(), 'quantity' => 2]]);
        $service = app(ResellerOwnCourierService::class);
        $service->verifyPayment($order->resellerAdvancePayment);

        $service->markPacked($order->fresh());

        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->reseller_parcel_packed_at);
        $this->assertSame(3, (int) $ring->fresh()->stock);
    }

    public function test_packing_with_short_stock_says_why_and_marks_nothing(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 2, price: 450);
        $order = $this->place([['product_id' => $ring->getKey(), 'quantity' => 2]]);
        app(ResellerOwnCourierService::class)->verifyPayment($order->resellerAdvancePayment);
        StockMovement::query()->create(['product_id' => $ring->getKey(), 'type' => 'adjustment', 'quantity' => -2, 'reason' => 'Sold in shop']);
        $this->asStaff();

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('markParcelPacked')
            ->assertNotified('Could not mark the parcel packed');

        $this->assertSame('draft', $order->fresh()->status);
        $this->assertNull($order->fresh()->reseller_parcel_packed_at);
    }

    public function test_a_courier_delivered_callback_makes_the_reseller_commission_payable(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 5, price: 450);
        $order = app(ResellerOrderPlacementService::class)->place($this->reseller, [
            'name' => 'Buyer', 'phone' => '01722000009', 'address' => 'Dhaka, Mirpur', 'note' => null,
        ], [['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 500]]);
        $this->asStaff();
        app(OrderStatusWorkflowService::class)->transition($order, 'confirmed');
        $booking = app(CourierService::class)->createManualBooking($order->fresh(), []);

        app(CourierService::class)->updateStatus($booking, CourierBooking::STATUS_DELIVERED, 'Updated by verified courier webhook.');

        $commission = ResellerCommission::query()->where('order_id', $order->getKey())->sole();
        $this->assertSame(ResellerCommission::STATUS_PAYABLE, $commission->status);
        $this->assertSame(190.0, (float) $commission->amount);
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
            ->assertDontSee('My profit')
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

    public function test_staff_cannot_add_or_change_items_on_an_own_courier_order(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $order = $this->place([['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 650]]);
        $this->asStaff();

        $page = Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->assertFormFieldIsDisabled('items')
            ->assertSee('only the reseller can add or change items');
        $items = $page->get('data.items');
        $key = array_key_first($items);
        $items[$key]['quantity'] = 5;
        $items['extra'] = ['product_id' => $ring->getKey(), 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100];
        // Even a forged request does not reach the locked items.
        $page->set('data.items', $items)->call('save');

        $this->assertSame(1, (int) $order->items()->sole()->quantity);
        $this->assertSame(650.0, (float) $order->items()->sole()->reseller_retail_price);

        // Store-delivered orders keep their editable items.
        $storeOrder = app(ResellerOrderPlacementService::class)->place($this->reseller, [
            'name' => 'Buyer', 'phone' => '01722000009', 'address' => 'Dhaka, Mirpur', 'note' => null,
        ], [['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 500]]);
        Livewire::test(EditOrder::class, ['record' => $storeOrder->getKey()])->assertFormFieldIsEnabled('items');
    }

    public function test_the_reseller_changes_prices_courier_and_customer_without_paying_more(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $order = $this->place([['product_id' => $ring->getKey(), 'quantity' => 2, 'price' => 650]]);
        $item = $order->items()->sole();

        $this->editOrder($order, [['item_id' => $item->getKey(), 'quantity' => 2, 'price' => 700]], buyer: [
            'name' => 'New Buyer', 'phone' => '01733000001', 'address' => 'Chattogram, Agrabad',
        ], courier: ['courier_name' => 'RedX', 'parcel_id' => 'REDX-9']);

        $order->refresh();
        $this->assertSame(620.0, (float) $order->total_amount);
        $this->assertSame(1400.0, $order->buyerTotal());
        $this->assertSame('RedX', $order->reseller_courier_name);
        $this->assertSame('REDX-9', $order->reseller_parcel_id);
        $this->assertSame('New Buyer', $order->customer->name);
        $this->assertSame('01733000001', $order->customer->phone);
        $this->assertStringContainsString('Delivery address: Chattogram, Agrabad', $order->note);
        // Nothing more to send: still the one payment.
        $this->assertSame(1, $order->resellerAdvancePayments()->count());
        $this->assertSame('waiting', app(ResellerOwnCourierService::class)->paymentState($order));
    }

    public function test_raising_the_total_asks_only_for_the_extra_and_packing_waits_for_all_of_it(): void
    {
        $this->company->storefrontSetting->update(['reseller_packaging_cost_per_unit' => 5]);
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $plain = $this->pickProduct('Plain', tier: null, stock: 20, price: 200);
        $order = $this->place([['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 650]]);
        $item = $order->items()->sole();
        $this->assertSame(315.0, (float) $order->total_amount); // 310 + packaging 5
        $lines = [
            ['item_id' => $item->getKey(), 'quantity' => 2, 'price' => 650],
            ['product_id' => $plain->getKey(), 'quantity' => 1, 'price' => 260],
        ];

        $quote = app(ResellerOrderPlacementService::class)->quoteEdit($this->reseller, $order, $lines);
        // 2 x 310 + 200, packaging 3 x 5; 315 was already sent.
        $this->assertSame(835.0, $quote['total']);
        $this->assertSame(520.0, $quote['extra']);
        $this->assertSame(1560.0, $quote['customer_total']);

        // The extra needs payment details.
        $errors = $this->errorsFor(fn () => $this->editOrder($order, $lines, payment: []));
        $this->assertEqualsCanonicalizing(['data.payment_method_id', 'data.sender', 'data.transaction_id'], array_keys($errors));
        $this->assertSame(1, $order->items()->count());

        $this->editOrder($order, $lines, payment: ['payment_method_id' => $this->bkash->getKey(), 'sender' => '01999999999', 'transaction_id' => 'TRX-EXTRA']);

        $order->refresh();
        $this->assertSame(835.0, (float) $order->total_amount);
        $this->assertSame(15.0, (float) $order->packaging_fee);
        $this->assertSame(2, (int) $order->items()->where('product_id', $ring->getKey())->value('quantity'));
        $this->assertSame(260.0, (float) $order->items()->where('product_id', $plain->getKey())->value('reseller_retail_price'));
        $this->assertSame(200.0, (float) $order->items()->where('product_id', $plain->getKey())->value('unit_price'));
        $this->assertSame([315.0, 520.0], $order->resellerAdvancePayments->map(fn ($payment): float => (float) $payment->amount)->all());

        // Staff verify the first payment: packing still waits for the extra.
        $service = app(ResellerOwnCourierService::class);
        $this->asStaff();
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('verifyResellerPayment')
            ->assertActionVisible('verifyResellerPayment')
            ->assertActionHidden('markParcelPacked');
        $this->assertSame('waiting', $service->paymentState($order->fresh()));
        $this->assertFalse($service->canEdit($order->fresh()));

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('verifyResellerPayment')
            ->assertActionHidden('verifyResellerPayment')
            ->callAction('markParcelPacked')
            ->assertHasNoActionErrors();

        $order->refresh();
        $this->assertSame(835.0, (float) $order->paid_amount);
        $this->assertSame('verified', $service->paymentState($order));
        $this->assertNotNull($order->reseller_parcel_packed_at);
    }

    public function test_a_rejected_extra_is_sent_again_for_the_extra_only(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $order = $this->place([['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 650]]);
        $item = $order->items()->sole();
        $this->editOrder($order, [['item_id' => $item->getKey(), 'quantity' => 3, 'price' => 650]],
            payment: ['payment_method_id' => $this->bkash->getKey(), 'sender' => '01999999999', 'transaction_id' => 'TRX-EXTRA']);
        $service = app(ResellerOwnCourierService::class);

        $service->rejectPayment($order->resellerAdvancePayments()->get()->last());
        $this->assertSame('rejected', $service->paymentState($order->fresh()));
        $this->assertSame(620.0, $service->outstandingAmount($order->fresh()));

        $service->submitPayment($order->fresh(), ['payment_method_id' => $this->bkash->getKey(), 'sender' => '01999999999', 'transaction_id' => 'TRX-AGAIN']);
        $this->assertSame(620.0, (float) $order->resellerAdvancePayments()->get()->last()->amount);
        $this->assertSame(0.0, $service->outstandingAmount($order->fresh()));
    }

    public function test_nothing_on_the_order_can_be_removed_lowered_or_priced_below_the_store_price(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $plain = $this->pickProduct('Plain', tier: null, stock: 3, price: 200);
        $order = $this->place([['product_id' => $ring->getKey(), 'quantity' => 2, 'price' => 650]]);
        $item = $order->items()->sole();

        $this->assertArrayHasKey('data.items.0.quantity', $this->errorsFor(fn () => $this->editOrder($order, [
            ['item_id' => $item->getKey(), 'quantity' => 1, 'price' => 650],
        ])));
        $this->assertArrayHasKey('data.items.0.price', $this->errorsFor(fn () => $this->editOrder($order, [
            ['item_id' => $item->getKey(), 'quantity' => 2, 'price' => 300],
        ])));
        $this->assertArrayHasKey('data.items', $this->errorsFor(fn () => $this->editOrder($order, [
            ['product_id' => $plain->getKey(), 'quantity' => 1, 'price' => 260],
        ])));
        $errors = $this->errorsFor(fn () => $this->editOrder($order, [
            ['item_id' => $item->getKey(), 'quantity' => 2, 'price' => 650],
            ['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 650],
            ['product_id' => $plain->getKey(), 'quantity' => 4, 'price' => 260],
        ]));
        $this->assertSame('This product is already on the order.', $errors['data.items.1.product_id'][0]);
        $this->assertSame('Plain does not have enough stock.', $errors['data.items.2.quantity'][0]);

        $this->assertSame(2, (int) $item->fresh()->quantity);
        $this->assertSame(650.0, (float) $item->fresh()->reseller_retail_price);
    }

    public function test_the_order_cannot_be_changed_once_the_store_verified_the_payment(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $order = $this->place([['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 650]]);
        $item = $order->items()->sole();
        $service = app(ResellerOwnCourierService::class);
        $this->assertTrue($service->canEdit($order->fresh()));

        $service->verifyPayment($order->resellerAdvancePayment);

        $this->assertFalse($service->canEdit($order->fresh()));
        $this->assertArrayHasKey('data.items', $this->errorsFor(fn () => $this->editOrder($order, [
            ['item_id' => $item->getKey(), 'quantity' => 1, 'price' => 700],
        ])));
        $this->assertSame(650.0, (float) $item->fresh()->reseller_retail_price);

        $this->asPortal();
        Livewire::test(Orders::class)->assertTableActionHidden('edit', $order);
        Livewire::test(EditOrderPage::class, ['record' => $order->getKey()])->assertRedirect(Orders::getUrl());
    }

    public function test_the_reseller_changes_the_order_from_the_portal(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $plain = $this->pickProduct('Plain', tier: null, stock: 20, price: 200);
        $order = $this->place([['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 650]]);
        $other = $this->makeReseller('01711000002');
        $this->asPortal();

        $this->get('https://reseller.parcel-co.example.test/portal/orders/'.$order->getKey().'/edit')
            ->assertOk()
            ->assertSee('Change order '.$order->order_number)
            ->assertSee('PATHAO-123');

        Livewire::test(Orders::class)
            ->assertTableActionVisible('edit', $order)
            ->assertTableActionHasUrl('edit', EditOrderPage::getUrl(['record' => $order->getKey()]), $order);

        $page = Livewire::test(EditOrderPage::class, ['record' => $order->getKey()])
            ->assertFormSet(['parcel_id' => 'PATHAO-123', 'name' => 'Buyer'])
            ->assertFormFieldIsHidden('transaction_id');
        $items = $page->get('data.items');
        $key = array_key_first($items);
        $items[$key]['quantity'] = 2;
        $items['new'] = ['item_id' => null, 'product_id' => $plain->getKey(), 'quantity' => 1, 'price' => 250];
        $page->set('data.items', $items)
            ->assertFormFieldIsVisible('transaction_id')
            ->assertSee('Send now')
            ->fillForm(['payment_method_id' => $this->bkash->getKey(), 'sender' => '01999999999', 'transaction_id' => 'TRX-PORTAL'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(Orders::getUrl());

        $order->refresh();
        $this->assertSame(820.0, (float) $order->total_amount); // 2 x 310 + 200
        $this->assertSame(510.0, (float) $order->resellerAdvancePayments()->get()->last()->amount);
        $this->assertSame('TRX-PORTAL', $order->resellerAdvancePayments()->get()->last()->transaction_id);

        // Another reseller cannot open it.
        $this->actingAs($other, 'customer');
        Livewire::test(EditOrderPage::class, ['record' => $order->getKey()])->assertNotFound();
    }

    public function test_an_overpayment_goes_to_the_deposit_and_pays_the_next_order(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $service = app(ResellerOwnCourierService::class);

        // Owed 310; the reseller says they sent 1000.
        $first = $this->place([['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 650]], ['amount' => 1000]);
        $this->assertSame(1000.0, (float) $first->resellerAdvancePayment->amount);
        $this->assertSame(0.0, $service->depositBalance($this->reseller)); // nothing until verified

        // Staff find 900 arrived: 590 above the total goes to the deposit.
        $service->verifyPayment($first->resellerAdvancePayment, null, 900);
        $this->assertSame(590.0, $service->depositBalance($this->reseller));
        $this->assertSame(590.0, $service->creditedFrom($first));
        $this->assertSame(900.0, (float) $first->fresh()->paid_amount);
        $this->assertSame('verified', $service->paymentState($first->fresh()));

        // The next own-courier order (2 x 310 = 620) takes 590 from the deposit; 30 is left to send.
        $quote = app(ResellerOrderPlacementService::class)->quote($this->reseller, [['product_id' => $ring->getKey(), 'quantity' => 2]], 'Dhaka', ownCourier: true);
        $this->assertSame(590.0, $quote['deposit_used']);
        $this->assertSame(30.0, $quote['to_send']);

        $second = $this->place([['product_id' => $ring->getKey(), 'quantity' => 2, 'price' => 650]], ['parcel_id' => 'PATHAO-456', 'transaction_id' => 'TRX2']);
        $this->assertSame(0.0, $service->depositBalance($this->reseller));
        $this->assertSame([590.0, 30.0], $second->resellerAdvancePayments->map(fn ($payment): float => (float) $payment->amount)->all());
        $this->assertSame(ResellerOwnCourierService::GATEWAY_DEPOSIT, $second->resellerAdvancePayments->first()->gateway);
        $this->assertSame(590.0, (float) $second->paid_amount);
        // The deposit part needs no checking and does not lock the order for changes.
        $this->assertSame('waiting', $service->paymentState($second));
        $this->assertTrue($service->canEdit($second));

        // A third order fully covered by a deposit needs no payment details at all.
        $service->verifyPayment($second->resellerAdvancePayments->last(), null, 1030); // 1000 above the rest
        $this->assertSame(1000.0, $service->depositBalance($this->reseller));
        $third = app(ResellerOrderPlacementService::class)->place($this->reseller, [
            'name' => 'Buyer', 'phone' => '01722000009', 'address' => 'Dhaka, Mirpur', 'note' => null,
        ], [['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 650]], ['courier_name' => null, 'parcel_id' => 'PATHAO-789']);
        $this->assertSame('verified', $service->paymentState($third));
        $this->assertSame(690.0, $service->depositBalance($this->reseller));
        $this->assertTrue($service->canMarkPacked($third));
    }

    public function test_a_cancelled_order_gives_back_what_it_took_from_the_deposit(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $service = app(ResellerOwnCourierService::class);
        $this->creditDeposit(100);

        // Owed 310 (1 x 310): 100 comes from the deposit, 210 is sent to the store.
        $order = $this->place([['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 650]]);
        $this->assertSame(0.0, $service->depositBalance($this->reseller));

        app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_CANCELLED, 'Customer cancelled', 'test');

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        // Only the deposit part returns; the 210 sent to the store is not refunded here.
        $this->assertSame(100.0, $service->depositBalance($this->reseller));
        $this->assertSame(100.0, $service->refundedTo($order->fresh()));
        $entry = ResellerDepositEntry::query()->where('type', ResellerDepositEntry::TYPE_REFUND)->sole();
        $this->assertSame($order->getKey(), $entry->order_id);
        $this->assertSame($this->reseller->getKey(), $entry->customer_id);
        $this->assertSame(100.0, (float) $entry->amount);
    }

    public function test_the_deposit_is_returned_only_once_however_often_the_order_is_saved(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $service = app(ResellerOwnCourierService::class);
        $this->creditDeposit(500);
        $order = $this->place([['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 650]]);
        $this->assertSame(190.0, $service->depositBalance($this->reseller));

        app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_CANCELLED, 'Customer cancelled', 'test');
        $order->fresh()->update(['note' => 'Customer called']);
        $this->assertSame(0.0, $service->refundDeposit($order->fresh()));
        $order->fresh()->touch();

        $this->assertSame(500.0, $service->depositBalance($this->reseller));
        $this->assertSame(1, ResellerDepositEntry::query()->where('type', ResellerDepositEntry::TYPE_REFUND)->count());
        $this->assertSame(310.0, $service->refundedTo($order->fresh()));
    }

    public function test_an_order_that_is_not_cancelled_or_took_nothing_from_the_deposit_returns_nothing(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $service = app(ResellerOwnCourierService::class);

        // Nothing was taken from the deposit: cancelling adds no entry.
        $plain = $this->place([['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 650]]);
        app(OrderStatusWorkflowService::class)->transition($plain, Order::STAGE_CANCELLED, 'Customer cancelled', 'test');
        $this->assertSame(0, ResellerDepositEntry::query()->where('type', ResellerDepositEntry::TYPE_REFUND)->count());

        // The deposit pays a second order; moving it forward (not cancelling) gives nothing back.
        $this->creditDeposit(200);
        $paid = $this->place([['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 650]], ['parcel_id' => 'PATHAO-777', 'transaction_id' => 'TRX9']);
        $this->assertSame(0.0, $service->depositBalance($this->reseller));
        app(OrderStatusWorkflowService::class)->transition($paid, Order::STAGE_CONFIRMED, null, 'test');
        $this->assertSame(0.0, $service->depositBalance($this->reseller));
        $this->assertSame(0.0, $service->refundDeposit($paid->fresh())); // not cancelled yet
        $this->assertSame(0, ResellerDepositEntry::query()->where('type', ResellerDepositEntry::TYPE_REFUND)->count());
    }

    public function test_a_cancelled_order_never_touches_another_resellers_deposit(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $service = app(ResellerOwnCourierService::class);
        $other = $this->makeReseller('01711000002');
        ResellerDepositEntry::query()->create([
            'company_id' => $this->company->getKey(), 'customer_id' => $other->getKey(),
            'type' => ResellerDepositEntry::TYPE_OVERPAYMENT, 'amount' => 700,
        ]);
        $this->creditDeposit(100);
        $order = $this->place([['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 650]]);

        app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_CANCELLED, 'Customer cancelled', 'test');

        $this->assertSame(100.0, $service->depositBalance($this->reseller));
        $this->assertSame(700.0, $service->depositBalance($other));
    }

    public function test_staff_see_how_much_a_cancelled_order_returned_to_the_deposit(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $this->creditDeposit(100);
        $order = $this->place([['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 650]]);
        app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_CANCELLED, 'Customer cancelled', 'test');
        $this->asStaff();

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSee('Returned to the reseller\'s deposit');
    }

    public function test_a_reseller_can_pay_the_advance_into_a_reseller_only_method(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $own = StorefrontPaymentMethod::query()->create([
            'company_id' => $this->company->getKey(), 'type' => StorefrontPaymentMethod::TYPE_MANUAL,
            'name' => 'bKash (Reseller)', 'account_number' => '01911111111', 'is_active' => true, 'reseller_only' => true,
        ]);
        $this->assertTrue(app(ResellerOwnCourierService::class)->paymentMethods($this->company)->contains('id', $own->getKey()));

        $order = $this->place([['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 650]], ['payment_method_id' => $own->getKey()]);

        $payment = $order->resellerAdvancePayment;
        $this->assertSame($own->getKey(), $payment->storefront_payment_method_id);

        app(ResellerOwnCourierService::class)->verifyPayment($payment);
        $this->assertStringContainsString('bKash (Reseller)', (string) OrderPayment::query()->where('order_id', $order->getKey())->value('note'));

        $this->asPortal();
        Livewire::test(CreateOrder::class)
            ->fillForm(['delivery_method' => 'own'])
            ->assertSee('bKash (Reseller)');
    }

    public function test_a_store_method_the_admin_did_not_tick_is_refused_for_the_advance(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $nagad = StorefrontPaymentMethod::query()->create([
            'company_id' => $this->company->getKey(), 'type' => StorefrontPaymentMethod::TYPE_MANUAL,
            'name' => 'Nagad', 'account_number' => '01822222222', 'is_active' => true,
        ]);
        $this->company->storefrontSetting->forceFill(['reseller_payment_method_ids' => [$nagad->getKey()]])->save();
        $service = app(ResellerOwnCourierService::class);

        $this->assertSame([$nagad->getKey()], $service->paymentMethods($this->company->fresh())->modelKeys());

        // bKash exists, is active, and is on the store's list -- but was not ticked.
        $errors = $this->errorsFor(fn () => $this->place([['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 650]]));
        $this->assertSame(['data.payment_method_id'], array_keys($errors));
        $this->assertSame(0, Order::query()->count());

        $order = $this->place([['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 650]], ['payment_method_id' => $nagad->getKey()]);
        $this->assertSame($nagad->getKey(), $order->resellerAdvancePayment->storefront_payment_method_id);
    }

    public function test_the_amount_sent_cannot_be_less_than_what_is_owed(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);

        $errors = $this->errorsFor(fn () => $this->place([['product_id' => $ring->getKey(), 'quantity' => 1]], ['amount' => 100]));

        $this->assertSame(['data.amount'], array_keys($errors));
        $this->assertSame(0, Order::query()->count());
    }

    public function test_staff_set_the_amount_received_and_less_than_owed_asks_for_the_rest(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        $order = $this->place([['product_id' => $ring->getKey(), 'quantity' => 1]]);
        $this->asStaff();

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->mountAction('verifyResellerPayment')
            ->assertActionDataSet(['amount_received' => 310.0])
            ->setActionData(['amount_received' => 200])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertActionHidden('markParcelPacked');

        $service = app(ResellerOwnCourierService::class);
        $this->assertSame('short', $service->paymentState($order->fresh()));
        $this->assertSame(110.0, $service->outstandingAmount($order->fresh()));

        $this->asPortal();
        Livewire::test(Orders::class)
            ->assertSee('Part received')
            ->callTableAction('resendPayment', $order, [
                'payment_method_id' => $this->bkash->getKey(), 'sender' => '01999999999', 'transaction_id' => 'TRX-REST',
            ])
            ->assertHasNoTableActionErrors();
        $this->assertSame(110.0, (float) $order->resellerAdvancePayments()->get()->last()->amount);
    }

    public function test_the_portal_shows_the_deposit_in_the_top_bar_and_uses_it_on_create_order(): void
    {
        $ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        ResellerDepositEntry::query()->create([
            'company_id' => $this->company->getKey(), 'customer_id' => $this->reseller->getKey(),
            'type' => ResellerDepositEntry::TYPE_OVERPAYMENT, 'amount' => 500,
        ]);
        $this->asPortal();

        $this->get('https://reseller.parcel-co.example.test/portal/orders')
            ->assertOk()
            ->assertSee('data-reseller-deposit', false)
            ->assertSee('Deposit');

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'name' => 'Buyer', 'phone' => '01722000009', 'address' => 'Dhaka, Mirpur',
                'delivery_method' => 'own', 'parcel_id' => 'PATHAO-123',
                'items' => [['product_id' => $ring->getKey(), 'quantity' => 1, 'price' => 650]],
            ])
            ->assertSee('From your deposit')
            ->assertFormFieldIsHidden('transaction_id')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(190.0, app(ResellerOwnCourierService::class)->depositBalance($this->reseller));
        $this->assertSame('verified', app(ResellerOwnCourierService::class)->paymentState(Order::query()->sole()));
    }

    public function test_a_pending_or_rejected_reseller_has_no_deposit_badge(): void
    {
        $this->reseller->update(['reseller_status' => 'pending']);
        $this->asPortal();

        $this->followingRedirects()
            ->get('https://reseller.parcel-co.example.test/portal')
            ->assertOk()
            ->assertSee('Your application is being reviewed')
            ->assertDontSee('data-reseller-deposit', false);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $payment
     */
    protected function editOrder(Order $order, array $lines, array $payment = [], ?array $buyer = null, ?array $courier = null): Order
    {
        return app(ResellerOrderPlacementService::class)->updateOwnCourierOrder(
            $this->reseller,
            $order,
            $buyer ?? ['name' => 'Buyer', 'phone' => '01722000009', 'address' => 'Dhaka, Mirpur'],
            $lines,
            $courier ?? ['courier_name' => 'Pathao', 'parcel_id' => 'PATHAO-123'],
            $payment,
        );
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

    protected function creditDeposit(float $amount): void
    {
        ResellerDepositEntry::query()->create([
            'company_id' => $this->company->getKey(), 'customer_id' => $this->reseller->getKey(),
            'type' => ResellerDepositEntry::TYPE_OVERPAYMENT, 'amount' => $amount,
        ]);
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
