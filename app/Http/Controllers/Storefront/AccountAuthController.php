<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\StorefrontCustomerActivity;
use App\Services\CompanyContext;
use App\Services\CustomerAccountService;
use App\Services\LoginGate;
use App\Services\StorefrontCustomerActivityService;
use App\Services\StorefrontMetaTrackingService;
use App\Services\StorefrontNotificationService;
use App\Support\LoginThrottled;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountAuthController extends Controller
{
    public function __construct(
        protected CompanyContext $context,
        protected CustomerAccountService $accounts,
        protected StorefrontNotificationService $notifications,
        protected StorefrontCustomerActivityService $activities,
    ) {}

    public function showLoginPreview(Request $request, Company $company): View|RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->showLogin($request);
    }

    public function loginPreview(Request $request, Company $company): RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->login($request);
    }

    public function showOtpPreview(Request $request, Company $company): View|RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->showOtp($request);
    }

    public function sendOtpPreview(Request $request, Company $company): RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->sendOtp($request);
    }

    public function showOtpVerifyPreview(Request $request, Company $company): View|RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->showOtpVerify($request);
    }

    public function verifyOtpPreview(Request $request, Company $company): RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->verifyOtp($request);
    }

    public function showRegisterPreview(Request $request, Company $company): View|RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->showRegister($request);
    }

    public function registerPreview(Request $request, Company $company): RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->register($request);
    }

    public function showRegisterVerifyPreview(Request $request, Company $company): View|RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->showRegisterVerify($request);
    }

    public function verifyRegisterPreview(Request $request, Company $company): RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->verifyRegister($request);
    }

    public function resendRegisterCodePreview(Request $request, Company $company): RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->resendRegisterCode($request);
    }

    public function showForgotPasswordPreview(Request $request, Company $company): View
    {
        $this->preparePreview($request, $company);

        return $this->showForgotPassword($request);
    }

    public function forgotPasswordPreview(Request $request, Company $company): RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->forgotPassword($request);
    }

    public function showResetPasswordPreview(Request $request, Company $company): View
    {
        $this->preparePreview($request, $company);

        return $this->showResetPassword($request);
    }

    public function resetPasswordPreview(Request $request, Company $company): RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->resetPassword($request);
    }

    public function logoutPreview(Request $request, Company $company): RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->logout($request);
    }

    public function showLogin(Request $request): View|RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);
        abort_if(! $setting->customer_accounts_enabled, 404);

        if (Auth::guard('customer')->check()) {
            return redirect()->to($this->accountRoute($request, 'profile'));
        }

        return StorefrontThemeRegistry::page('account.login', [
            'company' => $company,
            'setting' => $setting,
            'activeTab' => 'login',
            'previewSlug' => $this->previewSlug($request),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        [, $setting] = $this->domainStorefront($request);
        abort_if(! $setting->customer_accounts_enabled, 404);

        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        try {
            $customer = $this->accounts->attemptLogin($data['identifier'], $data['password']);
        } catch (LoginThrottled $exception) {
            // An unknown device while the account or the site is under attack:
            // the login form shows the CAPTCHA on the page it is sent back to.
            if ($exception->captcha) {
                session()->flash('captcha_required', true);
            }

            throw $exception;
        }

        if (! $customer) {
            throw ValidationException::withMessages([
                'identifier' => "Those details don't match an account. Check your phone/email and password.",
            ]);
        }

        Auth::guard('customer')->login($customer, $request->boolean('remember'));
        $request->session()->regenerate();

        // This browser is now a device the account has signed in from.
        app(LoginGate::class)->rememberDevice(LoginGate::SURFACE_CUSTOMER, app(CompanyContext::class)->id(), $customer);

        $this->activities->record(
            $customer,
            StorefrontCustomerActivity::TYPE_LOGIN,
            'Signed in to your account',
            'A successful sign-in was recorded.',
        );

        return redirect()->to($this->accountRoute($request, 'profile'))->with('storefront_status', "Welcome back, {$customer->name}.");
    }

    public function showOtp(Request $request): View|RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);
        abort_if(! $setting->customer_accounts_enabled, 404);

        if (Auth::guard('customer')->check()) {
            return redirect()->to($this->accountRoute($request, 'profile'));
        }

        return StorefrontThemeRegistry::page('account.otp-request', [
            'company' => $company,
            'setting' => $setting,
            'smsAvailable' => $this->notifications->smsConfigured($setting),
            'previewSlug' => $this->previewSlug($request),
        ]);
    }

    public function sendOtp(Request $request): RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);
        abort_if(! $setting->customer_accounts_enabled, 404);

        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'remember' => ['nullable', 'boolean'],
        ]);
        $identifier = trim($data['identifier']);
        $isEmail = str_contains($identifier, '@');

        if ($isEmail && ! filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['identifier' => 'Enter a valid email address.']);
        }

        if (! $isEmail && strlen(preg_replace('/\D+/', '', $identifier) ?: '') < 10) {
            throw ValidationException::withMessages(['identifier' => 'Enter a valid phone number.']);
        }

        if (! $isEmail && ! $this->notifications->smsConfigured($setting)) {
            throw ValidationException::withMessages([
                'identifier' => 'Phone OTP is not available right now. Use your email address or password to log in.',
            ]);
        }

        $customer = $this->accounts->findRegisteredByIdentifier($identifier);

        if ($customer) {
            $this->accounts->sendLoginOtp($company, $setting, $customer, $identifier);
        }

        $request->session()->put($this->otpSessionKey($company), $identifier);
        $request->session()->put($this->otpRememberSessionKey($company), $request->boolean('remember'));

        return redirect()
            ->to($this->accountRoute($request, 'otp.verify'))
            ->with('storefront_status', 'If a matching account exists, a 6-digit login code has been sent.');
    }

    public function showOtpVerify(Request $request): View|RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);
        abort_if(! $setting->customer_accounts_enabled, 404);

        $identifier = $request->session()->get($this->otpSessionKey($company));

        if (! is_string($identifier) || $identifier === '') {
            return redirect()->to($this->accountRoute($request, 'otp'))
                ->with('storefront_status', 'Enter your phone number or email to request a login code.');
        }

        return StorefrontThemeRegistry::page('account.otp-verify', [
            'company' => $company,
            'setting' => $setting,
            'maskedIdentifier' => $this->maskIdentifier($identifier),
            'previewSlug' => $this->previewSlug($request),
        ]);
    }

    public function verifyOtp(Request $request): RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);
        abort_if(! $setting->customer_accounts_enabled, 404);

        $data = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);
        $identifier = $request->session()->get($this->otpSessionKey($company));
        $customer = is_string($identifier) ? $this->accounts->findRegisteredByIdentifier($identifier) : null;

        if (! $customer || ! $this->accounts->verifyLoginOtp($customer, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => 'That code is invalid or has expired. Request a new code and try again.',
            ]);
        }

        $remember = (bool) $request->session()->pull($this->otpRememberSessionKey($company), false);
        $request->session()->forget($this->otpSessionKey($company));
        Auth::guard('customer')->login($customer, $remember);
        $request->session()->regenerate();

        $this->activities->record(
            $customer,
            StorefrontCustomerActivity::TYPE_LOGIN,
            'Signed in with OTP',
            'A successful one-time-code sign-in was recorded.',
            ['channel' => str_contains((string) $identifier, '@') ? 'email' : 'sms'],
        );

        return redirect()->to($this->accountRoute($request, 'profile'))->with('storefront_status', "Welcome back, {$customer->name}.");
    }

    public function showRegister(Request $request): View|RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);
        abort_if(! $setting->customer_accounts_enabled, 404);

        if (Auth::guard('customer')->check()) {
            return redirect()->to($this->accountRoute($request, 'profile'));
        }

        return StorefrontThemeRegistry::page('account.login', [
            'company' => $company,
            'setting' => $setting,
            'activeTab' => 'register',
            'previewSlug' => $this->previewSlug($request),
        ]);
    }

    /**
     * Sign-up. When the store can send SMS the phone is proven with a
     * 6-digit code first (CustomerAccountService::registrationVerification()),
     * because the new account takes over any existing customer record — and
     * its order history — of that number.
     */
    public function register(Request $request): RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);
        abort_if(! $setting->customer_accounts_enabled, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $this->accounts->ensureCanRegister($data);

        switch ($this->accounts->registrationVerification($setting, $data['phone'])) {
            case CustomerAccountService::VERIFY_UNAVAILABLE:
                throw ValidationException::withMessages([
                    'phone' => __('This phone number already has orders with us, and it can\'t be verified by SMS right now. Please contact us to set up your account.'),
                ]);

            case CustomerAccountService::VERIFY_SMS:
                if (! $this->accounts->sendRegistrationCode($company, $setting, $data['phone'])) {
                    throw ValidationException::withMessages([
                        'phone' => __('We couldn\'t send a verification code to this number. Please try again later.'),
                    ]);
                }

                // Only the hash of the password waits in the session.
                $request->session()->put($this->registrationSessionKey($company), [
                    ...Arr::except($data, ['password', 'password_confirmation']),
                    'password_hash' => Hash::make($data['password']),
                ]);

                return redirect()
                    ->to($this->accountRoute($request, 'register.verify'))
                    ->with('storefront_status', __('We sent a 6-digit code to your phone. Enter it to create your account.'));
        }

        return $this->completeRegistration($request, $this->accounts->register($data));
    }

    public function showRegisterVerify(Request $request): View|RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);
        abort_if(! $setting->customer_accounts_enabled, 404);

        $pending = $request->session()->get($this->registrationSessionKey($company));

        if (! is_array($pending)) {
            return redirect()->to($this->accountRoute($request, 'register'));
        }

        return StorefrontThemeRegistry::page('account.register-verify', [
            'company' => $company,
            'setting' => $setting,
            'maskedIdentifier' => $this->maskIdentifier($pending['phone']),
            'previewSlug' => $this->previewSlug($request),
        ]);
    }

    public function verifyRegister(Request $request): RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);
        abort_if(! $setting->customer_accounts_enabled, 404);

        $data = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);
        $pending = $request->session()->get($this->registrationSessionKey($company));

        if (! is_array($pending)) {
            return redirect()->to($this->accountRoute($request, 'register'));
        }

        if (! $this->accounts->verifyRegistrationCode($company, $pending['phone'], $data['code'])) {
            throw ValidationException::withMessages([
                'code' => __('That code is invalid or has expired. Request a new code and try again.'),
            ]);
        }

        $request->session()->forget($this->registrationSessionKey($company));

        try {
            $customer = $this->accounts->register($pending);
        } catch (ValidationException $exception) {
            // The number or email was taken while the code was on its way.
            return redirect()->to($this->accountRoute($request, 'register'))->withErrors($exception->errors());
        }

        return $this->completeRegistration($request, $customer);
    }

    public function resendRegisterCode(Request $request): RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);
        abort_if(! $setting->customer_accounts_enabled, 404);

        $pending = $request->session()->get($this->registrationSessionKey($company));

        if (! is_array($pending)) {
            return redirect()->to($this->accountRoute($request, 'register'));
        }

        $sent = $this->accounts->sendRegistrationCode($company, $setting, $pending['phone']);

        return redirect()
            ->to($this->accountRoute($request, 'register.verify'))
            ->with('storefront_status', $sent
                ? __('We sent a 6-digit code to your phone. Enter it to create your account.')
                : __('We couldn\'t send a verification code to this number. Please try again later.'));
    }

    protected function completeRegistration(Request $request, Customer $customer): RedirectResponse
    {
        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        $this->activities->record(
            $customer,
            StorefrontCustomerActivity::TYPE_ACCOUNT_CREATED,
            'Account created',
            "Your {$customer->company->name} customer account is ready.",
        );

        $request->session()->flash('storefront_meta_event', [
            'name' => 'CompleteRegistration',
            'event_id' => app(StorefrontMetaTrackingService::class)->eventId('complete-registration'),
            'data' => ['status' => 'registered'],
        ]);

        return redirect()->to($this->accountRoute($request, 'profile'))->with('storefront_status', "Welcome, {$customer->name}! Your account has been created.");
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();

        // Regenerate the CSRF token only - invalidating the whole session
        // would also wipe the guest's cart, which is stored in session.
        $request->session()->regenerateToken();

        $homeUrl = $this->previewSlug($request)
            ? route('storefront.preview.show', $this->previewSlug($request))
            : route('marketing.home');

        return redirect()->to($homeUrl)->with('storefront_status', 'You have been logged out.');
    }

    public function showForgotPassword(Request $request): View
    {
        [$company, $setting] = $this->domainStorefront($request);
        abort_if(! $setting->customer_accounts_enabled, 404);

        return StorefrontThemeRegistry::page('account.forgot-password', [
            'company' => $company,
            'setting' => $setting,
            'smsAvailable' => $this->notifications->smsConfigured($setting),
            'previewSlug' => $this->previewSlug($request),
        ]);
    }

    public function forgotPassword(Request $request): RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);
        abort_if(! $setting->customer_accounts_enabled, 404);

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
        ]);

        if (! $this->notifications->smsConfigured($setting)) {
            return back()->withInput()->with('storefront_status', 'Password reset by SMS isn\'t available right now. Please contact support to regain access to your account.');
        }

        $customer = $this->accounts->findRegisteredByPhone($data['phone']);

        if ($customer) {
            $this->accounts->sendPasswordResetCode($company, $setting, $customer);
        }

        // Same message whether or not a match was found, so a phone number
        // can't be used to probe which numbers have accounts.
        return redirect()
            ->to($this->accountRoute($request, 'reset-password', ['phone' => $data['phone']]))
            ->with('storefront_status', 'If an account exists for that phone number, a reset code has been sent by SMS.');
    }

    public function showResetPassword(Request $request): View
    {
        [$company, $setting] = $this->domainStorefront($request);
        abort_if(! $setting->customer_accounts_enabled, 404);

        return StorefrontThemeRegistry::page('account.reset-password', [
            'company' => $company,
            'setting' => $setting,
            'phone' => (string) $request->query('phone', ''),
            'previewSlug' => $this->previewSlug($request),
        ]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        [, $setting] = $this->domainStorefront($request);
        abort_if(! $setting->customer_accounts_enabled, 404);

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
            'code' => ['required', 'string', 'max:10'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $customer = $this->accounts->findRegisteredByPhone($data['phone']);
        $reset = $customer && $this->accounts->resetPassword($customer, $data['code'], $data['password']);

        if (! $reset) {
            throw ValidationException::withMessages([
                'code' => 'That code is invalid or has expired. Please request a new one.',
            ]);
        }

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        $this->activities->record(
            $customer,
            StorefrontCustomerActivity::TYPE_PASSWORD_RESET,
            'Password reset',
            'Your account password was reset successfully.',
        );

        return redirect()->to($this->accountRoute($request, 'profile'))->with('storefront_status', 'Your password has been reset.');
    }

    protected function preparePreview(Request $request, Company $company): void
    {
        abort_unless($company->storefrontSetting?->is_published, 404);

        $request->attributes->set('storefront_company', $company);
        $request->attributes->set('storefront_preview_slug', $company->slug);
    }

    protected function previewSlug(Request $request): ?string
    {
        return $request->attributes->get('storefront_preview_slug');
    }

    protected function accountRoute(Request $request, string $name, array $parameters = []): string
    {
        if ($previewSlug = $this->previewSlug($request)) {
            return route('storefront.preview.account.'.$name, ['company' => $previewSlug, ...$parameters]);
        }

        return route('storefront.account.'.$name, $parameters);
    }

    protected function registrationSessionKey(Company $company): string
    {
        return 'storefront_pending_registration_'.$company->getKey();
    }

    protected function otpSessionKey(Company $company): string
    {
        return 'storefront_login_otp_identifier_'.$company->getKey();
    }

    protected function otpRememberSessionKey(Company $company): string
    {
        return 'storefront_login_otp_remember_'.$company->getKey();
    }

    protected function maskIdentifier(string $identifier): string
    {
        if (str_contains($identifier, '@')) {
            [$local, $domain] = explode('@', $identifier, 2);

            return mb_substr($local, 0, 1).str_repeat('•', max(3, mb_strlen($local) - 1)).'@'.$domain;
        }

        $digits = preg_replace('/\D+/', '', $identifier) ?: $identifier;

        return str_repeat('•', max(0, mb_strlen($digits) - 4)).mb_substr($digits, -4);
    }

    protected function domainStorefront(Request $request): array
    {
        $company = $request->attributes->get('storefront_company');

        abort_unless($company instanceof Company && $company->storefrontSetting?->is_published, 404);

        $this->context->set($company);

        return [$company, $company->storefrontSetting];
    }
}
