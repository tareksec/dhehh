<?php

namespace App\Services;

use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Purchase;
use App\Models\SupplierPayment;
use App\Models\TransactionLedger;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Investment\InvestmentWithdrawalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Core Voucher & Fund Control workflow.
 *
 * This is a self-contained approval flow (pending -> verified -> approved /
 * rejected / cancelled), not yet wired into a shared ApprovalGateService —
 * that service does not exist in this codebase yet (it belongs to the
 * not-yet-built Task/Approval Workflow module). Per the module plan's own
 * fallback option, this ships with simple inline approval logic now so the
 * accounting rules land safely; migrating verify()/approve() onto a shared
 * ApprovalGateService later is a self-contained follow-up.
 *
 * Rule 1 (never violate): inventory_purchase, capital_investment,
 * owner_withdrawal, asset_purchase, loan, fund_transfer, and investor_payout
 * transaction types NEVER create an Expense record — they move funds between
 * a Fund Account and an asset/liability, they are not spend.
 */
class VoucherService
{
    /**
     * transaction_types that require an account_id (the account money moves
     * into/out of) at submission time.
     */
    protected const ACCOUNT_REQUIRED_TYPES = [
        'inventory_purchase', 'business_expense', 'supplier_payment', 'customer_payment',
        'capital_investment', 'owner_withdrawal', 'refund',
        'asset_purchase', 'loan', 'other', 'investor_payout',
    ];

    public function submit(array $data, User $user): Voucher
    {
        [$data, $orderIds] = $this->prepareInvoiceSelection($data);
        $data = $this->prepareRefundInvoice($data);
        $type = $data['type'] ?? null;
        $transactionType = $data['transaction_type'] ?? null;

        if ($transactionType === 'fund_transfer') {
            throw ValidationException::withMessages([
                'transaction_type' => 'Use the Fund Transfer screen for moving money between your own accounts, not a voucher.',
            ]);
        }

        if ((float) ($data['amount'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }

        if ($transactionType === 'inventory_purchase') {
            if (empty($data['purchase_id'])) {
                throw ValidationException::withMessages([
                    'purchase_id' => 'An inventory purchase voucher requires a purchase.',
                ]);
            }

            $purchase = Purchase::query()->find($data['purchase_id']);

            if (! $purchase || $purchase->status !== 'draft') {
                throw ValidationException::withMessages([
                    'purchase_id' => 'Select an incomplete purchase.',
                ]);
            }
        }

        if (in_array($transactionType, self::ACCOUNT_REQUIRED_TYPES, true) && empty($data['account_id'])) {
            throw ValidationException::withMessages([
                'account_id' => 'Please select the account this voucher moves money through.',
            ]);
        }

        if ($type === Voucher::TYPE_DEBIT && $transactionType === 'customer_payment' && empty($data['customer_id'])) {
            throw ValidationException::withMessages(['customer_id' => 'Please select a customer.']);
        }

        if ($transactionType === 'supplier_payment' && empty($data['supplier_id'])) {
            throw ValidationException::withMessages(['supplier_id' => 'Please select a supplier.']);
        }

        if ($transactionType === 'business_expense' && empty($data['expense_category_id'])) {
            throw ValidationException::withMessages(['expense_category_id' => 'Please select an expense category.']);
        }

        return DB::transaction(function () use ($data, $orderIds, $type, $user): Voucher {
            $voucher = Voucher::query()->create([
                ...$data,
                'voucher_number' => Voucher::nextVoucherNumber($type),
                'status' => Voucher::STATUS_PENDING,
                'submitted_by' => $user->getKey(),
            ]);
            $voucher->orders()->sync($orderIds);

            return $voucher;
        });
    }

    public function update(Voucher $voucher, array $data): Voucher
    {
        [$data, $orderIds] = $this->prepareInvoiceSelection($data);
        $data = $this->prepareRefundInvoice($data);

        return DB::transaction(function () use ($voucher, $data, $orderIds): Voucher {
            $voucher->update($data);
            $voucher->orders()->sync($orderIds);

            return $voucher->refresh();
        });
    }

    public function verify(Voucher $voucher, User $user): void
    {
        DB::transaction(function () use ($voucher, $user): void {
            $lockedVoucher = Voucher::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($voucher->getKey());

            if (! $lockedVoucher->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'Only a pending voucher can be verified.',
                ]);
            }

            $lockedVoucher->update([
                'status' => Voucher::STATUS_VERIFIED,
                'verified_by' => $user->getKey(),
                'verified_at' => now(),
            ]);

            $voucher->setRawAttributes($lockedVoucher->getAttributes());
        });
    }

