<?php

namespace App\Filament\Investor\Pages\Auth;

use App\Models\Company;
use App\Models\InvestorPortalAccount;
use App\Services\CompanyContext;
use App\Services\InvestorBusinessSwitchService;
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
use Livewire\Attributes\Locked;
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
    /** "password" or "code". */
    public string $mode = 'password';

    /** The phone a sign-in code was sent to; null until one is sent. */
    public ?string $codePhone = null;

    /** The company of the account the code went to, when it is not the open one (the shared IMS domain). */
    #[Locked]
    public ?int $codeCompanyId = null;

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
                // The central sign-in has no password reset: that is done on the business's own address.
                ->hint(filament()->hasPasswordReset() && ! $this->onCentralHost()
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

        if ($this->onCentralHost()) {
            return $this->authenticateOnCentralHost($data);
        }

        // An IMS domain (owner request 2026-10-20): a person who also invests in
        // another business signs in to all of them here, by password.
        $hostCompany = app(CompanyContext::class)->company();

        if ($this->mode === 'password' && $hostCompany
            && app(InvestorBusinessSwitchService::class)->imsHostCompany()
            && app(InvestorBusinessSwitchService::class)->hasAccountsElsewhere((string) $data['phone'], $hostCompany)) {
            return $this->authenticateOnCentralHost($data, $hostCompany);
        }

        if ($this->mode === 'code' && $this->codePhone === null) {
            $this->sendCode((string) $data['phone']);

            return null;
        }

        if ($this->mode === 'code') {
            $account = null;
            $valid = $this->inCodeCompany(function () use ($accounts, $data, &$account): bool {
                $account = $accounts->findByPhone((string) $this->codePhone);

                return $account && $this->isUserAllowedToAccessPanel($account) && $accounts->verifyCode($account, (string) $data['code']);
            });

            if (! $valid) {
                throw ValidationException::withMessages(['data.code' => __('That code is invalid or has expired. Please request a new one.')]);
            }
        } else {
            try {
                $account = $accounts->attemptPasswordLogin((string) $data['phone'], (string) $data['password']);
            } catch (LoginThrottled $exception) {
                Notification::make()->title($exception->getMessage())->danger()->send();

                return null;
            }

            if (! $account || ! $this->isUserAllowedToAccessPanel($account)) {
                throw ValidationException::withMessages(['data.phone' => __('filament-panels::auth/pages/login.messages.failed')]);
            }
        }

        Filament::auth()->login($account, (bool) ($data['remember'] ?? false));
        session()->regenerate();
        $accounts->recordLogin($account, request()->ip(), $this->mode);
        app(LoginGate::class)->rememberDevice(LoginGate::SURFACE_INVESTOR, (int) $account->company_id, $account);

        return app(LoginResponse::class);
    }

    /**
     * The central sign-in for every business (17_IMS_IN_PORTAL_PLAN.md §5):
     * phone + password only. It opens the first business the password belongs
     * to; the top bar then switches between the person's businesses. On an
     * IMS domain $prefer (that domain's business) is tried first.
     *
     * @param  array<string, mixed>  $data
     */
    protected function authenticateOnCentralHost(array $data, ?Company $prefer = null): ?LoginResponse
    {
        $switches = app(InvestorBusinessSwitchService::class);

        try {
            $match = $switches->attemptCentralLogin((string) $data['phone'], (string) ($data['password'] ?? ''), $prefer);
        } catch (LoginThrottled $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return null;
        }

        if ($match === null) {
            throw ValidationException::withMessages(['data.phone' => __('filament-panels::auth/pages/login.messages.failed')]);
        }

        $switches->openOnCentralHost($match['account'], $match['company'], request(), 'central_password', (bool) ($data['remember'] ?? false));

        return app(LoginResponse::class);
    }

    protected function onCentralHost(): bool
    {
        return app(InvestorBusinessSwitchService::class)->onCentralHost();
    }

    protected function sendCode(string $phone): void
    {
        $accounts = app(InvestorPortalAccountService::class);

        if (! $accounts->smsAvailable(app(CompanyContext::class)->company())) {
            $this->mode = 'password';
            Notification::make()->title(__('Sign-in by SMS code is not available right now. Please use your password.'))->danger()->send();

            return;
        }

        // On the shared IMS domain the account may be in another company that runs here (18_IMS_ALL_COMPANIES_PLAN.md §2).
        $account = app(InvestorBusinessSwitchService::class)->locateOnImsDomain(fn () => $accounts->findByPhone($phone));
        $open = app(CompanyContext::class)->id();
        $this->codeCompanyId = $account && (int) $account->company_id !== (int) $open ? (int) $account->company_id : null;

        if ($account) {
            $accounts->sendCode($account, 'login');
        }

        // Same reply whether or not the phone matched an account.
        $this->codePhone = $phone;

        Notification::make()
            ->title(__('If an investor portal account uses that phone number, a sign-in code has been sent by SMS.'))
            ->success()
            ->send();
    }

    /** Runs $callback in the company of the account the sign-in code went to. */
    protected function inCodeCompany(callable $callback): mixed
    {
        $company = $this->codeCompanyId === null ? null : Company::query()->whereKey($this->codeCompanyId)->where('is_active', true)->first();

        return $company === null ? $callback() : app(CompanyContext::class)->within($company, $callback);
    }

    public function useCode(): void
    {
        $this->mode = 'code';
        $this->codePhone = null;
        $this->codeCompanyId = null;
        $this->resetErrorBag();
    }

    public function usePassword(): void
    {
        $this->mode = 'password';
        $this->codePhone = null;
        $this->codeCompanyId = null;
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
        $smsAvailable = ! $this->onCentralHost() && app(InvestorPortalAccountService::class)->smsAvailable(app(CompanyContext::class)->company());

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
