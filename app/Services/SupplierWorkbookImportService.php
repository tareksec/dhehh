<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\Supplier;
use App\Models\SupplierAccountAdjustment;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * Copies the owner's hand-kept supplier workbook ("Shahin Vai - Product Details")
 * into the ERP as history: what each product received (with cartons, short,
 * damage and buy price), the goods the supplier took with the advance he
 * collected, and the cash, shipping and ad cost totals of the Summary sheet.
 *
 * History never moves stock (today's stock already counts it) and never touches
 * an account balance: receipts and orders are saved with books_stock off, and
 * the money totals become account entries. Running it again with "replace"
 * swaps the earlier import for the new figures, so the workbook can keep being
 * edited and imported again.
 */
class SupplierWorkbookImportService
{
    public const RECEIPT_REFERENCE = 'workbook-import';

    public const KEY_PREFIX = 'wb:';

    /** Product tabs are about 70 rows; the big sheets (Bills, Shipment) are never needed. */
    private const MAX_ROWS = 150;

    /**
     * @param  array<string, string>  $productMap  workbook product name => ERP product SKU (or variant SKU)
     * @return array<string, mixed> the report (see summary())
     */
    public function run(
        string $path,
        Supplier $supplier,
        bool $dryRun = true,
        ?CarbonInterface $openingDate = null,
        array $productMap = [],
        bool $replace = false,
        bool $skipUnmatched = false,
    ): array {
        $sheets = $this->readSheets($path);
        $tabs = collect($sheets)
            ->map(fn (array $rows, string $name): ?array => $this->parseTab($name, $rows))
            ->filter()
            ->values();

        if ($tabs->isEmpty()) {
            throw ValidationException::withMessages(['file' => __('No product tabs were found in this workbook.')]);
        }

        $workbookSummary = $this->parseSummary($sheets['Summary'] ?? []);
        $opening = ($openingDate ?? $this->earliestDate($tabs->all()) ?? now())->copy()->startOfDay();
        $index = $this->productIndex();
        $report = [
            'dry_run' => $dryRun,
            'products' => [],
            'unmatched' => [],
            'warnings' => [],
            'receipt_items' => 0,
            'orders' => 0,
            'skipped_rows' => 0,
            'adjustments' => 0,
            'figures' => [],
            'errors' => [],
            'opening_date' => $opening->toDateString(),
        ];

        $matched = [];
        $valueReceived = 0.0;
        $goodsTaken = 0.0;
        $advanceTotal = 0.0;

        foreach ($tabs as $tab) {
            $target = $this->resolve($tab, $productMap, $index);
            $valueReceived += $tab['received'] * $tab['buy'];
            $goodsTaken += collect($tab['taken'])->sum('quantity') * $tab['buy'];
            $advanceTotal += collect($tab['taken'])->sum('advance');

            $report['products'][] = [
                'tab' => $tab['tab'],
                'name' => $tab['name'],
                'erp' => $target ? ($target['product']->name.($target['variant'] ? ' / '.$target['variant']->label() : '')) : null,
                'received' => $tab['received'],
                'buy' => $tab['buy'],
                'taken' => (int) collect($tab['taken'])->sum('quantity'),
            ];

            if ($tab['received'] > 0 && $tab['buy'] <= 0) {
                $report['warnings'][] = __(':product has goods received but no buy price.', ['product' => $tab['name']]);
            }

            foreach ($tab['taken'] as $row) {
                $target && $row['quantity'] > 0 ? $report['orders']++ : $report['skipped_rows']++;
            }

            if (! $target) {
                $report['unmatched'][$tab['name']] = $this->suggestions($tab['name'], $index);

                continue;
            }

            $matched[] = [$tab, $target];
        }

        $report['figures'] = $this->figures($workbookSummary, $valueReceived, $goodsTaken, $advanceTotal);

        if ($report['unmatched'] !== [] && ! $skipUnmatched) {
            $report['warnings'][] = __(':count products are not matched to an ERP product yet, so nothing can be saved.', ['count' => count($report['unmatched'])]);
        }

        $future = [];
        $undated = 0;

        foreach ($matched as [$tab]) {
            foreach ($tab['taken'] as $row) {
                if ($row['date'] === null) {
                    $undated++;
                } elseif ($row['date']->isFuture()) {
                    $future[] = $tab['name'].' '.$row['date']->format('d M Y');
                }
            }
        }

        if ($undated > 0) {
            $report['warnings'][] = __(':count taken rows have no date, so they are dated :date.', ['count' => $undated, 'date' => $opening->format('d M Y')]);
        }

        if ($future !== []) {
            $report['warnings'][] = __('These rows are dated in the future, check the day and month: :rows', ['rows' => implode(', ', array_slice($future, 0, 8))]);
        }

        $entries = $this->entries($workbookSummary);
        $report['adjustments'] = count($entries);
        $report['receipt_items'] = collect($matched)->filter(fn (array $pair): bool => $pair[0]['received'] > 0)->count();

        if ($dryRun) {
            return $report;
        }

        if ($report['unmatched'] !== [] && ! $skipUnmatched) {
            throw ValidationException::withMessages(['file' => __('Match every product first, or choose to skip the unmatched ones.')]);
        }

        $alreadyImported = PurchaseReceipt::query()
            ->where('supplier_id', $supplier->getKey())
            ->where('reference', self::RECEIPT_REFERENCE)
            ->exists();

        if ($alreadyImported && ! $replace) {
            throw ValidationException::withMessages(['file' => __('This supplier already has an import from a workbook. Choose replace to swap it for these figures.')]);
        }

        try {
            DB::transaction(function () use ($supplier, $opening, $matched, $entries, $replace): void {
                if ($replace) {
                    $this->removePrevious($supplier);
                }

                $this->save($supplier, $opening, $matched, $entries);
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $report['errors'][] = Str::limit($exception->getMessage(), 200);
        }

        return $report;
    }

    /**
     * Human-readable lines for the notification and the artisan command.
     *
     * @param  array<string, mixed>  $report
     * @return array<int, string>
     */
    public function summary(array $report): array
    {
        $lines = [
            $report['dry_run']
                ? __('Preview only: nothing was saved.')
                : __('Imported from the workbook.'),
            __(':count products in the workbook, :matched matched to an ERP product.', [
                'count' => count($report['products']),
                'matched' => collect($report['products'])->whereNotNull('erp')->count(),
            ]),
            __(':items received lines (dated :date), :orders supplier orders, :entries account entries.', [
                'items' => $report['receipt_items'],
                'date' => Carbon::parse($report['opening_date'])->format('d M Y'),
                'orders' => $report['orders'],
                'entries' => $report['adjustments'],
            ]),
        ];

        if ($report['skipped_rows'] > 0) {
            $lines[] = __(':count taken rows were left out (no pieces, or the product is not matched).', ['count' => $report['skipped_rows']]);
        }

        foreach ($report['figures'] as $figure) {
            $lines[] = sprintf(
                '%s: %s  |  %s: %s  %s',
                __('Workbook'),
                $figure['workbook'] === null ? '-' : number_format($figure['workbook'], 2),
                __('Import'),
                number_format($figure['import'], 2),
                $figure['label'].($figure['ok'] ? ' ✓' : ' ✗'),
            );
        }

        foreach ($report['unmatched'] as $name => $suggestions) {
            $lines[] = __('Not matched: :name', ['name' => $name]).($suggestions !== [] ? ' ('.__('maybe').' '.implode(', ', $suggestions).')' : '');
        }

        foreach ($report['warnings'] as $warning) {
            $lines[] = $warning;
        }

        foreach ($report['errors'] as $error) {
            $lines[] = __('Error: :error', ['error' => $error]);
        }

        return $lines;
    }

    /**
     * Every sheet of the file as rows of values, at most MAX_ROWS rows each.
     *
     * @return array<string, array<int, array<int, mixed>>>
     */
    private function readSheets(string $path): array
    {
        $reader = new Reader;
        $sheets = [];

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];

                foreach ($sheet->getRowIterator() as $row) {
                    // A formula cell holds its formula; what the owner sees is the value Excel saved with it.
                    $rows[] = array_map(
                        fn ($cell) => $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue(),
                        $row->getCells(),
                    );

                    if (count($rows) >= self::MAX_ROWS) {
                        break;
                    }
                }

                $sheets[$sheet->getName()] = $rows;
            }

            $reader->close();
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['file' => __('The file could not be read as an Excel workbook (.xlsx).')]);
        }

        return $sheets;
    }

    /**
     * One product tab: what was received, the buy price and the rows of goods
     * the supplier took. Cells are found by their label, not by position.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function parseTab(string $tab, array $rows): ?array
    {
        $headerAt = null;

        foreach (array_slice($rows, 0, 14, true) as $at => $row) {
            if (in_array('Received Ctn.', $row, true)) {
                $headerAt = $at;

                break;
            }
        }

        if ($headerAt === null) {
            return null;
        }

        $header = array_flip(array_map(fn ($cell): string => trim((string) $cell), $rows[$headerAt]));
        $data = $rows[$headerAt + 1] ?? [];
        $name = trim((string) ($data[$header['Product name'] ?? 0] ?? '')) ?: $tab;

        $tab = [
            'tab' => $tab,
            'name' => $name,
            'sku' => trim((string) ($data[$header['SKU (sheet)'] ?? 1] ?? '')),
            'cartons' => $this->number($data[$header['Received Ctn.']] ?? 0),
            'received' => (int) round($this->number($data[$header['Received Qty.'] ?? -1] ?? 0)),
            'short' => 0,
            'damage' => 0,
            'buy' => 0.0,
            'taken' => [],
        ];

        foreach ($rows as $at => $row) {
            $first = trim((string) ($row[0] ?? ''));

            if (in_array('Damage % of received', $row, true)) {
                $columns = array_flip(array_map(fn ($cell): string => trim((string) $cell), $row));
                $figures = $rows[$at + 1] ?? [];
                $tab['damage'] = (int) round($this->number($figures[$columns['Damage Qty.'] ?? 0] ?? 0));
                $tab['short'] = (int) round($this->number($figures[$columns['Short Qty.'] ?? 2] ?? 0));
            }

            if (str_starts_with($first, 'Buy price per pc')) {
                $tab['buy'] = $this->number($row[1] ?? 0);
            }

            if (str_starts_with($first, 'Customer (')) {
                $tab['taken'] = $this->takenRows($tab['tab'], array_slice($rows, $at + 1, null, true));
            }
        }

        return $tab;
    }

    /**
     * The rows of "Taken by" until the total line.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function takenRows(string $tab, array $rows): array
    {
        $taken = [];

        foreach ($rows as $at => $row) {
            $first = trim((string) ($row[0] ?? ''));

            $blankRow = collect($row)->filter(fn ($cell): bool => $cell !== null && $cell !== '')->isEmpty();

            if (str_starts_with($first, 'Total qty') || $blankRow) {
                break;
            }

            $advance = $this->advance($row[3] ?? null);
            $line = $this->number($row[6] ?? 0);

            $taken[] = [
                'key' => self::KEY_PREFIX.Str::slug($tab).':'.($line > 0 ? (int) $line : 'r'.$at),
                'customer' => $first === '' || str_starts_with($first, '(no customer') ? null : $first,
                'date' => $this->date($row[1] ?? null),
                'quantity' => (int) round($this->number($row[2] ?? 0)),
                'advance' => $advance['amount'],
                'full_paid' => $advance['full_paid'],
                'advance_text' => is_string($row[3] ?? null) ? trim($row[3]) : null,
                'paid_by' => trim((string) ($row[4] ?? '')),
            ];
        }

        return $taken;
    }

    /**
     * The account block of the Summary sheet: the figures the owner sees.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<string, float|null>
     */
    private function parseSummary(array $rows): array
    {
        $labels = [
            'value_received' => 'value of goods received',
            'goods_taken' => 'less: goods',
            'advance' => 'less: customer advance',
            'after_deductions' => 'value after',
            'cash' => 'cash we gave',
            'shipping' => 'shipping we paid',
            'ad_cost' => 'ad cost we paid',
            'total_given' => 'total we gave',
        ];
        $figures = array_fill_keys([...array_keys($labels), 'balance'], null);

        foreach (array_slice($rows, 0, 30) as $row) {
            $first = mb_strtolower(trim((string) ($row[0] ?? '')));

            foreach ($labels as $key => $prefix) {
                if ($first !== '' && str_starts_with($first, $prefix) && is_numeric($row[1] ?? null)) {
                    $figures[$key] = (float) $row[1];
                }
            }

            if (str_contains($first, 'still gets from us') && is_numeric($row[1] ?? null)) {
                $figures['balance'] = (float) $row[1];
            }
        }

        return $figures;
    }

    /**
     * Workbook figure next to the figure the import gives, for the checks the
     * owner cares about: the same account the workbook shows.
     *
     * @param  array<string, float|null>  $workbook
     * @return array<int, array{label: string, workbook: float|null, import: float, ok: bool}>
     */
    private function figures(array $workbook, float $valueReceived, float $goodsTaken, float $advance): array
    {
        $import = [
            'value_received' => [__('Value of goods received'), $valueReceived],
            'goods_taken' => [__('Less: goods the supplier took from our stock'), $goodsTaken],
            'advance' => [__('Less: customer advance the supplier collected'), $advance],
        ];

        // What the workbook says we still owe, rebuilt from the same parts: the check that matters most.
        $given = (float) ($workbook['cash'] ?? 0) + (float) ($workbook['shipping'] ?? 0) + (float) ($workbook['ad_cost'] ?? 0);
        $import['balance'] = [__('Supplier still gets from us'), $valueReceived - $goodsTaken - $advance - $given];

        $lines = [];

        foreach ($import as $key => [$label, $value]) {
            $lines[] = [
                'label' => $label,
                'workbook' => $workbook[$key],
                'import' => round($value, 2),
                'ok' => $workbook[$key] !== null && abs($workbook[$key] - $value) < 0.01,
            ];
        }

        return $lines;
    }

    /**
     * The money totals of the Summary sheet as account entries.
     *
     * @param  array<string, float|null>  $summary
     * @return array<int, array{type: string, amount: float, key: string}>
     */
    private function entries(array $summary): array
    {
        $entries = [];

        foreach ([
            'cash' => SupplierAccountAdjustment::TYPE_CASH,
            'shipping' => SupplierAccountAdjustment::TYPE_SHIPPING,
            'ad_cost' => SupplierAccountAdjustment::TYPE_AD_COST,
        ] as $key => $type) {
            if (($summary[$key] ?? 0) > 0) {
                $entries[] = ['type' => $type, 'amount' => (float) $summary[$key], 'key' => self::KEY_PREFIX.$key];
            }
        }

        return $entries;
    }

    /**
     * @param  array<int, array{0: array<string, mixed>, 1: array<string, mixed>}>  $matched
     * @param  array<int, array{type: string, amount: float, key: string}>  $entries
     */
    private function save(Supplier $supplier, CarbonInterface $opening, array $matched, array $entries): void
    {
        $receipt = PurchaseReceipt::query()->create([
            'supplier_id' => $supplier->getKey(),
            'received_on' => $opening->toDateString(),
            'reference' => self::RECEIPT_REFERENCE,
            'is_opening' => true,
            'books_stock' => false,
            'note' => __('Copied from the supplier workbook. Cartons, short and damage are the workbook totals; stock is not changed.'),
        ]);

        foreach ($matched as [$tab, $target]) {
            if ($tab['received'] > 0) {
                PurchaseReceiptItem::query()->create([
                    'purchase_receipt_id' => $receipt->getKey(),
                    'product_id' => $target['product']->getKey(),
                    'product_variant_id' => $target['variant']?->getKey(),
                    'cartons' => $tab['cartons'] > 0 ? (int) $tab['cartons'] : null,
                    'pcs_per_carton' => $tab['cartons'] > 0 && $tab['received'] % (int) $tab['cartons'] === 0
                        ? intdiv($tab['received'], (int) $tab['cartons'])
                        : null,
                    'quantity' => $tab['received'],
                    'short_quantity' => min($tab['short'], $tab['received']),
                    'damage_quantity' => min($tab['damage'], max($tab['received'] - $tab['short'], 0)),
                    'unit_cost' => $tab['buy'],
                ]);
            }

            foreach ($tab['taken'] as $row) {
                if ($row['quantity'] <= 0) {
                    continue;
                }

                $order = SupplierOrder::query()->create([
                    'supplier_id' => $supplier->getKey(),
                    'external_reference' => $row['key'].':'.$supplier->getKey(),
                    'order_date' => ($row['date'] ?? $opening)->toDateString(),
                    'customer_name' => $row['customer'],
                    'status' => SupplierOrder::STATUS_DELIVERED,
                    'advance_amount' => $row['advance'],
                    'advance_collected_by' => SupplierOrder::COLLECTED_BY_SUPPLIER,
                    'books_stock' => false,
                    'note' => trim(implode(' ', array_filter([
                        __('Copied from the supplier workbook.'),
                        $row['full_paid'] ? __('Customer paid in full (amount not in the workbook).') : null,
                        $row['advance_text'] && ! $row['full_paid'] ? $row['advance_text'] : null,
                        $row['paid_by'] !== '' ? __('Paid by :name.', ['name' => $row['paid_by']]) : null,
                    ]))),
                ]);

                SupplierOrderItem::query()->create([
                    'supplier_order_id' => $order->getKey(),
                    'product_id' => $target['product']->getKey(),
                    'product_variant_id' => $target['variant']?->getKey(),
                    'quantity' => $row['quantity'],
                    'unit_price' => 0,
                    'supplier_unit_cost' => $tab['buy'],
                ]);
            }
        }

        foreach ($entries as $entry) {
            SupplierAccountAdjustment::query()->create([
                'supplier_id' => $supplier->getKey(),
                'entry_date' => now()->toDateString(),
                'type' => $entry['type'],
                'amount' => $entry['amount'],
                'external_reference' => $entry['key'].':'.$supplier->getKey(),
                'note' => __('Total from the supplier workbook.'),
            ]);
        }
    }

    /** Removes what an earlier import of this supplier created. */
    private function removePrevious(Supplier $supplier): void
    {
        PurchaseReceipt::query()
            ->where('supplier_id', $supplier->getKey())
            ->where('reference', self::RECEIPT_REFERENCE)
            ->get()
            ->each->delete();

        SupplierOrder::query()
            ->where('supplier_id', $supplier->getKey())
            ->where('external_reference', 'like', self::KEY_PREFIX.'%')
            ->get()
            ->each->delete();

        SupplierAccountAdjustment::query()
            ->where('supplier_id', $supplier->getKey())
            ->where('external_reference', 'like', self::KEY_PREFIX.'%')
            ->get()
            ->each->delete();
    }

    /**
     * @return array{by_name: array<string, array<string, mixed>>, by_sku: array<string, array<string, mixed>>, names: array<string, string>}
     */
    private function productIndex(): array
    {
        $byName = [];
        $bySku = [];
        $names = [];

        foreach (Product::query()->with('variants')->get() as $product) {
            $target = ['product' => $product, 'variant' => null];
            $names[$product->name] = $this->normalize($product->name);

            if (! $product->has_variants) {
                $byName[$this->normalize($product->name)] ??= $target;

                if (filled($product->sku)) {
                    $bySku[$this->normalize($product->sku)] ??= $target;
                }

                continue;
            }

            foreach ($product->variants as $variant) {
                $label = $product->name.' '.$variant->label();
                $names[$label] = $this->normalize($label);
                $byName[$this->normalize($label)] ??= ['product' => $product, 'variant' => $variant];

                if (filled($variant->sku)) {
                    $bySku[$this->normalize($variant->sku)] ??= ['product' => $product, 'variant' => $variant];
                }
            }
        }

        return ['by_name' => $byName, 'by_sku' => $bySku, 'names' => $names];
    }

    /**
     * The ERP product (and variant) a workbook product stands for: the owner's
     * map first, then the same name, never a guess.
     *
     * @param  array<string, mixed>  $tab
     * @param  array<string, string>  $productMap
     * @param  array<string, mixed>  $index
     * @return array{product: Product, variant: ProductVariant|null}|null
     */
    private function resolve(array $tab, array $productMap, array $index): ?array
    {
        $normalizedMap = collect($productMap)->mapWithKeys(fn (string $sku, string $name): array => [$this->normalize($name) => $this->normalize($sku)]);

        foreach ([$tab['name'], $tab['tab']] as $label) {
            $sku = $normalizedMap[$this->normalize($label)] ?? null;

            if ($sku !== null && isset($index['by_sku'][$sku])) {
                return $index['by_sku'][$sku];
            }
        }

        foreach ([$tab['name'], $tab['tab']] as $label) {
            if (isset($index['by_name'][$this->normalize($label)])) {
                return $index['by_name'][$this->normalize($label)];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $index
     * @return array<int, string>
     */
    private function suggestions(string $name, array $index): array
    {
        $needle = $this->normalize($name);

        return collect($index['names'])
            ->map(function (string $normalized) use ($needle): float {
                similar_text($needle, $normalized, $percent);

                return $percent;
            })
            ->filter(fn (float $percent): bool => $percent >= 45)
            ->sortDesc()
            ->keys()
            ->take(3)
            ->all();
    }

    private function normalize(string $value): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value));
    }

    /** @param  array<int, array<string, mixed>>  $tabs */
    private function earliestDate(array $tabs): ?CarbonInterface
    {
        return collect($tabs)
            ->flatMap(fn (array $tab): array => array_column($tab['taken'], 'date'))
            ->filter()
            ->sort()
            ->first();
    }

    private function number(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return is_string($value) && preg_match('/-?\d[\d,]*(?:\.\d+)?/', $value, $match)
            ? (float) str_replace(',', '', $match[0])
            : 0.0;
    }

    /**
     * The advance cell is a number, or text such as "City Bank - 80000" (the amount after the dash)
     * or "Full Paid" (paid in full, no amount written).
     *
     * @return array{amount: float, full_paid: bool}
     */
    private function advance(mixed $cell): array
    {
        if (is_numeric($cell)) {
            return ['amount' => round((float) $cell, 2), 'full_paid' => false];
        }

        if (! is_string($cell) || trim($cell) === '') {
            return ['amount' => 0.0, 'full_paid' => false];
        }

        if (preg_match('/[-–:]\s*(\d[\d,]*(?:\.\d+)?)\s*$/u', trim($cell), $match)) {
            return ['amount' => round((float) str_replace(',', '', $match[1]), 2), 'full_paid' => false];
        }

        return ['amount' => 0.0, 'full_paid' => (bool) preg_match('/full\s*paid/i', $cell)];
    }

    private function date(mixed $value): ?CarbonInterface
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->startOfDay();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $text = trim($value);

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $text);

                if ($date !== false && $date->format($format) === $text) {
                    return $date->startOfDay();
                }
            } catch (Throwable) {
                // not this format
            }
        }

        try {
            return Carbon::parse($text)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
