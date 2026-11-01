<?php

namespace Tests\Feature;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Livewire\AdminBottomNavigation;
use App\Models\Account;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CompanyContext;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The phone bottom bar of the admin panel (owner request 2026-11-01):
 * Dashboard and the company switch on the left, a raised "+" in the middle,
 * Orders and Products on the right. The "+" opens a menu of four quick-create
 * popups — order, product, purchase, expense — that fill in the real forms.
 */
class AdminBottomNavigationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Bar Co', 'slug' => 'bar-co', 'invoice_prefix' => 'BAR',
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
        $this->withSession(['current_company_id' => $this->company->getKey()]);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_the_bar_is_on_admin_pages_for_phone_widths_only(): void
    {
        $this->superAdmin();

        $html = $this->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString('data-admin-bottom-nav', $html);
        // Only below the large breakpoint, where the sidebar is hidden.
        $this->assertMatchesRegularExpression('/<nav[^>]*zz-bottom-nav[^>]*lg:hidden/s', $html);
    }

    public function test_the_popups_live_inside_the_livewire_component_not_beside_it(): void
    {
        $this->superAdmin();

        $html = $this->get('/admin')->assertOk()->getContent();

        // Livewire takes the first element of a component's view as its root.
        // When that was the <nav> (an @if around the whole view), the popups
        // drawn next to it sat outside the component and never appeared in the
        // browser — Livewire's own tests cannot see that, only the markup can.
        $this->assertMatchesRegularExpression('/<div[^>]*wire:id="[^"]+"[^>]*data-admin-bottom-nav-root/s', $html);
        $this->assertDoesNotMatchRegularExpression('/<nav[^>]*wire:id=/s', $html);

        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($document);

        $root = $xpath->query('//div[@data-admin-bottom-nav-root]');
        $this->assertSame(1, $root->length);
        $this->assertSame(1, $xpath->query('.//*[@*[name()="wire:partial"]="action-modals"]', $root->item(0))->length, "The popups' partial should sit inside the bar component.");
    }

    public function test_guests_do_not_get_the_bar(): void
    {
        $html = $this->get('/admin/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-admin-bottom-nav', $html);
    }

    public function test_tabs_link_to_dashboard_orders_and_products_in_the_right_order(): void
    {
        $this->superAdmin();

        $html = $this->get('/admin')->assertOk()->getContent();

        $links = [
            'dashboard' => Dashboard::getUrl(panel: 'admin'),
            'orders' => OrderResource::getUrl('index', panel: 'admin'),
            'products' => ProductResource::getUrl('index', panel: 'admin'),
        ];

        foreach ($links as $key => $url) {
            $this->assertMatchesRegularExpression(
                '/<a\s+href="'.preg_quote($url, '/').'"[^>]*data-bottom-nav="'.$key.'"/s',
                $html,
                "The {$key} tab should link to {$url}",
            );
        }

        // Left: dashboard, company switch · middle: "+" · right: orders, products.
        $positions = array_map(
            fn (string $key): int|false => strpos($html, 'data-bottom-nav="'.$key.'"'),
            ['dashboard', 'company', 'quick-create', 'orders', 'products'],
        );
        $this->assertNotContains(false, $positions, 'Every tab should be drawn.');
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Tabs should run dashboard, company, +, orders, products.');
    }

    public function test_the_tab_of_the_current_page_is_highlighted(): void
    {
        $this->superAdmin();

        $dashboard = $this->get('/admin')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*data-bottom-nav="dashboard"/s', $dashboard);
        $this->assertDoesNotMatchRegularExpression('/aria-current="page"[^>]*data-bottom-nav="orders"/s', $dashboard);

        $orders = $this->get(OrderResource::getUrl('index', panel: 'admin'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*data-bottom-nav="orders"/s', $orders);
        $this->assertDoesNotMatchRegularExpression('/aria-current="page"[^>]*data-bottom-nav="dashboard"/s', $orders);
    }

    public function test_the_plus_menu_lists_the_four_quick_creates(): void
    {
        $this->superAdmin();

        $html = $this->get('/admin')->assertOk()->getContent();

        foreach (['createOrder', 'createProduct', 'createPurchase', 'createExpense'] as $name) {
            $this->assertStringContainsString('data-quick-create="'.$name.'"', $html);
        }
    }

    public function test_the_company_switch_is_drawn_for_a_user_with_several_companies_only(): void
    {
        $user = $this->superAdmin();
        $other = Company::query()->create([
            'name' => 'Second Co', 'slug' => 'second-co', 'invoice_prefix' => 'SEC',
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);

        $html = $this->get('/admin')->assertOk()->getContent();
        $this->assertStringContainsString('data-bottom-nav="company"', $html);
        $this->assertStringContainsString('Second Co', $html);
        $this->assertStringContainsString('name="company_id" value="all"', $html);

        // A staff member who can reach one company has nothing to switch.
        $staff = User::factory()->create(['role' => 'manager', 'is_active' => true]);
        $staff->companies()->sync([$this->company->getKey() => ['role' => 'manager', 'is_default' => true]]);
        $this->actingAs($staff);
        app(CompanyContext::class)->set($this->company);

        $html = $this->get('/admin')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-bottom-nav="company"', $html);

        $this->assertNotSame($user->getKey(), $staff->getKey());
        $this->assertNotNull($other->getKey());
    }

    public function test_the_expense_popup_creates_an_expense_and_goes_to_the_expense_list(): void
    {
        $this->superAdmin();
        $account = Account::query()->create(['name' => 'Cash', 'type' => 'cash', 'opening_balance' => 500, 'is_active' => true]);
        $category = ExpenseCategory::query()->create(['name' => 'Rent', 'slug' => 'rent', 'is_active' => true]);

        Livewire::test(AdminBottomNavigation::class)
            ->callAction('createExpense', data: [
                'expense_category_id' => $category->getKey(),
                'account_id' => $account->getKey(),
                'expense_date' => now()->toDateString(),
                'amount' => 120,
            ])
            ->assertRedirect(ExpenseResource::getUrl('index', panel: 'admin'));

        $expense = Expense::query()->sole();
        $this->assertSame('120.00', $expense->amount);
        $this->assertSame($this->company->getKey(), $expense->company_id);
    }

    public function test_the_expense_popup_validates_its_fields(): void
    {
        $this->superAdmin();

        Livewire::test(AdminBottomNavigation::class)
            ->callAction('createExpense', data: ['amount' => null])
            ->assertHasActionErrors(['expense_category_id' => 'required', 'account_id' => 'required', 'amount' => 'required']);

        $this->assertSame(0, Expense::query()->count());
    }

    public function test_the_product_popup_creates_a_product_with_its_opening_stock(): void
    {
        $this->superAdmin();
        $category = Category::query()->create(['name' => 'Lamps', 'slug' => 'lamps', 'is_active' => true]);

        Livewire::test(AdminBottomNavigation::class)
            ->callAction('createProduct', data: [
                'category_id' => $category->getKey(),
                'name' => 'Quick Lamp',
                'sku' => 'QUICK-LAMP',
                'sale_price' => 250,
                'cost_price' => 150,
                'stock' => 7,
                'unit' => 'pcs',
                'reorder_level' => 1,
                'vat_rate' => 0,
                'is_active' => true,
                'status' => Product::STATUS_AVAILABLE,
            ])
            ->assertRedirect(ProductResource::getUrl('index', panel: 'admin'));

        $product = Product::query()->where('sku', 'QUICK-LAMP')->sole();
        $this->assertSame(250.0, (float) $product->price);
        $this->assertSame(7, (int) $product->fresh()->stock);
    }

    public function test_the_purchase_popup_creates_a_purchase_with_its_items(): void
    {
        $this->superAdmin();
        $supplier = Supplier::query()->create(['name' => 'Quick Supplier']);
        $product = Product::query()->create(['name' => 'Bought Thing', 'sku' => 'BOUGHT-1', 'price' => 100, 'sale_price' => 100, 'stock' => 0]);

        Livewire::test(AdminBottomNavigation::class)
            ->callAction('createPurchase', data: [
                'supplier_id' => $supplier->getKey(),
                'purchase_date' => now()->toDateString(),
                'status' => 'draft',
                'items' => [
                    ['product_id' => $product->getKey(), 'quantity' => 3, 'unit_cost' => 40],
                ],
            ])
            ->assertRedirect(PurchaseResource::getUrl('index', panel: 'admin'));

        $purchase = Purchase::query()->sole();
        $this->assertSame($supplier->getKey(), $purchase->supplier_id);
        $this->assertSame(3, PurchaseItem::query()->where('purchase_id', $purchase->getKey())->sole()->quantity);
    }

    public function test_the_order_popup_creates_an_order_with_its_items(): void
    {
        $this->superAdmin();
        $customer = Customer::query()->create(['name' => 'Quick Buyer', 'phone' => '01711111111', 'opening_balance' => 0, 'is_active' => true]);
        $product = Product::query()->create(['name' => 'Sold Thing', 'sku' => 'SOLD-1', 'price' => 100, 'sale_price' => 100, 'stock' => 0, 'is_active' => true, 'status' => Product::STATUS_AVAILABLE]);

        Livewire::test(AdminBottomNavigation::class)
            ->callAction('createOrder', data: [
                'customer_id' => $customer->getKey(),
                'order_date' => now()->toDateString(),
                'status' => Order::STATUS_DRAFT,
                'delivery_status' => 'not_booked',
                'items' => [
                    ['product_id' => $product->getKey(), 'quantity' => 2, 'unit_price' => 100],
                ],
            ])
            ->assertRedirect(OrderResource::getUrl('index', panel: 'admin'));

        $order = Order::query()->sole();
        $this->assertSame($customer->getKey(), $order->customer_id);
        $this->assertSame(1, $order->items()->count());
    }

    public function test_the_order_popup_stops_when_a_confirmed_order_is_short_of_stock(): void
    {
        $this->superAdmin();
        $customer = Customer::query()->create(['name' => 'Short Buyer', 'phone' => '01722222222', 'opening_balance' => 0, 'is_active' => true]);
        $product = Product::query()->create(['name' => 'Scarce Thing', 'sku' => 'SCARCE-1', 'price' => 100, 'sale_price' => 100, 'stock' => 0, 'is_active' => true, 'status' => Product::STATUS_AVAILABLE]);

        Livewire::test(AdminBottomNavigation::class)
            ->callAction('createOrder', data: [
                'customer_id' => $customer->getKey(),
                'order_date' => now()->toDateString(),
                'status' => Order::STATUS_CONFIRMED,
                'delivery_status' => 'not_booked',
                'items' => [
                    ['product_id' => $product->getKey(), 'quantity' => 5, 'unit_price' => 100],
                ],
            ])
            ->assertNotified('Not enough stock');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_user_who_cannot_create_a_record_cannot_open_its_popup(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $viewer->companies()->sync([$this->company->getKey() => ['role' => 'viewer', 'is_default' => true]]);
        $this->actingAs($viewer);

        $this->assertFalse(ExpenseResource::canCreate(), 'Precondition: the viewer cannot create expenses.');

        Livewire::test(AdminBottomNavigation::class)
            ->assertActionHidden('createExpense')
            ->assertActionHidden('createOrder');

        $this->assertSame(0, Expense::query()->count());
    }
}
