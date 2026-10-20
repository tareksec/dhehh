<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Numbers and dates on IMS screens (IMS-V2/DESIGN.md §5): lakh grouping in
 * both languages (৳ 5,20,000 / ৳ ৫,২০,০০০), Bangla digits in Bangla, and
 * "15 Jun 2026" / "১৫ জুন ২০২৬" dates.
 */
final class ImsNumbers
{
    private const BANGLA_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    public static function money(float|int|string|null $amount, string $currency = 'BDT', bool $signed = false): string
    {
        $value = is_numeric($amount) ? round((float) $amount, 2) : 0.0;
        $sign = $value < 0 ? '−' : ($signed && $value > 0 ? '+' : '');

        return $sign.MoneyFormatter::symbol($currency).' '.self::digits(self::lakh(abs($value)));
    }

    public static function count(int|float|null $value): string
    {
        return self::digits(self::lakh((float) ($value ?? 0), 0));
    }

    public static function percent(float|int|string|null $value): string
    {
        $number = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

        return self::digits($number).'%';
    }

    public static function date(CarbonInterface|string|null $date): string
    {
        if ($date === null || $date === '') {
            return '—';
        }

        $date = $date instanceof CarbonInterface ? $date : Carbon::parse($date);

        return self::digits($date->copy()->locale(app()->getLocale())->translatedFormat('j M Y'));
    }

    /** 1234567.5 → "12,34,567.50"; whole amounts keep no decimals. */
    public static function lakh(float $value, int $decimals = 2): string
    {
        $fixed = number_format($value, $decimals, '.', '');
        [$whole, $fraction] = array_pad(explode('.', $fixed, 2), 2, '');

        if (strlen($whole) > 3) {
            $last = substr($whole, -3);
            $rest = substr($whole, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $whole = $rest.','.$last;
        }

        return ($fraction === '' || (int) $fraction === 0) ? $whole : $whole.'.'.$fraction;
    }

    /** Western digits → Bangla digits while the screen is in Bangla. */
    public static function digits(string $text): string
    {
        return app()->getLocale() === 'bn' ? strtr($text, array_combine(range(0, 9), self::BANGLA_DIGITS)) : $text;
    }
}
