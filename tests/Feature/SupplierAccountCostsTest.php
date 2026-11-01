<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Purchase;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierAccountCostsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_expense_for_a_supplier_needs_a_cost_type_and_one_without_clears_it(): void
    {
        $supplier = $this->supplier();

        $shipping = $this->expense(['supplier_id' => $supplier->id, 'supplier_cost_type' => Expense::SUPPLIER_COST_SHIPPING]);
        $this->assertSame('shipping', $shipping->fresh()->supplier_cost_type);

        try {
            $this->expense(['supplier_id' => $supplier->id]);
            $this->fail('A supplier cost needs a cost type.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('supplier_cost_type', $exception->errors());
        }

        $plain = $this->expense(['supplier_cost_type' => Expense::SUPPLIER_COST_AD]);
        $this->assertNull($plain->fresh()->supplier_cost_type);
        $this->assertNull($plain->fresh()->supplier_id);
    }

    public function test_cash_into_the_supplier_account_can_exceed_the_invoice_payable_and_leaves_it_alone(): void
    {
        $supplier = $this->supplier();
        $account = $this->account();

        $this->assertFalse($supplier->hasConsignmentAccount());

        try {
            $this->payment($supplier, $account, 500, forAccount: false);
            $this->fail('An invoice payment above the payable must still be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }

        $this->payment($supplier, $account, 500, forAccount: true);

        // Paid ahead of any goods, and the purchase-invoice balance did not move.
        $this->assertSame(500.0, (float) SupplierPayment::query()->sum('amount'));
        $this->assertSame(0.0, (float) $supplier->fresh()->current_balance);
        $this->assertSame(9500.0, (float) $account->fresh()->current_balance);
    }

    public function test_invoice_payments_still_reduce_the_invoice_balance(): void
    {
        $supplier = $this->supplier();
        $account = $this->account();

        Purchase::query()->create([
            'supplier_id' => $supplier->id,
            'purchase_date' => '2026-03-01',
            'machine_purchase' => 1000,
            'status' => 'received',
        ]);

        $this->assertSame(1000.0, (float) $supplier->fresh()->current_balance);

        $this->payment($supplier, $account, 400, forAccount: false);
        $this->payment($supplier, $account, 250, forAccount: true);

        $this->assertSame(600.0, (float) $supplier->fresh()->current_balance);
    }

    public function test_a_supplier_with_receipts_has_a_consignment_account(): void
    {
        $supplier = $this->supplier();

        $this->assertFalse($supplier->hasConsignmentAccount());

        PurchaseReceipt::query()->create(['supplier_id' => $supplier->id, 'received_on' => '2026-02-05']);

        $this->assertTrue($supplier->fresh()->hasConsignmentAccount());
    }

    private function supplier(): Supplier
    {
        return Supplier::query()->create(['name' => 'Shahin Khan', 'opening_balance' => 0, 'is_active' => true]);
    }

    private function account(): Account
    {
        return Account::query()->create([
            'name' => 'Cash',
            'type' => 'cash',
            'opening_balance' => 10000,
            'is_active' => true,
        ]);
    }

    private function payment(Supplier $supplier, Account $account, float $amount, bool $forAccount): SupplierPayment
    {
        return SupplierPayment::query()->create([
            'supplier_id' => $supplier->id,
            'account_id' => $account->id,
            'amount' => $amount,
            'payment_date' => '2026-03-02',
            'method' => 'cash',
            'for_supplier_account' => $forAccount,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function expense(array $attributes): Expense
    {
        $category = ExpenseCategory::query()->firstOrCreate(['slug' => 'shipping'], ['name' => 'Shipping']);

        return Expense::query()->create([
            'expense_category_id' => $category->id,
            'account_id' => (Account::query()->where('name', 'Cash')->first() ?? $this->account())->id,
            'amount' => 100,
            'expense_date' => '2026-03-03',
            ...$attributes,
        ]);
    }
}
