<?php

namespace App\Filament\Reseller\Pages\Auth;

use App\Filament\Concerns\HasLoginCaptcha;
use App\Models\Customer;
use App\Services\CompanyContext;
use App\Services\CustomerAccountService;
use App\Services\LoginGate;
use App\Support\LoginThrottled;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
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
    use HasLoginCaptcha;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getRememberFormComponent(),
            $this->captchaFormComponent(),
        ]);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('Phone or email'))
            ->required()
            ->autocomplete()
            ->autofocus();
    }

    /** The link under the form: "Become a reseller" instead of Filament's "sign up for an account". */
    public function registerAction(): Action
    {
        return parent::registerAction()->label(__('Become a reseller'));
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

        try {
            $customer = app(CustomerAccountService::class)->attemptLogin($data['email'], $data['password'], $this->loginClient(LoginGate::SURFACE_CUSTOMER));
        } catch (LoginThrottled $exception) {
            $this->showLoginChallenge($exception);

            return null;
        }

        if (! $customer || ! $this->isUserAllowedToAccessPanel($customer)) {
            $this->resetCaptcha();

            throw ValidationException::withMessages([
                'data.email' => __('filament-panels::auth/pages/login.messages.failed'),
            ]);
        }

        Filament::auth()->login($customer, $data['remember'] ?? false);

        session()->regenerate();

        app(LoginGate::class)->rememberDevice(LoginGate::SURFACE_CUSTOMER, app(CompanyContext::class)->id(), $customer);

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
