<?php

namespace App\Support;

/**
 * Plain English number-to-words conversion (no locale/currency rules baked
 * in — callers supply their own currency/fraction wording), used for the
 * "Amount in Words" / "SAY USD ... ONLY" lines on printed trade documents
 * (Purchase PI/CI). Supports integers up to 999,999,999,999.
 */
class NumberToWords
{
    protected const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];

    protected const TENS = [
        '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety',
    ];

    protected const SCALES = ['', 'Thousand', 'Million', 'Billion'];

    /**
     * Converts a whole number into title-case English words, e.g.
     * 35600 => "Thirty Five Thousand Six Hundred".
     */
    public static function convert(int $number): string
    {
        if ($number === 0) {
            return 'Zero';
        }

        $negative = $number < 0;
        $number = abs($number);
        $chunks = [];

        while ($number > 0) {
            $chunks[] = $number % 1000;
            $number = intdiv($number, 1000);
        }

        $words = [];

        foreach (array_reverse($chunks, true) as $scale => $chunk) {
            if ($chunk === 0) {
                continue;
            }

            $chunkWords = self::convertChunk($chunk);
            $words[] = trim($chunkWords.' '.self::SCALES[$scale]);
        }

        return ($negative ? 'Minus ' : '').implode(' ', $words);
    }

    /**
     * Splits a decimal amount into whole + fraction word groups, e.g.
     * (35600.50, 'US Dollar', 'Cent') =>
     * "Thirty Five Thousand Six Hundred US Dollars And Fifty Cents Only".
     */
    public static function amountInWords(float $amount, string $unit = 'US Dollar', string $fractionUnit = 'Cent'): string
    {
        $whole = (int) floor(round($amount, 2));
        $fraction = (int) round((round($amount, 2) - $whole) * 100);

        $words = self::convert($whole).' '.self::pluralize($unit, $whole);

        if ($fraction > 0) {
            $words .= ' And '.self::convert($fraction).' '.self::pluralize($fractionUnit, $fraction);
        }

        return $words.' Only';
    }

    protected static function convertChunk(int $chunk): string
    {
        $hundreds = intdiv($chunk, 100);
        $remainder = $chunk % 100;
        $parts = [];

        if ($hundreds > 0) {
            $parts[] = self::ONES[$hundreds].' Hundred';
        }

        if ($remainder > 0) {
            if ($remainder < 20) {
                $parts[] = self::ONES[$remainder];
            } else {
                $tens = self::TENS[intdiv($remainder, 10)];
                $ones = self::ONES[$remainder % 10];
                $parts[] = trim($tens.' '.$ones);
            }
        }

        return implode(' ', $parts);
    }

    protected static function pluralize(string $unit, int $count): string
    {
        return $count === 1 ? $unit : $unit.'s';
    }
}
