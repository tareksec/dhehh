<?php

namespace App\Support;

/**
 * The BulkSMSBD rules every SMS wording must follow (owner request 2026-10-18).
 * Sources: the BulkSMSBD dashboard's Developers page and its API documentation.
 *
 * - OTP format: "Your {Brand/Company Name} OTP is XXXX" (Developers page: "OTP
 *   পাঠানোর জন্য এই ফরম্যাট টি ফলো করুন"). A code message is therefore exactly that:
 *   a brand, then the code, and nothing else.
 * - No single quotation mark (') in a message ("do not use single quotation (')
 *   in the message to avoid forbidden result", API example code).
 * - Masking SMS (a sender ID made of letters) must be sent in Bangla, error 1012
 *   "Masking SMS must be sent in Bengali". A numeric sender ID is not masking.
 * - Length: one GSM-7 SMS is 160 units (153 per part when longer); a message with
 *   Bangla (Unicode) is 70 units (67 per part). Spaces count.
 * - Special characters (&, $, @ ...) are URL-encoded when the message is sent.
 *
 * BulkSMSBD publishes no template-approval step for a numeric sender ID, so the
 * rules above are the whole check.
 */
final class BulkSmsBdPolicy
{
    /** The most SMS parts a template may need (3 parts = 459 GSM-7 or 201 Bangla characters). */
    public const MAX_PARTS = 3;

    /** GSM 03.38 basic character set (one unit each). */
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** GSM 03.38 extension characters (two units each). */
    private const GSM_EXTENDED = "^{}\\[~]|€\f";

    /**
     * Why this wording breaks a rule; empty when it is fine.
     *
     * @return list<string>
     */
    public static function problems(string $body, bool $isCode = false): array
    {
        $problems = [];

        if (str_contains($body, "'")) {
            $problems[] = __('Do not use a single quotation mark (\') in an SMS: BulkSMSBD refuses it.');
        }

        if ($isCode && ! self::isCodeFormat($body)) {
            $problems[] = __('A code message must read exactly: Your {brand or company name} OTP is :code (BulkSMSBD OTP format). Nothing else may be added.');
        }

        if (self::length($body)['parts'] > self::MAX_PARTS) {
            $problems[] = __('This message needs more than :parts SMS parts. Shorten it (a message with Bangla letters holds far fewer characters).', ['parts' => self::MAX_PARTS]);
        }

        return $problems;
    }

    /** "Your {Brand/Company Name} OTP is :code", with an optional full stop at the end. */
    public static function isCodeFormat(string $body): bool
    {
        $body = trim($body);

        return preg_match('/^Your\s+(?<brand>\S.*?)\s+OTP is :code\.?$/u', $body, $match) === 1
            && ! str_contains($match['brand'], ':code');
    }

    /** What the gateway is sent: a single quotation mark (a customer's or product's name may have one) is dropped. */
    public static function clean(string $text): string
    {
        return str_replace("'", '', $text);
    }

    /**
     * How many characters and SMS parts a text uses.
     *
     * @return array{encoding: string, units: int, parts: int}
     */
    public static function length(string $text): array
    {
        $unicode = false;
        $units = 0;

        foreach (mb_str_split($text) as $character) {
            if (str_contains(self::GSM_BASIC, $character)) {
                $units++;
            } elseif (str_contains(self::GSM_EXTENDED, $character)) {
                $units += 2;
            } else {
                $unicode = true;

                break;
            }
        }

        if (! $unicode) {
            return ['encoding' => 'GSM-7', 'units' => $units, 'parts' => $units === 0 ? 0 : ($units <= 160 ? 1 : (int) ceil($units / 153))];
        }

        // UTF-16 code units: a character outside the basic plane takes two.
        $units = intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')), 2);

        return ['encoding' => 'Unicode', 'units' => $units, 'parts' => $units <= 70 ? 1 : (int) ceil($units / 67)];
    }

    /** A sender ID made of letters is a masking name; a numeric one is not. */
    public static function isMaskingSender(?string $senderId): bool
    {
        $senderId = trim((string) $senderId);

        return $senderId !== '' && preg_match('/^[\d+\s-]+$/', $senderId) !== 1;
    }

    /** Whether the text has a Bangla letter (U+0980-U+09FF). */
    public static function hasBangla(string $text): bool
    {
        return preg_match('/[\x{0980}-\x{09FF}]/u', $text) === 1;
    }
}
