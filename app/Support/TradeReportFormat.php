<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Writes one report cell for the screen and the PDF (the portal's language,
 * Bangla digits in Bangla, lakh grouping) or as a plain value for Excel
 * (numbers stay numbers so they can be added up there).
 */
final class TradeReportFormat
{
    public static function text(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        // A label in a figure or date column (the "Total" row) is written as it is.
        if (self::isNumeric($type) && ! is_numeric($value) || $type === 'date' && ! self::isDate($value)) {
            return (string) $value;
        }

        return match ($type) {
            'money' => ImsNumbers::money((float) $value),
            'usd' => '$ '.ImsNumbers::digits(number_format((float) $value, 2)),
            'number' => ImsNumbers::digits(ImsNumbers::lakh((float) $value, fmod((float) $value, 1.0) == 0.0 ? 0 : 2)),
            'percent' => ImsNumbers::percent((float) $value),
            'date' => ImsNumbers::date(CarbonImmutable::parse((string) $value)),
            default => (string) $value,
        };
    }

    /**
     * A cell of the English PDF file: as text(), with "Tk" for the Taka sign,
     * which the PDF engine draws like a "b".
     */
    public static function pdf(mixed $value, string $type): string
    {
        return str_replace('৳', 'Tk', self::text($value, $type));
    }

    protected static function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1;
    }

    /** The value for an Excel cell: a number for amounts, a date string, else text. */
    public static function excel(mixed $value, string $type): string|int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'money', 'usd', 'number', 'percent' => is_numeric($value) ? (float) $value : (string) $value,
            'date' => self::isDate($value) ? CarbonImmutable::parse((string) $value)->format('d/m/Y') : (string) $value,
            default => (string) $value,
        };
    }

    public static function isNumeric(string $type): bool
    {
        return in_array($type, ['money', 'usd', 'number', 'percent'], true);
    }
}
