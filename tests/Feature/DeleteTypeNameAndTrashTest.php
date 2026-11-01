<?php

namespace Tests\Feature;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\TrashedExpenses;
use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CompanyContext;
use App\Support\TypeToConfirmDelete;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner request 2026-11-01: Delete asks for the record's name (a bulk delete
 * asks for the number of records), the record then lands in Trash, and from
 * Trash it is restored or deleted permanently (which asks for the name again).
 */
class DeleteTypeNameAndTrashTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Account $account;

    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $company = $this->admin->defaultCompany();
        app(CompanyContext::class)->set($company);
        $this->session(['current_company_id' => $company->getKey()]);

        $this->account = Account::query()->create(['name' => 'Cash', 'opening_balance' => 300]);
        $this->category = ExpenseCategory::query()->create(['name' => 'Rent', 'slug' => 'rent']);
    }

    private function expense(float $amount = 75): Expense
    {
        return Expense::query()->create([
            'expense_category_id' => $this->category->id,
            'account_id' => $this->account->id,
            'amount' => $amount,
            'expense_date' => now(),
        ]);
    }

    public function test_a_bulk_delete_needs_the_number_of_selected_records_and_then_goes_to_trash(): void
    {
        $first = $this->expense(10);
        $second = $this->expense(20);

        Livewire::actingAs($this->admin)
            ->test(ListExpenses::class)
            ->callTableBulkAction('delete', [$first, $second], data: ['confirmation' => '1'])
            ->assertHasTableBulkActionErrors(['confirmation']);

        $this->assertNotSoftDeleted($first);
        $this->assertNotSoftDeleted($second);

        Livewire::actingAs($this->admin)
            ->test(ListExpenses::class)
            ->callTableBulkAction('delete', [$first, $second], data: ['confirmation' => '2'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSoftDeleted($first);
        $this->assertSoftDeleted($second);
    }

    public function test_deleted_records_are_listed_in_trash_and_can_be_restored(): void
    {
        $expense = $this->expense(75);
        $expense->delete();

        // Moving it to trash gave the money back to the account...
        $this->assertSame('300.00', $this->account->refresh()->current_balance);

        Livewire::actingAs($this->admin)
            ->test(TrashedExpenses::class)
            ->assertCanSeeTableRecords([$expense])
            ->callTableAction('restore', $expense)
            ->assertHasNoTableActionErrors();

        // ...and restoring it takes the money out again.
        $this->assertNotSoftDeleted($expense);
        $this->assertSame('225.00', $this->account->refresh()->current_balance);
    }

    public function test_permanent_delete_from_trash_needs_the_name_typed(): void
    {
        $expense = $this->expense(75);
        $expense->delete();

        Livewire::actingAs($this->admin)
            ->test(TrashedExpenses::class)
            ->callTableAction('forceDelete', $expense, data: ['confirmation' => 'not the name'])
            ->assertHasTableActionErrors(['confirmation']);

        $this->assertSoftDeleted($expense);

        Livewire::actingAs($this->admin)
            ->test(TrashedExpenses::class)
            ->callTableAction('forceDelete', $expense, data: ['confirmation' => $expense->expense_number])
            ->assertHasNoTableActionErrors();

        $this->assertModelMissing($expense);
    }

    public function test_only_someone_who_may_delete_expenses_can_open_the_trash(): void
    {
        $staff = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);

        $this->actingAs($staff);

        $this->assertFalse(ExpenseResource::canRestoreAny());
        $this->assertFalse(ExpenseResource::canForceDeleteAny());
        $this->assertFalse(TrashedExpenses::canAccess());

        $this->actingAs($this->admin);

        $this->assertTrue(TrashedExpenses::canAccess());
    }

    public function test_only_models_with_a_trash_page_say_they_go_to_trash(): void
    {
        $this->actingAs($this->admin);

        $this->assertNotNull(TypeToConfirmDelete::trashUrl(Expense::class));
        $this->assertNull(TypeToConfirmDelete::trashUrl(StockMovement::class), 'A stock movement is a ledger row: it stays a permanent delete.');
    }
}
