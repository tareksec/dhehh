<?php

namespace App\Services\Email;

use App\Models\StorefrontSetting;
use App\Services\EmailLogService;
use App\Services\Ses\SesTrackingService;

/**
 * Which email service a site sends through, found from its SMTP host (owner request 2026-11-02: the delivery page
 * works for any provider, not only Amazon SES). The name is only for showing; nothing is switched on or off by
 * the guess except whether the service reports delivered / bounced back (today only Amazon SES, through its
 * tracking). A host this list does not know is "Other SMTP service" with the host shown, never a wrong name.
 */
final class EmailProvider
{
    public const SES = 'amazon_ses';

    public const SERVER = 'server';

    public const OTHER = 'other';

    /**
     * Host pattern (matched against the whole host, case-insensitive) => key and name. The first match wins.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const KNOWN = [
        '/^email-smtp\.[a-z0-9-]+\.amazonaws\.com$/' => [self::SES, 'Amazon SES'],
        '/^smtp\.mx\.cloudflare\.net$/' => ['cloudflare', 'Cloudflare Email Sending'],
        '/^smtp-relay\.(brevo|sendinblue)\.com$/' => ['brevo', 'Brevo'],
        '/^in-v3\.mailjet\.com$/' => ['mailjet', 'Mailjet'],
        '/^smtp\.resend\.com$/' => ['resend', 'Resend'],
        '/^smtp\.(gmail|googlemail)\.com$/' => ['gmail', 'Gmail'],
        '/^smtp-relay\.gmail\.com$/' => ['gmail', 'Gmail'],
        '/^smtp\.zeptomail\.[a-z.]+$/' => ['zeptomail', 'Zoho ZeptoMail'],
        '/^smtp(pro)?\.zoho\.[a-z.]+$/' => ['zoho', 'Zoho Mail'],
        '/^smtp\.sendgrid\.net$/' => ['sendgrid', 'SendGrid'],
        '/^smtp(\.eu)?\.mailgun\.org$/' => ['mailgun', 'Mailgun'],
        '/^smtp\.postmarkapp\.com$/' => ['postmark', 'Postmark'],
    ];

    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $host,
        public readonly string $via,
        public readonly bool $reportsBack,
    ) {}

    /** The service behind a site's saved SMTP account; the server's own mailer when no SMTP host is saved. */
    public static function forSetting(?StorefrontSetting $setting): self
    {
        $host = strtolower(trim((string) data_get($setting?->notification_credentials, 'mail_host')));

        if ($host === '' || $setting === null) {
            return new self(self::SERVER, __('The server\'s own mailer'), '', 'server:'.(string) config('mail.default'), false);
        }

        $via = app(EmailLogService::class)->via($setting, true);

        foreach (self::KNOWN as $pattern => [$key, $name]) {
            if (preg_match($pattern, $host) === 1) {
                // Amazon SES reports delivered / bounced / complained back through its tracking (Settings → Amazon SES).
                return new self($key, $name, $host, $via, $key === self::SES);
            }
        }

        return new self(self::OTHER, __('Other SMTP service'), $host, $via, false);
    }

    /** The names of the services that are recognised, for the "Recognised services" row. */
    public static function knownNames(): array
    {
        return array_values(array_unique(array_map(fn (array $entry): string => $entry[1], self::KNOWN)));
    }

    public function isAmazonSes(): bool
    {
        return $this->key === self::SES;
    }

    public function isServerMailer(): bool
    {
        return $this->key === self::SERVER;
    }

    /** Whether Settings → Amazon SES has its tracking switched on for this site (the only way a service reports back today). */
    public function trackingOn(?StorefrontSetting $setting): bool
    {
        return $this->reportsBack && $setting !== null && SesTrackingService::isEnabled($setting);
    }
}
