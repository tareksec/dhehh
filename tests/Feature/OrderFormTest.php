<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Form-layer (Livewire) coverage for the Order create screen (audit L-5).
 * Exercises the actual Filament schema + items repeater rather than creating
 * records directly, so a repeater field that submits a bad value for a
 * NOT NULL column (the class of bug that hit Purchase) is caught here.
 */
class OrderFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_order_form_saves_items(): void
    {
        $company = Company::query()->create([
            'name' => 'Order Form Co',
            'slug' => 'order-form-co',
            'invoice_prefix' => 'OFC',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);

        $customer = Customer::query()->create([
            'name' => 'Form Buyer',
            'phone' => '01766666666',
            'opening_balance' => 0,
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'name' => 'Order Form Product',
            'sku' => 'OFC-PROD-001',
            'price' => 100,
            'sale_price' => 100,
            'cost_price' => 60,
            'stock' => 10,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);

        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($user)->withSession(['current_company_id' => $company->id]);

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'order_date' => now()->toDateString(),
                'status' => 'draft',
                'delivery_status' => CourierBooking::STATUS_NOT_BOOKED,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = OrderItem::query()->where('product_id', $product->id)->firstOrFail();

        $this->assertSame(2, $item->quantity);
        $this->assertSame('100.00', $item->unit_price);
        $this->assertSame('200.00', $item->subtotal);
        $this->assertSame('60.00', $item->unit_cost);

        $this->assertSame('200.00', $item->order->subtotal);
    }

    /**
     * Owner request: after creating an order the Create page returns to the
     * orders list, not Filament's default jump to the new order's View page.
     */
    public function test_creating_an_order_redirects_to_the_orders_list(): void
    {
        $company = Company::query()->create([
            'name' => 'Redirect Co',
            'slug' => 'redirect-co',
            'invoice_prefix' => 'RDC',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);

        $customer = Customer::query()->create([
            'name' => 'Redirect Buyer',
            'phone' => '01799999999',
            'opening_balance' => 0,
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'name' => 'Redirect Product',
            'sku' => 'RDC-PROD-001',
            'price' => 100,
            'sale_price' => 100,
            'cost_price' => 60,
            'stock' => 10,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);

        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($user)->withSession(['current_company_id' => $company->id]);

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'order_date' => now()->toDateString(),
                'status' => 'draft',
                'delivery_status' => CourierBooking::STATUS_NOT_BOOKED,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(OrderResource::getUrl('index'));
    }

    /**
     * Regression test for the real owner report: on the live Create Order
     * form (not just at the Eloquent layer), selecting a customer whose
     * address doesn't match any configured shipping zone first drives
     * shipping_fee to 0 via customer_id's afterStateUpdated hook — typing a
     * real amount into Shipping Fee afterwards must still win at save time.
     */
    public function test_manually_typed_shipping_fee_on_the_live_form_is_not_reset_to_zero(): void
    {
        $company = Company::query()->create([
            'name' => 'No Zone Co',
            'slug' => 'no-zone-co',
            'invoice_prefix' => 'NZC',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);

        $customer = Customer::query()->create([
            'name' => 'No Zone Buyer',
            'phone' => '01788888888',
            'address' => 'Somewhere not in any configured zone',
            'opening_balance' => 0,
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'name' => 'No Zone Product',
            'sku' => 'NZC-PROD-001',
            'price' => 100,
            'sale_price' => 100,
            'cost_price' => 60,
            'stock' => 10,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);

        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($user)->withSession(['current_company_id' => $company->id]);

        Livewire::test(CreateOrder::class)
            ->fillForm([
                // customer_id first: its afterStateUpdated runs setShippingFee(),
                // which fails to detect a zone and drives shipping_fee to 0 —
                // exactly like real browser use, before the manual edit below.
                'customer_id' => $customer->id,
                'order_date' => now()->toDateString(),
                'status' => 'draft',
                'delivery_status' => CourierBooking::STATUS_NOT_BOOKED,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100],
                ],
                'shipping_fee' => 250,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $order = Order::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame('250.00', $order->shipping_fee);
        $this->assertSame('350.00', $order->total_amount);
    }

    /**
     * Owner report (2026-10-10, screenshot): on the Create Order form the
     * Shipping Fee showed ৳110 but Total amount and Due amount stayed at the
     * products' ৳200. The shipping fee must be part of Total amount / Due
     * amount whichever order the staff member fills the form in, and the order
     * saved from that form must carry it too.
     */
    public function test_shipping_fee_is_added_to_the_total_amount_on_the_live_create_form(): void
    {
        $company = Company::query()->create([
            'name' => 'Live Total Co',
            'slug' => 'live-total-co',
            'invoice_prefix' => 'LTC',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);

        $customer = Customer::query()->create([
            'name' => 'Live Total Buyer',
            'phone' => '01755555555',
            'address' => 'Somewhere not in any configured zone',
            'opening_balance' => 0,
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'name' => 'Live Total Product',
            'sku' => 'LTC-PROD-001',
            'price' => 200,
            'sale_price' => 200,
            'cost_price' => 120,
            'stock' => 10,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);

        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($user)->withSession(['current_company_id' => $company->id]);

        $totals = fn ($page): array => [
            (float) $page->get('data.subtotal'),
            (float) $page->get('data.total_amount'),
            (float) $page->get('data.due_amount'),
        ];

        // Customer and product first, shipping fee typed last (the screenshot).
        $page = Livewire::test(CreateOrder::class)
            ->set('data.customer_id', $customer->id);
        $itemKey = array_key_first($page->get('data.items'));

        $page->set("data.items.{$itemKey}.product_id", $product->id);
        $this->assertSame([200.0, 200.0, 200.0], $totals($page));

        $page->set('data.shipping_fee', 110);
        $this->assertSame([200.0, 310.0, 310.0], $totals($page));

        // Later edits keep the shipping fee in the total.
        $page->set("data.items.{$itemKey}.quantity", 2);
        $this->assertSame([400.0, 510.0, 510.0], $totals($page));

        $page->set('data.discount', 50)->set('data.vat', 20);
        $this->assertSame([400.0, 480.0, 480.0], $totals($page));

        $page->set('data.paid_amount', 100);
        $this->assertSame([400.0, 480.0, 380.0], $totals($page));

        // Shipping fee first, products added afterwards.
        $other = Livewire::test(CreateOrder::class);
        $otherKey = array_key_first($other->get('data.items'));

        $other->set('data.shipping_fee', 110);
        $this->assertSame([0.0, 110.0, 110.0], $totals($other));

        $other->set("data.items.{$otherKey}.product_id", $product->id);
        $this->assertSame([200.0, 310.0, 310.0], $totals($other));

        // The saved order keeps the shipping fee inside its total.
        $page
            ->set('data.status', 'draft')
            ->call('create')
            ->assertHasNoFormErrors();

        $order = Order::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame('110.00', $order->shipping_fee);
        $this->assertSame('480.00', $order->total_amount);
        $this->assertSame('380.00', $order->due_amount);
    }

    /**
     * Root cause of the report above, which a Livewire test alone cannot see
     * because it never "blurs" a field: every input that feeds Total amount /
     * Due amount used to be `live(onBlur: true)`. In a phone browser the
     * keyboard is dismissed without the field losing focus, so no update was
     * ever sent and the total never moved. These inputs must recalculate while
     * typing (debounced), not only on blur.
     */
    public function test_inputs_feeding_the_order_totals_recalculate_while_typing_not_only_on_blur(): void
    {
        $company = Company::query()->create([
            'name' => 'Debounce Co',
            'slug' => 'debounce-co',
            'invoice_prefix' => 'DBC',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);

        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($user)->withSession(['current_company_id' => $company->id]);

        $schema = Livewire::test(CreateOrder::class)->instance()->getSchema('form');

        $flat = $schema->getFlatFields(withAbsoluteKeys: true);

        $fields = [];
        foreach (['shipping_fee', 'discount', 'vat', 'paid_amount'] as $name) {
            $fields[$name] = $flat["form.{$name}"] ?? null;
        }

        // The item row's own inputs are keyed `form.items.<row id>.<name>`.
        $itemFields = [];
        foreach ($flat as $path => $field) {
            if (preg_match('/^form\.items\.[^.]+\.(quantity|unit_price)$/', (string) $path, $match) === 1) {
                $itemFields["item {$match[1]}"] = $field;
            }
        }

        $this->assertCount(2, $itemFields, 'The default item row should expose its quantity and unit price inputs.');

        foreach ([...$fields, ...$itemFields] as $name => $field) {
            $this->assertNotNull($field, "{$name} input is missing from the order form.");
            $this->assertTrue($field->isLive(), "{$name} must be a live field.");
            $this->assertFalse($field->isLiveOnBlur(), "{$name} must not wait for the field to lose focus.");
            $this->assertSame(
                OrderForm::TOTALS_DEBOUNCE_MS,
                $field->getNormalizedLiveDebounce(),
                "{$name} must recalculate the totals shortly after typing stops.",
            );
        }
    }

    /**
     * Regression test for a real production bug: paid_amount/due_amount on
     * the edit form are readOnly (see OrderForm) but that alone doesn't stop
     * them being submitted — before dehydrated(false) was added, saving the
     * edit form after adding a payment via Payments History (which recomputes
     * Order::paid_amount/due_amount straight in the database) would silently
     * overwrite the ledger-correct values with whatever was on the form when
     * the page first loaded, undoing the payment from the customer's and the
     * invoice's point of view.
     */
    public function test_saving_the_edit_form_does_not_revert_a_payment_added_via_the_ledger(): void
    {
        $company = Company::query()->create([
            'name' => 'Payment Sync Co',
            'slug' => 'payment-sync-co',
            'invoice_prefix' => 'PSC',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);

        $customer = Customer::query()->create([
            'name' => 'Payment Sync Buyer',
            'phone' => '01755555555',
            'opening_balance' => 0,
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'name' => 'Payment Sync Product',
            'sku' => 'PSC-PROD-001',
            'price' => 100,
            'sale_price' => 100,
            'cost_price' => 60,
            'stock' => 10,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);

        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($user)->withSession(['current_company_id' => $company->id]);

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'order_date' => now()->toDateString(),
                'status' => 'draft',
                'delivery_status' => CourierBooking::STATUS_NOT_BOOKED,
                'paid_amount' => 0,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $order = Order::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('0.00', $order->paid_amount);

        // Mounting the edit form fills paid_amount/due_amount from the order
        // as it is right now (0 / 200) -- this in-memory copy goes stale the
        // moment a payment is recorded below, which is exactly the scenario
        // the fix needs to survive.
        $editForm = Livewire::test(EditOrder::class, ['record' => $order->getKey()]);

        // Simulate what PaymentsRelationManager's own CreateAction does: add
        // a ledger row, which recomputes the order's paid_amount/due_amount
        // directly in the database (OrderPayment::booted() -> Order::
        // recalculatePaidAmount()).
        $order->payments()->create([
            'company_id' => $company->id,
            'type' => OrderPayment::TYPE_PARTIAL,
            'method' => 'cash',
            'amount' => 150,
            'paid_at' => now()->toDateString(),
        ]);

        $this->assertSame('150.00', $order->fresh()->paid_amount);

        // Saving the still-open edit form (its in-memory paid_amount/
        // due_amount are still the pre-payment 0 / 200) must not undo the
        // payment just recorded above.
        $editForm->call('save')->assertHasNoFormErrors();

        $order->refresh();
        $this->assertSame('150.00', $order->paid_amount);
        $this->assertSame('50.00', $order->due_amount);
    }
}
