<?php

namespace App\Filament\Investor\Pages\Auth;

use App\Filament\Concerns\HasLoginCaptcha;
use App\Models\InvestorPortalAccount;
use App\Services\CompanyContext;
use App\Services\InvestorPortalAccountService;
use App\Services\LoginGate;
use App\Support\LoginThrottled;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Investor-portal sign-in (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md §4.4):
 * phone + password, or phone + a 6-digit SMS code. The SMS option is shown
 * only while the company has an SMS gateway set up, so password sign-in
 * always works. The reply to "send code" is the same whether or not the
 * phone has an account, so the form cannot be used to find out who invests.
 */
class Login extends BaseLogin
{
    use HasLoginCaptcha;

    /** "password" or "code". */
    public string $mode = 'password';

    /** The phone a sign-in code was sent to; null until one is sent. */
    public ?string $codePhone = null;

    public function form(Schema $schema): Schema
    {
        $codeMode = fn (): bool => $this->mode === 'code';

        return $schema->components([
            TextInput::make('phone')
                ->label(__('Phone'))
                ->tel()
                ->required()
                ->maxLength(40)
                ->autocomplete('tel')
                ->autofocus()
                ->disabled(fn (): bool => $this->codePhone !== null),
            TextInput::make('password')
                ->label(__('Password'))
                ->password()
                ->revealable(filament()->arePasswordsRevealable())
                ->autocomplete('current-password')
                ->required()
                ->hint(filament()->hasPasswordReset()
                    ? new HtmlString(Blade::render('<x-filament::link :href="filament()->getRequestPasswordResetUrl()" tabindex="3">{{ __(\'Forgot your password?\') }}</x-filament::link>'))
                    : null)
                ->visible(fn (): bool => $this->mode === 'password'),
            TextInput::make('code')
                ->label(__('SMS code'))
                ->helperText(fn (): string => __('The 6-digit code sent by SMS to :phone. It expires in 1 minute.', ['phone' => (string) $this->codePhone]))
                ->required()
                ->maxLength(10)
                ->autocomplete('one-time-code')
                ->visible(fn (): bool => $codeMode() && $this->codePhone !== null),
            Checkbox::make('remember')
                ->label(__('filament-panels::auth/pages/login.form.remember.label')),
            $this->captchaFormComponent(),
        ]);
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();
        $accounts = app(InvestorPortalAccountService::class);

        if ($this->mode === 'code' && $this->codePhone === null) {
            $this->sendCode((string) $data['phone']);

            return null;
        }

        if ($this->mode === 'code') {
            $account = $accounts->findByPhone((string) $this->codePhone);
            $valid = $account && $this->isUserAllowedToAccessPanel($account) && $accounts->verifyCode($account, (string) $data['code']);

            if (! $valid) {
                throw ValidationException::withMessages(['data.code' => __('That code is invalid or has expired. Please request a new one.')]);
            }
        } else {
            try {
                $account = $accounts->attemptPasswordLogin((string) $data['phone'], (string) $data['password'], $this->loginClient(LoginGate::SURFACE_INVESTOR));
            } catch (LoginThrottled $exception) {
                $this->showLoginChallenge($exception);

                return null;
            }

            if (! $account || ! $this->isUserAllowedToAccessPanel($account)) {
                $this->resetCaptcha();

                throw ValidationException::withMessages(['data.phone' => __('filament-panels::auth/pages/login.messages.failed')]);
            }
        }

        Filament::auth()->login($account, (bool) ($data['remember'] ?? false));
        session()->regenerate();
        $accounts->recordLogin($account, request()->ip(), $this->mode);
        app(LoginGate::class)->rememberDevice(LoginGate::SURFACE_INVESTOR, app(CompanyContext::class)->id(), $account);

        return app(LoginResponse::class);
    }

    protected function sendCode(string $phone): void
    {
        $accounts = app(InvestorPortalAccountService::class);

        if (! $accounts->smsAvailable(app(CompanyContext::class)->company())) {
            $this->mode = 'password';
            Notification::make()->title(__('Sign-in by SMS code is not available right now. Please use your password.'))->danger()->send();

            return;
        }

        $account = $accounts->findByPhone($phone);

        if ($account && $this->isUserAllowedToAccessPanel($account)) {
            $accounts->sendCode($account, 'login');
        }

        // Same reply whether or not the phone matched an account.
        $this->codePhone = $phone;

        Notification::make()
            ->title(__('If an investor portal account uses that phone number, a sign-in code has been sent by SMS.'))
            ->success()
            ->send();
    }

    public function useCode(): void
    {
        $this->mode = 'code';
        $this->codePhone = null;
        $this->resetErrorBag();
    }

    public function usePassword(): void
    {
        $this->mode = 'password';
        $this->codePhone = null;
        $this->resetErrorBag();
    }

    /** Shown under the company logo so it is clear which sign-in this is. */
    public function getHeading(): string|Htmlable|null
    {
        return __('Investor Dashboard');
    }

    /** No "sign up" link here: sign-up works only through a partner's invite link. */
    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        return $user instanceof InvestorPortalAccount && $user->canAccessPanel(Filament::getCurrentOrDefaultPanel());
    }

    protected function getAuthenticateFormAction(): Action
    {
        return Action::make('authenticate')
            ->label(fn (): string => $this->mode === 'code' && $this->codePhone === null
                ? __('Send sign-in code')
                : __('filament-panels::auth/pages/login.form.actions.authenticate.label'))
            ->submit('authenticate');
    }

    protected function getFormActions(): array
    {
        $smsAvailable = app(InvestorPortalAccountService::class)->smsAvailable(app(CompanyContext::class)->company());

        return array_values(array_filter([
            $this->getAuthenticateFormAction(),
            $smsAvailable && $this->mode === 'password'
                ? Action::make('useCode')->label(__('Sign in with an SMS code instead'))->link()->action('useCode')
                : null,
            $this->mode === 'code'
                ? Action::make('usePassword')->label(__('Sign in with your password instead'))->link()->action('usePassword')
                : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        // Never used -- authenticate() is fully reimplemented above.
        return ['phone' => $data['phone'] ?? null, 'password' => $data['password'] ?? null];
    }
}
