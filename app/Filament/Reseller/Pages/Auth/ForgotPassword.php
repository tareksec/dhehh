<?php

namespace App\Filament\Reseller\Pages\Auth;

use App\Models\Customer;
use App\Models\StorefrontCustomerActivity;
use App\Services\CompanyContext;
use App\Services\CustomerAccountService;
use App\Services\StorefrontCustomerActivityService;
use App\Services\StorefrontNotificationService;
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
 * "Forgot password?" on the portal sign-in page. Resellers sign in with their
 * phone, so this works like the storefront's own reset (same
 * CustomerAccountService): a 6-digit code by SMS, valid for 3 minutes (CustomerAccountService::RESET_CODE_MINUTES), then
 * a new password. Filament's default e-mail link flow is not used.
 *
 * Only an account that may enter the portal (pending or approved reseller of
 * this company) gets a code, and the reply is the same whether or not the
 * phone matched, so the form cannot be used to find out who is a reseller.
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
        // Both steps are in one schema and switched by visibility, so the
        // form follows $phone on the very next render.
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
                ->helperText(fn (): string => __('The 6-digit code sent by SMS to :phone. It expires in :minutes minutes.', ['phone' => (string) $this->phone, 'minutes' => CustomerAccountService::RESET_CODE_MINUTES]))
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

        $phone = trim((string) $this->form->getState()['phone']);
        $company = app(CompanyContext::class)->company();
        $setting = $company?->storefrontSetting;

        if (! $company || ! $setting || ! app(StorefrontNotificationService::class)->smsConfigured($setting)) {
            Notification::make()
                ->title(__('Password reset by SMS is not available right now. Please contact the store to get back into your account.'))
                ->danger()
                ->send();

            return;
        }

        $customer = app(CustomerAccountService::class)->findRegisteredByPhone($phone);

        if ($customer && $customer->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
            app(CustomerAccountService::class)->sendPasswordResetCode($company, $setting, $customer);
        }

        // Same reply whether or not the phone matched an account.
        $this->phone = $phone;
        $this->form->fill();

        Notification::make()
            ->title(__('If a reseller account uses that phone number, a reset code has been sent by SMS.'))
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
        $accounts = app(CustomerAccountService::class);
        $panel = Filament::getCurrentOrDefaultPanel();
        $customer = $accounts->findRegisteredByPhone((string) $this->phone);

        $reset = $customer instanceof Customer
            && $customer->canAccessPanel($panel)
            && $accounts->resetPassword($customer, (string) $data['code'], (string) $data['password']);

        if (! $reset) {
            throw ValidationException::withMessages([
                'data.code' => __('That code is invalid or has expired. Please request a new one.'),
            ]);
        }

        app(StorefrontCustomerActivityService::class)->record(
            $customer,
            StorefrontCustomerActivity::TYPE_PASSWORD_RESET,
            'Password reset',
            'Your account password was reset from the reseller portal.',
        );

        Filament::auth()->login($customer);
        session()->regenerate();

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
