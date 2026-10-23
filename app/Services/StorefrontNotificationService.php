<?php

namespace App\Services;

use App\Mail\ResellerPasswordResetLink;
use App\Mail\StorefrontLoginOtp;
use App\Models\ConversationChannel;
use App\Models\EmailLog;
use App\Models\StorefrontSetting;
use App\Services\Meta\MetaGraphService;
use App\Support\PhoneLinks;
use App\Support\SmsSegments;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends customer notifications (abandoned-cart reminders etc.) over a
 * generic HTTP SMS gateway and the Meta Cloud WhatsApp API.
 *
 * Credentials live in storefront_settings.notification_credentials:
 * - sms_api_url: full gateway URL template with {api_key}, {sender_id},
 *   {phone}, {message} placeholders (works with BulkSMSBD-style GET APIs)
 * - sms_api_key, sms_sender_id
 * - whatsapp_token, whatsapp_phone_number_id, whatsapp_template_name,
 *   whatsapp_recovery_template_name, whatsapp_template_language (default
 *   "bn")
 * - mail_host, mail_port, mail_encryption, mail_username, mail_password,
 *   mail_from_address, mail_from_name: per-company SMTP for login OTP
 *   emails. Falls back to the global .env mailer when unset.
 */
class StorefrontNotificationService
{
    /** notification_credentials keys edited on Settings → Integrations → SMS Gateway (not on Storefront Settings). */
    public const SMS_CREDENTIAL_KEYS = ['sms_api_url', 'sms_api_key', 'sms_sender_id', 'sms_price_per_sms', 'sms_low_balance_alert'];

    /** notification_credentials keys edited on Settings → Integrations → Email Setup (not on Storefront Settings). */
    public const MAIL_CREDENTIAL_KEYS = ['mail_host', 'mail_port', 'mail_encryption', 'mail_username', 'mail_password', 'mail_from_address', 'mail_from_name'];

    /** Reply code BulkSMSBD-style gateways send (inside an HTTP 200) when the SMS was accepted. */
    public const SMS_GATEWAY_SUCCESS_CODE = 202;

    /** BulkSMSBD response codes, for the log when a gateway rejects an SMS. */
    public const SMS_GATEWAY_CODES = [
        202 => 'SMS Submitted Successfully',
        1001 => 'Invalid Number',
        1002 => 'Sender id not correct / sender id is disabled',
        1003 => 'Required fields missing',
        1005 => 'Internal Error',
        1006 => 'Balance Validity Not Available',
        1007 => 'Balance Insufficient',
        1011 => 'User Id not found',
        1012 => 'Masking SMS must be sent in Bengali',
        1013 => 'Sender Id has not found Gateway by api key',
        1014 => 'Sender Type Name not found using this sender by api key',
        1015 => 'Sender Id has not found Any Valid Gateway by api key',
        1016 => 'Sender Type Name Active Price Info not found by this sender id',
        1017 => 'Sender Type Name Price Info not found by this sender id',
        1018 => 'The Owner of this (username) Account is disabled',
        1019 => 'The (sender type name) Price of this (username) Account is disabled',
        1020 => 'The parent of this account is not found',
        1021 => 'The parent active (sender type name) price of this account is not found',
        1031 => 'Account Not Verified, contact the administrator',
        1032 => 'IP not whitelisted',
    ];

    public function __construct(protected MetaGraphService $meta) {}

    public function smsConfigured(StorefrontSetting $setting): bool
    {
        return filled(data_get($setting->notification_credentials, 'sms_api_url'));
    }

    /**
     * Sends one SMS for a caller that keeps no SMS log of its own (a login or recovery code, a reminder, a
     * broadcast message) and counts it for the SMS dashboard.
     */
    public function sendSms(StorefrontSetting $setting, string $phone, string $message): bool
    {
        $result = $this->sendSmsDetailed($setting, $phone, $message);

        app(SmsLogService::class)->other($setting, $phone, $result);

        return $result['ok'];
    }

    /**
     * An error message with its web addresses taken out. A failed request names the full gateway URL in its message,
     * and that URL carries the API key and the message text, which must not reach a log or a page.
     */
    public static function scrubUrls(string $text): string
    {
        return trim((string) preg_replace('~https?://[^\s)]+~i', '[gateway URL]', $text));
    }

