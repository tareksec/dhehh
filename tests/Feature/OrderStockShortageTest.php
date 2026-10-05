<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CompanyContext;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner report: an order with two items would not save when one of them was
 * out of stock — the stock error matched no form field, so nothing showed,
 * and the order was left half-saved. Placing a confirmed order now shows a
 * "Not enough stock" popup that can add the missing stock.
 */
class OrderStockShortageTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Product $inStock;

    private Product $outOfStock;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::query()->create([
            'name' => 'Stock Check Co',
            'slug' => 'stock-check-co',
            'invoice_prefix' => 'SCC',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);
        $this->withSession(['current_company_id' => $company->id]);

        $this->customer = Customer::query()->create([
            'name' => 'Stock Buyer',
            'phone' => '01755555555',
            'opening_balance' => 0,
            'is_active' => true,
        ]);
        $this->inStock = $this->product('In Stock Product', 10);
        $this->outOfStock = $this->product('Out Of Stock Product', 0);
    }

    private function product(string $name, int $stock): Product
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

        if ($stock > 0) {
            $product->setStockFromProductForm($stock);
        }

        return $product->refresh();
    }

    private function actingAsRole(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
    }

    private function fillOrder(string $status, int $outOfStockQuantity = 2)
    {
        return Livewire::test(CreateOrder::class)
            ->fillForm([
                'customer_id' => $this->customer->id,
                'order_date' => now()->toDateString(),
                'status' => $status,
                'delivery_status' => CourierBooking::STATUS_NOT_BOOKED,
                'items' => [
                    ['product_id' => $this->inStock->id, 'quantity' => 1, 'unit_price' => 100],
                    ['product_id' => $this->outOfStock->id, 'quantity' => $outOfStockQuantity, 'unit_price' => 100],
                ],
            ]);
    }

    public function test_confirmed_order_with_an_out_of_stock_item_shows_the_stock_popup_and_saves_nothing(): void
    {
        $this->actingAsRole('super_admin');

        $this->fillOrder(Order::STATUS_CONFIRMED)
            ->call('create')
            ->assertActionMounted('stockShortage');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, OrderItem::query()->count());
        $this->assertSame(10, $this->inStock->refresh()->stock);
    }

    public function test_adding_stock_from_the_popup_places_the_order(): void
    {
        $this->actingAsRole('super_admin');

        $this->fillOrder(Order::STATUS_CONFIRMED)
            ->call('create')
            ->assertActionMounted('stockShortage')
            ->setActionData(['add' => ['p'.$this->outOfStock->id.'v0' => 5]])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertRedirect();

        $order = Order::query()->sole();
        $this->assertSame(2, $order->items()->count());
        $this->assertSame(9, $this->inStock->refresh()->stock);
        // 5 added, 2 sold.
        $this->assertSame(3, $this->outOfStock->refresh()->stock);
        $this->assertTrue(StockMovement::query()
            ->where('product_id', $this->outOfStock->id)
            ->where('type', 'adjustment')
            ->where('quantity', 5)
            ->exists());
    }

    public function test_the_popup_will_not_add_less_than_the_shortage(): void
    {
        $this->actingAsRole('super_admin');

        $this->fillOrder(Order::STATUS_CONFIRMED, outOfStockQuantity: 3)
            ->call('create')
            ->setActionData(['add' => ['p'.$this->outOfStock->id.'v0' => 1]])
            ->callMountedAction()
            ->assertHasActionErrors(['add.p'.$this->outOfStock->id.'v0']);

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, $this->outOfStock->refresh()->stock);
    }

    public function test_staff_without_stock_permission_cannot_add_stock_from_the_popup(): void
    {
        $this->actingAsRole('sales_staff');

        $this->fillOrder(Order::STATUS_CONFIRMED)
            ->call('create')
            ->assertActionMounted('stockShortage')
            ->callMountedAction();

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, StockMovement::query()->where('product_id', $this->outOfStock->id)->count());
    }

    public function test_a_draft_order_does_not_need_stock(): void
    {
        $this->actingAsRole('super_admin');

        $this->fillOrder(Order::STATUS_DRAFT)
            ->call('create')
            ->assertActionNotMounted('stockShortage')
            ->assertHasNoFormErrors();

        $this->assertSame(2, Order::query()->sole()->items()->count());
    }

    public function test_two_in_stock_items_place_a_confirmed_order(): void
    {
        $this->actingAsRole('super_admin');
        $this->outOfStock->setStockFromProductForm(4);

        $this->fillOrder(Order::STATUS_CONFIRMED)
            ->call('create')
            ->assertActionNotMounted('stockShortage')
            ->assertHasNoFormErrors();

        $this->assertSame(2, Order::query()->sole()->items()->count());
        $this->assertSame(2, $this->outOfStock->refresh()->stock);
    }

    public function test_a_stock_error_while_editing_is_shown_and_nothing_is_half_saved(): void
    {
        $this->actingAsRole('super_admin');

        $order = Order::query()->create([
            'order_number' => 'SCC-EDIT-1',
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'status' => Order::STATUS_CONFIRMED,
            'delivery_status' => CourierBooking::STATUS_NOT_BOOKED,
        ]);
        $order->items()->create(['product_id' => $this->inStock->id, 'quantity' => 1, 'unit_price' => 100]);

        $component = Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()]);
        $itemKey = array_key_first($component->get('data.items'));

        $component
            ->set("data.items.{$itemKey}.quantity", 50)
            ->call('save');

        Notification::assertNotified(__('The order could not be saved'));
        $this->assertSame(1, $order->items()->sole()->quantity);
        $this->assertSame(9, $this->inStock->refresh()->stock);
    }
}
