<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Lets a CSV importer take an Excel file too: the first sheet of an .xlsx or
 * .ods file is written out as a temporary CSV (dates as YYYY-MM-DD), so the
 * importer reads it exactly as it reads a CSV. A .csv / .txt file is used as it is.
 */
final class SpreadsheetCsv
{
    public const EXTENSIONS = ['csv', 'txt', 'xlsx', 'ods'];

    /** The path of a CSV with the file's rows; the caller deletes it when it differs from $path. */
    public static function toCsvPath(string $path, ?string $extension): string
    {
        $extension = strtolower((string) $extension);

        if (! in_array($extension, ['xlsx', 'ods'], true)) {
            return $path;
        }

        $reader = $extension === 'ods' ? new OdsReader : new XlsxReader;
        $csvPath = tempnam(sys_get_temp_dir(), 'sheet-csv-');
        $handle = fopen($csvPath, 'w');

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    fputcsv($handle, array_map(fn ($cell): string => match (true) {
                        $cell instanceof DateTimeInterface => $cell->format('Y-m-d'),
                        is_bool($cell) => $cell ? 'yes' : 'no',
                        default => trim((string) $cell),
                    }, $row->toArray()));
                }

                break;
            }
        } catch (Throwable) {
            fclose($handle);
            @unlink($csvPath);

            throw ValidationException::withMessages(['file' => __('The file could not be read. Save it again as .xlsx or .csv and retry.')]);
        } finally {
            $reader->close();
        }

        fclose($handle);

        return $csvPath;
    }
}
