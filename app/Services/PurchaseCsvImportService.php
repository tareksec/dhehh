<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Creates purchases from a CSV (owner request 2026-10-21, modelled on the
 * Stock History sheet: date, product, quantity).
 *
 * Rules the owner chose:
 *  - The supplier and the status (Draft or Received) are picked once at upload.
 *  - One purchase per date: every row of the same date becomes an item of one
 *    purchase. A blank date cell takes the date of the row above (merged cells
 *    in a spreadsheet export).
 *  - A product is found by SKU, then by exact name. A row whose product is not
 *    found, or that is otherwise invalid, is skipped and listed; nothing is
 *    guessed and no product is created.
 *  - A blank unit cost uses the product's current cost price.
 *  - Importing the same file again does not duplicate: a purchase carries a
 *    `[csv:<ref>]` marker in its note and one that already exists is skipped.
 */
class PurchaseCsvImportService
{
    public const HEADINGS = ['date', 'sku', 'product', 'quantity', 'unit_cost', 'short_qty', 'damaged_qty'];

    private const ALIASES = [
        'date' => ['date', 'purchase_date', 'arrival_date', 'received_date'],
        'sku' => ['sku', 'product_sku', 'code'],
        'product' => ['product', 'product_name', 'name', 'item', 'item_name'],
        'quantity' => ['quantity', 'qty'],
        'unit_cost' => ['unit_cost', 'cost', 'cost_price', 'unit_price', 'price'],
        'short_qty' => ['short_qty', 'short', 'short_quantity'],
        'damaged_qty' => ['damaged_qty', 'damaged', 'damage', 'damaged_quantity'],
    ];

    /** How many skipped rows the summary lists before "and N more". */
    private const LISTED_ROWS = 15;

    /** @var array<string, Product|false|null> lookup cache: false = not found, null = ambiguous */
    private array $products = [];

