<?php

namespace App\Services;

use App\Mail\ResellerPasswordResetLink;
use App\Mail\StorefrontLoginOtp;
use App\Models\ConversationChannel;
use App\Models\StorefrontSetting;
use App\Services\Meta\MetaGraphService;
use App\Support\PhoneLinks;
use Illuminate\Contracts\Mail\Mailer;
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
    public const SMS_CREDENTIAL_KEYS = ['sms_api_url', 'sms_api_key', 'sms_sender_id'];

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

    public function sendSms(StorefrontSetting $setting, string $phone, string $message): bool
    {
        $credentials = $setting->notification_credentials ?? [];
        $urlTemplate = (string) ($credentials['sms_api_url'] ?? '');

        if ($urlTemplate === '') {
            return false;
        }

        $url = strtr($urlTemplate, [
            '{api_key}' => rawurlencode((string) ($credentials['sms_api_key'] ?? '')),
            '{sender_id}' => rawurlencode((string) ($credentials['sms_sender_id'] ?? '')),
            // Country-code form (8801XXXXXXXXX), the format the gateway's own docs show.
            '{phone}' => rawurlencode(PhoneLinks::internationalDigits($phone) ?? $phone),
            '{message}' => rawurlencode($message),
        ]);

        try {
            $response = Http::timeout(20)->get($url);
        } catch (\Throwable $exception) {
            Log::warning('Storefront SMS send failed', ['error' => $exception->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Storefront SMS rejected by gateway', ['company_id' => $setting->company_id, 'http_status' => $response->status()]);

            return false;
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

            return false;
        }

        return true;
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
            'address' => $credentials['mail_from_address'] ?? $credentials['mail_username'] ?? config('mail.from.address'),
            'name' => $credentials['mail_from_name'] ?? config('mail.from.name'),
        ]);

        return Mail::mailer('storefront_dynamic');
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

    public function sendResellerPasswordResetEmail(StorefrontSetting $setting, string $email, string $companyName, string $url, int $minutes): bool
    {
        try {
            $mailer = $this->mailConfigured($setting) ? $this->companyMailer($setting) : Mail::mailer();
            $mailer->to($email)->send(new ResellerPasswordResetLink($companyName, $url, $minutes));

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Reseller password reset email failed', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    public function sendLoginOtpEmail(StorefrontSetting $setting, string $email, string $companyName, string $code): bool
    {
        try {
            $mailer = $this->mailConfigured($setting) ? $this->companyMailer($setting) : Mail::mailer();
            $mailer->to($email)->send(new StorefrontLoginOtp($companyName, $code));

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Storefront login OTP email failed', ['error' => $exception->getMessage()]);

            return false;
        }
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