    /**
     * What to do about a gateway reply code, in the owner's words; null when the code needs no action or is unknown.
     * Shown in the SMS Log and after "Send test SMS", next to the gateway's own wording.
     */
    public static function smsGatewayHint(?int $code): ?string
    {
        return match ($code) {
            1011 => __('BulkSMSBD does not recognise this API key. Copy the API key again from the BulkSMSBD dashboard (Developers page) into Integrations → SMS Gateway → SMS API key, with no spaces, press Save changes, then press Send test SMS.'),
            1002, 1013, 1014, 1015 => __('The sender ID is wrong, switched off or does not belong to this API key. Use a sender ID that is approved on your BulkSMSBD account.'),
            1006, 1007 => __('The BulkSMSBD balance is empty or expired. Top it up.'),
            1012 => __('A masking sender ID sends Bangla text only. Write this message in Bangla on SMS Templates, or use a numeric sender ID.'),
            1032 => __('Add this server\'s IP address to the BulkSMSBD whitelist, or switch the IP whitelist off.'),
            1018, 1031 => __('Your BulkSMSBD account needs attention. Contact BulkSMSBD support.'),
            1001 => __('The gateway says the phone number is not valid. The system sends it as 8801XXXXXXXXX, so in the gateway URL write number={phone} with nothing in front of {phone}. Check the number itself too.'),
            1003 => __('A required value is missing in the gateway URL. Use the template shown in the URL box.'),
            default => null,
        };
    }

    /**
     * Sends one SMS and says what the gateway answered, so the SMS log and the "Send test SMS" button can
     * show why a message did not leave (no balance, wrong sender ID, IP not whitelisted ...), not only that it did not.
     * `reason` is null when the SMS was accepted, else no_gateway, no_api_key, gateway_unreachable or gateway_refused.
     * `segments` is how many SMS parts the text is billed as.
     *
     * `request` is the gateway address that was called with the API key and the text hidden, so a refused number or
     * sender ID can be checked against what was really sent.
     *
     * @return array{ok: bool, reason: ?string, code: ?int, meaning: ?string, detail: ?string, segments: int, request: ?string}
     */
    public function sendSmsDetailed(StorefrontSetting $setting, string $phone, string $message): array
    {
        return [
            ...$this->deliverSms($setting, $phone, $message),
            'segments' => SmsSegments::count($message),
            'request' => $this->smsRequestPreview($setting, $phone),
        ];
    }

    /**
     * The gateway address for one SMS, filled in from the saved URL template. The number goes in the country-code
     * form (8801XXXXXXXXX), the format the gateway's own docs show. A template copied from the gateway's example
     * may already have the country code in front of {phone} (number=88{phone}); it then gets the rest of the number,
     * so the code is not sent twice.
     *
     * @param  array<string, mixed>  $credentials
     */
    protected function smsRequestUrl(string $template, array $credentials, string $phone, string $message): string
    {
        $digits = PhoneLinks::internationalDigits($phone) ?? $phone;
        $template = (string) preg_replace(['/(?<=[=,&])880\{phone\}/', '/(?<=[=,&])88\{phone\}/'], ['880{phone_after_880}', '88{phone_after_88}'], $template);

        return strtr($template, [
            '{api_key}' => rawurlencode(trim((string) ($credentials['sms_api_key'] ?? ''))),
            '{sender_id}' => rawurlencode(trim((string) ($credentials['sms_sender_id'] ?? ''))),
            '{phone}' => rawurlencode($digits),
            '{phone_after_88}' => rawurlencode((string) preg_replace('/^88/', '', $digits)),
            '{phone_after_880}' => rawurlencode((string) preg_replace('/^880/', '', $digits)),
            '{message}' => rawurlencode($message),
        ]);
    }

