<?php

namespace App\Services;

use App\Support\SpreadsheetCsv;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Bring in old data" in the Trade Portal (BUILD-BRIEF.md phase 2): the old
 * books as a CSV or Excel file, read by the ERP's own importers —
 * CustomerCsvService for buyers with their old due (opening_balance) and
 * ProductCsvService for items with the stock on the shelf. A whole file is
 * refused when one row is wrong, so nothing is half done.
 *
 * Items: a row without a SKU gets one from its name ("TRD-" + the name), so
 * the same file brought in twice updates the same items; a row without a
 * sale price gets 0.
 */
class TradeImportService
{
    public const BUYER_HEADINGS = ['name', 'phone', 'address', 'opening_balance'];

    public const ITEM_HEADINGS = ['name', 'unit', 'sale_price', 'cost_price', 'stock', 'reorder_level'];

    /** @return array{created: int, updated: int} */
    public function importBuyers(string $path, ?string $extension): array
    {
        return $this->withCsv($path, $extension, fn (string $csv): array => app(CustomerCsvService::class)->import($csv));
    }

    /** @return array{created: int, updated: int} */
    public function importItems(string $path, ?string $extension): array
    {
        return $this->withCsv($path, $extension, function (string $csv): array {
            $prepared = $this->withItemDefaults($csv);

            try {
                return app(ProductCsvService::class)->import($prepared);
            } finally {
                @unlink($prepared);
            }
        });
    }

    public function buyerSample(): StreamedResponse
    {
        return $this->sample('old-buyers-and-dues.csv', self::BUYER_HEADINGS, ['Madina Knitwear', '01711000000', 'BSCIC, Narayanganj', '600000']);
    }

    public function itemSample(): StreamedResponse
    {
        return $this->sample('items-and-stock.csv', self::ITEM_HEADINGS, ['Circular knitting machine 34x72F', 'set', '750000', '520000', '3', '1']);
    }

    /**
     * @param  callable(string): array{created: int, updated: int}  $import
     * @return array{created: int, updated: int}
     */
    protected function withCsv(string $path, ?string $extension, callable $import): array
    {
        $csv = SpreadsheetCsv::toCsvPath($path, $extension);

        try {
            return $import($csv);
        } finally {
            if ($csv !== $path) {
                @unlink($csv);
            }
        }
    }

    /** A copy of the CSV with the sku and sale_price columns filled where empty. */
    protected function withItemDefaults(string $csv): string
    {
        $in = fopen($csv, 'r');
        $outPath = tempnam(sys_get_temp_dir(), 'trade-items-');
        $out = fopen($outPath, 'w');

        $headings = array_map(fn ($heading): string => Str::of((string) $heading)->replace("\xEF\xBB\xBF", '')->trim()->lower()->replace([' ', '-'], '_')->toString(), fgetcsv($in) ?: []);

        foreach (['sku', 'sale_price'] as $column) {
            if (! in_array($column, $headings, true)) {
                $headings[] = $column;
            }
        }

        $positions = array_flip($headings);
        fputcsv($out, $headings);

        while (($row = fgetcsv($in)) !== false) {
            $row = array_pad($row, count($headings), '');
            $name = trim((string) ($row[$positions['name'] ?? -1] ?? ''));

            if (trim((string) $row[$positions['sku']]) === '' && $name !== '') {
                $row[$positions['sku']] = 'TRD-'.(Str::upper(Str::limit(Str::slug($name), 40, '')) ?: substr(md5($name), 0, 10));
            }

            if (trim((string) $row[$positions['sale_price']]) === '' && $name !== '') {
                $row[$positions['sale_price']] = '0';
            }

            fputcsv($out, $row);
        }

        fclose($in);
        fclose($out);

        return $outPath;
    }

    /** @param  list<string>  $headings  @param  list<string>  $example */
    protected function sample(string $filename, array $headings, array $example): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $example): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headings);
            fputcsv($handle, $example);
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