    public function sample(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::HEADINGS);
            fputcsv($handle, ['2026-10-01', 'SKU-001', 'Product name', 100, 50, 0, 0]);
            fputcsv($handle, ['', 'SKU-002', 'Another product', 40, '', 2, 1]);
            fclose($handle);
        }, 'purchases-import-sample.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array{
     *     dry_run: bool, status: string, supplier: string, rows: int, purchases: int, items: int, quantity: int,
     *     already_imported: int, ambiguous_dates: int, skipped: list<array{row: int, reason: string}>
     * }
     */
    public function run(string $path, Supplier $supplier, string $status, bool $dryRun = false): array
    {
        if (! array_key_exists($status, Purchase::STATUSES) || $status === 'cancelled') {
            $status = 'draft';
        }

        $this->products = [];
        [$headings, $lines] = $this->read($path);
        $columns = $this->columnMap($headings);

        $report = [
            'dry_run' => $dryRun,
            'status' => $status,
            'supplier' => $supplier->name,
            'rows' => 0,
            'purchases' => 0,
            'items' => 0,
            'quantity' => 0,
            'already_imported' => 0,
            'ambiguous_dates' => 0,
            'skipped' => [],
        ];

        $groups = [];
        $lastDate = null;

        foreach ($lines as $index => $cells) {
            $rowNumber = $index + 2;
            $row = $this->rowValues($columns, $cells);

            if (implode('', $row) === '') {
                continue;
            }

            $report['rows']++;

            if ($row['date'] !== '') {
                $date = $this->parseDate($row['date']);
                $lastDate = $date;
            } else {
                $date = $lastDate;
            }

            if ($date === null) {
                $report['skipped'][] = ['row' => $rowNumber, 'reason' => __('Date is missing or not understood.')];

                continue;
            }

            $item = $this->item($row, $rowNumber);

            if (is_string($item)) {
                $report['skipped'][] = ['row' => $rowNumber, 'reason' => $item];

                continue;
            }

            if ($date['ambiguous'] && $row['date'] !== '') {
                $report['ambiguous_dates']++;
            }

            $groups[$date['date']][] = $item;
        }

        ksort($groups);

        foreach ($groups as $date => $items) {
            $ref = $this->reference($supplier, $date, $items);
            $marker = '[csv:'.$ref.']';

            if (Purchase::query()->where('supplier_id', $supplier->getKey())->where('note', 'like', '%'.$marker.'%')->exists()) {
                $report['already_imported']++;

                continue;
            }

            $report['purchases']++;
            $report['items'] += count($items);
            $report['quantity'] += array_sum(array_column($items, 'quantity'));

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($supplier, $status, $date, $items, $marker): void {
                $purchase = Purchase::query()->create([
                    'supplier_id' => $supplier->getKey(),
                    'purchase_date' => $date,
                    'status' => $status,
                    'note' => __('Imported from CSV').' '.$marker,
                ]);

                foreach ($items as $item) {
                    PurchaseItem::query()->create([
                        'purchase_id' => $purchase->getKey(),
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_cost' => $item['unit_cost'],
                        'short_quantity' => $item['short'],
                        'damaged_quantity' => $item['damaged'],
                    ]);
                }
            });
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    public function summary(array $report): array
    {
        $statusLabel = __(Purchase::STATUSES[$report['status']] ?? $report['status']);
        $lines = [
            $report['dry_run']
                ? __('Preview, nothing saved: :purchases purchases with :items items (:quantity units) would be created for :supplier as :status.', [
                    'purchases' => $report['purchases'], 'items' => $report['items'], 'quantity' => $report['quantity'],
                    'supplier' => $report['supplier'], 'status' => $statusLabel,
                ])
                : __('Created :purchases purchases with :items items (:quantity units) for :supplier as :status.', [
                    'purchases' => $report['purchases'], 'items' => $report['items'], 'quantity' => $report['quantity'],
                    'supplier' => $report['supplier'], 'status' => $statusLabel,
                ]),
            __(':rows rows read.', ['rows' => $report['rows']]),
        ];

        if ($report['already_imported'] > 0) {
            $lines[] = __(':count purchases from this file were imported earlier and were skipped.', ['count' => $report['already_imported']]);
        }

        if ($report['ambiguous_dates'] > 0) {
            $lines[] = __(':count rows have a date such as 08/09/2025 that could be day/month or month/day. It was read day first.', ['count' => $report['ambiguous_dates']]);
        }

        if ($report['skipped'] !== []) {
            $lines[] = __(':count rows were left out:', ['count' => count($report['skipped'])]);

            foreach (array_slice($report['skipped'], 0, self::LISTED_ROWS) as $skipped) {
                $lines[] = __('Row :row: :reason', ['row' => $skipped['row'], 'reason' => $skipped['reason']]);
            }

            if (count($report['skipped']) > self::LISTED_ROWS) {
                $lines[] = __('and :count more.', ['count' => count($report['skipped']) - self::LISTED_ROWS]);
            }
        }

        return $lines;
    }

    /**
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function read(string $path): array
    {
        $content = (string) file_get_contents($path);

        if ($content === '') {
            throw ValidationException::withMessages(['csv' => __('The file is empty.')]);
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = collect([',', ';', "\t"])
            ->sortByDesc(fn (string $candidate): int => substr_count($firstLine, $candidate))
            ->first();

        $rows = [];

        foreach (explode("\n", $content) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $rows[] = array_map(fn (?string $cell): string => trim((string) $cell), str_getcsv($line, $delimiter, '"', ''));
        }

        $headings = array_shift($rows) ?? [];

        return [$headings, $rows];
    }

    /**
     * @param  list<string>  $headings
     * @return array<string, int> field => column index
     */
    private function columnMap(array $headings): array
    {
        $map = [];

        foreach ($headings as $index => $heading) {
            $key = Str::of($heading)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();

            foreach (self::ALIASES as $field => $aliases) {
                if (! isset($map[$field]) && in_array($key, $aliases, true)) {
                    $map[$field] = $index;
                }
            }
        }

        $missing = [];

        foreach (['date', 'quantity'] as $required) {
            if (! isset($map[$required])) {
                $missing[] = $required;
            }
        }

        if (! isset($map['product']) && ! isset($map['sku'])) {
            $missing[] = 'product';
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'csv' => __('Missing CSV columns: :columns. Download the sample CSV to see the column names.', ['columns' => implode(', ', $missing)]),
            ]);
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $columns
     * @param  list<string>  $cells
     * @return array<string, string>
     */
    private function rowValues(array $columns, array $cells): array
    {
        $row = [];

        foreach (array_keys(self::ALIASES) as $field) {
            $row[$field] = isset($columns[$field]) ? ($cells[$columns[$field]] ?? '') : '';
        }

        return $row;
    }

    /**
     * @param  array<string, string>  $row
     * @return array{product_id: int, quantity: int, unit_cost: float, short: int, damaged: int, cost_cell: string}|string a reason when the row is not valid
     */
    private function item(array $row, int $rowNumber): array|string
    {
        $label = $row['sku'] !== '' ? $row['sku'] : $row['product'];

        if ($label === '') {
            return __('The product name or SKU is missing.');
        }

        $product = $this->product($row['sku'], $row['product']);

        if ($product === null) {
            return __('More than one product is named :name. Use its SKU.', ['name' => $label]);
        }

        if ($product === false) {
            return __('Product not found: :name', ['name' => $label]);
        }

        $quantity = $this->number($row['quantity']);

        if ($quantity === null || $quantity <= 0 || floor($quantity) !== $quantity) {
            return __('Quantity must be a whole number above zero.');
        }

        $unitCost = $row['unit_cost'] === '' ? (float) $product->cost_price : $this->number($row['unit_cost']);

        if ($unitCost === null || $unitCost < 0) {
            return __('Unit cost must be a number, zero or more.');
        }

        $short = $row['short_qty'] === '' ? 0.0 : $this->number($row['short_qty']);
        $damaged = $row['damaged_qty'] === '' ? 0.0 : $this->number($row['damaged_qty']);

        if ($short === null || $damaged === null || $short < 0 || $damaged < 0 || floor($short) !== $short || floor($damaged) !== $damaged) {
            return __('Short and damaged units must be whole numbers, zero or more.');
        }

        if ($short + $damaged > $quantity) {
            return __('Short plus damaged units cannot be more than the quantity.');
        }

        return [
            'product_id' => (int) $product->getKey(),
            'quantity' => (int) $quantity,
            'unit_cost' => round($unitCost, 2),
            'short' => (int) $short,
            'damaged' => (int) $damaged,
            'cost_cell' => $row['unit_cost'],
        ];
    }

    /**
     * Same supplier, date and lines (as typed in the file) give the same
     * reference, whatever the row order and whatever the product's cost
     * price is by the time the file is uploaded again.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function reference(Supplier $supplier, string $date, array $items): string
    {
        $lines = array_map(fn (array $item): string => implode(':', [
            $item['product_id'], $item['quantity'], $item['short'], $item['damaged'], $item['cost_cell'],
        ]), $items);
        sort($lines);

        return substr(sha1($supplier->getKey().'|'.$date.'|'.implode(',', $lines)), 0, 12);
    }

    /** @return Product|false|null the product, false when none matches, null when several match */
    private function product(string $sku, string $name): Product|false|null
    {
        foreach (array_filter([$sku, $name], fn (string $value): bool => $value !== '') as $value) {
            $key = Str::lower(preg_replace('/\s+/', ' ', $value));

            if (! array_key_exists($key, $this->products)) {
                $this->products[$key] = $this->findProduct($key);
            }

            if ($this->products[$key] !== false) {
                return $this->products[$key];
            }
        }

        return false;
    }

    private function findProduct(string $key): Product|false|null
    {
        $bySku = Product::query()->where('is_active', true)->whereRaw('lower(sku) = ?', [$key])->get();

        if ($bySku->count() === 1) {
            return $bySku->first();
        }

        if ($bySku->count() > 1) {
            return null;
        }

        $byName = Product::query()->where('is_active', true)->get()
            ->filter(fn (Product $product): bool => Str::lower(preg_replace('/\s+/', ' ', trim($product->name))) === $key);

        return match (true) {
            $byName->count() === 1 => $byName->first(),
            $byName->count() > 1 => null,
            default => false,
        };
    }

    private function number(string $value): ?float
    {
        $value = str_replace([',', ' ', '৳'], '', trim($value));
        $value = preg_replace('/^(bdt|tk)/i', '', $value);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array{date: string, ambiguous: bool}|null
     */
    private function parseDate(string $value): ?array
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $value, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? ['date' => sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]), 'ambiguous' => false]
                : null;
        }

        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2}|\d{4})$/', $value, $m)) {
            [$first, $second] = [(int) $m[1], (int) $m[2]];
            $year = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];
            $ambiguous = $first <= 12 && $second <= 12 && $first !== $second;
            [$day, $month] = ($second > 12 && $first <= 12) ? [$second, $first] : [$first, $second];

            return checkdate($month, $day, $year)
                ? ['date' => sprintf('%04d-%02d-%02d', $year, $month, $day), 'ambiguous' => $ambiguous]
                : null;
        }

        // Words such as "today" are not dates for a spreadsheet column.
        if (! preg_match('/\d/', $value)) {
            return null;
        }

        try {
            return ['date' => Carbon::parse($value)->toDateString(), 'ambiguous' => false];
        } catch (\Throwable) {
            return null;
        }
    }
}
