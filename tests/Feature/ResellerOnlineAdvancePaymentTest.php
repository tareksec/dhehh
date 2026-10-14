<?php

namespace Tests\Feature;

use App\Filament\Reseller\Pages\CreateOrder;
use App\Filament\Reseller\Pages\Orders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\ResellerDepositEntry;
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
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner decision (2026-10-14): whichever online gateway the store has switched
 * on (ZiniPay / PayStation) is offered to a reseller as a way to pay the
 * advance of an own-courier order, next to the send-money numbers. The
 * gateway -- not staff -- confirms it, and the payment is entered once.
 */
class ResellerOnlineAdvancePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const PORTAL = 'reseller.parcel-co.example.test';

    protected Company $company;

    protected Customer $reseller;

    protected StorefrontPaymentMethod $bkash;

    protected Product $ring;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->company = Company::query()->create([
            'name' => 'Parcel Co', 'slug' => 'parcel-co', 'domain' => 'parcel-co.example.test',
            'domain_verified' => true, 'invoice_prefix' => 'PCL', 'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka', 'is_active' => true, 'reseller_module_enabled' => true,
        ])->fresh();
        StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(), 'theme_color' => '#0F766E', 'is_published' => true,
            'new_customer_delivery_advance_enabled' => false, 'reseller_cost_per_unit' => 10,
            'online_payment_enabled' => true, 'online_payment_gateway' => 'zinipay',
            'payment_credentials' => ['zinipay_api_key' => 'test_key'],
        ]);
        app(CompanyContext::class)->set($this->company);
        $this->bkash = StorefrontPaymentMethod::query()->create([
            'company_id' => $this->company->getKey(), 'type' => StorefrontPaymentMethod::TYPE_MANUAL,
            'name' => 'bKash', 'account_number' => '01800000000', 'is_active' => true,
        ]);
        $this->reseller = $this->makeReseller('01711000001');
        // A tier of 300 + the company's 10 per unit: the reseller pays 310 a piece.
        $this->ring = $this->pickProduct('Ring', tier: 300, stock: 20, price: 450);
        // The links given to the gateway are built on the portal host, as in a real request.
        URL::forceRootUrl('http://'.self::PORTAL);
        Queue::fake();
    }

    public function test_the_active_gateway_is_offered_next_to_the_send_money_numbers(): void
    {
        $options = app(ResellerOwnCourierService::class)->paymentOptions($this->company);

        $this->assertSame(['bKash — 01800000000', 'Pay online (ZiniPay)'], array_values($options));
        $this->assertArrayHasKey(ResellerOwnCourierService::ONLINE, $options);

        // PayStation, when that is the active one.
        $this->setting()->update(['online_payment_gateway' => 'paystation', 'payment_credentials' => ['paystation_merchant_id' => 'm', 'paystation_password' => 'p']]);
        $this->assertSame('Pay online (PayStation)', app(ResellerOwnCourierService::class)->paymentOptions($this->company)[ResellerOwnCourierService::ONLINE]);
    }

    public function test_no_gateway_is_offered_when_it_is_off_or_not_set_up(): void
    {
        $this->setting()->update(['online_payment_enabled' => false]);
        $this->assertArrayNotHasKey(ResellerOwnCourierService::ONLINE, app(ResellerOwnCourierService::class)->paymentOptions($this->company));

        $this->setting()->update(['online_payment_enabled' => true, 'payment_credentials' => []]);
        $this->assertArrayNotHasKey(ResellerOwnCourierService::ONLINE, app(ResellerOwnCourierService::class)->paymentOptions($this->company));

        // Choosing it anyway is refused, and nothing is created.
        $errors = $this->errorsFor(fn () => $this->placeOnline());
        $this->assertSame(['data.payment_method_id'], array_keys($errors));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, StorefrontPayment::query()->count());
    }

    public function test_an_order_paid_online_starts_a_gateway_payment_and_waits_for_it(): void
    {
        $this->fakeGatewayCreate();

        $order = $this->placeOnline(quantity: 2);

        $payment = $order->resellerAdvancePayment;
        $this->assertSame('zinipay', $payment->gateway);
        $this->assertSame(StorefrontPayment::PURPOSE_RESELLER_ADVANCE, $payment->purpose);
        $this->assertSame(StorefrontPayment::STATUS_PENDING, $payment->status);
        $this->assertSame(620.0, (float) $payment->amount); // 2 x 310
        $this->assertSame('INV-777', $payment->invoice_id);
        $this->assertSame('https://pay.zinipay.com/checkout/INV-777', $payment->payload['payment_url']);
        $this->assertNull($payment->storefront_payment_method_id);
        $this->assertNull($payment->transaction_id);

        // Nothing is paid until the gateway says so; the reseller sees it is waiting for them.
        $this->assertSame(0.0, (float) $order->fresh()->paid_amount);
        $this->assertSame('paying', app(ResellerOwnCourierService::class)->paymentState($order));
        $this->assertSame($payment->getKey(), app(ResellerOwnCourierService::class)->pendingOnlinePayment($order)->getKey());

        Http::assertSent(function ($request) use ($payment): bool {
            return str_contains($request->url(), '/v1/payment/create')
                && (float) $request['amount'] === 620.0
                // Back to the portal, signed; the webhook goes to the store's own domain.
                && str_starts_with((string) $request['redirect_url'], 'http://'.self::PORTAL.'/portal/orders/payment/'.$payment->getKey().'/return')
                && str_contains((string) $request['redirect_url'], 'signature=')
                && (string) $request['webhook_url'] === 'https://parcel-co.example.test/webhooks/zinipay/'.$payment->getKey();
        });
    }

    public function test_the_gateways_webhook_confirms_the_payment_and_enters_it_once(): void
    {
        $this->fakeGatewayCreate();
        $order = $this->placeOnline(quantity: 2);
        $payment = $order->resellerAdvancePayment;
        $this->fakeGatewayVerify(620);

        // The webhook arrives twice (gateways retry): the money is entered once.
        $this->postJson('http://parcel-co.example.test/webhooks/zinipay/'.$payment->getKey(), ['invoice_id' => 'INV-777'])->assertOk();
        $this->postJson('http://parcel-co.example.test/webhooks/zinipay/'.$payment->getKey(), ['invoice_id' => 'INV-777'])->assertOk();

        $payment->refresh();
        $this->assertSame(StorefrontPayment::STATUS_COMPLETED, $payment->status);
        $this->assertSame('ONLINE-TRX-1', $payment->transaction_id);

        $entries = OrderPayment::withoutGlobalScopes()->where('order_id', $order->getKey())->get();
        $this->assertCount(1, $entries);
        $this->assertSame(620.0, (float) $entries->first()->amount);
        $this->assertSame(OrderPayment::TYPE_ADVANCE, $entries->first()->type);
        $this->assertSame(620.0, (float) $order->fresh()->paid_amount);

        $order = $order->fresh(['resellerAdvancePayments']);
        $service = app(ResellerOwnCourierService::class);
        $this->assertSame('verified', $service->paymentState($order));
        $this->assertSame(0.0, $service->outstandingAmount($order));
        // Verified by the gateway, so staff can pack the parcel, and the reseller can no longer change the order.
        $this->assertTrue($service->canMarkPacked($order));
        $this->assertFalse($service->canEdit($order));
    }

    public function test_the_signed_return_verifies_the_payment_and_the_webhook_afterwards_changes_nothing(): void
    {
        $this->fakeGatewayCreate();
        $order = $this->placeOnline(quantity: 1);
        $payment = $order->resellerAdvancePayment;
        $returnUrl = $this->capturedReturnUrl();
        $this->fakeGatewayVerify(310);

        $this->get($returnUrl.'&invoice_number=INV-777&trx_id=ONLINE-TRX-1')
            ->assertRedirect(Orders::getUrl(panel: 'reseller'));

        $this->assertSame(StorefrontPayment::STATUS_COMPLETED, $payment->fresh()->status);
        $this->assertSame(1, OrderPayment::withoutGlobalScopes()->where('order_id', $order->getKey())->count());

        $this->postJson('http://parcel-co.example.test/webhooks/zinipay/'.$payment->getKey(), ['invoice_id' => 'INV-777'])->assertOk();
        $this->get($returnUrl.'&invoice_number=INV-777')->assertRedirect();

        $this->assertSame(1, OrderPayment::withoutGlobalScopes()->where('order_id', $order->getKey())->count());
        $this->assertSame(310.0, (float) $order->fresh()->paid_amount);
    }

    public function test_the_return_link_must_be_the_signed_one_for_this_portal(): void
    {
        $this->fakeGatewayCreate();
        $order = $this->placeOnline();
        $payment = $order->resellerAdvancePayment;
        $returnUrl = $this->capturedReturnUrl();
        $this->fakeGatewayVerify(310);

        // A tampered or unsigned link verifies nothing.
        $this->get('http://'.self::PORTAL.'/portal/orders/payment/'.$payment->getKey().'/return')->assertForbidden();
        $this->get(str_replace('signature=', 'signature=x', $returnUrl))->assertForbidden();
        $this->assertSame(StorefrontPayment::STATUS_PENDING, $payment->fresh()->status);

        // Another company's portal cannot be used to finish this payment.
        $other = Company::query()->create([
            'name' => 'Other Co', 'slug' => 'other-co', 'domain' => 'other-co.example.test', 'domain_verified' => true,
            'invoice_prefix' => 'OTH', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true, 'reseller_module_enabled' => true,
        ]);
        URL::forceRootUrl('http://reseller.other-co.example.test');
        $foreign = URL::temporarySignedRoute('reseller.portal.orders.payment-return', now()->addHour(), ['payment' => $payment]);
        $this->get($foreign)->assertNotFound();
        $this->assertSame(StorefrontPayment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertNotNull($other);
    }

    public function test_a_payment_the_gateway_did_not_complete_stays_open(): void
    {
        $this->fakeGatewayCreate();
        $order = $this->placeOnline();
        $payment = $order->resellerAdvancePayment;
        $returnUrl = $this->capturedReturnUrl();

        // The gateway answers each return in turn: still pending, paid but not what we asked for, then failed.
        Http::fake(['api.zinipay.com/v1/payment/verify' => Http::sequence()
            ->push(['amount' => 310, 'invoice_id' => 'INV-777', 'status' => 'PENDING'])
            ->push(['amount' => 100, 'invoice_id' => 'INV-777', 'payment_method' => 'bkash', 'transaction_id' => 'T', 'status' => 'COMPLETED'])
            ->push(['amount' => 310, 'invoice_id' => 'INV-777', 'status' => 'FAILED'])]);

        $this->get($returnUrl)->assertRedirect();
        $this->assertSame(StorefrontPayment::STATUS_PENDING, $payment->fresh()->status);

        $this->get($returnUrl)->assertRedirect();
        $this->assertSame(StorefrontPayment::STATUS_PENDING, $payment->fresh()->status);

        $this->get($returnUrl)->assertRedirect();
        $this->assertSame(StorefrontPayment::STATUS_FAILED, $payment->fresh()->status);

        $this->assertSame(0, OrderPayment::withoutGlobalScopes()->where('order_id', $order->getKey())->count());
        $this->assertSame(0.0, (float) $order->fresh()->paid_amount);
        $this->assertSame('rejected', app(ResellerOwnCourierService::class)->paymentState($order->fresh(['resellerAdvancePayments'])));
    }

    public function test_an_unfinished_online_payment_gives_way_to_a_new_attempt_or_a_manual_payment(): void
    {
        $this->fakeGatewayCreate();
        $order = $this->placeOnline();
        $first = $order->resellerAdvancePayment;
        $service = app(ResellerOwnCourierService::class);

        // Starting again closes the unfinished one: exactly one is open, for the full amount.
        $second = $service->startOnlinePayment($order);
        $this->assertSame(StorefrontPayment::STATUS_FAILED, $first->fresh()->status);
        $this->assertSame(StorefrontPayment::STATUS_PENDING, $second->status);
        $this->assertSame(310.0, (float) $second->amount);
        $this->assertSame(1, $order->resellerAdvancePayments()->where('status', StorefrontPayment::STATUS_PENDING)->count());

        // Paying by send-money instead works too, and closes the online one.
        $manual = $service->submitPayment($order, [
            'payment_method_id' => $this->bkash->getKey(), 'sender' => '01999999999', 'transaction_id' => 'TRX1',
        ]);
        $this->assertSame(StorefrontPayment::STATUS_FAILED, $second->fresh()->status);
        $this->assertSame(StorefrontPayment::STATUS_PENDING, $manual->status);
        $this->assertSame('waiting', $service->paymentState($order->fresh(['resellerAdvancePayments'])));

        // Once something is waiting or verified, a further online payment is refused.
        $errors = $this->errorsFor(fn () => $service->startOnlinePayment($order));
        $this->assertSame(['data.transaction_id'], array_keys($errors));
        $this->assertSame(StorefrontPayment::STATUS_PENDING, $manual->fresh()->status);
    }

    public function test_a_gateway_that_cannot_start_the_payment_creates_no_order(): void
    {
        Http::fake(['api.zinipay.com/v1/payment/create' => Http::response(['message' => 'down'], 500)]);

        $errors = $this->errorsFor(fn () => $this->placeOnline());

        $this->assertSame(['data.payment_method_id'], array_keys($errors));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, StorefrontPayment::query()->count());
    }

    public function test_the_deposit_pays_first_and_only_the_rest_goes_to_the_gateway(): void
    {
        $this->fakeGatewayCreate();
        ResellerDepositEntry::query()->create([
            'company_id' => $this->company->getKey(), 'customer_id' => $this->reseller->getKey(),
            'type' => ResellerDepositEntry::TYPE_OVERPAYMENT, 'amount' => 100,
        ]);

        $order = $this->placeOnline(quantity: 2);

        $online = $order->resellerAdvancePayments()->where('gateway', 'zinipay')->sole();
        $this->assertSame(520.0, (float) $online->amount); // 620 - 100 from the deposit
    }

    public function test_staff_cannot_verify_or_reject_an_online_payment_by_hand(): void
    {
        $this->fakeGatewayCreate();
        $order = $this->placeOnline();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $this->company->getKey()]);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionHidden('verifyResellerPayment')
            ->assertActionHidden('rejectResellerPayment')
            ->assertSee('paid online with ZiniPay')
            ->assertSee('Waiting for the online payment');

        // The same order paid by send-money can still be checked by hand.
        app(ResellerOwnCourierService::class)->submitPayment($order, [
            'payment_method_id' => $this->bkash->getKey(), 'sender' => '01999999999', 'transaction_id' => 'TRX1',
        ]);
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('verifyResellerPayment');
    }

    public function test_the_portal_form_sends_the_reseller_to_the_gateways_page_without_asking_for_a_transaction_id(): void
    {
        $this->fakeGatewayCreate();
        $this->asPortal();

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'delivery_method' => 'own',
                'name' => 'Buyer One', 'phone' => '01722000001', 'address' => 'House 1, Mirpur, Dhaka',
                'courier_name' => 'Steadfast', 'parcel_id' => 'SF-77',
                'payment_method_id' => ResellerOwnCourierService::ONLINE,
                'items' => [['product_id' => $this->ring->getKey(), 'quantity' => 2]],
            ])
            ->assertFormFieldHidden('sender')
            ->assertFormFieldHidden('transaction_id')
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect('https://pay.zinipay.com/checkout/INV-777');

        $order = Order::query()->where('reseller_customer_id', $this->reseller->getKey())->sole();
        $this->assertSame(620.0, (float) $order->total_amount);
        $this->assertSame('paying', app(ResellerOwnCourierService::class)->paymentState($order));
    }

    public function test_the_portal_form_still_needs_the_transaction_id_for_a_send_money_payment(): void
    {
        $this->asPortal();

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'delivery_method' => 'own',
                'name' => 'Buyer One', 'phone' => '01722000001', 'address' => 'Mirpur',
                'courier_name' => 'Steadfast', 'parcel_id' => 'SF-77',
                'payment_method_id' => $this->bkash->getKey(),
                'items' => [['product_id' => $this->ring->getKey(), 'quantity' => 1]],
            ])
            ->call('create')
            ->assertHasFormErrors(['sender', 'transaction_id']);
    }

    public function test_own_courier_stays_available_with_only_the_gateway(): void
    {
        $this->bkash->update(['is_active' => false]);
        $this->asPortal();

        Livewire::test(CreateOrder::class)->assertDontSee('Own courier orders are not available yet');

        $this->setting()->update(['online_payment_enabled' => false]);
        Livewire::test(CreateOrder::class)->assertSee('Own courier orders are not available yet');
    }

    public function test_the_orders_list_lets_the_reseller_go_back_to_an_unfinished_payment(): void
    {
        $this->fakeGatewayCreate();
        $order = $this->placeOnline();
        $this->asPortal();

        Livewire::test(Orders::class)
            ->assertSee('Waiting for the online payment')
            ->assertTableActionVisible('payOnline', $order)
            ->assertTableActionHasUrl('payOnline', 'https://pay.zinipay.com/checkout/INV-777', $order)
            // They can still change their mind and pay by send-money.
            ->assertTableActionVisible('resendPayment', $order);

        // Once the gateway confirmed it, there is nothing left to pay.
        $this->fakeGatewayVerify(310);
        $this->postJson('http://parcel-co.example.test/webhooks/zinipay/'.$order->resellerAdvancePayment->getKey(), ['invoice_id' => 'INV-777'])->assertOk();

        $this->asPortal();
        Livewire::test(Orders::class)
            ->assertTableActionHidden('payOnline', $order)
            ->assertTableActionHidden('resendPayment', $order);
    }

    public function test_choosing_pay_online_again_from_the_orders_list_goes_to_the_gateway(): void
    {
        $this->fakeGatewayCreate();
        $order = $this->placeOnline();
        $first = $order->resellerAdvancePayment;
        $this->asPortal();

        Livewire::test(Orders::class)
            ->callTableAction('resendPayment', $order, ['payment_method_id' => ResellerOwnCourierService::ONLINE])
            ->assertHasNoTableActionErrors()
            ->assertRedirect('https://pay.zinipay.com/checkout/INV-777');

        $this->assertSame(StorefrontPayment::STATUS_FAILED, $first->fresh()->status);
        $this->assertSame(1, $order->resellerAdvancePayments()->where('status', StorefrontPayment::STATUS_PENDING)->count());
    }

    public function test_the_extra_after_raising_an_order_can_be_paid_online(): void
    {
        $this->fakeGatewayCreate();
        // 310 sent by send-money and waiting; then the reseller raises the quantity to 3.
        $order = app(ResellerOrderPlacementService::class)->place($this->reseller, [
            'name' => 'Buyer', 'phone' => '01722000009', 'address' => 'Dhaka, Mirpur', 'note' => null,
        ], [['product_id' => $this->ring->getKey(), 'quantity' => 1, 'price' => 500]], [
            'courier_name' => 'Pathao', 'parcel_id' => 'PATHAO-1',
            'payment_method_id' => $this->bkash->getKey(), 'sender' => '01999999999', 'transaction_id' => 'TRX1',
        ]);
        $kept = $order->items()->sole();

        $updated = app(ResellerOrderPlacementService::class)->updateOwnCourierOrder(
            $this->reseller,
            $order,
            ['name' => 'Buyer', 'phone' => '01722000009', 'address' => 'Dhaka, Mirpur'],
            [['item_id' => $kept->getKey(), 'product_id' => $this->ring->getKey(), 'quantity' => 3, 'price' => 500]],
            ['courier_name' => 'Pathao', 'parcel_id' => 'PATHAO-1'],
            ['payment_method_id' => ResellerOwnCourierService::ONLINE],
        );

        $online = $updated->resellerAdvancePayments()->where('gateway', 'zinipay')->sole();
        $this->assertSame(620.0, (float) $online->amount); // only the extra 2 x 310
        $this->assertSame('https://pay.zinipay.com/checkout/INV-777', $online->payload['payment_url']);
        // The send-money payment already made stays as it was.
        $this->assertSame(StorefrontPayment::STATUS_PENDING, $updated->resellerAdvancePayments()->where('gateway', StorefrontPaymentMethod::TYPE_MANUAL)->sole()->status);
        $this->assertSame(930.0, (float) $updated->total_amount);

        // The gateway confirms the extra; the first part still waits for staff.
        $this->fakeGatewayVerify(620);
        $this->postJson('http://parcel-co.example.test/webhooks/zinipay/'.$online->getKey(), ['invoice_id' => 'INV-777'])->assertOk();
        $this->assertSame(620.0, (float) $updated->fresh()->paid_amount);
        $this->assertSame('waiting', app(ResellerOwnCourierService::class)->paymentState($updated->fresh(['resellerAdvancePayments'])));
    }

    public function test_another_resellers_online_payment_is_never_reachable(): void
    {
        $this->fakeGatewayCreate();
        $order = $this->placeOnline();
        $this->reseller = $this->makeReseller('01733000002');
        $this->asPortal();

        // The order, and so its way back to the gateway's page, is not in their list.
        Livewire::test(Orders::class)->assertCanNotSeeTableRecords([$order]);
    }

    protected function setting(): StorefrontSetting
    {
        return StorefrontSetting::withoutGlobalScopes()->where('company_id', $this->company->getKey())->firstOrFail();
    }

    protected function fakeGatewayCreate(): void
    {
        Http::fake(['api.zinipay.com/v1/payment/create' => Http::response(['payment_url' => 'https://pay.zinipay.com/checkout/INV-777'])]);
    }

    protected function fakeGatewayVerify(float $amount): void
    {
        Http::fake(['api.zinipay.com/v1/payment/verify' => Http::response([
            'amount' => $amount, 'invoice_id' => 'INV-777', 'payment_method' => 'bkash', 'transaction_id' => 'ONLINE-TRX-1', 'status' => 'COMPLETED',
        ])]);
    }

    /** The link the gateway was given to send the reseller back to. */
    protected function capturedReturnUrl(): string
    {
        $url = null;

        Http::assertSent(function ($request) use (&$url): bool {
            if (str_contains($request->url(), '/v1/payment/create')) {
                $url = (string) $request['redirect_url'];
            }

            return true;
        });

        $this->assertNotNull($url);

        return $url;
    }

    protected function placeOnline(int $quantity = 1): Order
    {
        return app(ResellerOrderPlacementService::class)->place($this->reseller, [
            'name' => 'Buyer', 'phone' => '01722000009', 'address' => 'Dhaka, Mirpur', 'note' => null,
        ], [['product_id' => $this->ring->getKey(), 'quantity' => $quantity]], [
            'courier_name' => 'Pathao', 'parcel_id' => 'PATHAO-'.Str::random(5),
            'payment_method_id' => ResellerOwnCourierService::ONLINE, 'sender' => '', 'transaction_id' => '',
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

    protected function asPortal(): void
    {
        app(CompanyContext::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $this->actingAs($this->reseller, 'customer');
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
