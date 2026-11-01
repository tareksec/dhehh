<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\PaymentsRelationManager;
use App\Models\Account;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\TransactionLedger;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\OrderSummaryCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner's order-management requests: payment method picked from Accounts
 * (posts to the account ledger), call/WhatsApp icons in the order list, and
 * the shareable order summary card on the order view page.
 */
class OrderManagementFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_order_payment_with_an_account_posts_money_into_that_account(): void
    {
        [$company] = $this->signIn();
        $bkash = $this->account($company, 'bKash', 'mobile_banking');
        $order = $this->order($company);

        $payment = $order->payments()->create([
            'type' => OrderPayment::TYPE_PARTIAL,
            'account_id' => $bkash->getKey(),
            'amount' => 300,
            'paid_at' => now()->toDateString(),
        ]);

        $this->assertSame('mobile_banking', $payment->method);
        $this->assertDatabaseHas('transaction_ledgers', [
            'reference_type' => OrderPayment::class,
            'reference_id' => $payment->getKey(),
            'account_id' => $bkash->getKey(),
            'type' => 'customer_payment',
            'direction' => 'in',
            'amount' => 300,
        ]);
        $this->assertEquals(300, (float) $bkash->fresh()->current_balance);
        $this->assertEquals(300, (float) $order->fresh()->paid_amount);

        $payment->update(['amount' => 450]);
        $this->assertEquals(450, (float) $bkash->fresh()->current_balance);

        $payment->delete();
        $this->assertEquals(0, (float) $bkash->fresh()->current_balance);
        $this->assertDatabaseMissing('transaction_ledgers', ['reference_type' => OrderPayment::class]);
    }

    public function test_moving_a_payment_to_another_account_moves_the_money(): void
    {
        [$company] = $this->signIn();
        $cash = $this->account($company, 'Cash', 'cash');
        $bank = $this->account($company, 'City Bank', 'bank');
        $order = $this->order($company);

        $payment = $order->payments()->create([
            'account_id' => $cash->getKey(),
            'amount' => 200,
            'paid_at' => now()->toDateString(),
        ]);
        $payment->update(['account_id' => $bank->getKey()]);

        $this->assertSame('bank', $payment->fresh()->method);
        $this->assertEquals(0, (float) $cash->fresh()->current_balance);
        $this->assertEquals(200, (float) $bank->fresh()->current_balance);
    }

    public function test_a_payment_without_an_account_posts_nothing(): void
    {
        [$company] = $this->signIn();
        $order = $this->order($company);

        $order->payments()->create([
            'method' => 'cash',
            'amount' => 100,
            'paid_at' => now()->toDateString(),
        ]);

        $this->assertSame(0, TransactionLedger::query()->count());
        $this->assertEquals(100, (float) $order->fresh()->paid_amount);
    }

    public function test_permanently_deleting_an_order_removes_its_payment_postings(): void
    {
        [$company] = $this->signIn();
        $cash = $this->account($company, 'Cash', 'cash');
        $order = $this->order($company);
        $order->payments()->create([
            'account_id' => $cash->getKey(),
            'amount' => 250,
            'paid_at' => now()->toDateString(),
        ]);
        $this->assertEquals(250, (float) $cash->fresh()->current_balance);

        $order->forceDelete();

        $this->assertSame(0, TransactionLedger::query()->count());
        $this->assertEquals(0, (float) $cash->fresh()->current_balance);
    }

    public function test_payment_method_options_are_the_active_money_accounts_only(): void
    {
        [$company] = $this->signIn();
        Account::ensureSystemAccountsForCompany($company);
        $cash = $this->account($company, 'Cash', 'cash');
        $inactive = $this->account($company, 'Old Nagad', 'mobile_banking');
        $inactive->update(['is_active' => false]);

        $this->assertSame([$cash->getKey() => 'Cash'], OrderPayment::accountOptions());
    }

    public function test_create_order_form_posts_the_paid_amount_into_the_chosen_account(): void
    {
        [$company] = $this->signIn();
        $bkash = $this->account($company, 'bKash', 'mobile_banking');
        $customer = $this->customer();
        $product = $this->product();

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'customer_id' => $customer->getKey(),
                'order_date' => now()->toDateString(),
                'status' => 'draft',
                'delivery_status' => CourierBooking::STATUS_NOT_BOOKED,
                'items' => [
                    ['product_id' => $product->getKey(), 'quantity' => 1, 'unit_price' => 500],
                ],
                'paid_amount' => 200,
                'payment_account_id' => $bkash->getKey(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $payment = OrderPayment::query()->sole();
        $this->assertSame($bkash->getKey(), $payment->account_id);
        $this->assertSame('mobile_banking', $payment->method);
        $this->assertEquals(200, (float) $bkash->fresh()->current_balance);
    }

    public function test_create_order_form_requires_a_payment_method_when_something_was_paid(): void
    {
        [$company] = $this->signIn();
        $this->account($company, 'Cash', 'cash');
        $customer = $this->customer();
        $product = $this->product();

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'customer_id' => $customer->getKey(),
                'order_date' => now()->toDateString(),
                'status' => 'draft',
                'delivery_status' => CourierBooking::STATUS_NOT_BOOKED,
                'items' => [
                    ['product_id' => $product->getKey(), 'quantity' => 1, 'unit_price' => 500],
                ],
                'paid_amount' => 200,
            ])
            ->call('create')
            ->assertHasFormErrors(['payment_account_id' => 'required']);
    }

    public function test_payments_history_records_a_payment_into_the_chosen_account(): void
    {
        [$company] = $this->signIn();
        $cash = $this->account($company, 'Cash', 'cash');
        $order = $this->order($company);

        Livewire::test(PaymentsRelationManager::class, [
            'ownerRecord' => $order,
            'pageClass' => EditOrder::class,
        ])
            ->callTableAction('create', data: [
                'type' => OrderPayment::TYPE_PARTIAL,
                'account_id' => $cash->getKey(),
                'amount' => 150,
                'paid_at' => now()->toDateString(),
            ])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(150, (float) $cash->fresh()->current_balance);
        $this->assertEquals(150, (float) $order->fresh()->paid_amount);
    }

    public function test_a_deleted_payment_goes_to_the_orders_trash_and_comes_back_on_restore(): void
    {
        [$company] = $this->signIn();
        $cash = $this->account($company, 'Cash', 'cash');
        $order = $this->order($company);
        $payment = OrderPayment::query()->create([
            'order_id' => $order->getKey(),
            'account_id' => $cash->getKey(),
            'type' => OrderPayment::TYPE_PARTIAL,
            'amount' => 150,
            'paid_at' => now()->toDateString(),
        ]);
        $this->assertEquals(150, (float) $order->fresh()->paid_amount);

        $name = 'Payment #'.$payment->getKey().' (150.00)';
        $params = ['ownerRecord' => $order, 'pageClass' => EditOrder::class];

        // The wrong name deletes nothing.
        Livewire::test(PaymentsRelationManager::class, $params)
            ->callTableAction('delete', $payment, data: ['confirmation' => 'something else'])
            ->assertHasTableActionErrors(['confirmation']);
        $this->assertNotSoftDeleted($payment);

        // The right name moves it to Trash and the order and the account lose the payment.
        Livewire::test(PaymentsRelationManager::class, $params)
            ->callTableAction('delete', $payment, data: ['confirmation' => $name])
            ->assertHasNoTableActionErrors();
        $this->assertSoftDeleted($payment);
        $this->assertEquals(0, (float) $order->fresh()->paid_amount);
        $this->assertEquals(0, (float) $cash->fresh()->current_balance);

        // It is not in the list, it is in the Trash, and Restore puts everything back.
        Livewire::test(PaymentsRelationManager::class, $params)
            ->assertCanNotSeeTableRecords([$payment])
            ->set('showTrash', true)
            ->assertCanSeeTableRecords([$payment])
            ->callTableAction('restore', $payment)
            ->assertHasNoTableActionErrors();
        $this->assertNotSoftDeleted($payment);
        $this->assertEquals(150, (float) $order->fresh()->paid_amount);
        $this->assertEquals(150, (float) $cash->fresh()->current_balance);
    }

    public function test_a_trashed_payment_is_deleted_permanently_only_after_typing_its_name(): void
    {
        [$company] = $this->signIn();
        $cash = $this->account($company, 'Cash', 'cash');
        $order = $this->order($company);
        $payment = OrderPayment::query()->create([
            'order_id' => $order->getKey(),
            'account_id' => $cash->getKey(),
            'type' => OrderPayment::TYPE_PARTIAL,
            'amount' => 150,
            'paid_at' => now()->toDateString(),
        ]);
        $payment->delete();

        $params = ['ownerRecord' => $order, 'pageClass' => EditOrder::class];

        Livewire::test(PaymentsRelationManager::class, $params)
            ->set('showTrash', true)
            ->callTableAction('forceDelete', $payment, data: ['confirmation' => 'something else'])
            ->assertHasTableActionErrors(['confirmation']);
        $this->assertSoftDeleted($payment);

        Livewire::test(PaymentsRelationManager::class, $params)
            ->set('showTrash', true)
            ->callTableAction('forceDelete', $payment, data: ['confirmation' => 'Payment #'.$payment->getKey().' (150.00)'])
            ->assertHasNoTableActionErrors();
        $this->assertModelMissing($payment);
    }

    public function test_order_list_shows_call_and_whatsapp_links_under_the_phone(): void
    {
        [$company] = $this->signIn();
        $this->order($company);

        Livewire::test(ListOrders::class)
            ->assertSeeHtml('href="tel:01711111111"')
            ->assertSeeHtml('href="https://wa.me/8801711111111"');
    }

    public function test_order_summary_card_has_order_details_and_share_links(): void
    {
        [$company] = $this->signIn();
        $order = $this->order($company);

        $card = app(OrderSummaryCardService::class)->build($order);

        $this->assertSame('Zam Zam Gadget', $card['company']);
        $this->assertStringContainsString($order->order_number, $card['text']);
        $this->assertStringContainsString('Summary Product × 1', $card['text']);
        $this->assertSame('8801711111111', $card['customer_phone']);
    }

    public function test_order_summary_card_carries_the_ordered_products_picture(): void
    {
        [$company] = $this->signIn();
        Storage::fake('public');
        $picture = imagecreatetruecolor(400, 300);
        imagefill($picture, 0, 0, imagecolorallocate($picture, 200, 30, 30));
        ob_start();
        imagepng($picture);
        Storage::disk('public')->put('products/summary-product.png', (string) ob_get_clean());

        $order = $this->order($company);
        $order->items->first()->product->update(['image' => 'products/summary-product.png']);

        $item = app(OrderSummaryCardService::class)->build($order->fresh())['items'][0];

        $this->assertStringStartsWith('data:image/jpeg;base64,', $item['image']);
        [$width, $height] = getimagesizefromstring(base64_decode(substr($item['image'], strlen('data:image/jpeg;base64,'))));
        $this->assertSame(160, $width);
        $this->assertSame(160, $height);
    }

    public function test_order_summary_card_has_no_picture_when_the_product_has_none_or_it_is_missing(): void
    {
        [$company] = $this->signIn();
        Storage::fake('public');
        $order = $this->order($company);

        $this->assertNull(app(OrderSummaryCardService::class)->build($order)['items'][0]['image']);

        $order->items->first()->product->update(['image' => 'products/gone.png']);

        $this->assertNull(app(OrderSummaryCardService::class)->build($order->fresh())['items'][0]['image']);
    }

    public function test_order_summary_card_is_laid_out_like_a_corporate_document(): void
    {
        [$company] = $this->signIn();
        Storage::fake('public');
        $logo = imagecreatetruecolor(800, 200);
        ob_start();
        imagepng($logo);
        Storage::disk('public')->put('company/logo.png', (string) ob_get_clean());
        $company->update(['logo' => 'company/logo.png', 'phone' => '01700000000', 'email' => 'info@zamzamint.com', 'address' => 'Uttara, Dhaka']);

        $order = $this->order($company);
        $order->forceFill(['note' => 'Call before delivery'])->save();

        $card = app(OrderSummaryCardService::class)->build($order->fresh());

        // Logo is shrunk to fit and embedded; contact comes from the company.
        $this->assertStringStartsWith('data:image/png;base64,', $card['logo']);
        [$width, $height] = getimagesizefromstring(base64_decode(substr($card['logo'], strlen('data:image/png;base64,'))));
        $this->assertLessThanOrEqual(520, $width);
        $this->assertLessThanOrEqual(160, $height);
        $this->assertSame(['01700000000', 'info@zamzamint.com'], $card['contact']);
        $this->assertSame('Uttara, Dhaka', $card['address']);
        $this->assertSame('Call before delivery', $card['note']);
        $this->assertSame($order->order_number, $card['invoice']);

        // Order and customer details are separate blocks; an unpaid order says so.
        $this->assertSame(['invoice', 'date', 'status'], array_column($card['order_rows'], 'key'));
        $this->assertSame(['customer', 'phone', 'address'], array_column($card['customer_rows'], 'key'));
        $this->assertSame('unpaid', $card['payment']['key']);
        $this->assertSame('500', preg_replace('/\D/', '', $card['items'][0]['price']));

        $order->payments()->create(['method' => 'cash', 'amount' => 200, 'paid_at' => now()->toDateString()]);
        $this->assertSame('partial', app(OrderSummaryCardService::class)->build($order->fresh())['payment']['key']);

        $order->payments()->create(['method' => 'cash', 'amount' => 300, 'paid_at' => now()->toDateString()]);
        $this->assertSame('paid', app(OrderSummaryCardService::class)->build($order->fresh())['payment']['key']);
    }

    public function test_order_summary_card_without_a_logo_has_none_and_still_builds(): void
    {
        [$company] = $this->signIn();
        $order = $this->order($company);

        $card = app(OrderSummaryCardService::class)->build($order);

        $this->assertNull($card['logo']);
        $this->assertSame([], $card['contact']);
        $this->assertNull($card['note']);
    }

    public function test_order_view_page_opens_the_order_summary_card(): void
    {
        [$company] = $this->signIn();
        $order = $this->order($company);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('orderSummaryCard')
            ->mountAction('orderSummaryCard')
            ->assertActionMounted('orderSummaryCard');

        $html = view('filament.orders.summary-card', [
            'card' => app(OrderSummaryCardService::class)->build($order),
        ])->render();

        foreach (['WhatsApp', 'WeChat', 'Messenger', 'Telegram', 'Download image', 'Copy text', 'send(\'whatsapp\')', 'ZzShareBridge'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    /**
     * @return array{0: Company, 1: User}
     */
    protected function signIn(): array
    {
        $company = Company::query()->create([
            'name' => 'Zam Zam Gadget',
            'slug' => 'zam-zam-gadget',
            'invoice_prefix' => 'ZZG',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);

        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($user)->withSession(['current_company_id' => $company->getKey()]);

        return [$company, $user];
    }

    protected function account(Company $company, string $name, string $type): Account
    {
        return Account::query()->create([
            'company_id' => $company->getKey(),
            'name' => $name,
            'type' => $type,
            'opening_balance' => 0,
            'current_balance' => 0,
            'is_active' => true,
        ]);
    }

    protected function customer(): Customer
    {
        return Customer::query()->create([
            'name' => 'Summary Customer',
            'phone' => '01711111111',
            'address' => 'Mirpur, Dhaka',
            'opening_balance' => 0,
            'is_active' => true,
        ]);
    }

    protected function product(): Product
    {
        return Product::query()->create([
            'name' => 'Summary Product',
            'sku' => 'SUM-'.uniqid(),
            'price' => 500,
            'sale_price' => 500,
            'cost_price' => 300,
            'stock' => 10,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);
    }

    protected function order(Company $company): Order
    {
        $order = Order::query()->create([
            'company_id' => $company->getKey(),
            'customer_id' => $this->customer()->getKey(),
            'order_date' => now()->toDateString(),
            'discount' => 0,
            'vat' => 0,
            'paid_amount' => 0,
            'status' => 'draft',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->getKey(),
            'product_id' => $this->product()->getKey(),
            'quantity' => 1,
            'unit_price' => 500,
        ]);

        return $order->refresh();
    }
}
