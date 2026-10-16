<?php

namespace App\Filament\Reseller\Pages\Auth;

use App\Models\Customer;
use App\Services\CustomerAccountService;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use SensitiveParameter;

/**
 * The page behind the link staff email to a reseller (Resellers → Email a
 * password reset link). The link is signed and carries a one-time token that
 * CustomerAccountService checks; the reseller only chooses a new password.
 * Filament's e-mail broker is not used, as for the SMS reset on ForgotPassword.
 */
class ResetPasswordByLink extends ResetPassword
{
    #[Locked]
    public ?int $customerId = null;

    public function getTitle(): string|Htmlable
    {
        return __('Choose a new password');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Choose a new password');
    }

    public function mount(?string $email = null, #[SensitiveParameter] ?string $token = null): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());
        }

        $this->token = $token ?? (string) request()->query('token');
        $this->customerId = (int) request()->query('customer') ?: null;

        $customer = $this->customer();

        if (! $customer || ! app(CustomerAccountService::class)->resetCodeIsValid($customer, (string) $this->token)) {
            Notification::make()->title($this->invalidLinkMessage())->danger()->send();

            $this->redirect(Filament::getRequestPasswordResetUrl(), navigate: false);

            return;
        }

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('password')
                ->label(__('New password'))
                ->password()
                ->autocomplete('new-password')
                ->revealable(filament()->arePasswordsRevealable())
                ->required()
                ->autofocus()
                ->rule(Password::default())
                ->same('passwordConfirmation'),
            TextInput::make('passwordConfirmation')
                ->label(__('Confirm new password'))
                ->password()
                ->autocomplete('new-password')
                ->revealable(filament()->arePasswordsRevealable())
                ->required()
                ->dehydrated(false),
        ]);
    }

    public function resetPassword(): ?PasswordResetResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();
        $accounts = app(CustomerAccountService::class);
        $customer = $this->customer();

        $reset = $customer instanceof Customer
            && $customer->canAccessPanel(Filament::getCurrentOrDefaultPanel())
            && $accounts->resetPassword($customer, (string) $this->token, (string) $data['password']);

        if (! $reset) {
            Notification::make()->title($this->invalidLinkMessage())->danger()->send();

            return null;
        }

        Notification::make()
            ->title(__('Your password was changed. Sign in with the new one.'))
            ->success()
            ->send();

        return app(PasswordResetResponse::class);
    }

    /** Looked up inside the portal's company only (the host decides it), never across companies. */
    protected function customer(): ?Customer
    {
        return $this->customerId ? Customer::query()->find($this->customerId) : null;
    }

    protected function invalidLinkMessage(): string
    {
        return __('This reset link is invalid or has expired. Ask the store to send a new one.');
    }
}
