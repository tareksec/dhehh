<?php

namespace Tests\Feature;

use App\Filament\Resources\ResellerDeposits\Pages\ListResellerDeposits;
use App\Filament\Resources\Resellers\Pages\ListResellers;
use App\Filament\Resources\Resellers\Pages\ViewReseller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ResellerDepositEntry;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\ResellerDepositService;
use App\Services\ResellerOwnCourierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner decision (2026-10-12): the admin sees every reseller's deposit with
 * every entry, and staff can add or deduct with a reason that is always
 * required; everything is logged.
 */
class ResellerDepositAdminTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Company $other;

    protected Customer $reseller;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('Deposit Co', 'deposit-co');
        $this->other = $this->makeCompany('Other Co', 'other-co');
        app(CompanyContext::class)->set($this->company);
        $this->reseller = $this->makeReseller($this->company, '01711000001');
        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    public function test_adding_to_the_deposit_keeps_the_reason_the_staff_member_and_a_positive_entry(): void
    {
        $service = app(ResellerDepositService::class);

        $entry = $service->adjust($this->reseller, ResellerDepositService::ADD, 250.5, '  Bank transfer received outside the system ', $this->admin);

        $this->assertSame(ResellerDepositEntry::TYPE_ADJUSTMENT, $entry->type);
        $this->assertSame(250.5, (float) $entry->amount);
        $this->assertSame('Bank transfer received outside the system', $entry->note);
        $this->assertSame($this->admin->getKey(), $entry->created_by);
        $this->assertSame($this->company->getKey(), $entry->company_id);
        $this->assertSame($this->reseller->getKey(), $entry->customer_id);
        $this->assertSame(250.5, app(ResellerOwnCourierService::class)->depositBalance($this->reseller));
    }

    public function test_deducting_lowers_the_deposit_and_can_empty_it(): void
    {
        $service = app(ResellerDepositService::class);
        $service->adjust($this->reseller, ResellerDepositService::ADD, 300, 'Opening deposit', $this->admin);

        $entry = $service->adjust($this->reseller, ResellerDepositService::DEDUCT, 120, 'Wrong amount was added', $this->admin);
        $this->assertSame(-120.0, (float) $entry->amount);
        $this->assertSame(180.0, app(ResellerOwnCourierService::class)->depositBalance($this->reseller));

        $service->adjust($this->reseller, ResellerDepositService::DEDUCT, 180, 'Closing the account', $this->admin);
        $this->assertSame(0.0, app(ResellerOwnCourierService::class)->depositBalance($this->reseller));
    }

    public function test_a_deduction_larger_than_the_deposit_is_refused_and_changes_nothing(): void
    {
        $service = app(ResellerDepositService::class);
        $service->adjust($this->reseller, ResellerDepositService::ADD, 100, 'Opening deposit', $this->admin);

        $errors = $this->errorsFor(fn () => $service->adjust($this->reseller, ResellerDepositService::DEDUCT, 100.01, 'Too much', $this->admin));

        $this->assertSame(['amount'], array_keys($errors));
        $this->assertSame(1, ResellerDepositEntry::query()->count());
        $this->assertSame(100.0, app(ResellerOwnCourierService::class)->depositBalance($this->reseller));
    }

    public function test_a_reason_an_amount_above_zero_and_a_direction_are_always_required(): void
    {
        $service = app(ResellerDepositService::class);

        $this->assertSame(['reason'], array_keys($this->errorsFor(fn () => $service->adjust($this->reseller, ResellerDepositService::ADD, 50, '   ', $this->admin))));
        $this->assertSame(['amount'], array_keys($this->errorsFor(fn () => $service->adjust($this->reseller, ResellerDepositService::ADD, 0, 'Why', $this->admin))));
        $this->assertSame(['amount'], array_keys($this->errorsFor(fn () => $service->adjust($this->reseller, ResellerDepositService::ADD, -5, 'Why', $this->admin))));
        $this->assertSame(['direction'], array_keys($this->errorsFor(fn () => $service->adjust($this->reseller, 'multiply', 50, 'Why', $this->admin))));
        $this->assertSame(0, ResellerDepositEntry::query()->count());
    }

    public function test_an_adjustment_is_written_to_the_audit_log_with_the_staff_member(): void
    {
        $this->actingAs($this->admin);

        $entry = app(ResellerDepositService::class)->adjust($this->reseller, ResellerDepositService::ADD, 75, 'Goodwill credit', $this->admin);

        $log = AuditLog::query()->where('auditable_type', ResellerDepositEntry::class)->where('auditable_id', $entry->getKey())->sole();
        $this->assertSame('created', $log->action);
        $this->assertSame($this->admin->getKey(), $log->user_id);
        $this->assertSame($this->company->getKey(), $log->company_id);
        $this->assertSame('Goodwill credit', $log->new_values['note']);
    }

    public function test_the_admin_page_lists_every_entry_with_its_type_reason_and_who_did_it(): void
    {
        $this->actingAs($this->admin)->withSession(['current_company_id' => $this->company->getKey()]);
        $adjustment = app(ResellerDepositService::class)->adjust($this->reseller, ResellerDepositService::ADD, 90, 'Goodwill credit', $this->admin);
        $automatic = ResellerDepositEntry::query()->create([
            'company_id' => $this->company->getKey(), 'customer_id' => $this->reseller->getKey(),
            'type' => ResellerDepositEntry::TYPE_OVERPAYMENT, 'amount' => 40, 'note' => 'Paid above the total of order X',
        ]);

        Livewire::test(ListResellerDeposits::class)
            ->assertCanSeeTableRecords([$adjustment, $automatic])
            ->assertSee('Goodwill credit')
            ->assertSee('Adjusted by staff')
            ->assertSee('Sent above an order total')
            ->assertSee($this->admin->name)
            ->assertSee('Automatic')
            ->filterTable('type', ResellerDepositEntry::TYPE_ADJUSTMENT)
            ->assertCanSeeTableRecords([$adjustment])
            ->assertCanNotSeeTableRecords([$automatic]);
    }

    public function test_staff_add_and_deduct_from_the_page_and_the_reason_is_mandatory(): void
    {
        $this->actingAs($this->admin)->withSession(['current_company_id' => $this->company->getKey()]);
        $service = app(ResellerOwnCourierService::class);

        Livewire::test(ListResellerDeposits::class)
            ->callAction('addToDeposit', data: ['customer_id' => $this->reseller->getKey(), 'amount' => 500, 'reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);
        $this->assertSame(0, ResellerDepositEntry::query()->count());

        Livewire::test(ListResellerDeposits::class)
            ->callAction('addToDeposit', data: ['customer_id' => $this->reseller->getKey(), 'amount' => 500, 'reason' => 'Cash handed over'])
            ->assertHasNoActionErrors()
            ->callAction('deductFromDeposit', data: ['customer_id' => $this->reseller->getKey(), 'amount' => 200, 'reason' => 'Returned cash'])
            ->assertHasNoActionErrors();

        $this->assertSame(300.0, $service->depositBalance($this->reseller));
        $this->assertSame(['Cash handed over', 'Returned cash'], ResellerDepositEntry::query()->orderBy('id')->pluck('note')->all());
    }

    public function test_a_deduction_over_the_balance_from_the_page_tells_staff_and_changes_nothing(): void
    {
        $this->actingAs($this->admin)->withSession(['current_company_id' => $this->company->getKey()]);
        app(ResellerDepositService::class)->adjust($this->reseller, ResellerDepositService::ADD, 100, 'Opening', $this->admin);

        Livewire::test(ListResellerDeposits::class)
            ->callAction('deductFromDeposit', data: ['customer_id' => $this->reseller->getKey(), 'amount' => 150, 'reason' => 'Too much'])
            ->assertNotified(__('The deposit holds only :balance, so :amount cannot be deducted.', ['balance' => '100', 'amount' => '150']));

        $this->assertSame(100.0, app(ResellerOwnCourierService::class)->depositBalance($this->reseller));
        $this->assertSame(1, ResellerDepositEntry::query()->count());
    }

    public function test_staff_see_and_adjust_only_their_own_companys_resellers_and_entries(): void
    {
        $theirs = $this->makeReseller($this->other, '01722000009');
        $foreign = ResellerDepositEntry::withoutGlobalScopes()->create([
            'company_id' => $this->other->getKey(), 'customer_id' => $theirs->getKey(),
            'type' => ResellerDepositEntry::TYPE_OVERPAYMENT, 'amount' => 999, 'note' => 'Other company entry',
        ]);
        $own = ResellerDepositEntry::query()->create([
            'company_id' => $this->company->getKey(), 'customer_id' => $this->reseller->getKey(),
            'type' => ResellerDepositEntry::TYPE_OVERPAYMENT, 'amount' => 10, 'note' => 'Own company entry',
        ]);
        $this->actingAs($this->admin)->withSession(['current_company_id' => $this->company->getKey()]);

        Livewire::test(ListResellerDeposits::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign])
            ->assertDontSee('Other company entry');

        // The other company's reseller cannot be picked or adjusted from here.
        Livewire::test(ListResellerDeposits::class)
            ->callAction('addToDeposit', data: ['customer_id' => $theirs->getKey(), 'amount' => 50, 'reason' => 'Sneaky'])
            ->assertHasActionErrors(['customer_id']);
        $this->assertSame(999.0, app(ResellerOwnCourierService::class)->depositBalance($theirs));
    }

    public function test_only_approved_resellers_can_be_adjusted_from_the_page(): void
    {
        $pending = $this->makeReseller($this->company, '01733000003', status: 'pending');
        $this->actingAs($this->admin)->withSession(['current_company_id' => $this->company->getKey()]);

        Livewire::test(ListResellerDeposits::class)
            ->callAction('addToDeposit', data: ['customer_id' => $pending->getKey(), 'amount' => 50, 'reason' => 'Not yet approved'])
            ->assertHasActionErrors(['customer_id']);

        $this->assertSame(0, ResellerDepositEntry::query()->count());
    }

    public function test_staff_who_may_only_view_sales_cannot_adjust_a_deposit(): void
    {
        UserRole::query()->create(['name' => 'Sales viewer', 'slug' => 'sales-viewer', 'permissions' => ['sales.view'], 'is_active' => true]);
        $viewer = User::factory()->create(['role' => 'sales-viewer', 'is_active' => true]);
        $this->actingAs($viewer)->withSession(['current_company_id' => $this->company->getKey()]);

        Livewire::test(ListResellerDeposits::class)
            ->assertActionHidden('addToDeposit')
            ->assertActionHidden('deductFromDeposit');

        UserRole::query()->create(['name' => 'Sales manager', 'slug' => 'sales-manager', 'permissions' => ['sales.view', 'sales.update'], 'is_active' => true]);
        $manager = User::factory()->create(['role' => 'sales-manager', 'is_active' => true]);
        $this->actingAs($manager)->withSession(['current_company_id' => $this->company->getKey()]);

        Livewire::test(ListResellerDeposits::class)
            ->assertActionVisible('addToDeposit')
            ->assertActionVisible('deductFromDeposit');
    }

    public function test_an_adjustment_changes_what_the_next_order_can_take_from_the_deposit(): void
    {
        $service = app(ResellerOwnCourierService::class);
        app(ResellerDepositService::class)->adjust($this->reseller, ResellerDepositService::ADD, 400, 'Opening', $this->admin);
        app(ResellerDepositService::class)->adjust($this->reseller, ResellerDepositService::DEDUCT, 150, 'Correction', $this->admin);

        $this->assertSame(250.0, $service->depositBalance($this->reseller));
    }

    public function test_the_resellers_list_and_page_show_the_deposit(): void
    {
        $this->actingAs($this->admin)->withSession(['current_company_id' => $this->company->getKey()]);
        app(ResellerDepositService::class)->adjust($this->reseller, ResellerDepositService::ADD, 1234.5, 'Opening', $this->admin);

        Livewire::test(ListResellers::class)
            ->assertCanSeeTableRecords([$this->reseller])
            ->assertTableColumnStateSet('deposit_balance', 1234.5, $this->reseller);

        Livewire::test(ViewReseller::class, ['record' => $this->reseller->getKey()])
            ->assertSee('1,234.50');
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

    protected function makeCompany(string $name, string $slug): Company
    {
        return Company::query()->create([
            'name' => $name, 'slug' => $slug, 'domain' => $slug.'.example.test', 'domain_verified' => true,
            'invoice_prefix' => strtoupper(substr($slug, 0, 3)), 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka',
            'is_active' => true, 'reseller_module_enabled' => true,
        ])->fresh();
    }

    protected function makeReseller(Company $company, string $phone, string $status = 'approved'): Customer
    {
        $customer = new Customer([
            'name' => 'Reseller '.$phone, 'phone' => $phone, 'reseller_status' => $status,
            'reseller_slug' => 'r-'.Str::random(6), 'business_name' => 'Shop '.$phone, 'is_active' => true,
        ]);
        $customer->company_id = $company->getKey();
        $customer->forceFill(['password' => Hash::make('secret-password')])->save();

        return $customer;
    }
}
