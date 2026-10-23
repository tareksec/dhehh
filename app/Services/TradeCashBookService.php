<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CustomerPayment;
use App\Models\DeliveryChallan;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FundTransfer;
use App\Models\OrderPayment;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\TransactionLedger;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Trade Portal's cash book and simple balance sheet
 * (docs/tasneem-samples/PLAN.md §5). There is no ledger of its own: the book
 * is read from the ERP's account ledger (TransactionLedger) of the company's
 * cash, bank and mobile-banking accounts, where every money movement is
 * already posted — sale payments (OrderPayment), old dues (CustomerPayment),
 * supplier payments (SupplierPayment), costs (Expense) and moves between
 * accounts (FundTransfer). The entries made here go through those same
 * models, so the ERP's rules (no account below zero, no supplier paid more
 * than owed) apply unchanged.
 *
 * Money moved between the company's own accounts (a bank deposit) is shown
 * but is neither money in nor money out of the business.
 */
class TradeCashBookService
{
    public const KIND_SALE = 'sale';

    public const KIND_OLD_DUE = 'old_due';

    public const KIND_SUPPLIER = 'supplier';

    public const KIND_COST = 'cost';

    public const KIND_MOVE = 'move';

    public const KIND_OTHER = 'other';

    /** @return Collection<int, Account> the cash, bank and mobile-banking accounts, by name */
    public function accounts(): Collection
    {
        return Account::query()->manual()->orderBy('name')->get();
    }

    /** Money in all accounts at the start of $date (before anything of that day). */
    public function openingOn(CarbonImmutable $date): float
    {
        return round($this->accounts()->sum(fn (Account $account): float => $this->accountBalanceBefore($account, $date)), 2);
    }

    public function accountBalanceBefore(Account $account, CarbonImmutable $date): float
    {
        $ledger = TransactionLedger::query()->where('account_id', $account->getKey())->whereDate('transaction_date', '<', $date->toDateString());

        return (float) $account->opening_balance
            + (float) (clone $ledger)->where('direction', 'in')->sum('amount')
            - (float) (clone $ledger)->where('direction', 'out')->sum('amount');
    }

    /**
     * One day of the cash book: the opening, every line, money in and out
     * (moves between own accounts left out of both) and each account at the
     * end of the day.
     *
     * @return array{date: CarbonImmutable, opening: float, lines: list<array{kind: string, label: string, detail: string|null, account: string, direction: string, amount: float}>, in: float, out: float, closing: float, accounts: list<array{name: string, type: string, balance: float}>}
     */
    public function day(CarbonImmutable $date): array
    {
        $accounts = $this->accounts();
        $lines = $this->lines(
            TransactionLedger::query()
                ->whereIn('account_id', $accounts->modelKeys())
                ->whereDate('transaction_date', $date->toDateString())
                ->orderBy('id')
                ->get(),
            $accounts,
        );

        $counted = collect($lines)->where('kind', '!=', self::KIND_MOVE);
        $in = round((float) $counted->where('direction', 'in')->sum('amount'), 2);
        $out = round((float) $counted->where('direction', 'out')->sum('amount'), 2);
        $next = $date->addDay();

        return [
            'date' => $date,
            'opening' => $this->openingOn($date),
            'lines' => $lines,
            'in' => $in,
            'out' => $out,
            'closing' => $this->openingOn($next),
            'accounts' => $accounts->map(fn (Account $account): array => [
                'name' => (string) $account->name,
                'type' => (string) $account->type,
                'balance' => round($this->accountBalanceBefore($account, $next), 2),
            ])->values()->all(),
        ];
    }

    /**
     * A month: money in and out per day, the totals per kind, the costs per
     * category, and the money at the start and the end.
     *
     * @return array{start: CarbonImmutable, opening: float, closing: float, in: float, out: float, days: list<array{date: CarbonImmutable, in: float, out: float}>, kinds: array<string, array{in: float, out: float}>, costs: array<string, float>}
     */
    public function month(CarbonImmutable $month): array
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();
        $accounts = $this->accounts();
        $lines = collect($this->lines(
            TransactionLedger::query()
                ->whereIn('account_id', $accounts->modelKeys())
                ->whereDate('transaction_date', '>=', $start->toDateString())
                ->whereDate('transaction_date', '<=', $end->toDateString())
                ->orderBy('transaction_date')
                ->orderBy('id')
                ->get(),
            $accounts,
        ))->where('kind', '!=', self::KIND_MOVE);

        $days = $lines->groupBy(fn (array $line): string => $line['date'])
            ->map(fn (Collection $day, string $date): array => [
                'date' => CarbonImmutable::parse($date),
                'in' => round((float) $day->where('direction', 'in')->sum('amount'), 2),
                'out' => round((float) $day->where('direction', 'out')->sum('amount'), 2),
            ])
            ->sortBy('date')
            ->values()
            ->all();

        $kinds = [];

