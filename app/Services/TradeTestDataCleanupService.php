<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\DeliveryChallan;
use App\Models\DeliveryChallanItem;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FundTransfer;
use App\Models\MachineSerial;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\TransactionLedger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Removes the QA test records of one Trade Portal company: everything named
 * with the test prefix ("TEST-"), and what hangs on it (the test customers'
 * challans and orders, the test suppliers' purchases with their LC file,
 * landed cost and serials, their payments, test costs and test money moves).
 *
 * Rows go through their models, so each one's own hooks take its account
 * ledger rows, stock movements and customer / supplier balances out the way
 * the ERP always does. It refuses before writing anything when a test record
 * is mixed with a real one (a real product on a test challan, a test product
 * on a real purchase), so real business data is never touched.
 */
class TradeTestDataCleanupService
{
    /**
     * What would be removed, by kind, with the names / numbers found.
     *
     * @return array<string, Collection<int, string>>
     */
    public function plan(Company $company, string $prefix): array
    {
        return app(CompanyContext::class)->within($company, function () use ($prefix): array {
            $found = $this->find($prefix);
            $this->assertNotMixed($found);

            return [
                'Customers' => $found['customers']->pluck('name'),
                'Customer payments' => $found['customerPayments']->map(fn (CustomerPayment $p): string => $p->payment_date?->toDateString().' '.$p->amount),
                'Delivery challans' => $found['challans']->map(fn (DeliveryChallan $c): string => $c->challan_number.' ('.$c->status.')'),
                'Orders' => $found['orders']->pluck('order_number'),
                'Order payments' => $found['orderPayments']->map(fn (OrderPayment $p): string => $p->paid_at?->toDateString().' '.$p->amount),
                'Suppliers' => $found['suppliers']->pluck('name'),
                'Supplier payments' => $found['supplierPayments']->map(fn (SupplierPayment $p): string => $p->payment_date?->toDateString().' '.$p->amount),
                'Purchases' => $found['purchases']->pluck('purchase_number'),
                'LC files' => $found['purchases']->filter(fn (Purchase $p): bool => $p->lcFile !== null)->map(fn (Purchase $p): string => (string) ($p->lc_number ?: $p->purchase_number))->values(),
                'Machine serials' => $found['serials']->pluck('serial_number'),
                'Products' => $found['products']->pluck('name'),
                'Stock movements' => $found['movements']->map(fn (StockMovement $m): string => $m->type.' '.$m->quantity),
                'Expenses' => $found['expenses']->map(fn (Expense $e): string => $e->expense_date?->toDateString().' '.$e->amount),
                'Expense categories' => $found['categories']->pluck('name'),
                'Fund transfers' => $found['transfers']->map(fn (FundTransfer $t): string => $t->transfer_number.' '.$t->amount),
            ];
        });
    }

    /**
     * Removes the test records in one transaction. With $commit false the
     * transaction is rolled back after the work, so a dry run proves the
     * whole removal goes through and shows the balances it would leave.
     *
     * @return array{accounts: list<array{name: string, opening: float, balance: float}>, ledger: list<array{date: string, account: string, type: string, direction: string, amount: float, reference: string, note: string|null}>}
     */
    public function remove(Company $company, string $prefix, bool $commit): array
    {
        return app(CompanyContext::class)->within($company, function () use ($prefix, $commit): array {
            DB::beginTransaction();

            try {
                $found = $this->find($prefix);
                $this->assertNotMixed($found);
                $this->delete($found);

                $after = $this->state();

                $commit ? DB::commit() : DB::rollBack();

                return $after;
            } catch (\Throwable $exception) {
                DB::rollBack();

                throw $exception;
            }
        });
    }

