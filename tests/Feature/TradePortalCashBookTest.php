<?php

namespace Tests\Feature;

use App\Filament\Trade\Pages\CashBook;
use App\Filament\Trade\Resources\MoneyAccounts\Pages\CreateMoneyAccount;
use App\Http\Middleware\SetTradePortalLocale;
use App\Models\Account;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Trade Portal phase 3 (BUILD-BRIEF.md): the cash book, the balance sheet and the cash/bank accounts pages. */
class TradePortalCashBookTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Cash Co', 'slug' => 'cash-'.uniqid(), 'invoice_prefix' => 'CB'.random_int(1000, 9999),
            'is_active' => true, 'trade_portal_enabled' => true,
        ]);
        $this->owner = User::factory()->create(['role' => 'trade_owner', 'is_active' => true]);
        $this->owner->companies()->attach($this->company->getKey(), ['role' => 'trade_owner', 'is_default' => true]);
    }

    protected function inPortal(): void
    {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('trade'));
        app(CompanyContext::class)->set($this->company);
    }

    protected function english(): array
    {
        return [SetTradePortalLocale::SESSION_KEY => 'en'];
    }

    public function test_the_owner_gets_the_cash_book_and_its_pages_open(): void
    {
        $permissions = UserRole::query()->where('slug', 'trade_owner')->value('permissions');
        $this->assertContains('trade.cashbook', $permissions);
        $this->assertContains('trade.money_accounts', $permissions);
        $this->assertTrue($this->owner->isTradePortalOnly(), 'The new permissions keep the Trade Owner out of /admin.');

        $this->actingAs($this->owner)->withSession($this->english())->get('/trade/cash-book')
            ->assertOk()->assertSee('Add your cash box and bank accounts first');

        foreach (['/trade/balance-sheet', '/trade/accounts', '/trade/accounts/create'] as $url) {
            $this->actingAs($this->owner)->get($url)->assertOk();
        }
    }

    public function test_without_the_permission_the_pages_are_closed(): void
    {
        UserRole::query()->create(['name' => 'Clerk', 'slug' => 'trade_clerk', 'permissions' => ['trade.access', 'trade.sales'], 'is_active' => true]);
        $clerk = User::factory()->create(['role' => 'trade_clerk', 'is_active' => true]);
        $clerk->companies()->attach($this->company->getKey(), ['role' => 'trade_clerk', 'is_default' => true]);

        foreach (['/trade/cash-book', '/trade/balance-sheet', '/trade/accounts'] as $url) {
            $this->actingAs($clerk)->get($url)->assertForbidden();
        }

        $this->actingAs($clerk)->withSession($this->english())->get('/trade')->assertOk()->assertDontSee('Cash and bank now');
    }

    public function test_accounts_are_added_and_the_book_records_a_deposit_a_supplier_payment_and_a_cost(): void
    {
        $this->inPortal();

        Livewire::test(CreateMoneyAccount::class)
            ->fillForm(['name' => 'Cash box', 'type' => 'cash', 'opening_balance' => 100000])
            ->call('create')->assertHasNoFormErrors();
        Livewire::test(CreateMoneyAccount::class)
            ->fillForm(['name' => 'DBBL', 'type' => 'bank', 'opening_balance' => 0])
            ->call('create')->assertHasNoFormErrors();

        $cash = Account::query()->where('name', 'Cash box')->firstOrFail();
        $bank = Account::query()->where('name', 'DBBL')->firstOrFail();
        $this->assertSame(100000.0, (float) $cash->current_balance);
        $supplier = Supplier::query()->create(['name' => 'Hengli', 'opening_balance' => 50000]);
        $today = now()->toDateString();

        Livewire::test(CashBook::class)
            ->callAction('moveMoney', ['from_account_id' => $cash->getKey(), 'to_account_id' => $bank->getKey(), 'amount' => 60000, 'date' => $today])
            ->assertHasNoActionErrors()
            ->callAction('paySupplier', ['supplier_id' => $supplier->getKey(), 'amount' => 20000, 'date' => $today, 'account_id' => $bank->getKey()])
            ->assertHasNoActionErrors()
            ->callAction('recordCost', ['new_category' => 'Truck fare', 'amount' => 1500, 'date' => $today, 'account_id' => $cash->getKey()])
            ->assertHasNoActionErrors()
            ->assertSee('Moved to DBBL')
            ->assertSee('Truck fare');

        $this->assertSame(38500.0, (float) $cash->refresh()->current_balance);
        $this->assertSame(40000.0, (float) $bank->refresh()->current_balance);
        $this->assertSame(30000.0, (float) $supplier->refresh()->current_balance);
        $this->assertSame(1, Expense::query()->count());

        Livewire::test(CashBook::class)
            ->call('showMode', 'month')
            ->assertSee('Truck fare')
            ->call('step', 1)
            ->assertSet('date', $today);

        $this->actingAs($this->owner)->withSession($this->english())->get('/trade')
            ->assertOk()->assertSee('Cash and bank now');
        $this->actingAs($this->owner)->withSession($this->english())->get('/trade/balance-sheet')
            ->assertOk()->assertSee('What is left (your capital)');
    }

    public function test_a_cost_bigger_than_the_account_is_refused_with_the_erp_rule(): void
    {
        $this->inPortal();
        $cash = Account::query()->create(['name' => 'Cash box', 'type' => 'cash', 'opening_balance' => 1000, 'is_active' => true]);

        Livewire::test(CashBook::class)
            ->callAction('recordCost', ['new_category' => 'Rent', 'amount' => 5000, 'date' => now()->toDateString(), 'account_id' => $cash->getKey()])
            ->assertNotified(__('Not saved'));

        $this->assertSame(0, Expense::query()->count());
    }
}
