<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-configurable CAPTCHA (Cloudflare Turnstile) for the sign-in gate
 * (App\Services\LoginGate), stored in the global AppSetting key/value store —
 * per CLAUDE.md external credentials are admin-configurable encrypted settings,
 * never deployment config. One widget (one key pair) serves every sign-in page
 * of the app (admin, storefronts, reseller and investor portals), so the
 * setting is global like FirebaseSettings; the widget's allowed hostnames are
 * managed in the Cloudflare dashboard.
 *
 * The CAPTCHA is only a step-up: it is shown when LoginGate answers CHALLENGE
 * (an unknown device while the account or the whole site is under attack),
 * never to a normal sign-in. With the setting off or incomplete, a challenge
 * stays a plain wait.
 */
class LoginCaptchaSettings
{
    /**
     * Field key => [AppSetting key, whether it is stored encrypted].
     *
     * @var array<string, array{key: string, encrypted: bool}>
     */
    public const FIELDS = [
        'enabled' => ['key' => 'login_captcha.enabled', 'encrypted' => false],
        'site_key' => ['key' => 'login_captcha.turnstile.site_key', 'encrypted' => true],
        'secret_key' => ['key' => 'login_captcha.turnstile.secret_key', 'encrypted' => true],
    ];

    public static function schemaIsReady(): bool
    {
        return Schema::hasTable('app_settings');
    }

    /**
     * @return array{enabled: bool, site_key: ?string, secret_key: ?string}
     */
    public static function all(): array
    {
        if (! self::schemaIsReady()) {
            return ['enabled' => false, 'site_key' => null, 'secret_key' => null];
        }

        return [
            'enabled' => AppSetting::boolValue(self::FIELDS['enabled']['key'], false),
            'site_key' => self::clean(AppSetting::getValue(self::FIELDS['site_key']['key'])),
            'secret_key' => self::clean(AppSetting::getValue(self::FIELDS['secret_key']['key'])),
        ];
    }

    /** Switched on AND both keys saved: the only state in which a challenge shows a CAPTCHA. */
    public static function isConfigured(): bool
    {
        $settings = self::all();

        return $settings['enabled'] && $settings['site_key'] !== null && $settings['secret_key'] !== null;
    }

    /** The public key the widget needs; null unless the CAPTCHA is configured. */
    public static function siteKey(): ?string
    {
        return self::isConfigured() ? self::all()['site_key'] : null;
    }

    public static function secretKey(): ?string
    {
        return self::isConfigured() ? self::all()['secret_key'] : null;
    }

    /**
     * Blank key fields mean "leave the saved key unchanged".
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data): void
    {
        if (array_key_exists('enabled', $data)) {
            AppSetting::setValue(self::FIELDS['enabled']['key'], $data['enabled'] ? '1' : '0');
        }

        foreach (['site_key', 'secret_key'] as $field) {
            $value = self::clean($data[$field] ?? null);

            if ($value !== null) {
                AppSetting::setValue(self::FIELDS[$field]['key'], $value, encrypted: true);
            }
        }
    }

    private static function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