    /** What would be called for this phone, with the key and the text hidden; null when no gateway is saved. */
    protected function smsRequestPreview(StorefrontSetting $setting, string $phone): ?string
    {
        $credentials = $setting->notification_credentials ?? [];
        $template = trim((string) ($credentials['sms_api_url'] ?? ''));

        if ($template === '') {
            return null;
        }

        $url = rawurldecode($this->smsRequestUrl($template, $credentials, $phone, '[message]'));
        $apiKey = trim((string) ($credentials['sms_api_key'] ?? ''));

        if ($apiKey !== '') {
            $url = str_replace([$apiKey, rawurlencode($apiKey)], '***', $url);
        }

        // A key typed into the template itself, not through {api_key}.
        return (string) preg_replace('/((?:api_?key|key|token|secret|pass(?:word)?)=)[^&]*/i', '${1}***', $url);
    }

    /** @return array{ok: bool, reason: ?string, code: ?int, meaning: ?string, detail: ?string} */
    protected function deliverSms(StorefrontSetting $setting, string $phone, string $message): array
    {
        $credentials = $setting->notification_credentials ?? [];
        $urlTemplate = trim((string) ($credentials['sms_api_url'] ?? ''));
        $apiKey = trim((string) ($credentials['sms_api_key'] ?? ''));

        if ($urlTemplate === '') {
            return ['ok' => false, 'reason' => 'no_gateway', 'code' => null, 'meaning' => null, 'detail' => null];
        }

        // The gateway would answer "User Id not found" for an empty key; say what is really wrong.
        if ($apiKey === '' && str_contains($urlTemplate, '{api_key}')) {
            return ['ok' => false, 'reason' => 'no_api_key', 'code' => null, 'meaning' => null, 'detail' => null];
        }

        $url = $this->smsRequestUrl($urlTemplate, $credentials, $phone, $message);

        try {
            $response = Http::timeout(20)->get($url);
        } catch (\Throwable $exception) {
            $error = self::scrubUrls($exception->getMessage());
            Log::warning('Storefront SMS send failed', ['error' => $error]);

            return ['ok' => false, 'reason' => 'gateway_unreachable', 'code' => null, 'meaning' => null, 'detail' => 'unreachable: '.str($error)->limit(180)];
        }

        if (! $response->successful()) {
            Log::warning('Storefront SMS rejected by gateway', ['company_id' => $setting->company_id, 'http_status' => $response->status()]);

            return ['ok' => false, 'reason' => 'gateway_unreachable', 'code' => null, 'meaning' => null, 'detail' => 'http_'.$response->status()];
        }

        // These gateways answer HTTP 200 even when they refuse the SMS (no balance, wrong sender ID, IP not
        // whitelisted), with the real result in a reply code, so the HTTP status alone is not enough.
        $code = $this->smsGatewayCode($response->body());

        if ($code !== null && $code !== self::SMS_GATEWAY_SUCCESS_CODE) {
            Log::warning('Storefront SMS refused by gateway', [
                'company_id' => $setting->company_id,
                'code' => $code,
                'meaning' => self::SMS_GATEWAY_CODES[$code] ?? null,
            ]);

            return ['ok' => false, 'reason' => 'gateway_refused', 'code' => $code, 'meaning' => self::SMS_GATEWAY_CODES[$code] ?? null, 'detail' => null];
        }

        return ['ok' => true, 'reason' => null, 'code' => $code, 'meaning' => $code !== null ? (self::SMS_GATEWAY_CODES[$code] ?? null) : null, 'detail' => null];
    }

    /**
     * The reply code of a gateway response: `response_code` in a JSON body, or a plain body that is exactly a
     * known code. Null when the gateway sends no code (other gateways), so those keep going by HTTP status.
     */
    protected function smsGatewayCode(string $body): ?int
    {
        $body = trim($body);
        $json = json_decode($body, true);

        if (is_array($json) && isset($json['response_code']) && is_numeric($json['response_code'])) {
            return (int) $json['response_code'];
        }

        if (preg_match('/^\d{3,4}$/', $body) && array_key_exists((int) $body, self::SMS_GATEWAY_CODES)) {
            return (int) $body;
        }

        return null;
    }

    public function mailConfigured(StorefrontSetting $setting): bool
    {
        return filled(data_get($setting->notification_credentials, 'mail_host'))
            && filled(data_get($setting->notification_credentials, 'mail_username'));
    }