    /**
     * The money accounts with their balance, and every ledger row left on
     * them: after a cleanup only real movements should remain.
     *
     * @return array{accounts: list<array{name: string, opening: float, balance: float}>, ledger: list<array{date: string, account: string, type: string, direction: string, amount: float, reference: string, note: string|null}>}
     */
    public function state(): array
    {
        $accounts = Account::query()->manual()->orderBy('name')->get();
        $accounts->each(fn (Account $account) => $account->syncCurrentBalance());
        $names = $accounts->pluck('name', 'id');

        return [
            'accounts' => $accounts->map(fn (Account $account): array => [
                'name' => (string) $account->name,
                'opening' => (float) $account->opening_balance,
                'balance' => (float) $account->refresh()->current_balance,
            ])->values()->all(),
            'ledger' => TransactionLedger::query()
                ->whereIn('account_id', $accounts->modelKeys())
                ->orderBy('transaction_date')
                ->orderBy('id')
                ->get()
                ->map(fn (TransactionLedger $ledger): array => [
                    'date' => (string) $ledger->transaction_date?->toDateString(),
                    'account' => (string) ($names[$ledger->account_id] ?? $ledger->account_id),
                    'type' => (string) $ledger->type,
                    'direction' => (string) $ledger->direction,
                    'amount' => (float) $ledger->amount,
                    'reference' => class_basename((string) $ledger->reference_type).' #'.$ledger->reference_id,
                    'note' => $ledger->note,
                ])->values()->all(),
        ];
    }

    /** @return array<string, Collection<int, mixed>> */
    protected function find(string $prefix): array
    {
        $like = addcslashes($prefix, '%_\\').'%';

        $customers = Customer::query()->where('name', 'like', $like)->get();
        $suppliers = Supplier::query()->where('name', 'like', $like)->get();
        $products = Product::query()->where('name', 'like', $like)->get();
        $categories = ExpenseCategory::query()->where('name', 'like', $like)->get();

        $challans = DeliveryChallan::query()->whereIn('customer_id', $customers->modelKeys())->get();
        $orders = Order::query()->withTrashed()
            ->where(fn ($query) => $query->whereIn('customer_id', $customers->modelKeys())->orWhereIn('id', $challans->pluck('order_id')->filter()))
            ->get();
        $purchases = Purchase::query()->with('lcFile')->whereIn('supplier_id', $suppliers->modelKeys())->get();

        $serials = MachineSerial::query()
            ->where(fn ($query) => $query->whereIn('product_id', $products->modelKeys())
                ->orWhereIn('purchase_id', $purchases->modelKeys())
                ->orWhereIn('delivery_challan_id', $challans->modelKeys())
                ->orWhere('serial_number', 'like', $like))
            ->get();

        return [
            'customers' => $customers,
            'customerPayments' => CustomerPayment::query()->whereIn('customer_id', $customers->modelKeys())->get(),
            'challans' => $challans,
            'orders' => $orders,
            'orderPayments' => OrderPayment::query()->withTrashed()->whereIn('order_id', $orders->modelKeys())->get(),
            'suppliers' => $suppliers,
            'supplierPayments' => SupplierPayment::query()->whereIn('supplier_id', $suppliers->modelKeys())->get(),
            'purchases' => $purchases,
            'serials' => $serials,
            'products' => $products,
            'movements' => StockMovement::query()->whereIn('product_id', $products->modelKeys())->get(),
            'expenses' => Expense::query()->withTrashed()
                ->where(fn ($query) => $query->whereIn('expense_category_id', $categories->modelKeys())->orWhere('note', 'like', $like))
                ->get(),
            'categories' => $categories,
            'transfers' => FundTransfer::query()->where('remarks', 'like', $like)->get(),
        ];
    }