        foreach ([self::KIND_SALE, self::KIND_OLD_DUE, self::KIND_SUPPLIER, self::KIND_COST, self::KIND_OTHER] as $kind) {
            $ofKind = $lines->where('kind', $kind);

            if ($ofKind->isNotEmpty()) {
                $kinds[$kind] = [
                    'in' => round((float) $ofKind->where('direction', 'in')->sum('amount'), 2),
                    'out' => round((float) $ofKind->where('direction', 'out')->sum('amount'), 2),
                ];
            }
        }

        $costs = $lines->where('kind', self::KIND_COST)
            ->groupBy(fn (array $line): string => $line['label'])
            ->map(fn (Collection $group): float => round((float) $group->sum('amount'), 2))
            ->sortDesc()
            ->all();

        return [
            'start' => $start,
            'opening' => $this->openingOn($start),
            'closing' => $this->openingOn($end->addDay()->startOfDay()),
            'in' => round((float) $lines->where('direction', 'in')->sum('amount'), 2),
            'out' => round((float) $lines->where('direction', 'out')->sum('amount'), 2),
            'days' => $days,
            'kinds' => $kinds,
            'costs' => $costs,
        ];
    }

    /**
     * What the business has and owes today, in plain words: cash, bank, the
     * stock at buying price, money buyers owe; money owed to suppliers; and
     * what is left.
     *
     * @return array{cash: float, bank: float, stock: float, receivable: float, assets: float, payable: float, net: float}
     */
    public function balanceSheet(): array
    {
        $accounts = $this->accounts();
        $companyId = app(CompanyContext::class)->id();
        $balances = app(AccountBalanceService::class);

        $cash = round((float) $accounts->where('type', 'cash')->sum(fn (Account $account): float => (float) $account->current_balance), 2);
        $bank = round((float) $accounts->where('type', '!=', 'cash')->sum(fn (Account $account): float => (float) $account->current_balance), 2);
        $stock = round($balances->inventoryValue($companyId), 2);
        $receivable = round($balances->customerDue($companyId), 2);
        $payable = round((float) Supplier::query()->where('current_balance', '>', 0)->sum('current_balance'), 2);
        $assets = round($cash + $bank + $stock + $receivable, 2);

        return [
            'cash' => $cash,
            'bank' => $bank,
            'stock' => $stock,
            'receivable' => $receivable,
            'assets' => $assets,
            'payable' => $payable,
            'net' => round($assets - $payable, 2),
        ];
    }

    /**
     * Money moved from one own account to another (cash to the bank, the bank
     * to cash): an approved fund transfer, dated the day it happened.
     */
    public function moveMoney(int $fromAccountId, int $toAccountId, float $amount, string $date, ?string $note, User $by): FundTransfer
    {
        $from = $this->account($fromAccountId, 'from_account_id');
        $to = $this->account($toAccountId, 'to_account_id');
        $amount = round($amount, 2);

        if ($from->is($to)) {
            throw ValidationException::withMessages(['to_account_id' => __('Choose two different accounts.')]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('The amount must be more than zero.')]);
        }

        if ($from->projectedBalanceAfterOutflow($amount) < 0) {
            throw ValidationException::withMessages(['amount' => __(':account has only :balance.', ['account' => $from->name, 'balance' => number_format((float) $from->current_balance, 2)])]);
        }

        return DB::transaction(function () use ($from, $to, $amount, $date, $note, $by): FundTransfer {
            $transfer = FundTransfer::query()->create([
                'from_account_id' => $from->getKey(),
                'to_account_id' => $to->getKey(),
                'amount' => $amount,
                'transaction_cost' => 0,
                'remarks' => $note,
                'requested_by' => $by->getKey(),
            ]);

            app(FundTransferService::class)->approve($transfer, $by);

            // The transfer service dates its ledger rows today; the cash book needs the day it happened.
            TransactionLedger::query()
                ->where('reference_type', FundTransfer::class)
                ->where('reference_id', $transfer->getKey())
                ->get()
                ->each(fn (TransactionLedger $ledger) => $ledger->update(['transaction_date' => $date]));

            return $transfer->refresh();
        });
    }

    public function paySupplier(int $supplierId, float $amount, string $date, int $accountId, ?string $note): SupplierPayment
    {
        $account = $this->account($accountId, 'account_id');
        $supplier = Supplier::query()->find($supplierId) ?? throw ValidationException::withMessages(['supplier_id' => __('The supplier was not found.')]);

        return SupplierPayment::query()->create([
            'supplier_id' => $supplier->getKey(),
            'account_id' => $account->getKey(),
            'method' => array_key_exists((string) $account->type, SupplierPayment::METHODS) ? $account->type : 'other',
            'amount' => round($amount, 2),
            'payment_date' => $date,
            'note' => $note,
        ]);
    }

    /** A cost paid from an account: an existing category, or a new one by name. */
    public function recordCost(?int $categoryId, ?string $newCategory, float $amount, string $date, int $accountId, ?string $note): Expense
    {
        $account = $this->account($accountId, 'account_id');

        $category = $categoryId
            ? ExpenseCategory::query()->find($categoryId)
            : (filled(trim((string) $newCategory)) ? ExpenseCategory::createWithUniqueSlug(trim((string) $newCategory)) : null);

        if (! $category) {
            throw ValidationException::withMessages(['expense_category_id' => __('Choose what the cost was for.')]);
        }

        return Expense::query()->create([
            'expense_category_id' => $category->getKey(),
            'account_id' => $account->getKey(),
            'amount' => round($amount, 2),
            'expense_date' => $date,
            'note' => $note,
        ]);
    }

    protected function account(int $id, string $field): Account
    {
        return Account::query()->manual()->whereKey($id)->first()
            ?? throw ValidationException::withMessages([$field => __('Choose where the money went.')]);
    }

    /**
     * Ledger rows in words: what each one was, for whom, from which account.
     *
     * @param  Collection<int, TransactionLedger>  $ledgers
     * @param  Collection<int, Account>  $accounts
     * @return list<array{kind: string, label: string, detail: string|null, account: string, direction: string, amount: float, date: string}>
     */
    protected function lines(Collection $ledgers, Collection $accounts): array
    {
        $names = $accounts->pluck('name', 'id');
        $references = $this->references($ledgers);

        return $ledgers->map(function (TransactionLedger $ledger) use ($names, $references): array {
            $reference = $references[$ledger->reference_type][$ledger->reference_id] ?? null;
            $challan = $reference instanceof OrderPayment ? ($references['challans'][$reference->order_id] ?? null) : null;
            [$kind, $label, $detail] = $this->describe($ledger, $reference, $names, $challan);

            return [
                'kind' => $kind,
                'label' => $label,
                'detail' => $detail,
                'account' => (string) ($names[$ledger->account_id] ?? ''),
                'direction' => (string) $ledger->direction,
                'amount' => (float) $ledger->amount,
                'date' => $ledger->transaction_date?->toDateString() ?? '',
            ];
        })->values()->all();
    }

    /**
     * The records behind the ledger rows, loaded once per kind.
     *
     * @param  Collection<int, TransactionLedger>  $ledgers
     * @return array<string, array<int, mixed>>
     */
    protected function references(Collection $ledgers): array
    {
        $loaders = [
            OrderPayment::class => fn (array $ids) => OrderPayment::query()->with('order')->whereIn('id', $ids)->get(),
            CustomerPayment::class => fn (array $ids) => CustomerPayment::query()->with('customer')->whereIn('id', $ids)->get(),
            SupplierPayment::class => fn (array $ids) => SupplierPayment::query()->with('supplier')->whereIn('id', $ids)->get(),
            Expense::class => fn (array $ids) => Expense::query()->with('category')->whereIn('id', $ids)->get(),
            FundTransfer::class => fn (array $ids) => FundTransfer::query()->whereIn('id', $ids)->get(),
        ];

        $references = [];

        foreach ($ledgers->groupBy('reference_type') as $type => $rows) {
            if (isset($loaders[$type])) {
                $references[$type] = $loaders[$type]($rows->pluck('reference_id')->unique()->values()->all())->keyBy('id')->all();
            }
        }

        $orderIds = collect($references[OrderPayment::class] ?? [])->pluck('order_id')->filter()->unique()->values()->all();
        $references['challans'] = $orderIds === [] ? [] : DeliveryChallan::query()->whereIn('order_id', $orderIds)->get()->keyBy('order_id')->all();

        return $references;
    }

    /**
     * @param  Collection<int, string>  $names
     * @return array{0: string, 1: string, 2: string|null}
     */
    protected function describe(TransactionLedger $ledger, mixed $reference, Collection $names, ?DeliveryChallan $challan): array
    {
        return match (true) {
            $reference instanceof OrderPayment => $this->describeSale($reference, $challan),
            $reference instanceof CustomerPayment => [self::KIND_OLD_DUE, __('Old due received'), $reference->customer?->name],
            $reference instanceof SupplierPayment => [self::KIND_SUPPLIER, __('Paid to supplier'), $reference->supplier?->name],
            $reference instanceof Expense => [self::KIND_COST, (string) ($reference->category?->name ?? __('Cost')), $reference->note],
            $reference instanceof FundTransfer && $ledger->type === 'fund_transfer_cost' => [self::KIND_COST, __('Bank charge'), null],
            $reference instanceof FundTransfer => [
                self::KIND_MOVE,
                $ledger->direction === 'out'
                    ? __('Moved to :account', ['account' => $names[$reference->to_account_id] ?? '—'])
                    : __('Moved from :account', ['account' => $names[$reference->from_account_id] ?? '—']),
                $reference->remarks,
            ],
            default => [self::KIND_OTHER, (string) (TransactionLedger::TYPES[$ledger->type] ?? __('Other')), $ledger->note],
        };
    }

    /** @return array{0: string, 1: string, 2: string|null} */
    protected function describeSale(OrderPayment $payment, ?DeliveryChallan $challan): array
    {
        return [
            self::KIND_SALE,
            $challan ? __('Sale — challan :number', ['number' => $challan->challan_number]) : __('Sale — order :number', ['number' => $payment->order?->order_number]),
            $challan?->buyer_name ?? $payment->order?->customer_name,
        ];
    }
}
