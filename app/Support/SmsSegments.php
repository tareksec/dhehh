<?php

namespace App\Support;

/**
 * How many SMS parts a message is billed as. Plain English text (the GSM
 * 7-bit alphabet) takes 160 characters in one part and 153 per part when it is
 * longer; anything else, Bangla included, is sent as Unicode and takes 70 in
 * one part and 67 per part when it is longer. A gateway may count differently,
 * so the SMS dashboard shows the cost that follows from this as an estimate.
 */
final class SmsSegments
{
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** Each of these is two characters long in the GSM alphabet. */
    private const GSM_EXTENDED = "^{}\\[~]|€\f";

    public static function count(string $message): int
    {
        if ($message === '') {
            return 0;
        }

        $gsmLength = self::gsmLength($message);

        if ($gsmLength !== null) {
            return $gsmLength <= 160 ? 1 : (int) ceil($gsmLength / 153);
        }

        $units = intdiv(strlen((string) mb_convert_encoding($message, 'UTF-16BE', 'UTF-8')), 2);

        return $units <= 70 ? 1 : (int) ceil($units / 67);
    }

    /** The length in the GSM alphabet, or null when the text has a character outside it (so it goes as Unicode). */
    private static function gsmLength(string $message): ?int
    {
        $length = 0;

        foreach (mb_str_split($message) as $character) {
            if (mb_strpos(self::GSM_BASIC, $character) !== false) {
                $length++;
            } elseif (mb_strpos(self::GSM_EXTENDED, $character) !== false) {
                $length += 2;
            } else {
                return null;
            }
        }

        return $length;
    }
}
