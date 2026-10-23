<?php

namespace App\Filament\Auth;

use App\Filament\Concerns\HasLoginCaptcha;
use App\Models\User;
use App\Services\LoginGate;
use App\Support\ImsPanelSessions;
use App\Support\LoginThrottled;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The admin (staff) sign-in: Filament's own login with LoginGate in front of
 * it. Filament keeps doing the password check, the multi-factor step and the
 * panel-access check; this page only asks the gate BEFORE any of that runs
 * (so a refused guess costs no password hash) and tells it afterwards how the
 * attempt ended.
 *
 * The page's company is null: staff users are not tied to the storefront
 * company the visitor's domain points at.
 */
class AdminLogin extends BaseLogin
{
    use HasLoginCaptcha;

    public function mount(): void
    {
        // Already signed in: go to this panel, never a portal page left from before (ImsPanelSessions).
        $panel = Filament::getCurrentPanel();
        ImsPanelSessions::forgetForeignIntendedUrl($panel);

        if ($panel?->getId() === 'trade') {
            ImsPanelSessions::forgetIntendedUrlOutside($panel);
        }

        parent::mount();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getRememberFormComponent(),
            $this->captchaFormComponent(),
        ]);
    }

    public function authenticate(): ?LoginResponse
    {
        $gate = app(LoginGate::class);
        $email = trim((string) data_get($this->data, 'email'));
        $user = $email === '' ? null : User::query()->withoutGlobalScopes()->where('email', $email)->first();

        $attempt = $gate->begin(
            LoginGate::SURFACE_ADMIN,
            null,
            $email,
            $user,
            $this->loginClient(LoginGate::SURFACE_ADMIN),
        );

        if ($attempt->isDenied()) {
            $gate->failed($attempt);
            $this->resetCaptcha();

            $this->throwFailureValidationException();
        }

        if ($attempt->isBlocked()) {
            $this->showLoginChallenge(LoginThrottled::for($attempt));

            return null;
        }

        try {
            $response = parent::authenticate();
        } catch (ValidationException $exception) {
            $gate->failed($attempt);
            $this->resetCaptcha();

            throw $exception;
        }

        // null: rate limited by Filament itself, or waiting for the multi-factor code.
        $signedIn = Filament::auth()->user();

        if ($response !== null && $signedIn !== null) {
            $gate->succeeded($attempt);
            $gate->rememberDevice(LoginGate::SURFACE_ADMIN, null, $signedIn);
        }

        return $response;
    }
}
