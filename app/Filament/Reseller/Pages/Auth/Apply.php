<?php

namespace App\Filament\Reseller\Pages\Auth;

use App\Models\Company;
use App\Models\Customer;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\CustomerAccountService;
use App\Support\StorefrontApi\Phone;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Events\Registered;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * "Become a reseller". A phone that already belongs to a customer is reused
 * rather than duplicated, so someone with past storefront orders isn't asked
 * to start over, and re-applying never downgrades an already-approved
 * reseller. The form also sets a password, so approval alone is enough to
 * log into the portal (owner's answer, §2.1 Q4).
 *
 * Because the application takes over that customer record and sets its
 * password, the applicant first proves the number is theirs with a 6-digit
 * SMS code (owner decision 2026-10-12). A brand-new number needs no code.
 * Without an SMS gateway an existing number can't be proven, so the form
 * says to contact the store instead of taking the record over.
 */
class Apply extends BaseRegister
{
    protected const PENDING_SESSION_KEY = 'reseller-apply-pending';

    /** The phone a code was sent to; null while the details step is shown. */
    public ?string $verifyingPhone = null;

    public function getTitle(): string
    {
        return __('Become a reseller');
    }

    public function getHeading(): string
    {
        return __('Become a reseller');
    }

    public function form(Schema $schema): Schema
    {
        // Both steps are in one schema and switched by visibility, so the
        // form follows $verifyingPhone on the very next render.
        $detailsStep = fn (): bool => $this->verifyingPhone === null;
        $codeStep = fn (): bool => $this->verifyingPhone !== null;

        return $schema
            ->components([
                $this->getNameFormComponent()->visible($detailsStep),
                $this->getPhoneFormComponent()->visible($detailsStep),
                $this->getBusinessNameFormComponent()->visible($detailsStep),
                $this->getNoteFormComponent()->visible($detailsStep),
                $this->getPasswordFormComponent()->visible($detailsStep),
                $this->getPasswordConfirmationFormComponent()->visible($detailsStep),
                TextInput::make('code')
                    ->label(__('Verification code'))
                    ->helperText(fn (): string => __('This number already belongs to a customer of the store, so we sent a 6-digit code by SMS to :phone. It expires in :minutes minutes.', ['phone' => (string) $this->verifyingPhone, 'minutes' => CustomerAccountService::REGISTRATION_CODE_MINUTES]))
                    ->required()
                    ->rule('digits:6')
                    ->inputMode('numeric')
                    ->autofocus()
                    ->visible($codeStep),
            ]);
    }

    protected function getPhoneFormComponent(): Component
    {
        return TextInput::make('phone')
            ->label(__('Phone'))
            ->required()
            ->maxLength(40);
    }

    protected function getBusinessNameFormComponent(): Component
    {
        return TextInput::make('business_name')
            ->label(__('Business name'))
            ->required()
            ->maxLength(255);
    }

    protected function getNoteFormComponent(): Component
    {
        return Textarea::make('note')
            ->label(__('Note (optional)'))
            ->maxLength(1000);
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('Password'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->rule(Password::default())
            ->same('passwordConfirmation')
            ->validationAttribute(__('password'));
    }

    public function register(): ?RegistrationResponse
    {
        try {
            $this->rateLimit($this->verifyingPhone === null ? 3 : 10);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        if ($this->verifyingPhone !== null) {
            return $this->verifyCodeAndApply();
        }

        $data = $this->form->getState();
        $data['phone'] = Phone::normalize($data['phone']);
        [$company, $setting] = $this->company();
        $accounts = app(CustomerAccountService::class);

        switch ($accounts->applicationVerification($setting, $data['phone'])) {
            case CustomerAccountService::VERIFY_UNAVAILABLE:
                throw ValidationException::withMessages([
                    'data.phone' => __('This phone number already belongs to a customer of the store, and it can\'t be verified by SMS right now. Please contact the store to become a reseller.'),
                ]);

            case CustomerAccountService::VERIFY_SMS:
                if (! $accounts->sendRegistrationCode($company, $setting, $data['phone'])) {
                    throw ValidationException::withMessages([
                        'data.phone' => __('We couldn\'t send a verification code to this number. Please try again later.'),
                    ]);
                }

                // Only the hash of the password waits in the session.
                session()->put(self::PENDING_SESSION_KEY, [
                    ...Arr::except($data, ['password', 'passwordConfirmation']),
                    'password_hash' => Hash::make($data['password']),
                ]);
                $this->verifyingPhone = $data['phone'];
                $this->form->fill();

                Notification::make()->title(__('We sent a 6-digit code to your phone. Enter it to finish applying.'))->success()->send();

                return null;
        }

        return $this->completeApplication($data);
    }

    protected function verifyCodeAndApply(): ?RegistrationResponse
    {
        $pending = session()->get(self::PENDING_SESSION_KEY);

        if (! is_array($pending)) {
            $this->startOver();

            return null;
        }

        $data = $this->form->getState();
        [$company] = $this->company();

        if (! app(CustomerAccountService::class)->verifyRegistrationCode($company, $pending['phone'], (string) $data['code'])) {
            throw ValidationException::withMessages([
                'data.code' => __('That code is invalid or has expired. Request a new code and try again.'),
            ]);
        }

        session()->forget(self::PENDING_SESSION_KEY);

        return $this->completeApplication($pending);
    }

    /** What Filament does after a sign-up: create, announce, sign in. */
    protected function completeApplication(#[SensitiveParameter] array $data): RegistrationResponse
    {
        $customer = $this->wrapInDatabaseTransaction(fn (): Model => $this->handleRegistration($data));

        event(new Registered($customer));

        $this->sendEmailVerificationNotification($customer);

        Filament::auth()->login($customer);

        session()->regenerate();

        return app(RegistrationResponse::class);
    }

    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        $customer = app(CustomerAccountService::class)->findByPhone($data['phone']) ?? new Customer(['phone' => $data['phone']]);
        $existing = $customer->exists;

        $customer->fill([
            'name' => $data['name'],
            'business_name' => $data['business_name'],
            'reseller_note' => $data['note'] ?? null,
            'reseller_status' => $customer->reseller_status === 'approved' ? 'approved' : 'pending',
            'customer_type' => $customer->customer_type ?: 'reseller',
            'customer_source' => $customer->customer_source ?: 'website',
            'opening_balance' => $customer->opening_balance ?? 0,
            'is_active' => true,
        ]);
        $customer->password = $data['password_hash'] ?? Hash::make($data['password']);

        if ($existing) {
            // A new password signs the other devices out (see CustomerAccountService::resetPassword()).
            $customer->remember_token = Str::random(60);
        }

        $customer->save();

        return $customer;
    }

    /** Sends the code again (at most once a minute, see sendRegistrationCode()). */
    public function resendCode(): void
    {
        $pending = session()->get(self::PENDING_SESSION_KEY);

        if (! is_array($pending)) {
            $this->startOver();

            return;
        }

        [$company, $setting] = $this->company();
        $sent = app(CustomerAccountService::class)->sendRegistrationCode($company, $setting, $pending['phone']);

        Notification::make()
            ->title($sent ? __('We sent a 6-digit code to your phone. Enter it to finish applying.') : __('We couldn\'t send a verification code to this number. Please try again later.'))
            ->color($sent ? 'success' : 'danger')
            ->send();
    }

    /** Back to the details step, e.g. to correct the phone number. */
    public function startOver(): void
    {
        session()->forget(self::PENDING_SESSION_KEY);
        $this->verifyingPhone = null;
        $this->form->fill();
    }

    public function getRegisterFormAction(): Action
    {
        return parent::getRegisterFormAction()
            ->label($this->verifyingPhone === null ? __('Sign up') : __('Verify and apply'));
    }

    protected function getFormActions(): array
    {
        if ($this->verifyingPhone === null) {
            return [$this->getRegisterFormAction()];
        }

        return [
            $this->getRegisterFormAction(),
            Action::make('resendCode')->label(__('Send a new code'))->link()->action('resendCode'),
            Action::make('startOver')->label(__('Use a different number'))->link()->action('startOver'),
        ];
    }

    /**
     * The company the portal host resolved to and its storefront settings (a
     * blank one when it has none yet, which simply has no SMS gateway).
     *
     * @return array{0: Company, 1: StorefrontSetting}
     */
    protected function company(): array
    {
        $company = app(CompanyContext::class)->company();

        abort_unless($company instanceof Company, 404);

        return [$company, $company->storefrontSetting ?? new StorefrontSetting];
    }
}
