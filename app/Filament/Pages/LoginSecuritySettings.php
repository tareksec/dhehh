<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Support\LoginCaptchaSettings;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Settings → Login Security: the Cloudflare Turnstile keys for the sign-in
 * CAPTCHA (App\Services\LoginGate step-up). Super admin only; the secret key is
 * stored encrypted and never sent back to the browser.
 */
class LoginSecuritySettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 11;

    protected static ?string $title = null;

    protected string $view = 'filament.pages.login-security-settings';

    /** @var array<string, mixed> */
    public array $settings = [];

    public static function getNavigationLabel(): string
    {
        return __('Login Security');
    }

    public function getTitle(): string
    {
        return __('Login Security');
    }

    public static function canAccess(): bool
    {
        return LoginCaptchaSettings::schemaIsReady() && (Auth::user()?->isSuperAdmin() ?? false);
    }

    public function mount(): void
    {
        $this->loadSettings();
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->validate([
            'settings.enabled' => ['boolean'],
            'settings.site_key' => ['nullable', 'string', 'max:191'],
            'settings.secret_key' => ['nullable', 'string', 'max:191'],
        ]);

        $saved = LoginCaptchaSettings::all();
        $siteKey = trim((string) ($this->settings['site_key'] ?? ''));
        $secretKey = trim((string) ($this->settings['secret_key'] ?? ''));

        // Switching it on needs both keys: the ones already saved, or the ones typed now.
        if (! empty($this->settings['enabled']) && ($siteKey === '' && $saved['site_key'] === null)) {
            $this->addError('settings.site_key', __('Enter the Turnstile site key to switch the security check on.'));

            return;
        }

        if (! empty($this->settings['enabled']) && ($secretKey === '' && $saved['secret_key'] === null)) {
            $this->addError('settings.secret_key', __('Enter the Turnstile secret key to switch the security check on.'));

            return;
        }

        LoginCaptchaSettings::save([
            'enabled' => (bool) ($this->settings['enabled'] ?? false),
            // Blank means "keep the saved key".
            'site_key' => $siteKey,
            'secret_key' => $secretKey,
        ]);

        $this->loadSettings();

        Notification::make()
            ->title(__('Login security settings saved'))
            ->success()
            ->send();
    }

    private function loadSettings(): void
    {
        $saved = LoginCaptchaSettings::all();

        $this->settings = [
            'enabled' => $saved['enabled'],
            // The site key is public (it sits in the page's HTML), so it is shown.
            'site_key' => $saved['site_key'] ?? '',
            // The secret key is never round-tripped to the browser.
            'secret_key' => '',
            'has_secret_key' => $saved['secret_key'] !== null,
        ];
    }
}
