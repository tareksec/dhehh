<?php

namespace App\Support;

/**
 * CSV / formula injection guard for spreadsheet exports.
 *
 * Names, addresses, notes and product titles reach the system from public
 * forms (storefront checkout, website API, chat order links). A cell that
 * starts with = + - @ (or a tab / carriage return) is run as a formula when the
 * staff member opens the export in Excel or Sheets - it can pull data out of the
 * workbook or call a remote address. Such a text cell is written with a leading
 * apostrophe, which spreadsheets show as plain text. Numbers ("-500.00",
 * "+8801711223344") and non-text values are left as they are.
 */
final class CsvSafe
{
    public static function cell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || is_numeric($value)) {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /**
     * @param  array<int|string, mixed>  $row
     * @return array<int|string, mixed>
     */
    public static function row(array $row): array
    {
        return array_map(self::cell(...), $row);
    }
}
