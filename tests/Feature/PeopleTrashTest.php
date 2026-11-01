<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\TrashedCustomers;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Filament\Resources\Resellers\Pages\ListResellers;
use App\Filament\Resources\Resellers\Pages\TrashedResellers;
use App\Filament\Resources\Resellers\ResellerResource;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Resources\Suppliers\Pages\TrashedSuppliers;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Account;
use App\Models\Broadcast;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Order;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StorefrontCustomerToken;
use App\Models\StorefrontSetting;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\AccountBalanceService;
use App\Services\CompanyContext;
use App\Services\Crm\BroadcastService;
use App\Services\CustomerAccountService;
use App\Services\CustomerCsvService;
use App\Services\StorefrontCustomerSession;
use App\Services\SupplierCsvService;
use App\Support\TypeToConfirmDelete;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Owner request 2026-11-01, phase T4a of docs/delete-and-trash-plan.md: customers
 * (resellers are customers too) and suppliers go to Trash when deleted. Their
 * orders, payments, purchases and balance are not touched; a customer in Trash
 * cannot sign in and has no store; a customer who orders again comes back.
 */
class PeopleTrashTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->company = $this->admin->defaultCompany();
        app(CompanyContext::class)->set($this->company);
        $this->session(['current_company_id' => $this->company->getKey()]);
    }

    /** @return array<string, array{0: class-string, 1: class-string, 2: string}> */
    public static function trashedResources(): array
    {
        return [
            'customers' => [CustomerResource::class, Customer::class, 'customers'],
            'resellers' => [ResellerResource::class, Customer::class, 'customers'],
            'suppliers' => [SupplierResource::class, Supplier::class, 'suppliers'],
        ];
    }

    #[DataProvider('trashedResources')]
    public function test_customers_resellers_and_suppliers_have_a_trash(string $resource, string $model, string $table): void
    {
        $this->actingAs($this->admin);

        $this->assertTrue(Schema::hasColumn($table, 'deleted_at'));
        $this->assertTrue(TypeToConfirmDelete::usesSoftDeletes($model));
        $this->assertTrue($resource::hasPage('trash'));
        $this->assertTrue($resource::canRestoreAny());
        $this->assertTrue($resource::canForceDeleteAny());
    }

    #[DataProvider('trashedResources')]
    public function test_someone_who_may_not_delete_cannot_use_the_trash(string $resource, string ...$unused): void
    {
        $staff = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);

        $this->actingAs($staff);

        $this->assertFalse($resource::canDeleteAny());
        $this->assertFalse($resource::canRestoreAny());
        $this->assertFalse($resource::canForceDeleteAny());
    }

    public function test_a_customer_goes_to_trash_by_count_and_comes_back_with_orders_payments_and_balance_untouched(): void
    {
        $account = Account::query()->create(['name' => 'Cash', 'opening_balance' => 0]);
        $customer = Customer::query()->create(['name' => 'Rahim Traders', 'phone' => '01711111111', 'opening_balance' => 20]);
        $order = $this->order($customer, 500);
        CustomerPayment::query()->create(['customer_id' => $customer->id, 'account_id' => $account->id, 'amount' => 100, 'payment_date' => now(), 'method' => 'cash']);
        $this->assertSame('420.00', $customer->refresh()->current_balance);

        Livewire::actingAs($this->admin)
            ->test(ListCustomers::class)
            ->callTableBulkAction('delete', [$customer], data: ['confirmation' => '7'])
            ->assertHasTableBulkActionErrors(['confirmation']);

        $this->assertNotSoftDeleted($customer);

        Livewire::actingAs($this->admin)
            ->test(ListCustomers::class)
            ->callTableBulkAction('delete', [$customer], data: ['confirmation' => '1'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSoftDeleted($customer);
        $this->assertNull(Customer::query()->find($customer->getKey()));

        // Nothing about the money changed, and the order still names the customer.
        $order = $order->fresh();
        $this->assertSame($customer->getKey(), $order->customer_id);
        $this->assertSame('Rahim Traders', $order->customer->name);
        $this->assertSame('420.00', Customer::withTrashed()->find($customer->getKey())->current_balance);

        // A payment taken while the customer is in Trash still moves their balance...
        CustomerPayment::query()->create(['customer_id' => $customer->id, 'account_id' => $account->id, 'amount' => 20, 'payment_date' => now(), 'method' => 'cash']);
        $this->assertSame('400.00', Customer::withTrashed()->find($customer->getKey())->current_balance);

        // ...and the list no longer shows them while the Trash does.
        Livewire::actingAs($this->admin)->test(ListCustomers::class)->assertCanNotSeeTableRecords([$customer]);

        Livewire::actingAs($this->admin)
            ->test(TrashedCustomers::class)
            ->assertCanSeeTableRecords([$customer])
            ->callTableAction('restore', $customer)
            ->assertHasNoTableActionErrors();

        $this->assertNotSoftDeleted($customer);
        $this->assertSame('400.00', $customer->fresh()->current_balance);
        $this->assertSame(1, $customer->orders()->count());
    }

    public function test_a_customer_in_trash_is_left_out_of_the_dues_total(): void
    {
        $due = Customer::query()->create(['name' => 'Owes money', 'opening_balance' => 300]);
        Customer::query()->create(['name' => 'Also owes', 'opening_balance' => 100]);

        $this->assertSame(400.0, app(AccountBalanceService::class)->customerDue($this->company->getKey()));

        $due->delete();

        $this->assertSame(100.0, app(AccountBalanceService::class)->customerDue($this->company->getKey()));

        $due->restore();

        $this->assertSame(400.0, app(AccountBalanceService::class)->customerDue($this->company->getKey()));
    }

    public function test_a_customer_in_trash_cannot_sign_in_and_an_old_login_stops_working(): void
    {
        $customer = Customer::query()->create(['name' => 'Account holder', 'phone' => '01722222222', 'is_active' => true]);
        $customer->forceFill(['password' => Hash::make('secret-pass-1')])->save();
        [, $plain] = StorefrontCustomerToken::issue($customer);

        $request = Request::create('/', 'GET', server: ['HTTP_X_CUSTOMER_TOKEN' => $plain]);
        $this->assertNotNull(app(StorefrontCustomerSession::class)->resolve($request));
        $this->assertNotNull(app(CustomerAccountService::class)->attemptLogin('01722222222', 'secret-pass-1'));

        $customer->delete();

        $this->assertNull(app(StorefrontCustomerSession::class)->resolve($request));
        $this->assertNull(app(CustomerAccountService::class)->attemptLogin('01722222222', 'secret-pass-1'));

        $customer->restore();

        $this->assertNotNull(app(StorefrontCustomerSession::class)->resolve($request));
    }

    public function test_a_reseller_in_trash_has_no_store_and_keeps_its_money_history(): void
    {
        $reseller = Customer::query()->create([
            'name' => 'Karim Store', 'phone' => '01733333333', 'reseller_status' => 'approved',
            'reseller_custom_domain' => 'shop.karim.test', 'reseller_custom_domain_verified_at' => now(),
        ]);
        $this->company->forceFill(['reseller_module_enabled' => true, 'reseller_custom_domains_enabled' => true])->save();

        $this->assertNotNull(Company::forResellerCustomDomainHost('shop.karim.test'));

        // The Resellers list deletes the same record the Customers list would.
        Livewire::actingAs($this->admin)
            ->test(ListResellers::class)
            ->callTableBulkAction('delete', [$reseller], data: ['confirmation' => '1'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSoftDeleted($reseller);
        $this->assertNull(Company::forResellerCustomDomainHost('shop.karim.test'), 'a reseller in Trash serves no store');

        Livewire::actingAs($this->admin)
            ->test(TrashedResellers::class)
            ->assertCanSeeTableRecords([$reseller])
            ->callTableAction('restore', $reseller)
            ->assertHasNoTableActionErrors();

        $this->assertNotNull(Company::forResellerCustomDomainHost('shop.karim.test'));
    }

    public function test_a_customer_cannot_be_restored_while_another_customer_uses_the_same_phone(): void
    {
        $old = Customer::query()->create(['name' => 'Old record', 'phone' => '01744444444']);
        $old->delete();
        $new = Customer::query()->create(['name' => 'Signed up again', 'phone' => '01744444444']);

        Livewire::actingAs($this->admin)
            ->test(TrashedCustomers::class)
            ->callTableAction('restore', $old);

        $this->assertSoftDeleted($old);

        $new->delete();
        $new->forceDelete();

        Livewire::actingAs($this->admin)
            ->test(TrashedCustomers::class)
            ->callTableAction('restore', $old)
            ->assertHasNoTableActionErrors();

        $this->assertNotSoftDeleted($old);
    }

    public function test_a_customer_in_trash_who_orders_again_comes_back_and_is_not_duplicated(): void
    {
        Queue::fake();
        [$company, $token] = $this->nextjsCompany();
        app(CompanyContext::class)->set($company);

        $customer = Customer::query()->create(['name' => 'Returning', 'phone' => '01712345678', 'opening_balance' => 0]);
        $customer->delete();

        Product::query()->create([
            'name' => 'Item', 'sku' => 'item', 'price' => 500, 'sale_price' => 500, 'cost_price' => 300, 'stock' => 10,
            'unit' => 'pcs', 'weight_kg' => 1, 'reorder_level' => 1, 'vat_rate' => 0, 'is_active' => true, 'status' => Product::STATUS_AVAILABLE,
        ]);

        $this->postJson('/api/v1/storefront/checkout', [
            'items' => [['slug' => 'item', 'quantity' => 1]],
            'name' => 'Returning',
            'phone' => '01712345678',
            'address' => 'House 1, Dhanmondi, Dhaka',
            'payment_method' => 'cod',
            'return_url' => 'https://zamzamint.com/checkout/payment/return',
            'cancel_url' => 'https://zamzamint.com/cart',
        ], ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])->assertCreated();

        $this->assertSame(1, Customer::withTrashed()->where('phone', '01712345678')->count(), 'the order must not create a second customer');
        $this->assertNotSoftDeleted($customer);
        $this->assertSame($customer->getKey(), Order::query()->sole()->customer_id);
    }

    public function test_a_live_customer_is_preferred_over_one_in_trash_and_nothing_is_restored_without_a_match(): void
    {
        $old = Customer::query()->create(['name' => 'Old', 'phone' => '01755555555']);
        $old->delete();
        $live = Customer::query()->create(['name' => 'Live', 'phone' => '01755555555']);

        $found = Customer::matchOrRestore(fn ($query) => $query->where('phone', '01755555555'));

        $this->assertTrue($found->is($live));
        $this->assertSoftDeleted($old);
        $this->assertNull(Customer::matchOrRestore(fn ($query) => $query->where('phone', '01799999999')));
        $this->assertTrue(Customer::matchIncludingTrash(fn ($query) => $query->where('phone', '01755555555'))->is($live));

        $live->forceDelete();
        $this->assertTrue(Customer::matchIncludingTrash(fn ($query) => $query->where('phone', '01755555555'))->is($old), 'looking is not restoring');
        $this->assertSoftDeleted($old);
    }

    public function test_a_csv_import_row_for_a_customer_in_trash_brings_them_back_instead_of_adding_a_second(): void
    {
        Storage::fake('local');
        $customer = Customer::query()->create(['name' => 'Old name', 'phone' => '+8801766666666', 'email' => 'back@example.com', 'customer_type' => 'regular']);
        $customer->delete();

        $path = Storage::disk('local')->path('customers.csv');
        file_put_contents($path, implode(PHP_EOL, [
            implode(',', CustomerCsvService::HEADINGS),
            'New name,+8801766666666,back@example.com,Dealer,WhatsApp Lead,0,yes,"Dhaka"',
        ]));

        $result = app(CustomerCsvService::class)->import($path);

        $this->assertSame(['created' => 0, 'updated' => 1], $result);
        $this->assertSame(1, Customer::withTrashed()->count());
        $this->assertNotSoftDeleted($customer);
        $this->assertSame('New name', $customer->fresh()->name);
    }

    public function test_a_broadcast_never_goes_to_a_customer_in_trash(): void
    {
        Customer::query()->create(['name' => 'Kept', 'phone' => '8801811110001', 'opening_balance' => 0, 'is_active' => true]);
        Customer::query()->create(['name' => 'Deleted', 'phone' => '8801811110002', 'opening_balance' => 0, 'is_active' => true])->delete();

        $broadcast = Broadcast::query()->create([
            'name' => 'All customers', 'audience_type' => 'customers', 'channel' => 'sms', 'sms_body' => 'Hi {{name}}', 'status' => 'draft',
        ]);

        $this->assertSame(1, app(BroadcastService::class)->buildRecipients($broadcast));
    }

    public function test_a_supplier_goes_to_trash_and_its_purchases_payments_and_balance_stay(): void
    {
        $account = Account::query()->create(['name' => 'Cash', 'opening_balance' => 1000]);
        $supplier = Supplier::query()->create(['name' => 'Gulshan Imports', 'phone' => '01899999999', 'opening_balance' => 50]);
        $purchase = Purchase::query()->create([
            'supplier_id' => $supplier->id, 'purchase_date' => now(), 'total_amount' => 400, 'due_amount' => 400, 'status' => 'received',
        ]);
        $purchase->forceFill(['due_amount' => 400])->saveQuietly();
        $supplier->syncCurrentBalance();
        $this->assertSame('450.00', $supplier->refresh()->current_balance);

        Livewire::actingAs($this->admin)
            ->test(ListSuppliers::class)
            ->callTableBulkAction('delete', [$supplier], data: ['confirmation' => '1'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSoftDeleted($supplier);
        $this->assertSame('Gulshan Imports', $purchase->fresh()->supplier->name, 'the purchase still names its supplier');

        // A payment while the supplier is in Trash still lowers what is owed.
        SupplierPayment::query()->create(['supplier_id' => $supplier->id, 'account_id' => $account->id, 'amount' => 100, 'payment_date' => now()]);
        $this->assertSame('350.00', Supplier::withTrashed()->find($supplier->getKey())->current_balance);

        Livewire::actingAs($this->admin)
            ->test(TrashedSuppliers::class)
            ->assertCanSeeTableRecords([$supplier])
            ->callTableAction('restore', $supplier)
            ->assertHasNoTableActionErrors();

        $this->assertNotSoftDeleted($supplier);
        $this->assertSame('350.00', $supplier->fresh()->current_balance);
    }

    public function test_a_supplier_csv_row_for_a_supplier_in_trash_brings_them_back(): void
    {
        Storage::fake('local');
        $supplier = Supplier::query()->create(['name' => 'Old supplier', 'email' => 'sup@example.com', 'phone' => '01877777777']);
        $supplier->delete();

        $path = Storage::disk('local')->path('suppliers.csv');
        file_put_contents($path, implode(PHP_EOL, [
            implode(',', SupplierCsvService::HEADINGS),
            'Renamed supplier,Gulshan Co,01877777777,sup@example.com,0,yes,"Dhaka"',
        ]));

        app(SupplierCsvService::class)->import($path);

        $this->assertSame(1, Supplier::withTrashed()->count());
        $this->assertNotSoftDeleted($supplier);
        $this->assertSame('Renamed supplier', $supplier->fresh()->name);
    }

    public function test_an_order_and_a_purchase_still_show_a_party_that_is_in_trash_when_opened_for_editing(): void
    {
        $customer = Customer::query()->create(['name' => 'Gone Customer', 'phone' => '01700000001', 'is_active' => true]);
        $order = Order::query()->create(['customer_id' => $customer->id, 'subtotal' => 100, 'total_amount' => 100, 'due_amount' => 100, 'status' => 'draft']);
        $customer->delete();

        $form = Livewire::actingAs($this->admin)->test(EditOrder::class, ['record' => $order->getRouteKey()]);
        $form->assertFormSet(['customer_id' => $customer->id]);
        $this->assertSame('Gone Customer (01700000001)', $form->instance()->getSchema('form')->getFlatComponents()['customer_id']->getOptionLabel());

        $supplier = Supplier::query()->create(['name' => 'Gone Supplier', 'is_active' => true]);
        $purchase = Purchase::query()->create(['supplier_id' => $supplier->id, 'purchase_date' => now(), 'total_amount' => 100, 'due_amount' => 100, 'status' => 'pending']);
        $supplier->delete();

        $form = Livewire::actingAs($this->admin)->test(EditPurchase::class, ['record' => $purchase->getRouteKey()]);
        $form->assertFormSet(['supplier_id' => $supplier->id]);
        $this->assertSame('Gone Supplier', $form->instance()->getSchema('form')->getFlatComponents()['supplier_id']->getOptionLabel());
    }

    private function order(Customer $customer, float $total): Order
    {
        $order = Order::query()->create([
            'customer_id' => $customer->id, 'subtotal' => $total, 'total_amount' => $total, 'due_amount' => $total, 'status' => 'completed',
        ]);
        $order->forceFill(['subtotal' => $total, 'total_amount' => $total, 'due_amount' => $total])->saveQuietly();
        $customer->syncCurrentBalance();

        return $order;
    }

    /** @return array{0: Company, 1: string} */
    private function nextjsCompany(): array
    {
        $company = Company::query()->create([
            'name' => 'ZamZam International', 'slug' => 'zamzam-international', 'domain' => 'offer.example.test', 'domain_verified' => true,
            'invoice_prefix' => 'ZAM', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(), 'site_type' => StorefrontSetting::SITE_NEXTJS, 'is_published' => true,
            'nextjs_site_enabled' => true, 'nextjs_site_domain' => 'zamzamint.com', 'new_customer_delivery_advance_enabled' => false,
            'delivery_first_kg_inside' => 70, 'delivery_first_kg_outside' => 110, 'delivery_additional_per_kg' => 20,
        ]);

        [, $token] = CompanyApiKey::generate($company);

        return [$company->fresh(), $token];
    }
}
