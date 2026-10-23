<?php

namespace App\Support;

/**
 * The SMS gateway address, built from the API key and sender ID fields (owner request 2026-10-23): the owner
 * types the key and sender ID in their own boxes and the address follows, the way {phone} and {message} are
 * filled in at send time. A BulkSMSBD address pasted with real or sample values in it (api_key=…, senderid=…,
 * number=Receiver, message=TestSMS) is turned back into the placeholder form, and a key or sender ID found in
 * it is moved to its own box when that box is empty.
 */
final class SmsGatewayUrl
{
    /** The address BulkSMSBD's API takes, with every changing part as a placeholder. */
    public const BULKSMSBD = 'http://bulksmsbd.net/api/smsapi?api_key={api_key}&type=text&number={phone}&senderid={sender_id}&message={message}';

    private const HOST_PATTERN = '/(^|\.)bulksmsbd\.(net|com)$/i';

    /** The words in the gateway's own example address; they stand for a value, they are not one. */
    private const SAMPLE_WORDS = ['api_key', 'apikey', 'your_api_key', 'yourapikey', 'senderid', 'sender_id', 'yoursenderid', 'receiver', 'testsms', 'testsm'];

    public static function isBulkSmsBd(string $url): bool
    {
        $host = parse_url(str_replace(['{api_key}', '{sender_id}', '{phone}', '{message}'], 'x', trim($url)), PHP_URL_HOST);

        return is_string($host) && preg_match(self::HOST_PATTERN, $host) === 1;
    }

    /**
     * A BulkSMSBD address in its placeholder form. Other gateways are not touched: only BulkSMSBD's parameter
     * names (api_key, senderid, number, message) are known.
     *
     * @return array{url: string, api_key: string, sender_id: string, changed: bool}
     */
    public static function tidy(string $url, string $apiKey = '', string $senderId = ''): array
    {
        $url = trim($url);
        $apiKey = trim($apiKey);
        $senderId = trim($senderId);
        [$originalKey, $originalSender] = [$apiKey, $senderId];

        if ($url === '' || ! self::isBulkSmsBd($url) || ! str_contains($url, '?')) {
            return ['url' => $url, 'api_key' => $apiKey, 'sender_id' => $senderId, 'changed' => false];
        }

        [$base, $query] = explode('?', $url, 2);
        $parts = $query === '' ? [] : explode('&', $query);

        foreach ($parts as $index => $part) {
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $value = trim($value);

            $parts[$index] = match (strtolower(trim($name))) {
                'api_key' => self::keep($part, $value, '{api_key}', $apiKey),
                'senderid' => self::keep($part, $value, '{sender_id}', $senderId),
                'number' => str_contains($value, '{phone}') ? $part : 'number={phone}',
                'message' => str_contains($value, '{message}') ? $part : 'message={message}',
                default => $part,
            };
        }

        $tidy = $base.'?'.implode('&', $parts);

        return ['url' => $tidy, 'api_key' => $apiKey, 'sender_id' => $senderId, 'changed' => $tidy !== $url || $apiKey !== $originalKey || $senderId !== $originalSender];
    }

    /**
     * The address as the system will call it, for the page to show: the API key shortened (it is a secret), the
     * sender ID filled in, {phone} and {message} left for the moment of sending.
     */
    public static function preview(string $url, string $apiKey, string $senderId): string
    {
        $apiKey = trim($apiKey);
        $shown = $apiKey === '' ? '' : (mb_strlen($apiKey) > 8 ? mb_substr($apiKey, 0, 4).'***' : '***');

        return strtr(trim($url), ['{api_key}' => $shown, '{sender_id}' => trim($senderId)]);
    }

    /**
     * One query parameter of the address that carries a key or sender ID: a value that is already the placeholder
     * stays, a real value moves into its own box (when that is empty), a sample word is just dropped.
     */
    private static function keep(string $part, string $value, string $placeholder, string &$box): string
    {
        [$name] = explode('=', $part, 2);

        if (str_contains($value, '{')) {
            return $part;
        }

        if ($box === '' && $value !== '' && ! in_array(strtolower($value), self::SAMPLE_WORDS, true)) {
            $box = rawurldecode($value);
        }

        return trim($name).'='.$placeholder;
    }
}
