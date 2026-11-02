<?php

namespace App\Support;

/**
 * Turns what a person typed in To / Cc / Bcc ("Rahim <rahim@x.test>, sales@y.test; ...", or a list of addresses or
 * of name/address pairs) into clean address entries. An address that is not valid is returned apart, never
 * dropped silently, so the person is told which one to fix.
 */
final class EmailAddressList
{
    /** The most recipients one message may have (Cloudflare Email Sending allows 50 per message). */
    public const MAX_RECIPIENTS = 50;

    /**
     * @param  string|array<int, string|array{address?: string, name?: ?string}>|null  $input
     * @return array{valid: array<int, array{address: string, name: ?string}>, invalid: array<int, string>}
     */
    public static function parse(string|array|null $input): array
    {
        $entries = [];

        foreach (is_array($input) ? $input : self::split((string) $input) as $item) {
            $entries[] = is_array($item) ? [(string) ($item['address'] ?? ''), $item['name'] ?? null] : self::one((string) $item);
        }

        $valid = [];
        $invalid = [];

        foreach ($entries as [$address, $name]) {
            $clean = mb_strtolower(trim($address));

            if ($clean === '') {
                continue;
            }

            if (! filter_var($clean, FILTER_VALIDATE_EMAIL) || mb_strlen($clean) > 190) {
                $invalid[] = $address;

                continue;
            }

            $valid[$clean] ??= ['address' => $clean, 'name' => self::cleanName($name)];
        }

        return ['valid' => array_values($valid), 'invalid' => $invalid];
    }

    /** @param  array<int, array{address: string, name: ?string}>  $list */
    public static function addresses(array $list): array
    {
        return array_column($list, 'address');
    }

    /** "Name <address>" for display and for a quoted header line. */
    public static function label(array $entry): string
    {
        return filled($entry['name'] ?? null) ? $entry['name'].' <'.$entry['address'].'>' : (string) $entry['address'];
    }

    /** @return array<int, string> */
    private static function split(string $text): array
    {
        // Split on commas and semicolons that are not inside a "quoted name" or <angle brackets>.
        return array_values(array_filter(array_map('trim', preg_split('/[,;](?=(?:[^"<]*(?:"[^"]*"|<[^>]*>))*[^"<>]*$)/u', $text) ?: []), fn (string $part): bool => $part !== ''));
    }

    /** @return array{0: string, 1: ?string} */
    private static function one(string $text): array
    {
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^<>]+)>\s*$/u', $text, $match) === 1) {
            return [trim($match[2]), trim($match[1]) === '' ? null : trim($match[1])];
        }

        return [trim($text, " \t\r\n<>"), null];
    }

    private static function cleanName(?string $name): ?string
    {
        $name = trim((string) preg_replace('/[\r\n\t"<>]+/u', ' ', (string) $name));

        return $name === '' ? null : mb_substr($name, 0, 190);
    }
}