    /**
     * A credit voucher must be verified first (money received needs a
     * second set of eyes on the evidence before it's booked). A debit
     * voucher may be approved straight from pending, or from verified if it
     * went through that optional step.
     */
    public function approve(Voucher $voucher, User $user): void
    {
        // The status check and, for purchase funding, the "does this push
        // total funding over the purchase total" sum-check both need to run
        // against a row that cannot change under us between the check and
        // the write. Re-fetch the voucher (and, when relevant, lock the
        // Purchase row) *inside* the transaction so two vouchers approved
        // for the same voucher/purchase at nearly the same time serialize
        // instead of both reading a stale "not yet approved" / "not yet
        // fully funded" state and both going through.
        DB::transaction(function () use ($voucher, $user): void {
            $lockedVoucher = Voucher::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($voucher->getKey());

            $allowedFrom = $lockedVoucher->isCredit()
                ? [Voucher::STATUS_VERIFIED]
                : [Voucher::STATUS_PENDING, Voucher::STATUS_VERIFIED];

            if (! in_array($lockedVoucher->status, $allowedFrom, true)) {
                throw ValidationException::withMessages([
                    'status' => $lockedVoucher->isCredit()
                        ? 'A credit voucher must be verified before it can be approved.'
                        : 'This voucher cannot be approved from its current status.',
                ]);
            }

            if ($lockedVoucher->transaction_type === 'inventory_purchase') {
                $this->assertFundingDoesNotExceedPurchaseTotal($lockedVoucher);
            }

            $resulting = $this->applyAccountingEffect($lockedVoucher);

            $lockedVoucher->update([
                'status' => Voucher::STATUS_APPROVED,
                'approved_by' => $user->getKey(),
                'approved_at' => now(),
                'resulting_model_type' => $resulting ? $resulting::class : null,
                'resulting_model_id' => $resulting?->getKey(),
            ]);

            $voucher->setRawAttributes($lockedVoucher->getAttributes());
        });
    }

