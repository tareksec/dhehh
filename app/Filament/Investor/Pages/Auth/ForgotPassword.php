<?php

namespace App\Filament\Investor\Pages\Auth;

use App\Models\InvestorPortalAccount;
use App\Models\Company;
use App\Services\CompanyContext;
use App\Services\InvestorBusinessSwitchService;
use App\Services\InvestorPortalAccountService;
use App\Support\ImsPassword;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * "Forgot password?" on the investor portal: a 6-digit code by SMS or by
 * e-mail (IMS-V2/PLAN.md §4ক, owner 2026-10-19), then a new password, on one
 * page. The person picks how to get the code; only the ways the company has
 * set up are offered. Only an account that may enter this company's portal
 * gets a code, and the reply is the same whether or not the phone or e-mail
 * matched. By e-mail the code lives 10 minutes (SMS: 1 minute).
 */
class ForgotPassword extends RequestPasswordReset
{
    public const BY_SMS = 'sms';

    public const BY_EMAIL = 'email';

    /** How the code was sent; null while the first step is shown. */
    public ?string $sentBy = null;

    /** The phone or e-mail the code was sent to; null while the first step is shown. */
    public ?string $sentTo = null;

    /** The company of the account the code went to, when it is not the open one (the shared IMS domain). */
    #[Locked]
    public ?int $accountCompanyId = null;

    public function getTitle(): string|Htmlable
    {
        return __('Reset your password');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Reset your password');
    }

    /** @return array<string, string> the ways this company can send a code */
    public function channels(): array
    {
        $company = app(CompanyContext::class)->company();
        $accounts = app(InvestorPortalAccountService::class);

        return array_filter([
            self::BY_SMS => $accounts->smsAvailable($company) ? __('By SMS') : null,
            self::BY_EMAIL => $accounts->emailAvailable($company) ? __('By e-mail') : null,
        ]);
    }

    public function mount(): void
    {
        parent::mount();

        $this->form->fill(['channel' => array_key_first($this->channels()) ?? self::BY_SMS]);
    }

    public function form(Schema $schema): Schema
    {
        $firstStep = fn (): bool => $this->sentTo === null;
        $codeStep = fn (): bool => $this->sentTo !== null;
        $by = fn (string $channel): \Closure => fn (Get $get): bool => $this->sentTo === null && ($get('channel') ?? self::BY_SMS) === $channel;

        return $schema->components([
            ToggleButtons::make('channel')
                ->label(__('How should we send the code?'))
                ->options(fn (): array => $this->channels())
                ->inline()
                ->live()
                ->visible(fn (): bool => $this->sentTo === null && count($this->channels()) > 1),
            TextInput::make('phone')
                ->label(__('Phone'))
                ->helperText(__('We will send a reset code by SMS to this number.'))
                ->tel()
                ->required($by(self::BY_SMS))
                ->maxLength(40)
                ->autofocus()
                ->visible($by(self::BY_SMS)),
            TextInput::make('email')
                ->label(__('E-mail'))
                ->helperText(__('The e-mail address the company has on your record. We will send a reset code there.'))
                ->email()
                ->required($by(self::BY_EMAIL))
                ->maxLength(255)
                ->visible($by(self::BY_EMAIL)),
            TextInput::make('code')
                ->label(__('Reset code'))
                ->helperText(fn (): string => $this->sentBy === self::BY_EMAIL
                    ? __('The 6-digit code sent by e-mail to :email. It expires in :minutes minutes.', ['email' => (string) $this->sentTo, 'minutes' => InvestorPortalAccountService::EMAIL_CODE_VALID_MINUTES])
                    : __('The 6-digit code sent by SMS to :phone. It expires in 1 minute.', ['phone' => (string) $this->sentTo]))
                ->required()
                ->maxLength(10)
                ->visible($codeStep),
            TextInput::make('password')
                ->label(__('New password'))
                ->password()
                ->revealable(filament()->arePasswordsRevealable())
                ->required()
                ->rule(ImsPassword::rule())
                ->same('passwordConfirmation')
                ->visible($codeStep),
            TextInput::make('passwordConfirmation')
                ->label(__('Confirm new password'))
                ->password()
                ->revealable(filament()->arePasswordsRevealable())
                ->required()
                ->dehydrated(false)
                ->visible($codeStep),
        ]);
    }

