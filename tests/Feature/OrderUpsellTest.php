<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner request (2026-10-05): an item added to an order after it was created
 * is an upsell. The "Upsell" tag shows in the admin panel only, never on
 * anything the customer sees.
 */
class OrderUpsellTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Product $first;

    private Product $second;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::query()->create([
            'name' => 'Add-on Co',
            'slug' => 'add-on-co',
            'invoice_prefix' => 'ADD',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);
        $this->withSession(['current_company_id' => $company->id]);
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        $this->customer = Customer::query()->create([
            'name' => 'Repeat Buyer',
            'phone' => '01766666666',
            'opening_balance' => 0,
            'is_active' => true,
        ]);
        $this->first = $this->product('First Product');
        $this->second = $this->product('Second Product');
    }

    private function product(string $name): Product
    {
        $product = Product::query()->create([
            'name' => $name,
            'sku' => str($name)->slug()->upper()->toString(),
            'price' => 100,
            'sale_price' => 100,
            'cost_price' => 60,
            'stock' => 0,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);
        $product->setStockFromProductForm(10);

        return $product->refresh();
    }

    private function createOrder(): Order
    {
        Livewire::test(CreateOrder::class)
            ->fillForm([
                'customer_id' => $this->customer->id,
                'order_date' => now()->toDateString(),
                'status' => Order::STATUS_CONFIRMED,
                'delivery_status' => CourierBooking::STATUS_NOT_BOOKED,
                'items' => [
                    ['product_id' => $this->first->id, 'quantity' => 1, 'unit_price' => 100],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        return Order::query()->sole();
    }

    private function addItemAfterCreation(Order $order): void
    {
        $component = Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()]);
        $items = $component->get('data.items');
        $items['added-item'] = [
            'product_id' => $this->second->id,
            'product_variant_id' => null,
            'variant_label' => null,
            'quantity' => 2,
            'unit_price' => 100,
            'subtotal' => 200,
        ];

        $component->set('data.items', $items)
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_items_placed_with_the_order_are_not_upsells(): void
    {
        $order = $this->createOrder();

        $this->assertFalse($order->items()->sole()->is_upsell);
    }

    public function test_an_item_added_after_the_order_was_created_is_an_upsell(): void
    {
        $order = $this->createOrder();

        $this->addItemAfterCreation($order);

        $this->assertFalse($order->items()->where('product_id', $this->first->id)->sole()->is_upsell);
        $this->assertTrue($order->items()->where('product_id', $this->second->id)->sole()->is_upsell);
        $this->assertSame(8, $this->second->refresh()->stock);
        $this->assertSame(300.0, (float) $order->refresh()->subtotal);
    }

    public function test_editing_an_existing_line_does_not_make_it_an_upsell(): void
    {
        $order = $this->createOrder();

        $component = Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()]);
        $itemKey = array_key_first($component->get('data.items'));
        $component->set("data.items.{$itemKey}.quantity", 3)->call('save')->assertHasNoFormErrors();

        $this->assertSame(3, $order->items()->sole()->quantity);
        $this->assertFalse($order->items()->sole()->is_upsell);
    }

    public function test_the_upsell_tag_shows_in_the_admin_panel(): void
    {
        $order = $this->createOrder();
        $this->addItemAfterCreation($order);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])->assertSee(__('Upsell'));
        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertSee(__('Upsell'));
    }

    public function test_the_admin_panel_shows_no_upsell_tag_without_an_upsell(): void
    {
        $order = $this->createOrder();

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])->assertDontSee(__('Upsell'));
    }

    public function test_the_customer_invoice_never_shows_the_upsell_tag(): void
    {
        $order = $this->createOrder();
        $this->addItemAfterCreation($order);

        $this->get(route('orders.print', $order))
            ->assertOk()
            ->assertSee('Second Product')
            ->assertDontSee(__('Upsell'));
    }
}
