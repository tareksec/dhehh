<?php

namespace App\Services\Ses;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Proves that a message posted to the SES webhook really comes from Amazon SNS,
 * by checking its signature against the certificate Amazon publishes (the
 * procedure in AWS's "Verifying the signatures of Amazon SNS messages"). The
 * certificate is only fetched from an sns.<region>.amazonaws.com address, over
 * https, so a forged message cannot point us at a certificate of its own.
 */
class SnsMessageValidator
{
    private const HOST_PATTERN = '/^sns\.[a-z0-9-]{3,}\.amazonaws\.com(\.cn)?$/i';

    /** The fields that are signed, in order, per message type. */
    private const SIGNED_FIELDS = [
        'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
        'SubscriptionConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
        'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
    ];

    /** @param  array<string, mixed>  $message  the decoded JSON the SNS delivery carried */
    public function validate(array $message): void
    {
        $type = (string) ($message['Type'] ?? '');
        $fields = self::SIGNED_FIELDS[$type] ?? throw new RuntimeException('Unknown SNS message type.');
        $version = (string) ($message['SignatureVersion'] ?? '');

        if (! in_array($version, ['1', '2'], true)) {
            throw new RuntimeException('Unsupported SNS signature version.');
        }

        $signature = base64_decode((string) ($message['Signature'] ?? ''), true);
        $certificate = $this->certificate((string) ($message['SigningCertURL'] ?? ''));

        if ($signature === false || $signature === '') {
            throw new RuntimeException('SNS signature is missing.');
        }

        $key = openssl_pkey_get_public($certificate);

        if ($key === false) {
            throw new RuntimeException('SNS signing certificate could not be read.');
        }

        $valid = openssl_verify($this->stringToSign($message, $fields), $signature, $key, $version === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1);

        if ($valid !== 1) {
            throw new RuntimeException('SNS signature does not match.');
        }
    }

    /** An https address on Amazon SNS itself; the only kind of address the webhook ever fetches. */
    public static function isSnsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && preg_match(self::HOST_PATTERN, (string) ($parts['host'] ?? '')) === 1
            && ! isset($parts['user'], $parts['pass'])
            && (! isset($parts['port']) || (int) $parts['port'] === 443);
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<int, string>  $fields
     */
    protected function stringToSign(array $message, array $fields): string
    {
        $text = '';

        foreach ($fields as $field) {
            if (array_key_exists($field, $message) && $message[$field] !== null) {
                $text .= $field."\n".$message[$field]."\n";
            }
        }

        return $text;
    }

    protected function certificate(string $url): string
    {
        if (! self::isSnsUrl($url) || ! str_ends_with((string) parse_url($url, PHP_URL_PATH), '.pem')) {
            throw new RuntimeException('SNS signing certificate address is not an Amazon SNS address.');
        }

        return Cache::remember('sns-cert:'.sha1($url), now()->addHours(12), function () use ($url): string {
            $response = Http::timeout(5)->get($url);

            if (! $response->successful()) {
                throw new RuntimeException('SNS signing certificate could not be downloaded.');
            }

            return (string) $response->body();
        });
    }
}