    public function reject(Voucher $voucher, string $reason, User $user): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => 'A rejection reason is required.',
            ]);
        }

        DB::transaction(function () use ($voucher, $user, $reason): void {
            $lockedVoucher = Voucher::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($voucher->getKey());

            if (in_array($lockedVoucher->status, [Voucher::STATUS_APPROVED, Voucher::STATUS_REJECTED, Voucher::STATUS_CANCELLED], true)) {
                throw ValidationException::withMessages([
                    'status' => 'This voucher has already reached a final status.',
                ]);
            }

            $lockedVoucher->update([
                'status' => Voucher::STATUS_REJECTED,
                'rejection_reason' => $reason,
                'approved_by' => $user->getKey(),
                'approved_at' => now(),
            ]);
            app(InvestmentWithdrawalService::class)->voucherVoided($lockedVoucher);
            app(VoucherLinkedPayoutsService::class)->voucherVoided($lockedVoucher);

            $voucher->setRawAttributes($lockedVoucher->getAttributes());
        });
    }

    public function cancel(Voucher $voucher): void
    {
        DB::transaction(function () use ($voucher): void {
            $lockedVoucher = Voucher::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($voucher->getKey());

            if (! $lockedVoucher->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'Only a pending voucher can be cancelled.',
                ]);
            }

            $lockedVoucher->update(['status' => Voucher::STATUS_CANCELLED]);
            app(InvestmentWithdrawalService::class)->voucherVoided($lockedVoucher);
            app(VoucherLinkedPayoutsService::class)->voucherVoided($lockedVoucher);

            $voucher->setRawAttributes($lockedVoucher->getAttributes());
        });
    }

    /**
     * Funding vouchers for one Purchase (across possibly multiple fund
     * sources) must never sum past that purchase's total — otherwise more
     * money would be recorded as spent on it than it actually cost.
     */
    protected function assertFundingDoesNotExceedPurchaseTotal(Voucher $voucher): void
    {
        // Locking the Purchase row serializes concurrent approve() calls for
        // *different* vouchers funding the same purchase (the caller's own
        // voucher-row lock only protects against double-approving this one
        // voucher), so the sum below is never read from two transactions at
        // once and cannot jointly overfund the purchase.
        $purchase = Purchase::query()->withoutGlobalScopes()->withoutTrashed()->lockForUpdate()->find($voucher->purchase_id);

        if (! $purchase) {
            return;
        }

        $alreadyFunded = (float) Voucher::query()
            ->where('purchase_id', $purchase->getKey())
            ->where('status', Voucher::STATUS_APPROVED)
            ->whereKeyNot($voucher->getKey())
            ->sum('amount');

        if ($alreadyFunded + (float) $voucher->amount > (float) $purchase->total_amount + 0.01) {
            throw ValidationException::withMessages([
                'amount' => 'Total funding for this purchase would exceed its purchase total.',
            ]);
        }
    }

    /**
     * Books the approved voucher into the existing accounting system and
     * returns the resulting downstream record (or null when the effect is
     * only a ledger entry / a fund-source balance contribution).
     */
    protected function applyAccountingEffect(Voucher $voucher): ?object
    {
        return match ($voucher->transaction_type) {
            'customer_payment' => $voucher->customer_id
                ? $this->bookCustomerPayment($voucher)
                : $this->bookAccountLedger($voucher, 'in'),
            'supplier_payment' => $this->bookSupplierPayment($voucher),
            'business_expense' => $this->bookExpense($voucher),
            'inventory_purchase' => $this->bookAccountLedger($voucher, 'out'),
            'capital_investment', 'loan' => $this->bookAccountLedger($voucher, 'in'),
            'owner_withdrawal', 'refund', 'asset_purchase', 'investor_payout' => $this->bookAccountLedger($voucher, 'out'),
            'other' => $this->bookAccountLedger($voucher, $voucher->isCredit() ? 'in' : 'out'),
            default => null,
        };
    }

    protected function bookCustomerPayment(Voucher $voucher): CustomerPayment
    {
        return CustomerPayment::query()->create([
            'company_id' => $voucher->company_id,
            'customer_id' => $voucher->customer_id,
            'account_id' => $voucher->account_id,
            'amount' => $voucher->amount,
            'payment_date' => now()->toDateString(),
            'method' => $voucher->payment_method ?: 'other',
            'reference' => $voucher->voucher_number,
            'note' => $voucher->purpose,
        ]);
    }

    protected function bookSupplierPayment(Voucher $voucher): SupplierPayment
    {
        return SupplierPayment::query()->create([
            'company_id' => $voucher->company_id,
            'supplier_id' => $voucher->supplier_id,
            'account_id' => $voucher->account_id,
            'amount' => $voucher->amount,
            'payment_date' => now()->toDateString(),
            'method' => $voucher->payment_method ?: 'other',
            'reference' => $voucher->voucher_number,
            'note' => $voucher->purpose,
        ]);
    }

    protected function bookExpense(Voucher $voucher): Expense
    {
        return Expense::query()->create([
            'company_id' => $voucher->company_id,
            'expense_category_id' => $voucher->expense_category_id,
            'account_id' => $voucher->account_id,
            'amount' => $voucher->amount,
            'expense_date' => now()->toDateString(),
            'reference' => $voucher->voucher_number,
            'note' => $voucher->purpose,
        ]);
    }

    /**
     * For transaction types that never create an Expense/Payment record,
     * book the movement directly against the voucher's Account.
     */
    protected function bookAccountLedger(Voucher $voucher, string $direction): ?TransactionLedger
    {
        if (! $voucher->account_id) {
            return null;
        }

        return TransactionLedger::query()->create([
            'company_id' => $voucher->company_id,
            'account_id' => $voucher->account_id,
            'type' => $voucher->isCredit() ? 'voucher_credit' : 'voucher_debit',
            'direction' => $direction,
            'amount' => $voucher->amount,
            'reference_type' => Voucher::class,
            'reference_id' => $voucher->getKey(),
            'transaction_date' => now()->toDateString(),
            'note' => "Voucher {$voucher->voucher_number}",
        ]);
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<int>}
     */
    protected function prepareInvoiceSelection(array $data): array
    {
        $orderIds = collect($data['order_ids'] ?? [])
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        unset($data['order_ids']);

        if (($data['type'] ?? null) !== Voucher::TYPE_CREDIT) {
            return [$data, []];
        }

        if (($data['transaction_type'] ?? null) !== 'customer_payment' && $orderIds->isEmpty()) {
            return [$data, []];
        }

        $data['transaction_type'] = 'customer_payment';

        if ($orderIds->isEmpty()) {
            $data['order_id'] = null;

            return [$data, []];
        }

        $orders = Order::query()
            ->whereKey($orderIds)
            ->get(['id', 'customer_id']);

        if ($orders->count() !== $orderIds->count()) {
            throw ValidationException::withMessages([
                'order_ids' => 'Select valid order invoices.',
            ]);
        }

        $customerIds = $orders->pluck('customer_id')->filter()->unique();

        if ($customerIds->count() !== 1 || $orders->contains(fn (Order $order): bool => ! $order->customer_id)) {
            throw ValidationException::withMessages([
                'order_ids' => 'All selected order invoices must belong to the same customer.',
            ]);
        }

        if (empty($data['customer_id'])) {
            $data['customer_id'] = $customerIds->first();
        } elseif ((int) $data['customer_id'] !== (int) $customerIds->first()) {
            throw ValidationException::withMessages([
                'order_ids' => 'Selected order invoices must belong to the selected customer account.',
            ]);
        }

        $data['order_id'] = $orderIds->first();

        return [$data, $orderIds->all()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function prepareRefundInvoice(array $data): array
    {
        if (($data['transaction_type'] ?? null) !== 'refund') {
            return $data;
        }

        $order = Order::query()->find($data['order_id'] ?? null);

        if (! $order || ! $order->isAccounted()) {
            throw ValidationException::withMessages([
                'order_id' => 'Select a confirmed, processing, or completed order invoice for this refund.',
            ]);
        }

        $data['customer_id'] = $order->customer_id;

        return $data;
    }
}