    public function request(): void
    {
        if ($this->sentTo !== null) {
            $this->resetPassword();

            return;
        }

        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $channels = $this->channels();

        if ($channels === []) {
            Notification::make()
                ->title(__('Password reset by SMS or e-mail is not available right now. Please contact the company office to get back into your account.'))
                ->danger()
                ->send();

            return;
        }

        $data = $this->form->getState();
        $channel = array_key_exists($data['channel'] ?? '', $channels) ? $data['channel'] : array_key_first($channels);
        $accounts = app(InvestorPortalAccountService::class);

        if ($channel === self::BY_EMAIL) {
            $contact = mb_strtolower(trim((string) ($data['email'] ?? '')));
            $account = $this->locate(fn () => $accounts->findByEmail($contact));

            if ($account) {
                $accounts->sendResetCodeByEmail($account);
            }

            $message = __('If an investor portal account uses that e-mail address, a reset code has been sent by e-mail.');
        } else {
            $contact = trim((string) ($data['phone'] ?? ''));
            $account = $this->locate(fn () => $accounts->findByPhone($contact));

            if ($account) {
                $accounts->sendCode($account, 'reset');
            }

            $message = __('If an investor portal account uses that phone number, a reset code has been sent by SMS.');
        }

        // Same reply whether or not the phone or e-mail matched an account.
        $this->sentBy = $channel;
        $this->sentTo = $contact;
        $this->form->fill();

        Notification::make()->title($message)->success()->send();
    }

    protected function resetPassword(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();
        $accounts = app(InvestorPortalAccountService::class);
        $account = null;

        $reset = $this->inAccountCompany(function () use ($accounts, $data, &$account): bool {
            $account = $this->sentBy === self::BY_EMAIL
                ? $accounts->findByEmail((string) $this->sentTo)
                : $accounts->findByPhone((string) $this->sentTo);

            return $account instanceof InvestorPortalAccount
                && $account->canAccessPanel(Filament::getCurrentOrDefaultPanel())
                && $accounts->resetPassword($account, (string) $data['code'], (string) $data['password']);
        });

        if (! $reset) {
            throw ValidationException::withMessages([
                'data.code' => __('That code is invalid or has expired. Please request a new one.'),
            ]);
        }

        Filament::auth()->login($account);
        session()->regenerate();
        $accounts->recordLogin($account, request()->ip(), 'reset');

        Notification::make()->title(__('Your password has been reset.'))->success()->send();

        $this->redirect(Filament::getUrl());
    }

    /**
     * The account the code goes to: in the open company, or on the shared IMS
     * domain in another company that runs there (18_IMS_ALL_COMPANIES_PLAN.md §2),
     * which is then remembered for the second step.
     */
    protected function locate(callable $finder): ?InvestorPortalAccount
    {
        $account = app(InvestorBusinessSwitchService::class)->locateOnImsDomain($finder);
        $open = app(CompanyContext::class)->id();
        $this->accountCompanyId = $account && (int) $account->company_id !== (int) $open ? (int) $account->company_id : null;

        return $account;
    }

    /** Runs $callback in the company of the account the code went to. */
    protected function inAccountCompany(callable $callback): mixed
    {
        $company = $this->accountCompanyId === null ? null : Company::query()->whereKey($this->accountCompanyId)->where('is_active', true)->first();

        return $company === null ? $callback() : app(CompanyContext::class)->within($company, $callback);
    }

    /** Back to the first step, e.g. to ask for a new code or use the other way. */
    public function startOver(): void
    {
        $this->sentBy = null;
        $this->sentTo = null;
        $this->accountCompanyId = null;
        $this->form->fill(['channel' => array_key_first($this->channels()) ?? self::BY_SMS]);
    }

    protected function getRequestFormAction(): Action
    {
        return Action::make('request')
            ->label($this->sentTo === null ? __('Send reset code') : __('Reset password'))
            ->submit('request');
    }

    protected function getFormActions(): array
    {
        if ($this->sentTo === null) {
            return [$this->getRequestFormAction()];
        }

        return [
            $this->getRequestFormAction(),
            Action::make('startOver')
                ->label(__('Send a new code'))
                ->link()
                ->action('startOver'),
        ];
    }
}