    /**
     * Stops before anything is written when a test record is tied to a real
     * one, so a real sale, purchase or item can never go with the test data.
     *
     * @param  array<string, Collection<int, mixed>>  $found
     */
    protected function assertNotMixed(array $found): void
    {
        $testProducts = $found['products']->modelKeys();
        $problems = [];

        $realOnTestChallans = DeliveryChallanItem::query()
            ->whereIn('delivery_challan_id', $found['challans']->modelKeys())
            ->whereNotNull('product_id')
            ->whereNotIn('product_id', $testProducts)
            ->count();
        $realOnTestOrders = OrderItem::query()->whereIn('order_id', $found['orders']->modelKeys())->whereNotIn('product_id', $testProducts)->count();
        $realOnTestPurchases = PurchaseItem::query()->whereIn('purchase_id', $found['purchases']->modelKeys())->whereNotIn('product_id', $testProducts)->count();

        if ($realOnTestChallans + $realOnTestOrders > 0) {
            $problems[] = 'a test customer\'s challan or order carries a real product';
        }

        if ($realOnTestPurchases > 0) {
            $problems[] = 'a test supplier\'s purchase carries a real product';
        }

        if (OrderItem::query()->whereIn('product_id', $testProducts)->whereNotIn('order_id', $found['orders']->modelKeys())->exists()) {
            $problems[] = 'a test product is on a real customer\'s order';
        }

        if (PurchaseItem::query()->whereIn('product_id', $testProducts)->whereNotIn('purchase_id', $found['purchases']->modelKeys())->exists()) {
            $problems[] = 'a test product is on a real supplier\'s purchase';
        }

        if (DeliveryChallanItem::query()->whereIn('product_id', $testProducts)->whereNotIn('delivery_challan_id', $found['challans']->modelKeys())->exists()) {
            $problems[] = 'a test product is on a real customer\'s challan';
        }

        if ($found['products']->contains(fn (Product $product): bool => filled($product->stock_pool_id))) {
            $problems[] = 'a test product shares stock with another company (stock pool)';
        }

        if ($found['expenses']->contains(fn (Expense $expense): bool => ! $found['categories']->contains('id', $expense->expense_category_id))) {
            $problems[] = 'a cost noted as a test is under a real cost category';
        }

        if ($problems !== []) {
            throw new RuntimeException('Nothing was removed: '.implode('; ', $problems).'. Check these records by hand first.');
        }
    }

    /** @param  array<string, Collection<int, mixed>>  $found */
    protected function delete(array $found): void
    {
        // Serials first: they point at the test challans, purchases and products.
        $found['serials']->each->delete();

        // Old-due money of the test customers (each takes its ledger row out).
        $found['customerPayments']->each->delete();

        // The sales: payments one by one (ledger rows out), then the orders
        // (their stock movements out, customer due recounted), then the challans.
        $found['orderPayments']->each->forceDelete();
        $found['orders']->each->forceDelete();
        $found['challans']->each->delete();
        $found['customers']->each->delete();

        // The purchases: supplier payments (ledger rows out), then the
        // purchases (purchase stock movements out; LC file and landed cost
        // lines go with them), then the suppliers.
        $found['supplierPayments']->each->delete();
        $found['purchases']->each->forceDelete();
        $found['suppliers']->each->delete();

        // What is left on the test products' stock ledger (opening stock,
        // corrections): stock going out before stock coming in, so it never
        // dips below zero (signed as StockMovementService counts it).
        StockMovement::query()
            ->whereIn('product_id', $found['products']->modelKeys())
            ->get()
            ->sortBy(fn (StockMovement $movement): int => match (true) {
                in_array($movement->type, ['sale', 'damage'], true) => -abs((int) $movement->quantity),
                in_array($movement->type, ['adjustment', 'transfer'], true) => (int) $movement->quantity,
                default => abs((int) $movement->quantity),
            })
            ->each->delete();
        $found['products']->each->delete();

        $found['expenses']->each->forceDelete();
        $found['categories']->each->delete();

        // A fund transfer keeps no hook on its ledger rows, so they go first.
        foreach ($found['transfers'] as $transfer) {
            TransactionLedger::query()
                ->where('reference_type', FundTransfer::class)
                ->where('reference_id', $transfer->getKey())
                ->get()
                ->each->delete();
            $transfer->delete();
        }
    }
}
