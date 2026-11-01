<?php

namespace App\Reports;

use App\Support\MoneyFormatter;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Turns a raw report value into what a person reads (screen, print) or what a
 * file keeps (CSV, XLSX, PDF).
 */
final class ReportFormatter
{
    public static function screen(ReportColumn $column, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($column->type) {
            ReportColumn::MONEY => MoneyFormatter::currency($value),
            ReportColumn::NUMBER => MoneyFormatter::number($value),
            ReportColumn::DATE => self::date($value, 'd M Y'),
            ReportColumn::BADGE => (string) str((string) $value)->headline(),
            default => (string) $value,
        };
    }

    /**
     * @param  bool  $spreadsheet  true for XLSX: numbers stay numbers so Excel can add them up
     */
    public static function file(ReportColumn $column, mixed $value, bool $spreadsheet = false): mixed
    {
        if ($value === null) {
            return '';
        }

        if ($column->type === ReportColumn::DATE) {
            return self::date($value, 'Y-m-d');
        }

        if ($spreadsheet && $column->isNumeric() && is_numeric($value)) {
            return $column->type === ReportColumn::NUMBER && (float) $value === floor((float) $value)
                ? (int) $value
                : (float) $value;
        }

        return $value;
    }

    private static function date(mixed $value, string $format): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format($format);
        }

        try {
            return Carbon::parse((string) $value)->format($format);
        } catch (Throwable) {
            return (string) $value;
        }
    }
}
