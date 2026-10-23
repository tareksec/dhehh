<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DeliveryChallan;
use App\Models\LcFile;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TransactionLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The Trade Portal's reports (docs/tasneem-samples/PLAN.md §6, SUMMARY.md §4),
 * each built once and shown three ways (screen, PDF, Excel) from the same
 * result: a title, the period, typed columns, rows and totals. Everything is
 * read from the ERP's own records for the open company (orders and challans,
 * customers, the stock ledger, suppliers, the account ledger, LC files).
 *
 * Column types: text, date, money (Taka), usd, number, percent.
 */
class TradeReportService
{
    /** key => [title, period kind: day | month | range | none]. */
    public const REPORTS = [
        'daily_sales' => ['Daily sales', 'day'],
        'monthly_sales' => ['Monthly sales', 'month'],
        'receivables_aging' => ['Dues by age', 'none'],
        'stock_ledger' => ['Stock ledger', 'range'],
        'supplier_payable' => ['Owed to suppliers', 'none'],
        'cash_bank' => ['Cash and bank', 'range'],
        'lc_status' => ['LC status', 'none'],
        'landed_cost' => ['Landed cost per shipment', 'none'],
        'shipment_advice_register' => ['Shipment advice and insurance register', 'range'],
    ];

    /**
     * @return array{key: string, title: string, period: string, columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, totals: array<string, mixed>|null, notes: list<string>}
     */
    public function build(string $key, ?string $from = null, ?string $to = null): array
    {
        if (! array_key_exists($key, self::REPORTS)) {
            throw ValidationException::withMessages(['report' => __('This report does not exist.')]);
        }

        [$start, $end] = $this->period(self::REPORTS[$key][1], $from, $to);

        $report = match ($key) {
            'daily_sales', 'monthly_sales' => $key === 'daily_sales' ? $this->dailySales($start, $end) : $this->monthlySales($start, $end),
            'receivables_aging' => $this->receivablesAging(),
            'stock_ledger' => $this->stockLedger($start, $end),
            'supplier_payable' => $this->supplierPayable(),
            'cash_bank' => $this->cashBank($start, $end),
            'lc_status' => $this->lcStatus(),
            'landed_cost' => $this->landedCost(),
            'shipment_advice_register' => $this->adviceRegister($start, $end),
        };

        return [
            'key' => $key,
            'title' => __(self::REPORTS[$key][0]),
            'period' => $this->periodLabel(self::REPORTS[$key][1], $start, $end),
            'notes' => [],
            'totals' => null,
            ...$report,
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function period(string $kind, ?string $from, ?string $to): array
    {
        $parse = function (?string $value, CarbonImmutable $fallback): CarbonImmutable {
            try {
                return filled($value) ? CarbonImmutable::parse($value) : $fallback;
            } catch (\Throwable) {
                return $fallback;
            }
        };

        $today = CarbonImmutable::today();

        return match ($kind) {
            'day' => [$parse($from, $today)->startOfDay(), $parse($from, $today)->endOfDay()],
            'month' => [$parse($from, $today)->startOfMonth(), $parse($from, $today)->endOfMonth()],
            'range' => (function () use ($parse, $from, $to, $today): array {
                $start = $parse($from, $today->startOfMonth())->startOfDay();
                $end = $parse($to, $today)->endOfDay();

                return $end->lessThan($start) ? [$end->startOfDay(), $start->endOfDay()] : [$start, $end];
            })(),
            default => [$today, $today->endOfDay()],
        };
    }

    protected function periodLabel(string $kind, CarbonImmutable $start, CarbonImmutable $end): string
    {
        return match ($kind) {
            'day' => $start->format('d/m/Y'),
            'month' => $start->translatedFormat('F Y'),
            'range' => $start->format('d/m/Y').' – '.$end->format('d/m/Y'),
            default => __('As of :date', ['date' => $start->format('d/m/Y')]),
        };
    }

    /** @return Collection<int, Order> counted sales between the dates, with their challans */
    protected function sales(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $orders = Order::query()
            ->with(['customer', 'items.product'])
            ->whereIn('status', Order::ACCOUNTED_STATUSES)
            ->whereDate('order_date', '>=', $start->toDateString())
            ->whereDate('order_date', '<=', $end->toDateString())
            ->orderBy('order_date')
            ->orderBy('id')
            ->get();

        $challans = DeliveryChallan::query()->with(['items', 'serials'])->whereIn('order_id', $orders->modelKeys())->get()->keyBy('order_id');

        return $orders->each(fn (Order $order) => $order->setRelation('challan', $challans[$order->getKey()] ?? null));
    }

    protected function dailySales(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->sales($start, $end)->map(function (Order $order): array {
            /** @var DeliveryChallan|null $challan */
            $challan = $order->getRelation('challan');
            $items = $challan
                ? $challan->items->map(fn ($item): string => trim($item->description.' × '.$item->quantity.' '.$item->unit))->implode('; ')
                : $order->items->map(fn ($item): string => ($item->product?->name ?? '').' × '.$item->quantity)->implode('; ');
            $serials = $challan?->serials->pluck('serial_number')->implode(', ');

            return [
                'number' => $challan ? __('Challan :number', ['number' => $challan->challan_number]) : $order->order_number,
                'buyer' => $challan?->buyer_name ?? $order->customer_name,
                'phone' => $challan?->buyer_phone ?? $order->customer?->phone,
                'items' => $items.(filled($serials) ? ' — '.__('S/N: :numbers', ['numbers' => $serials]) : ''),
                'total' => (float) $order->total_amount,
                'received' => (float) $order->paid_amount,
                'due' => (float) $order->due_amount,
                'terms' => $challan?->due_terms,
            ];
        })->values()->all();

        return [
            'columns' => [
                ['key' => 'number', 'label' => __('Challan / order'), 'type' => 'text'],
                ['key' => 'buyer', 'label' => __('Buyer'), 'type' => 'text'],
                ['key' => 'phone', 'label' => __('Phone'), 'type' => 'text'],
                ['key' => 'items', 'label' => __('Items'), 'type' => 'text'],
                ['key' => 'total', 'label' => __('Total'), 'type' => 'money'],
                ['key' => 'received', 'label' => __('Received'), 'type' => 'money'],
                ['key' => 'due', 'label' => __('Due'), 'type' => 'money'],
                ['key' => 'terms', 'label' => __('When the due will be paid'), 'type' => 'text'],
            ],
            'rows' => $rows,
            'totals' => $this->sum($rows, ['total', 'received', 'due'], ['number' => __('Total')]),
        ];
    }

    protected function monthlySales(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->sales($start, $end)
            ->groupBy(fn (Order $order): string => $order->order_date->toDateString())
            ->map(fn (Collection $day, string $date): array => [
                'date' => $date,
                'count' => $day->count(),
                'total' => round((float) $day->sum('total_amount'), 2),
                'received' => round((float) $day->sum('paid_amount'), 2),
                'due' => round((float) $day->sum('due_amount'), 2),
            ])
            ->values()
            ->all();

        return [
            'columns' => [
                ['key' => 'date', 'label' => __('Date'), 'type' => 'date'],
                ['key' => 'count', 'label' => __('Sales'), 'type' => 'number'],
                ['key' => 'total', 'label' => __('Total'), 'type' => 'money'],
                ['key' => 'received', 'label' => __('Received'), 'type' => 'money'],
                ['key' => 'due', 'label' => __('Due'), 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => $this->sum($rows, ['count', 'total', 'received', 'due'], ['date' => __('Total')]),
        ];
    }

    /**
     * Each buyer's due split by how long ago the sale was (SUMMARY.md §4 report
     * 5). There are no due dates, so the age is counted from the sale date;
     * the old due brought in from the old books has a column of its own.
     */
    protected function receivablesAging(): array
    {
        $today = CarbonImmutable::today();
        $rows = [];

        foreach (Customer::query()->where('current_balance', '>', 0)->orderByDesc('current_balance')->get() as $customer) {
            $buckets = ['d0_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90' => 0.0];

            $orders = Order::query()->where('customer_id', $customer->getKey())->whereIn('status', Order::ACCOUNTED_STATUSES)->where('due_amount', '>', 0)->get(['order_date', 'due_amount']);

            foreach ($orders as $order) {
                $days = (int) $order->order_date->diffInDays($today);
                $bucket = match (true) {
                    $days <= 30 => 'd0_30',
                    $days <= 60 => 'd31_60',
                    $days <= 90 => 'd61_90',
                    default => 'd90',
                };
                $buckets[$bucket] += (float) $order->due_amount;
            }

            $rows[] = [
                'buyer' => $customer->name,
                'phone' => $customer->phone,
                ...array_map(fn (float $amount): float => round($amount, 2), $buckets),
                'old' => round(max((float) $customer->current_balance - array_sum($buckets), 0), 2),
                'total' => round((float) $customer->current_balance, 2),
            ];
        }

        return [
            'columns' => [
                ['key' => 'buyer', 'label' => __('Buyer'), 'type' => 'text'],
                ['key' => 'phone', 'label' => __('Phone'), 'type' => 'text'],
                ['key' => 'd0_30', 'label' => __('0–30 days'), 'type' => 'money'],
                ['key' => 'd31_60', 'label' => __('31–60 days'), 'type' => 'money'],
                ['key' => 'd61_90', 'label' => __('61–90 days'), 'type' => 'money'],
                ['key' => 'd90', 'label' => __('Over 90 days'), 'type' => 'money'],
                ['key' => 'old', 'label' => __('Old due'), 'type' => 'money'],
                ['key' => 'total', 'label' => __('Total due'), 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => $this->sum($rows, ['d0_30', 'd31_60', 'd61_90', 'd90', 'old', 'total'], ['buyer' => __('Total')]),
            'notes' => [__('Days are counted from the sale date. "Old due" is the due brought in from the old books (and anything not on a sale).')],
        ];
    }

    /**
     * Per item: opening + received − sold ± other = closing, at its buying
     * price (SUMMARY.md §4 report 4), from the ERP's stock ledger.
     */
    protected function stockLedger(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $signed = "CASE WHEN type IN ('sale', 'damage') THEN -ABS(quantity) WHEN type IN ('adjustment', 'transfer') THEN quantity ELSE ABS(quantity) END";

        $before = StockMovement::query()->whereDate('created_at', '<', $start->toDateString())
            ->selectRaw("product_id, SUM({$signed}) as qty")->groupBy('product_id')->pluck('qty', 'product_id');

        $within = StockMovement::query()
            ->whereDate('created_at', '>=', $start->toDateString())
            ->whereDate('created_at', '<=', $end->toDateString())
            ->selectRaw("product_id, type, SUM({$signed}) as qty")
            ->groupBy('product_id', 'type')
            ->get()
            ->groupBy('product_id');

        $rows = Product::query()
            ->where('has_variants', false)
            ->where('status', '!=', Product::STATUS_COMING_SOON)
            ->orderBy('name')
            ->get()
            ->map(function (Product $product) use ($before, $within): array {
                $moves = $within->get($product->getKey(), collect())->pluck('qty', 'type');
                $opening = (int) ($before[$product->getKey()] ?? 0);
                $received = (int) (($moves['purchase'] ?? 0) + ($moves['opening'] ?? 0) + ($moves['return'] ?? 0));
                $sold = (int) abs((int) ($moves['sale'] ?? 0));
                $other = (int) (($moves['adjustment'] ?? 0) + ($moves['damage'] ?? 0) + ($moves['transfer'] ?? 0));
                $closing = $opening + $received - $sold + $other;

                return [
                    'item' => $product->name,
                    'unit' => $product->unit ?: 'pcs',
                    'opening' => $opening,
                    'received' => $received,
                    'sold' => $sold,
                    'other' => $other,
                    'closing' => $closing,
                    'cost' => (float) $product->cost_price,
                    'value' => round($closing * (float) $product->cost_price, 2),
                ];
            })
            ->filter(fn (array $row): bool => $row['opening'] !== 0 || $row['received'] !== 0 || $row['sold'] !== 0 || $row['other'] !== 0 || $row['closing'] !== 0)
            ->values()
            ->all();

        return [
            'columns' => [
                ['key' => 'item', 'label' => __('Item'), 'type' => 'text'],
                ['key' => 'unit', 'label' => __('Unit'), 'type' => 'text'],
                ['key' => 'opening', 'label' => __('Opening'), 'type' => 'number'],
                ['key' => 'received', 'label' => __('Received'), 'type' => 'number'],
                ['key' => 'sold', 'label' => __('Sold'), 'type' => 'number'],
                ['key' => 'other', 'label' => __('Corrections'), 'type' => 'number'],
                ['key' => 'closing', 'label' => __('Closing'), 'type' => 'number'],
                ['key' => 'cost', 'label' => __('Buying price'), 'type' => 'money'],
                ['key' => 'value', 'label' => __('Value'), 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => $this->sum($rows, ['value'], ['item' => __('Total')]),
            'notes' => [
                __('Value = closing quantity × buying price (the landed cost once applied).'),
                __('Stock moves are counted on the day they were entered in the system.'),
            ],
        ];
    }

    protected function supplierPayable(): array
    {
        $rows = Supplier::query()->where('current_balance', '>', 0)->orderByDesc('current_balance')->get()
            ->map(function (Supplier $supplier): array {
                $openLcs = LcFile::query()->where('stage', '!=', 'cleared')->whereHas('purchase', fn ($query) => $query->where('supplier_id', $supplier->getKey()))->count();

                return [
                    'supplier' => $supplier->company_name ?: $supplier->name,
                    'phone' => $supplier->phone,
                    'open_lcs' => $openLcs,
                    'due' => round((float) $supplier->current_balance, 2),
                ];
            })->values()->all();

        return [
            'columns' => [
                ['key' => 'supplier', 'label' => __('Supplier'), 'type' => 'text'],
                ['key' => 'phone', 'label' => __('Phone'), 'type' => 'text'],
                ['key' => 'open_lcs', 'label' => __('Open LCs'), 'type' => 'number'],
                ['key' => 'due', 'label' => __('Owed'), 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => $this->sum($rows, ['due'], ['supplier' => __('Total')]),
        ];
    }

    /**
     * Per cash / bank account: opening, money in, money out and closing for
     * the period (SUMMARY.md §4 report 1), with the LC margins held by banks.
     */
    protected function cashBank(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $cashBook = app(TradeCashBookService::class);

        $rows = $cashBook->accounts()->map(function ($account) use ($cashBook, $start, $end): array {
            $within = TransactionLedger::query()->where('account_id', $account->getKey())
                ->whereDate('transaction_date', '>=', $start->toDateString())
                ->whereDate('transaction_date', '<=', $end->toDateString());
            $in = round((float) (clone $within)->where('direction', 'in')->sum('amount'), 2);
            $out = round((float) (clone $within)->where('direction', 'out')->sum('amount'), 2);
            $opening = round($cashBook->accountBalanceBefore($account, $start), 2);

            return [
                'account' => $account->name,
                'opening' => $opening,
                'in' => $in,
                'out' => $out,
                'closing' => round($opening + $in - $out, 2),
            ];
        })->values()->all();

        $margins = round((float) LcFile::query()->where('stage', '!=', 'cleared')->sum('margin_amount_bdt'), 2);

        return [
            'columns' => [
                ['key' => 'account', 'label' => __('Account'), 'type' => 'text'],
                ['key' => 'opening', 'label' => __('Opening'), 'type' => 'money'],
                ['key' => 'in', 'label' => __('Money in'), 'type' => 'money'],
                ['key' => 'out', 'label' => __('Money out'), 'type' => 'money'],
                ['key' => 'closing', 'label' => __('Closing'), 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => $this->sum($rows, ['opening', 'in', 'out', 'closing'], ['account' => __('Total')]),
            'notes' => [
                __('LC margins held by the bank (LCs not cleared): :amount', ['amount' => number_format($margins, 2)]),
                __('Money moved between your own accounts is in and out of both, so the total is not changed by it.'),
            ],
        ];
    }

    /** @return Collection<int, LcFile> */
    protected function lcFiles(): Collection
    {
        return LcFile::query()->with(['purchase.supplier', 'purchase.items.product', 'landedCostLines'])->orderByDesc('id')->get();
    }

    /** SUMMARY.md §4 report 2: every LC file with its numbers, shipment, documents and stage. */
    protected function lcStatus(): array
    {
        $rows = $this->lcFiles()->map(function (LcFile $file): array {
            $purchase = $file->purchase;

            return [
                'lc' => trim(($purchase?->lc_number ?? '').' '.($purchase?->lc_date?->format('d/m/Y') ?? '')),
                'supplier' => $purchase?->supplier?->company_name ?: $purchase?->supplier?->name,
                'pi' => trim(($purchase?->pi_number ?? '').' '.($purchase?->pi_date?->format('d/m/Y') ?? '')),
                'items' => $purchase?->items->map(fn ($item): string => ($item->product?->name ?? '').' × '.$item->quantity)->implode('; '),
                'value' => $file->lcValue(),
                'margin' => $file->margin_percent !== null ? (float) $file->margin_percent : null,
                'margin_bdt' => $file->margin_amount_bdt !== null ? (float) $file->margin_amount_bdt : null,
                'ci' => trim(($purchase?->ci_number ?? '').' '.($purchase?->ci_date?->format('d/m/Y') ?? '')),
                'bl' => trim(($file->bl_number ?? '').' '.($file->bl_date?->format('d/m/Y') ?? '')),
                'container' => collect([$file->container_number, $file->seal_number])->filter()->implode(' / '),
                'vessel' => collect([$file->carrier, $file->vessel_name])->filter()->implode(' · '),
                'eta' => $file->eta?->toDateString(),
                'documents' => LcFile::docStatusLabel($file->doc_status),
                'stage' => LcFile::stageLabel($file->stage),
            ];
        })->values()->all();

        return [
            'columns' => [
                ['key' => 'lc', 'label' => __('LC no. and date'), 'type' => 'text'],
                ['key' => 'supplier', 'label' => __('Supplier'), 'type' => 'text'],
                ['key' => 'pi', 'label' => __('PI no. and date'), 'type' => 'text'],
                ['key' => 'items', 'label' => __('Items'), 'type' => 'text'],
                ['key' => 'value', 'label' => __('LC value (USD)'), 'type' => 'usd'],
                ['key' => 'margin', 'label' => __('Margin %'), 'type' => 'percent'],
                ['key' => 'margin_bdt', 'label' => __('Margin paid (Taka)'), 'type' => 'money'],
                ['key' => 'ci', 'label' => __('Commercial Invoice no. and date'), 'type' => 'text'],
                ['key' => 'bl', 'label' => __('Bill of lading no.'), 'type' => 'text'],
                ['key' => 'container', 'label' => __('Container no., seal no. and packages'), 'type' => 'text'],
                ['key' => 'vessel', 'label' => __('Vessel name'), 'type' => 'text'],
                ['key' => 'eta', 'label' => __('ETA (arrives)'), 'type' => 'date'],
                ['key' => 'documents', 'label' => __('Documents at the bank'), 'type' => 'text'],
                ['key' => 'stage', 'label' => __('Stage'), 'type' => 'text'],
            ],
            'rows' => $rows,
            'totals' => $this->sum($rows, ['value', 'margin_bdt'], ['lc' => __('Total')]),
        ];
    }

    /** SUMMARY.md §4 report 3: each shipment's landed cost, from LandedCostService. */
    protected function landedCost(): array
    {
        $service = app(LandedCostService::class);

        $rows = $this->lcFiles()->map(function (LcFile $file) use ($service): array {
            $sheet = $service->worksheet($file);

            return [
                'lc' => $file->purchase?->lc_number ?: $file->purchase?->purchase_number,
                'ci' => $file->purchase?->ci_number,
                'value' => $sheet['goods_usd'],
                'rate' => $sheet['rate'],
                'goods' => $sheet['goods_bdt'],
                'costs' => $sheet['costs'],
                'total' => $sheet['total'],
                'quantity' => $sheet['quantity'],
                'per_unit' => $sheet['per_unit'],
            ];
        })->values()->all();

        return [
            'columns' => [
                ['key' => 'lc', 'label' => __('LC no.'), 'type' => 'text'],
                ['key' => 'ci', 'label' => __('Commercial Invoice no.'), 'type' => 'text'],
                ['key' => 'value', 'label' => __('LC value (USD)'), 'type' => 'usd'],
                ['key' => 'rate', 'label' => __('Bank rate'), 'type' => 'number'],
                ['key' => 'goods', 'label' => __('Goods (CFR)'), 'type' => 'money'],
                ['key' => 'costs', 'label' => __('Costs'), 'type' => 'money'],
                ['key' => 'total', 'label' => __('Total landed cost'), 'type' => 'money'],
                ['key' => 'quantity', 'label' => __('Quantity'), 'type' => 'number'],
                ['key' => 'per_unit', 'label' => __('Landed cost per unit'), 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => $this->sum($rows, ['value', 'goods', 'costs', 'total', 'quantity'], ['lc' => __('Total')]),
        ];
    }

    /** SUMMARY.md §4 report 6: shipment advices (by advice date, else the certificate date) with their insurance. */
    protected function adviceRegister(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->lcFiles()
            ->map(function (LcFile $file): ?array {
                $date = $file->shipment_advice_date ?? $file->certificate_date;

                if ($date === null) {
                    return null;
                }

                $purchase = $file->purchase;

                return [
                    'date' => $date->toDateString(),
                    'lc' => trim(($purchase?->lc_number ?? '').' '.($purchase?->lc_date?->format('d/m/Y') ?? '')),
                    'insurer' => $file->insurer_name,
                    'cover_note' => trim(($file->cover_note_number ?? '').' '.($file->cover_note_date?->format('d/m/Y') ?? '')),
                    'ci' => $purchase?->ci_number,
                    'value' => $file->lcValue(),
                    'vessel' => collect([$file->vessel_name, $file->bl_number])->filter()->implode(' · '),
                    'ports' => collect([$purchase?->port_of_loading, $purchase?->port_of_discharge])->filter()->implode(' → '),
                    'container' => collect([$file->container_number, $file->seal_number])->filter()->implode(' / '),
                    'weights' => number_format($file->netWeight(), 0).' / '.number_format($file->grossWeight(), 0).($file->cbm ? ' · '.rtrim(rtrim(number_format((float) $file->cbm, 2), '0'), '.').' CBM' : ''),
                ];
            })
            ->filter()
            ->filter(fn (array $row): bool => $row['date'] >= $start->toDateString() && $row['date'] <= $end->toDateString())
            ->sortBy('date')
            ->values()
            ->all();

        return [
            'columns' => [
                ['key' => 'date', 'label' => __('Advice date'), 'type' => 'date'],
                ['key' => 'lc', 'label' => __('LC no. and date'), 'type' => 'text'],
                ['key' => 'insurer', 'label' => __('Insurance company'), 'type' => 'text'],
                ['key' => 'cover_note', 'label' => __('Insurance cover note no. and date'), 'type' => 'text'],
                ['key' => 'ci', 'label' => __('Commercial Invoice no.'), 'type' => 'text'],
                ['key' => 'value', 'label' => __('LC value (USD)'), 'type' => 'usd'],
                ['key' => 'vessel', 'label' => __('Vessel and B/L'), 'type' => 'text'],
                ['key' => 'ports', 'label' => __('Port of loading and port of discharge'), 'type' => 'text'],
                ['key' => 'container', 'label' => __('Container no., seal no. and packages'), 'type' => 'text'],
                ['key' => 'weights', 'label' => __('Net / gross weight (KGS)'), 'type' => 'text'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Totals of the given number columns, plus fixed labels.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $columns
     * @param  array<string, string>  $labels
     * @return array<string, mixed>|null
     */
    protected function sum(array $rows, array $columns, array $labels): ?array
    {
        if ($rows === []) {
            return null;
        }

        $totals = $labels;

        foreach ($columns as $column) {
            $totals[$column] = round((float) array_sum(array_map(fn (array $row): float => (float) ($row[$column] ?? 0), $rows)), 2);
        }

        return $totals;
    }
}
