<?php

namespace App\Filament\Concerns;

use App\Support\LoginClient;
use App\Support\LoginThrottled;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\View;

/**
 * The CAPTCHA step of the Filament sign-in pages (admin, reseller, investor).
 *
 * A normal sign-in never sees it. When LoginGate answers CHALLENGE (an unknown
 * device while the account or the whole site is under attack) and a CAPTCHA is
 * set up (Settings → Login Security), the page shows the Cloudflare Turnstile
 * widget; the visitor solves it and signs in again, and the token travels in
 * the LoginClient the page hands the gate. Without a CAPTCHA the challenge is
 * a plain wait and the widget never appears.
 */
trait HasLoginCaptcha
{
    /** The gate asked for the CAPTCHA, so the widget is shown. */
    public bool $captchaRequired = false;

    /** Turnstile's answer, written by the widget (never trusted: the gate verifies it). */
    public ?string $captchaToken = null;

    protected function captchaFormComponent(): View
    {
        return View::make('filament.components.login-captcha')
            ->visible(fn (): bool => $this->captchaRequired);
    }

    protected function loginClient(string $surface): LoginClient
    {
        return LoginClient::fromRequest(request(), $surface)->withCaptchaToken($this->captchaToken);
    }

    /** The gate stopped the attempt: tell the visitor and, if it can be cleared, show the widget. */
    protected function showLoginChallenge(LoginThrottled $exception): void
    {
        $this->captchaRequired = $exception->captcha;
        $this->resetCaptcha();

        Notification::make()->title($exception->getMessage())->danger()->send();
    }

    /** A Turnstile token works once, so the widget starts again after every attempt. */
    protected function resetCaptcha(): void
    {
        $this->captchaToken = null;

        if ($this->captchaRequired) {
            $this->dispatch('login-captcha-reset');
        }
    }
}
