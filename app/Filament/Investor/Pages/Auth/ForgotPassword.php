<?php

namespace App\Filament\Investor\Pages\Auth;

use App\Models\InvestorPortalAccount;
use App\Services\CompanyContext;
use App\Services\InvestorPortalAccountService;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * "Forgot password?" on the investor portal: a 6-digit code by SMS, then a
 * new password, on one page (the reseller portal's flow). Only an account
 * that may enter this company's portal gets a code, and the reply is the same
 * whether or not the phone matched.
 */
class ForgotPassword extends RequestPasswordReset
{
    /** The phone the code was sent to; null while the phone step is shown. */
    public ?string $phone = null;

    public function getTitle(): string|Htmlable
    {
        return __('Reset your password');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Reset your password');
    }

    public function form(Schema $schema): Schema
    {
        $phoneStep = fn (): bool => $this->phone === null;
        $codeStep = fn (): bool => $this->phone !== null;

        return $schema->components([
            TextInput::make('phone')
                ->label(__('Phone'))
                ->helperText(__('We will send a reset code by SMS to this number.'))
                ->tel()
                ->required()
                ->maxLength(40)
                ->autofocus()
                ->visible($phoneStep),
            TextInput::make('code')
                ->label(__('Reset code'))
                ->helperText(fn (): string => __('The 6-digit code sent by SMS to :phone. It expires in 10 minutes.', ['phone' => (string) $this->phone]))
                ->required()
                ->maxLength(10)
                ->visible($codeStep),
            TextInput::make('password')
                ->label(__('New password'))
                ->password()
                ->revealable(filament()->arePasswordsRevealable())
                ->required()
                ->rule(Password::default())
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
        if ($this->phone !== null) {
            $this->resetPassword();

            return;
        }

        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $accounts = app(InvestorPortalAccountService::class);
        $phone = trim((string) $this->form->getState()['phone']);

        if (! $accounts->smsAvailable(app(CompanyContext::class)->company())) {
            Notification::make()
                ->title(__('Password reset by SMS is not available right now. Please contact the company office to get back into your account.'))
                ->danger()
                ->send();

            return;
        }

        $account = $accounts->findByPhone($phone);

        if ($account && $account->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
            $accounts->sendCode($account, 'reset');
        }

        // Same reply whether or not the phone matched an account.
        $this->phone = $phone;
        $this->form->fill();

        Notification::make()
            ->title(__('If an investor portal account uses that phone number, a reset code has been sent by SMS.'))
            ->success()
            ->send();
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
        $account = $accounts->findByPhone((string) $this->phone);

        $reset = $account instanceof InvestorPortalAccount
            && $account->canAccessPanel(Filament::getCurrentOrDefaultPanel())
            && $accounts->resetPassword($account, (string) $data['code'], (string) $data['password']);

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

    /** Back to the phone step, e.g. to ask for a new code. */
    public function startOver(): void
    {
        $this->phone = null;
        $this->form->fill();
    }

    protected function getRequestFormAction(): Action
    {
        return Action::make('request')
            ->label($this->phone === null ? __('Send reset code') : __('Reset password'))
            ->submit('request');
    }

    protected function getFormActions(): array
    {
        if ($this->phone === null) {
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
