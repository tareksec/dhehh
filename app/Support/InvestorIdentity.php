<?php

namespace App\Support;

/**
 * An investor is identified by their NID / passport number (owner's rule:
 * a phone number can change, the NID does not). The number is stored
 * encrypted, so lookups go through a keyed hash of its normalised form.
 */
class InvestorIdentity
{
    /**
     * Uppercase, Bangla digits turned into 0-9, and everything except
     * letters and digits removed: "1990 1234-567" and "১৯৯০১২৩৪৫৬৭" match.
     */
    public static function normalize(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $bangla = array_map(fn (int $digit): string => mb_chr(0x09E6 + $digit, 'UTF-8'), range(0, 9));
        $number = str_replace($bangla, array_map('strval', range(0, 9)), $number);
        $number = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $number));

        return $number === '' ? null : $number;
    }

    /** Keyed hash used for the unique index and for search; null when there is no number. */
    public static function hash(?string $number): ?string
    {
        $normalized = self::normalize($number);

        return $normalized === null ? null : hash_hmac('sha256', $normalized, (string) config('app.key'));
    }
}
