<?php

namespace App\Services;

use App\Models\EmailLog;
use App\Models\StorefrontSetting;
use Throwable;

/**
 * Writes the email log (owner request 2026-10-23): one row per email, so the
 * owner can see whether it was sent, failed, or not sent, and why, instead of
 * guessing. Logging never stops an email: a failed write is reported and
 * ignored. Only the recipient, the kind of message and the mail server's
 * answer are written, never the text, so a login or reset code is never kept.
 */
class EmailLogService
{
    public const REASON_NOT_CONFIGURED = 'not_configured';

    public const REASON_LOG_ONLY = 'log_only';

    public const REASON_AUTH_FAILED = 'auth_failed';

    public const REASON_ADDRESS_NOT_VERIFIED = 'address_not_verified';

    public const REASON_LIMIT_REACHED = 'limit_reached';

    public const REASON_UNREACHABLE = 'unreachable';

    public const REASON_INVALID_ADDRESS = 'invalid_address';

    public const REASON_SEND_ERROR = 'send_error';

    /** @return array<string, string> reason key => what it means for the owner */
    public static function reasonLabels(): array
    {
        return [
            self::REASON_NOT_CONFIGURED => __('No email account is set up for this site (Settings → Integrations → Email Setup).'),
            self::REASON_LOG_ONLY => __('The server\'s own mailer only writes to a log file, so nothing left the server. Set up Settings → Integrations → Email Setup.'),
            self::REASON_AUTH_FAILED => __('The mail server refused the SMTP user name or password.'),
            self::REASON_ADDRESS_NOT_VERIFIED => __('The mail server says an email address is not verified: the From address, or the recipient while Amazon SES is still in its sandbox.'),
            self::REASON_LIMIT_REACHED => __('The mail server\'s sending limit or sending rate was reached.'),
            self::REASON_UNREACHABLE => __('The mail server could not be reached.'),
            self::REASON_INVALID_ADDRESS => __('The email address is not valid.'),
            self::REASON_SEND_ERROR => __('The mail server could not send the message.'),
        ];
    }

    /** Where the email goes through: this site's own SMTP host, or the server's default mailer. */
    public function via(StorefrontSetting $setting, bool $ownSmtp): string
    {
        return $ownSmtp
            ? 'smtp:'.mb_substr((string) data_get($setting->notification_credentials, 'mail_host'), 0, 140)
            : 'server:'.(string) config('mail.default');
    }

    /** The mail server accepted the message. */
    public function sent(StorefrontSetting $setting, string $kind, string $recipient, string $via): void
    {
        $this->write((int) $setting->company_id, $kind, $recipient, EmailLog::STATUS_SENT, null, $via, null);
    }

    /** The send failed; $message is the mail server's (or the transport's) own error text. */
    public function failed(StorefrontSetting $setting, string $kind, string $recipient, string $via, string $message): void
    {
        $this->write((int) $setting->company_id, $kind, $recipient, EmailLog::STATUS_FAILED, $this->classify($message), $via, $message);
    }

    /** The email was not sent (or went nowhere), with the reason key. */
    public function skipped(int $companyId, string $kind, string $recipient, string $reason, ?string $via = null): void
    {
        $this->write($companyId, $kind, $recipient, EmailLog::STATUS_SKIPPED, $reason, $via, null);
    }

    /** Turns the mail server's or transport's error text into one of the reason keys. */
    public function classify(string $message): string
    {
        $text = mb_strtolower($message);

        return match (true) {
            str_contains($text, 'authenticat') || str_contains($text, 'credentials') || preg_match('/\b535\b/', $text) === 1 => self::REASON_AUTH_FAILED,
            str_contains($text, 'not verified') || str_contains($text, 'messagerejected') => self::REASON_ADDRESS_NOT_VERIFIED,
            str_contains($text, 'throttl') || str_contains($text, 'quota') || str_contains($text, 'rate exceeded') || str_contains($text, 'sending limit') => self::REASON_LIMIT_REACHED,
            str_contains($text, 'does not comply with addressing specification') || str_contains($text, 'invalid address') => self::REASON_INVALID_ADDRESS,
            str_contains($text, 'could not be established') || str_contains($text, 'connection refused') || str_contains($text, 'timed out')
                || str_contains($text, 'timeout') || str_contains($text, 'getaddrinfo') || str_contains($text, 'name or service not known')
                || str_contains($text, 'unable to connect') => self::REASON_UNREACHABLE,
            default => self::REASON_SEND_ERROR,
        };
    }

    protected function write(int $companyId, string $kind, string $recipient, string $status, ?string $reason, ?string $via, ?string $detail): void
    {
        try {
            EmailLog::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'kind' => $kind,
                'recipient' => mb_substr($recipient, 0, 254),
                'via' => $via,
                'status' => $status,
                'reason' => $reason,
                'detail' => $detail === null ? null : mb_substr($detail, 0, 500),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