    /**
     * The sender address this site's email goes out from: the From address, else the SMTP username when that
     * is itself an email address. Null when neither is — Amazon SES, for one, uses an access-key ID like
     * AKIA… as the SMTP username, which is not a sender.
     */
    public function mailFromAddress(StorefrontSetting $setting): ?string
    {
        $credentials = (array) $setting->notification_credentials;

        foreach ([$credentials['mail_from_address'] ?? null, $credentials['mail_username'] ?? null] as $candidate) {
            if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function companyMailer(StorefrontSetting $setting): Mailer
    {
        $credentials = (array) $setting->notification_credentials;

        Config::set('mail.mailers.storefront_dynamic', [
            'transport' => 'smtp',
            'host' => $credentials['mail_host'] ?? null,
            'port' => (int) ($credentials['mail_port'] ?? 587),
            'encryption' => ($credentials['mail_encryption'] ?? 'tls') ?: null,
            'username' => $credentials['mail_username'] ?? null,
            'password' => $credentials['mail_password'] ?? null,
        ]);
        Config::set('mail.from', [
            'address' => $this->mailFromAddress($setting) ?? config('mail.from.address'),
            'name' => $credentials['mail_from_name'] ?? config('mail.from.name'),
        ]);

        // The mail manager keeps a built mailer for the whole process; a queue worker sending for one company
        // after another would otherwise keep using the first company's SMTP account and sender.
        Mail::purge('storefront_dynamic');

        return Mail::mailer('storefront_dynamic');
    }

    /**
     * Sends a short test message through this site's own saved SMTP account (never the server's default
     * mailer). Returns null when the server accepted it, else the server's own error, which for Amazon SES
     * says what is wrong (unverified sender, sandbox recipient, wrong credentials).
     */
    public function sendTestEmail(StorefrontSetting $setting, string $email): ?string
    {
        if (! $this->mailConfigured($setting)) {
            return __('SMTP host and username are not saved yet.');
        }

        $logs = app(EmailLogService::class);
        $via = $logs->via($setting, true);

        try {
            $this->companyMailer($setting)->raw(
                __('This is a test email from ZamZam ERP. If you can read it, email sending for this site works.'),
                fn ($message) => $message->to($email)->subject(__('ZamZam ERP test email')),
            );
        } catch (\Throwable $exception) {
            $logs->failed($setting, EmailLog::KIND_TEST, $email, $via, $exception->getMessage());

            return $exception->getMessage();
        }

        $logs->sent($setting, EmailLog::KIND_TEST, $email, $via);

        return null;
    }

    /**
     * Whether an email sent for this company would really leave the server: the
     * company's own SMTP is filled in, or the server's default mailer is a real
     * one (the "log" and "array" mailers only write the message down).
     */
    public function emailDeliveryAvailable(StorefrontSetting $setting): bool
    {
        return $this->mailConfigured($setting)
            || ! in_array(config('mail.default'), ['log', 'array', null, ''], true);
    }

    /**
     * Any mail through the company's own SMTP when it has one (IMS v2 notifications). $kind says what the
     * message is for, in the email log.
     */
    public function sendMailable(StorefrontSetting $setting, string $email, Mailable $mail, string $kind = EmailLog::KIND_OTHER): bool
    {
        return $this->deliver($setting, $email, $mail, $kind, 'Company email failed');
    }

    public function sendResellerPasswordResetEmail(StorefrontSetting $setting, string $email, string $companyName, string $url, int $minutes): bool
    {
        return $this->deliver(
            $setting,
            $email,
            new ResellerPasswordResetLink($companyName, $url, $minutes),
            EmailLog::KIND_RESELLER_PASSWORD_RESET,
            'Reseller password reset email failed',
        );
    }

    public function sendLoginOtpEmail(StorefrontSetting $setting, string $email, string $companyName, string $code): bool
    {
        return $this->deliver($setting, $email, new StorefrontLoginOtp($companyName, $code), EmailLog::KIND_LOGIN_CODE, 'Storefront login OTP email failed');
    }

    /**
     * Sends one mailable through this site's own SMTP when it has one, else the server's default mailer, and
     * writes the outcome to the email log (never the text of the message). Logging never changes the result.
     */
    protected function deliver(StorefrontSetting $setting, string $email, Mailable $mail, string $kind, string $failureWarning): bool
    {
        $logs = app(EmailLogService::class);
        $ownSmtp = $this->mailConfigured($setting);
        $via = $logs->via($setting, $ownSmtp);

        try {
            $mailer = $ownSmtp ? $this->companyMailer($setting) : Mail::mailer();
            $mailer->to($email)->send($mail);
        } catch (\Throwable $exception) {
            Log::warning($failureWarning, ['error' => $exception->getMessage(), 'mail' => $mail::class]);
            $logs->failed($setting, $kind, $email, $via, $exception->getMessage());

            return false;
        }

        // The server's default mailer may only write to a log file ("log" / "array"): the send works, but
        // nothing leaves the server, and the log must not call that "sent".
        if (! $ownSmtp && ! $this->emailDeliveryAvailable($setting)) {
            $logs->skipped((int) $setting->company_id, $kind, $email, EmailLogService::REASON_LOG_ONLY, $via);
        } else {
            $logs->sent($setting, $kind, $email, $via);
        }

        return true;
    }

    public function sendWhatsAppTemplate(
        StorefrontSetting $setting,
        string $phone,
        array $bodyParameters = [],
        ?string $templateOverride = null,
    ): bool {
        $credentials = $setting->notification_credentials ?? [];
        $template = trim((string) ($templateOverride ?: ($credentials['whatsapp_template_name'] ?? '')));
        $selectedChannelId = $credentials['whatsapp_channel_id'] ?? null;
        $channelQuery = ConversationChannel::withoutGlobalScopes()
            ->where('company_id', $setting->company_id)
            ->where('provider', 'whatsapp')
            ->where('is_active', true);
        $channel = filled($selectedChannelId)
            ? $channelQuery->whereKey($selectedChannelId)->first()
            : null;

        if (filled($selectedChannelId) && ! $channel) {
            Log::warning('Storefront WhatsApp reminder channel is unavailable.', [
                'company_id' => $setting->company_id,
                'channel_id' => $selectedChannelId,
            ]);

            return false;
        }

        // Credentials are selected atomically. Never combine one field from a
        // selected channel with another field from the legacy fallback.
        if ($channel) {
            $token = (string) $channel->access_token;
            $phoneNumberId = (string) $channel->external_id;

            if ($token === '' || $phoneNumberId === '') {
                $channel->recordDiagnosticError('The selected WhatsApp channel is missing its access token or Phone Number ID. Complete and test the channel before sending storefront templates.', 'outbound');

                return false;
            }
        } else {
            // Backward-compatible fallback for stores not yet migrated to the
            // centralized Chat Channel credentials.
            $token = (string) ($credentials['whatsapp_token'] ?? '');
            $phoneNumberId = (string) ($credentials['whatsapp_phone_number_id'] ?? '');
        }

        if ($token === '' || $phoneNumberId === '' || $template === '') {
            return false;
        }

        try {
            $messageId = $this->meta->sendWhatsAppTemplate(
                $phoneNumberId,
                $token,
                preg_replace('/\D+/', '', $phone) ?: $phone,
                $template,
                (string) ($credentials['whatsapp_template_language'] ?? 'bn'),
                $bodyParameters,
            );
        } catch (\Throwable $exception) {
            Log::warning('Storefront WhatsApp send failed', ['error' => $exception->getMessage()]);

            try {
                $channel?->recordDiagnosticError($exception->getMessage(), 'outbound');
            } catch (\Throwable $diagnosticException) {
                Log::warning('Storefront WhatsApp diagnostic could not be saved.', [
                    'exception' => $diagnosticException::class,
                ]);
            }

            return false;
        }

        if ($channel) {
            try {
                $channel->markOutboundSent();
            } catch (\Throwable $exception) {
                // Meta already accepted the template. Reporting failure here
                // could make the reminder scheduler send a duplicate.
                Log::warning('Storefront WhatsApp template was accepted but diagnostics could not be updated.', [
                    'company_id' => $setting->company_id,
                    'channel_id' => $channel->getKey(),
                    'exception' => $exception::class,
                ]);
            }
        }

        return true;
    }
}
