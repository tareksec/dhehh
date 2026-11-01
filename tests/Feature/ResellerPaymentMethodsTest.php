<?php

namespace Tests\Feature;

use App\Filament\Pages\ResellerSettings;
use App\Filament\Resources\StorefrontPaymentMethods\Pages\ListStorefrontPaymentMethods;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\StorefrontPayment;
use App\Models\StorefrontPaymentMethod;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\ResellerOwnCourierService;
use App\Services\StorefrontCart;
use App\Services\StorefrontCheckoutService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner decision (2026-10-12): the payment methods a reseller sends an
 * own-courier advance to are chosen by the admin from the store's list, and
 * Reseller Settings can add methods that exist only for the reseller portal.
 * Changing the store's list never changes the reseller-only ones, and the
 * reseller-only ones are never offered at the storefront.
 */
class ResellerPaymentMethodsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected StorefrontPaymentMethod $bkash;

    protected StorefrontPaymentMethod $nagad;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->company = $this->makeCompany('pay-co.example.test', 'PAY');
        app(CompanyContext::class)->set($this->company);
        $this->bkash = $this->storeMethod($this->company, 'bKash', '01800000001', 1);
        $this->nagad = $this->storeMethod($this->company, 'Nagad', '01800000002', 2);
    }

    public function test_until_a_choice_is_saved_every_active_store_method_is_offered_and_ticked(): void
    {
        $inactive = $this->storeMethod($this->company, 'Rocket', '01800000003', 3);
        $inactive->update(['is_active' => false]);
        $this->asAdmin();

        $this->assertSame([$this->bkash->getKey(), $this->nagad->getKey()], $this->offered());

        Livewire::test(ResellerSettings::class)
            ->assertFormSet(['reseller_payment_method_ids' => [$this->bkash->getKey(), $this->nagad->getKey()]])
            ->assertSee('bKash — 01800000001')
            ->assertDontSee('Rocket');
    }

    public function test_the_admin_chooses_which_store_methods_resellers_can_use(): void
    {
        $this->asAdmin();

        Livewire::test(ResellerSettings::class)
            ->fillForm(['reseller_payment_method_ids' => [$this->nagad->getKey()]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$this->nagad->getKey()], $this->company->fresh()->storefrontSetting->reseller_payment_method_ids);
        $this->assertSame([$this->nagad->getKey()], $this->offered());

        // A method added to the store later stays hidden until it is ticked.
        $rocket = $this->storeMethod($this->company, 'Rocket', '01800000003', 3);
        $this->assertSame([$this->nagad->getKey()], $this->offered());

        Livewire::test(ResellerSettings::class)
            ->assertFormSet(['reseller_payment_method_ids' => [$this->nagad->getKey()]])
            ->fillForm(['reseller_payment_method_ids' => [$this->nagad->getKey(), $rocket->getKey()]])
            ->call('save');
        $this->assertSame([$this->nagad->getKey(), $rocket->getKey()], $this->offered());

        // Ticking nothing means no store method (not "everything").
        Livewire::test(ResellerSettings::class)->fillForm(['reseller_payment_method_ids' => []])->call('save');
        $this->assertSame([], $this->offered());
    }

    public function test_a_store_method_in_trash_is_not_offered_to_resellers_and_returns_on_restore(): void
    {
        $this->asAdmin();
        $this->assertSame([$this->bkash->getKey(), $this->nagad->getKey()], $this->offered());

        $this->bkash->delete();

        $this->assertSame([$this->nagad->getKey()], $this->offered());

        $this->bkash->restore();

        $this->assertSame([$this->bkash->getKey(), $this->nagad->getKey()], $this->offered());
    }

    public function test_a_deactivated_store_method_is_not_offered_even_when_ticked(): void
    {
        $this->asAdmin();
        Livewire::test(ResellerSettings::class)->fillForm(['reseller_payment_method_ids' => [$this->bkash->getKey()]])->call('save');
        $this->assertSame([$this->bkash->getKey()], $this->offered());

        $this->bkash->update(['is_active' => false]);

        $this->assertSame([], $this->offered());
    }

    public function test_only_real_store_methods_of_this_company_can_be_saved_as_the_choice(): void
    {
        $other = $this->makeCompany('other-pay.example.test', 'OTH');
        $foreign = $this->storeMethod($other, 'Foreign bKash', '01999999999', 1);
        $resellerOnly = $this->resellerOnlyMethod($this->company, 'Private', '01700000009');
        $this->asAdmin();

        // A forged id (another company's method, a reseller-only one, one that does not exist) is refused by the form...
        Livewire::test(ResellerSettings::class)
            ->fillForm(['reseller_payment_method_ids' => [$this->bkash->getKey(), $foreign->getKey(), $resellerOnly->getKey(), 987654]])
            ->call('save')
            ->assertHasErrors();
        $this->assertNull($this->company->fresh()->storefrontSetting->reseller_payment_method_ids);

        // ...and a good choice is saved as a plain list of ids.
        Livewire::test(ResellerSettings::class)
            ->fillForm(['reseller_payment_method_ids' => [$this->bkash->getKey()]])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame([$this->bkash->getKey()], $this->company->fresh()->storefrontSetting->reseller_payment_method_ids);
    }

    public function test_the_admin_adds_reseller_only_methods_and_a_second_save_updates_them_in_place(): void
    {
        $this->asAdmin();

        Livewire::test(ResellerSettings::class)
            ->fillForm(['reseller_only_payment_methods' => [
                ['id' => null, 'name' => 'bKash', 'account_number' => '01911111111', 'instructions' => 'Send money, then enter the ID', 'is_active' => true],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $method = StorefrontPaymentMethod::withoutGlobalScopes()->where('reseller_only', true)->sole();
        $this->assertSame($this->company->getKey(), $method->company_id);
        $this->assertSame(StorefrontPaymentMethod::TYPE_MANUAL, $method->type);
        $this->assertSame('01911111111', $method->account_number);
        // The same name as a store method is fine: it is a different number for resellers.
        $this->assertSame('bKash', $this->bkash->fresh()->name);
        $this->assertContains($method->getKey(), $this->offered());

        // Saving again from the same page edits the row instead of adding another one.
        $page = Livewire::test(ResellerSettings::class);
        $page->assertFormSet(fn (array $state): bool => $state['reseller_only_payment_methods'][array_key_first($state['reseller_only_payment_methods'])]['id'] === $method->getKey());
        $page->fillForm(['reseller_only_payment_methods' => [
            ['id' => $method->getKey(), 'name' => 'bKash Agent', 'account_number' => '01922222222', 'instructions' => null, 'is_active' => true],
        ]])->call('save');

        $this->assertSame(1, StorefrontPaymentMethod::withoutGlobalScopes()->where('reseller_only', true)->count());
        $this->assertSame('bKash Agent', $method->fresh()->name);
        $this->assertSame('01922222222', $method->fresh()->account_number);

        // Switched off: not offered.
        Livewire::test(ResellerSettings::class)->fillForm(['reseller_only_payment_methods' => [
            ['id' => $method->getKey(), 'name' => 'bKash Agent', 'account_number' => '01922222222', 'instructions' => null, 'is_active' => false],
        ]])->call('save');
        $this->assertNotContains($method->getKey(), $this->offered());
    }

    public function test_the_same_name_twice_among_reseller_only_methods_is_refused(): void
    {
        $this->asAdmin();

        Livewire::test(ResellerSettings::class)
            ->fillForm(['reseller_only_payment_methods' => [
                ['id' => null, 'name' => 'bKash', 'account_number' => '01911111111', 'instructions' => null, 'is_active' => true],
                ['id' => null, 'name' => ' BKASH ', 'account_number' => '01922222222', 'instructions' => null, 'is_active' => true],
            ]])
            ->call('save')
            ->assertHasErrors(['data.reseller_only_payment_methods']);

        $this->assertSame(0, StorefrontPaymentMethod::withoutGlobalScopes()->where('reseller_only', true)->count());
    }

    public function test_removing_a_reseller_only_method_deletes_it_unless_a_payment_used_it(): void
    {
        $unused = $this->resellerOnlyMethod($this->company, 'Unused', '01700000001');
        $used = $this->resellerOnlyMethod($this->company, 'Used', '01700000002');
        $order = $this->makeOrder($this->company);
        StorefrontPayment::query()->create([
            'company_id' => $this->company->getKey(), 'order_id' => $order->getKey(), 'gateway' => 'manual',
            'storefront_payment_method_id' => $used->getKey(), 'purpose' => StorefrontPayment::PURPOSE_RESELLER_ADVANCE,
            'amount' => 100, 'status' => StorefrontPayment::STATUS_PENDING, 'payment_method' => '01711111111', 'transaction_id' => 'TRX1',
        ]);
        $this->asAdmin();

        Livewire::test(ResellerSettings::class)
            ->fillForm(['reseller_only_payment_methods' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull(StorefrontPaymentMethod::withoutGlobalScopes()->find($unused->getKey()));
        $this->assertFalse((bool) $used->fresh()->is_active);
        $this->assertNotContains($used->getKey(), $this->offered());
        // Its payment still points at it.
        $this->assertSame($used->getKey(), StorefrontPayment::query()->sole()->storefront_payment_method_id);
    }

    public function test_changing_the_stores_list_does_not_change_the_reseller_only_methods(): void
    {
        $own = $this->resellerOnlyMethod($this->company, 'bKash (Reseller)', '01700000001');
        $this->asAdmin();

        $this->bkash->update(['name' => 'bKash Personal', 'is_active' => false]);
        $this->nagad->delete();

        $own->refresh();
        $this->assertSame('bKash (Reseller)', $own->name);
        $this->assertTrue((bool) $own->is_active);
        $this->assertSame([$own->getKey()], $this->offered());

        // The store's own Payment Methods page never lists a reseller-only channel.
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListStorefrontPaymentMethods::class)
            ->assertCanSeeTableRecords([$this->bkash])
            ->assertCanNotSeeTableRecords([$own])
            ->assertDontSee('bKash (Reseller)');
    }

    public function test_reseller_only_methods_are_never_offered_at_the_storefront(): void
    {
        $own = $this->resellerOnlyMethod($this->company, 'Reseller Rocket', '01700000555');
        $setting = $this->company->storefrontSetting;

        $names = app(StorefrontCheckoutService::class)->activePaymentMethods($setting)->pluck('name')->all();
        $this->assertContains('bKash', $names);
        $this->assertNotContains('Reseller Rocket', $names);

        $product = Product::query()->create([
            'name' => 'Shop Item', 'sku' => 'PAY-001', 'price' => 500, 'sale_price' => 500, 'cost_price' => 300, 'stock' => 10,
            'unit' => 'pcs', 'reorder_level' => 2, 'vat_rate' => 0, 'is_active' => true, 'status' => Product::STATUS_AVAILABLE,
        ]);
        app(StorefrontCart::class)->add($this->company, $product, 1);

        // The checkout page does not show it...
        $this->get('http://pay-co.example.test/checkout')
            ->assertOk()
            ->assertSee('bKash')
            ->assertDontSee('Reseller Rocket')
            ->assertDontSee('01700000555');

        // ...and a forged request that names it is rejected without making an order.
        $this->post('http://pay-co.example.test/checkout', [
            'name' => 'Sneaky Buyer', 'phone' => '01711112222', 'address' => 'Dhaka', 'delivery_area' => 'inside',
            'payment_method' => 'manual:'.$own->getKey(), 'sender_number' => '01799998888', 'trx_id' => 'TRX999',
        ])->assertSessionHasErrors('payment_method');
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_another_companys_methods_and_choices_are_never_offered(): void
    {
        $other = $this->makeCompany('other-pay.example.test', 'OTH');
        $this->resellerOnlyMethod($other, 'Other Reseller bKash', '01955555555');
        $this->storeMethod($other, 'Other Store bKash', '01966666666', 1);
        StorefrontSetting::withoutGlobalScopes()->where('company_id', $other->getKey())->firstOrFail()->forceFill(['reseller_payment_method_ids' => [$this->bkash->getKey()]])->save();

        $service = app(ResellerOwnCourierService::class);

        $this->assertSame(['Other Reseller bKash'], $service->paymentMethods($other->fresh())->pluck('name')->all());
        $this->assertSame(['bKash', 'Nagad'], $service->paymentMethods($this->company->fresh())->pluck('name')->all());
    }

    /** @return list<int> */
    protected function offered(): array
    {
        return app(ResellerOwnCourierService::class)->paymentMethods($this->company->fresh())->modelKeys();
    }

    protected function asAdmin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $this->company->getKey()]);
        app(CompanyContext::class)->set($this->company);
    }

    protected function makeCompany(string $domain, string $prefix): Company
    {
        $company = Company::query()->create([
            'name' => 'Company '.$prefix, 'slug' => str($domain)->slug()->toString(), 'domain' => $domain,
            'domain_verified' => true, 'invoice_prefix' => $prefix, 'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka', 'is_active' => true, 'reseller_module_enabled' => true,
        ])->fresh();
        StorefrontSetting::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(), 'theme_color' => '#0F766E', 'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
        ]);

        return $company->fresh();
    }

    protected function storeMethod(Company $company, string $name, string $number, int $sort): StorefrontPaymentMethod
    {
        return StorefrontPaymentMethod::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(), 'type' => StorefrontPaymentMethod::TYPE_MANUAL,
            'name' => $name, 'account_number' => $number, 'is_active' => true, 'sort_order' => $sort,
        ]);
    }

    protected function resellerOnlyMethod(Company $company, string $name, string $number): StorefrontPaymentMethod
    {
        return StorefrontPaymentMethod::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(), 'type' => StorefrontPaymentMethod::TYPE_MANUAL,
            'name' => $name, 'account_number' => $number, 'is_active' => true, 'reseller_only' => true,
        ]);
    }

    protected function makeOrder(Company $company): Order
    {
        $order = new Order(['order_date' => now(), 'status' => Order::STATUS_DRAFT, 'customer_name' => 'Test Buyer', 'total_amount' => 100, 'subtotal' => 100]);
        $order->company_id = $company->getKey();
        $order->save();

        return $order;
    }
}
