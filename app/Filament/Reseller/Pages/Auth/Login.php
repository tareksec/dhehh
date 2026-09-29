<?php

namespace App\Filament\Reseller\Pages\Auth;

use App\Models\Customer;
use App\Services\CustomerAccountService;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Resellers log in the same way they do on the storefront (phone or email +
 * password, same CustomerAccountService), so the portal never has a second
 * set of login rules to keep in sync. This is why the whole authenticate()
 * flow is reimplemented rather than mapped onto Filament's default
 * email-only EloquentUserProvider lookup (getCredentialsFromFormData() alone
 * cannot express "match by phone OR email").
 */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('Phone or email'))
            ->required()
            ->autocomplete()
            ->autofocus();
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

        $customer = app(CustomerAccountService::class)->attemptLogin($data['email'], $data['password']);

        if (! $customer || ! $this->isUserAllowedToAccessPanel($customer)) {
            throw ValidationException::withMessages([
                'data.email' => __('filament-panels::auth/pages/login.messages.failed'),
            ]);
        }

        Filament::auth()->login($customer, $data['remember'] ?? false);

        session()->regenerate();

        return app(LoginResponse::class);
    }

    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        return $user instanceof Customer && $user->canAccessPanel(Filament::getCurrentOrDefaultPanel());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        // Never used -- authenticate() is fully reimplemented above -- kept
        // only because the parent class declares it.
        return ['email' => $data['email'], 'password' => $data['password']];
    }
}
