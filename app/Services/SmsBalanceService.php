<?php

namespace App\Services;

use App\Models\StorefrontSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The balance left on the BulkSMSBD account, read from its balance API
 * (`/api/getBalanceApi?api_key=...`), for the SMS dashboard (owner request
 * 2026-10-23). That is all the gateway lets an API do about money: it has no
 * API to recharge, no delivery reports and no price list, so the dashboard
 * links to BulkSMSBD for a recharge and estimates costs from its own log.
 *
 * The balance reply is not documented, so it is read from the usual shapes
 * (a `balance` field, or a bare number); anything else is returned as it came,
 * for the owner to see, rather than guessed at.
 */
class SmsBalanceService
{
    /** How long a balance is reused, so opening the dashboard does not call the gateway every time. */
    public const CACHE_MINUTES = 5;

    /** The gateway hosts this service knows the balance API of. */
    protected const HOST_PATTERN = '/(^|\.)bulksmsbd\.(net|com)$/i';

    /** Whether the saved gateway is a BulkSMSBD one (the only gateway with a balance API we know of). */
    public function supported(StorefrontSetting $setting): bool
    {
        return $this->host($setting) !== null;
    }

    /** Where to top the balance up: the gateway's own site, as the API cannot take a payment. */
    public function rechargeUrl(StorefrontSetting $setting): ?string
    {
        $host = $this->host($setting);

        return $host === null ? null : 'https://'.$host;
    }

    /**
     * @return array{ok: bool, balance: ?float, message: ?string, raw: ?string, code: ?int, checked_at: string}
     */
    public function balance(StorefrontSetting $setting, bool $fresh = false): array
    {
        $key = 'sms-balance:'.$setting->getKey().':'.md5((string) data_get($setting->notification_credentials, 'sms_api_key'));

        if ($fresh) {
            Cache::forget($key);
        }

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->fetch($setting);

        // A failure is not kept: fixing the key and pressing refresh must try again.
        if ($result['ok']) {
            Cache::put($key, $result, now()->addMinutes(self::CACHE_MINUTES));
        }

        return $result;
    }

    /** @return array{ok: bool, balance: ?float, message: ?string, raw: ?string, code: ?int, checked_at: string} */
    protected function fetch(StorefrontSetting $setting): array
    {
        $host = $this->host($setting);
        $apiKey = trim((string) data_get($setting->notification_credentials, 'sms_api_key'));

        if ($host === null) {
            return $this->failed(__('The balance can be read only for a BulkSMSBD gateway.'));
        }

        if ($apiKey === '') {
            return $this->failed(__('The SMS API key is empty. Enter it in Integrations → SMS Gateway → SMS API key and save.'));
        }

        try {
            $response = Http::timeout(15)->get('https://'.$host.'/api/getBalanceApi', ['api_key' => $apiKey]);
        } catch (\Throwable $exception) {
            return $this->failed(__('The SMS gateway could not be reached: :error', ['error' => str(StorefrontNotificationService::scrubUrls($exception->getMessage()))->limit(160)]));
        }

        if (! $response->successful()) {
            return $this->failed(__('The SMS gateway answered with an error (HTTP :status).', ['status' => $response->status()]));
        }

        return $this->parse(trim($response->body()));
    }

    /** @return array{ok: bool, balance: ?float, message: ?string, raw: ?string, code: ?int, checked_at: string} */
    public function parse(string $body): array
    {
        $json = json_decode($body, true);

        if (is_array($json)) {
            $code = isset($json['response_code']) && is_numeric($json['response_code']) ? (int) $json['response_code'] : null;

            if ($code !== null && $code !== StorefrontNotificationService::SMS_GATEWAY_SUCCESS_CODE && ! $this->balanceIn($json)) {
                $meaning = StorefrontNotificationService::SMS_GATEWAY_CODES[$code] ?? ($json['error_message'] ?? null);

                return [...$this->failed(collect([$code, $meaning, StorefrontNotificationService::smsGatewayHint($code)])->filter()->implode(' - ')), 'code' => $code];
            }

            $balance = $this->balanceIn($json);

            if ($balance !== null) {
                return $this->done($balance);
            }
        } elseif (is_numeric($body)) {
            return $this->done((float) $body);
        }

        return [...$this->failed(__('The gateway answered, but no balance could be read from its reply.')), 'raw' => str($body)->limit(300)->toString()];
    }

    /** The balance in a decoded reply: a `balance` field at the top or one level down. */
    protected function balanceIn(array $json): ?float
    {
        foreach ([$json, ...array_filter($json, 'is_array')] as $level) {
            foreach ($level as $field => $value) {
                if (strcasecmp(str_replace(['_', ' '], '', (string) $field), 'balance') === 0 && is_numeric(str_replace(',', '', (string) $value))) {
                    return (float) str_replace(',', '', (string) $value);
                }
            }
        }

        return null;
    }

    /** The gateway's host (from the saved URL template) when it is a BulkSMSBD one. */
    protected function host(StorefrontSetting $setting): ?string
    {
        $template = trim((string) data_get($setting->notification_credentials, 'sms_api_url'));
        $host = parse_url(str_replace(['{api_key}', '{sender_id}', '{phone}', '{message}'], 'x', $template), PHP_URL_HOST);

        return is_string($host) && preg_match(self::HOST_PATTERN, $host) === 1 ? strtolower($host) : null;
    }

    /** @return array{ok: bool, balance: ?float, message: ?string, raw: ?string, code: ?int, checked_at: string} */
    protected function done(float $balance): array
    {
        return ['ok' => true, 'balance' => $balance, 'message' => null, 'raw' => null, 'code' => null, 'checked_at' => now()->toIso8601String()];
    }

    /** @return array{ok: bool, balance: ?float, message: ?string, raw: ?string, code: ?int, checked_at: string} */
    protected function failed(string $message): array
    {
        return ['ok' => false, 'balance' => null, 'message' => $message, 'raw' => null, 'code' => null, 'checked_at' => now()->toIso8601String()];
    }
}
