<?php

namespace App\Services;

use App\Mail\StorefrontLoginOtp;
use App\Models\ConversationChannel;
use App\Models\StorefrontSetting;
use App\Services\Meta\MetaGraphService;
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
            '{phone}' => rawurlencode(preg_replace('/\D+/', '', $phone) ?: $phone),
            '{message}' => rawurlencode($message),
        ]);

        try {
            return Http::timeout(20)->get($url)->successful();
        } catch (\Throwable $exception) {
            Log::warning('Storefront SMS send failed', ['error' => $exception->getMessage()]);

            return false;
        }
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
