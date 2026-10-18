<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\StorefrontSetting;
use App\Support\LoginClient;
use App\Support\LoginThrottled;
use App\Support\StorefrontApi\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Storefront customer login/registration, scoped to the active company via
 * the existing CompanyContext/CompanyScope (already set by the controller
 * before any of these methods run, same as checkout/cart/track).
 */
class CustomerAccountService
{
    public function __construct(
        protected StorefrontNotificationService $notifications,
        protected SmsTemplateService $templates,
        protected LoginGate $loginGate,
    ) {}

    /** No code needed: SMS is off and the phone has no customer record yet. */
    public const VERIFY_NONE = 'none';

    /** The phone must be proven with a 6-digit SMS code first. */
    public const VERIFY_SMS = 'sms';

    /** The phone belongs to an existing customer but no SMS gateway is set up to prove it. */
    public const VERIFY_UNAVAILABLE = 'unavailable';

    public const REGISTRATION_CODE_MINUTES = 10;

    protected const REGISTRATION_CODE_MAX_ATTEMPTS = 5;

    /**
     * Registers (or upgrades) a Customer. Reuses an existing phone-matched
     * CRM/order record instead of creating a duplicate, so past storefront
     * orders are immediately visible once the account is created. That is
     * why the storefront sign-up forms prove the phone with an SMS code
     * first (registrationVerification()) — otherwise anyone who knows a
     * customer's number could open their order history.
     *
     * $data['password_hash'] (already hashed) may stand in for
     * $data['password'], so a sign-up waiting for its code never keeps the
     * plain password.
     */
    public function register(array $data): Customer
    {
        $customer = $this->ensureCanRegister($data);

        $customer ??= new Customer;

        $customer->fill([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? $customer->email,
            'address' => $data['address'] ?? $customer->address,
            'customer_type' => $customer->customer_type ?: 'regular',
            'customer_source' => $customer->customer_source ?: 'website',
            'is_active' => true,
        ]);
        $customer->password = $data['password_hash'] ?? Hash::make($data['password']);
        $customer->save();

        return $customer;
    }

    /**
     * The phone/email checks of register(), run before a verification code is
     * sent so nobody waits for a code that can't be used.
     *
     * @return Customer|null the unregistered customer record the account will adopt
     *
     * @throws ValidationException
     */
    public function ensureCanRegister(array $data): ?Customer
    {
        $customer = Customer::query()->tap(fn (Builder $query) => $this->wherePhoneMatches($query, $data['phone']))->first();

        if ($customer?->isRegistered()) {
            throw ValidationException::withMessages([
                'phone' => 'An account with this phone number already exists. Please log in instead.',
            ]);
        }

        if (filled($data['email'] ?? null) && $this->emailTaken($data['email'])) {
            throw ValidationException::withMessages([
                'email' => 'An account with this email address already exists. Please log in instead.',
            ]);
        }

        return $customer;
    }

    /**
     * How a storefront sign-up for $phone must prove the phone is the
     * visitor's: always by SMS code when the store has an SMS gateway; with
     * no gateway a brand-new number still signs up directly, but a number
     * that already has a customer record (and so order history) can't.
     */
    public function registrationVerification(StorefrontSetting $setting, string $phone): string
    {
        if ($this->notifications->smsConfigured($setting)) {
            return self::VERIFY_SMS;
        }

        return Customer::query()->tap(fn (Builder $query) => $this->wherePhoneMatches($query, $phone))->exists()
            ? self::VERIFY_UNAVAILABLE
            : self::VERIFY_NONE;
    }

    /**
     * The customer record already using $phone, whatever way the number was
     * typed (+880…, 880…, 01…), in the current company.
     */
    public function findByPhone(string $phone): ?Customer
    {
        return Customer::query()->tap(fn (Builder $query) => $this->wherePhoneMatches($query, $phone))->first();
    }

    /**
     * How a reseller application for $phone must prove the number is the
     * applicant's (owner decision 2026-10-12): a brand-new number needs no
     * code, but a number that already belongs to a customer — whose record
     * the application would reuse, and whose password it would set — must be
     * proven with an SMS code first. With no SMS gateway such a number can't
     * be proven at all (VERIFY_UNAVAILABLE).
     */
    public function applicationVerification(StorefrontSetting $setting, string $phone): string
    {
        if ($this->findByPhone($phone) === null) {
            return self::VERIFY_NONE;
        }

        return $this->notifications->smsConfigured($setting) ? self::VERIFY_SMS : self::VERIFY_UNAVAILABLE;
    }

    /**
     * Texts a 6-digit sign-up code to $phone, valid for 10 minutes and five
     * tries. Asking again within a minute keeps the code already sent, so
     * a double click or the website's "resend" can't flood the number.
     * Kept in the cache, not on a customer row: the number may have no
     * customer record yet.
     */
    public function sendRegistrationCode(Company $company, StorefrontSetting $setting, string $phone): bool
    {
        $key = $this->registrationCodeKey($company, $phone);
        $pending = Cache::get($key);

        if (is_array($pending) && $pending['sent_at'] > now()->subMinute()->getTimestamp() && $pending['expires_at'] > now()->getTimestamp()) {
            return true;
        }

        $code = (string) random_int(100000, 999999);

        Cache::put($key, [
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::REGISTRATION_CODE_MINUTES)->getTimestamp(),
            'sent_at' => now()->getTimestamp(),
            'attempts' => 0,
        ], now()->addMinutes(self::REGISTRATION_CODE_MINUTES));

        $sent = $this->notifications->sendSms(
            $setting,
            $phone,
            (string) $this->templates->render((int) $company->getKey(), SmsTemplateService::OTP_CUSTOMER_SIGNUP, [
                'company' => $company->name,
                'code' => $code,
                'minutes' => self::REGISTRATION_CODE_MINUTES,
            ]),
        );

        if (! $sent) {
            Cache::forget($key);
        }

        return $sent;
    }

    /** One use only; five wrong tries throw the code away. */
    public function verifyRegistrationCode(Company $company, string $phone, string $code): bool
    {
        $key = $this->registrationCodeKey($company, $phone);
        $pending = Cache::get($key);

        if (! is_array($pending) || $pending['expires_at'] <= now()->getTimestamp() || $pending['attempts'] >= self::REGISTRATION_CODE_MAX_ATTEMPTS) {
            Cache::forget($key);

            return false;
        }

        if (! Hash::check($code, $pending['code'])) {
            $pending['attempts']++;

            $pending['attempts'] >= self::REGISTRATION_CODE_MAX_ATTEMPTS
                ? Cache::forget($key)
                : Cache::put($key, $pending, now()->setTimestamp($pending['expires_at']));

            return false;
        }

        Cache::forget($key);

        return true;
    }

    /** One code per company and number, whatever spelling of the number was typed. */
    protected function registrationCodeKey(Company $company, string $phone): string
    {
        return 'storefront-register-code:'.$company->getKey().':'.(preg_replace('/\D+/', '', Phone::normalize($phone)) ?: $phone);
    }

    /**
     * @param  LoginClient|null  $client  who is asking; defaults to the current web request
     *                                    (the Next.js API passes the visitor it forwards)
     * @return Customer|null null when the identifier/password pair doesn't match a registered account.
     *
     * @throws LoginThrottled when LoginGate stops the attempt before the password is checked.
     *                        The gate counts wrong passwords per account and per device — never
     *                        per IP alone — so it also catches a password-guessing attack spread
     *                        over many IPs (the route-level `throttle:10,1` only protects a single
     *                        IP), and the slow password hash is not computed for a request the gate
     *                        refuses. The phone is normalised first, so "+880…", "880…" and "01…"
     *                        count against the same account.
     */
    public function attemptLogin(string $identifier, string $password, ?LoginClient $client = null): ?Customer
    {
        $identifier = Phone::identifier($identifier);
        $client ??= LoginClient::fromRequest(request(), LoginGate::SURFACE_CUSTOMER);

        $query = Customer::query()->whereNotNull('password');

        if (str_contains($identifier, '@')) {
            // Same case-insensitive match the one-time-code login uses.
            $query->whereRaw('LOWER(email) = ?', [mb_strtolower($identifier)]);
        } else {
            $this->wherePhoneMatches($query, $identifier);
        }

        $customer = $query->first();

        $attempt = $this->loginGate->begin(LoginGate::SURFACE_CUSTOMER, app(CompanyContext::class)->id(), $identifier, $customer, $client);

        if ($attempt->isDenied()) {
            $this->loginGate->failed($attempt);

            return null;
        }

        if ($attempt->isBlocked()) {
            throw LoginThrottled::for($attempt);
        }

        if (! $customer) {
            // An identifier with no account still costs one password hash and
            // counts as a failure, so neither the time nor a lockout can be
            // used to tell "wrong password" from "no such account".
            $this->loginGate->spendHashTime($password);
            $this->loginGate->failed($attempt);

            return null;
        }

        if (! Hash::check($password, $customer->password)) {
            $this->loginGate->failed($attempt);

            return null;
        }

        $this->loginGate->succeeded($attempt);

        return $customer;
    }

    /**
     * Looks up a registered account by phone (used by both the forgot- and
     * reset-password steps). Callers show the same generic message whether
     * or not this returns a match, to avoid leaking which phones have accounts.
     */
    public function findRegisteredByPhone(string $phone): ?Customer
    {
        return Customer::query()
            ->whereNotNull('password')
            ->tap(fn (Builder $query) => $this->wherePhoneMatches($query, $phone))
            ->first();
    }

    public function findRegisteredByIdentifier(string $identifier): ?Customer
    {
        $identifier = trim($identifier);
        $query = Customer::query()
            ->whereNotNull('password')
            ->where('is_active', true);

        if (str_contains($identifier, '@')) {
            $query->whereRaw('LOWER(email) = ?', [mb_strtolower($identifier)]);
        } else {
            $this->wherePhoneMatches($query, $identifier);
        }

        return $query->first();
    }

    /**
     * Sends a one-time login code over the channel implied by the identifier.
     * A one-minute resend cooldown prevents accidental or abusive bursts.
     */
    public function sendLoginOtp(Company $company, StorefrontSetting $setting, Customer $customer, string $identifier): bool
    {
        if ($customer->login_otp_sent_at?->isAfter(now()->subMinute()) && $customer->login_otp_expires_at?->isFuture()) {
            return true;
        }

        $channel = str_contains($identifier, '@') ? 'email' : 'sms';
        $code = (string) random_int(100000, 999999);

        $customer->forceFill([
            'login_otp_code' => Hash::make($code),
            'login_otp_channel' => $channel,
            'login_otp_expires_at' => now()->addMinutes(10),
            'login_otp_sent_at' => now(),
            'login_otp_attempts' => 0,
        ])->save();

        $sent = $channel === 'email'
            ? $this->notifications->sendLoginOtpEmail($setting, (string) $customer->email, $company->name, $code)
            : $this->notifications->sendSms(
                $setting,
                $customer->phone,
                (string) $this->templates->render((int) $company->getKey(), SmsTemplateService::OTP_CUSTOMER_LOGIN, [
                    'company' => $company->name,
                    'code' => $code,
                    'minutes' => 10,
                ]),
            );

        if (! $sent) {
            $this->clearLoginOtp($customer);
        }

        return $sent;
    }

    public function verifyLoginOtp(Customer $customer, string $code): bool
    {
        if (
            blank($customer->login_otp_code)
            || ! $customer->login_otp_expires_at
            || $customer->login_otp_expires_at->isPast()
            || (int) $customer->login_otp_attempts >= 5
        ) {
            $this->clearLoginOtp($customer);

            return false;
        }

        if (! Hash::check($code, $customer->login_otp_code)) {
            $attempts = (int) $customer->login_otp_attempts + 1;
            $customer->forceFill(['login_otp_attempts' => $attempts])->save();

            if ($attempts >= 5) {
                $this->clearLoginOtp($customer);
            }

            return false;
        }

        $this->clearLoginOtp($customer);

        return true;
    }

    /**
     * Sends a 6-digit SMS reset code. Returns false when no SMS gateway is
     * configured for this company - the caller still shows the generic
     * "if an account exists" message either way.
     */
    public function sendPasswordResetCode(Company $company, StorefrontSetting $setting, Customer $customer): bool
    {
        $code = (string) random_int(100000, 999999);

        $customer->forceFill([
            'password_reset_code' => Hash::make($code),
            'password_reset_expires_at' => now()->addMinutes(15),
        ])->save();

        return $this->notifications->sendSms(
            $setting,
            $customer->phone,
            (string) $this->templates->render((int) $company->getKey(), SmsTemplateService::RESET_CUSTOMER_PASSWORD, [
                'company' => $company->name,
                'code' => $code,
                'minutes' => 15,
            ]),
        );
    }

    public function resetPassword(Customer $customer, string $code, string $newPassword): bool
    {
        // A 6-digit code must not be guessable by trying them all: five wrong
        // tries lock this account's reset for the rest of the 15-minute window
        // (the code itself expires then anyway).
        $attemptsKey = 'storefront-reset:'.$customer->getKey();

        if (RateLimiter::tooManyAttempts($attemptsKey, 5)) {
            return false;
        }

        if (
            blank($customer->password_reset_code)
            || ! $customer->password_reset_expires_at
            || $customer->password_reset_expires_at->isPast()
            || ! Hash::check($code, $customer->password_reset_code)
        ) {
            RateLimiter::hit($attemptsKey, 15 * 60);

            return false;
        }

        RateLimiter::clear($attemptsKey);

        $customer->forceFill([
            'password' => Hash::make($newPassword),
            'password_reset_code' => null,
            'password_reset_expires_at' => null,
            // Kills "remember me" cookies on every other device; their open
            // sessions are signed out by InvalidateStaleCustomerSession.
            'remember_token' => Str::random(60),
        ])->save();

        return true;
    }

    /** Seconds until a locked reset can be tried again (0 when it is not locked). */
    public function resetLockedFor(Customer $customer): int
    {
        $key = 'storefront-reset:'.$customer->getKey();

        return RateLimiter::tooManyAttempts($key, 5) ? RateLimiter::availableIn($key) : 0;
    }

    /** How long an emailed reset link works. */
    public const RESET_LINK_MINUTES = 60;

    /**
     * Starts an emailed reset for $customer and returns the one-time token
     * for the link. It uses the same hashed code + expiry as the SMS reset
     * (resetPassword() checks it), so it works once, expires, and a pending
     * SMS code is replaced. A lock from earlier wrong tries is lifted: the
     * link is sent by staff on purpose.
     */
    public function issueResetLink(Customer $customer): string
    {
        $token = Str::random(48);

        $customer->forceFill([
            'password_reset_code' => Hash::make($token),
            'password_reset_expires_at' => now()->addMinutes(self::RESET_LINK_MINUTES),
        ])->save();

        RateLimiter::clear('storefront-reset:'.$customer->getKey());

        return $token;
    }

    /** Drops a pending reset (a link that could not be sent must not stay valid). */
    public function cancelReset(Customer $customer): void
    {
        $customer->forceFill(['password_reset_code' => null, 'password_reset_expires_at' => null])->save();
    }

    /** Whether $code is the customer's unexpired reset code. Does not use the code up. */
    public function resetCodeIsValid(Customer $customer, string $code): bool
    {
        return filled($customer->password_reset_code)
            && $customer->password_reset_expires_at?->isFuture() === true
            && Hash::check($code, $customer->password_reset_code);
    }

    /**
     * Staff set a new password for $customer: they are signed out everywhere
     * and any pending reset code or link stops working.
     */
    public function setPasswordByStaff(Customer $customer, string $newPassword): void
    {
        $this->updatePassword($customer, $newPassword);
        $this->cancelReset($customer);
        RateLimiter::clear('storefront-reset:'.$customer->getKey());
    }

    public function updatePassword(Customer $customer, string $newPassword): void
    {
        $customer->forceFill([
            'password' => Hash::make($newPassword),
            // See resetPassword(): other devices must sign in again.
            'remember_token' => Str::random(60),
        ])->save();
    }

    public function emailAvailable(string $email, ?int $ignoreCustomerId = null): bool
    {
        return ! $this->emailTaken($email, $ignoreCustomerId);
    }

    protected function emailTaken(string $email, ?int $ignoreCustomerId = null): bool
    {
        return Customer::query()
            ->whereNotNull('password')
            ->where('email', $email)
            ->when($ignoreCustomerId, fn (Builder $query) => $query->whereKeyNot($ignoreCustomerId))
            ->exists();
    }

    protected function clearLoginOtp(Customer $customer): void
    {
        $customer->forceFill([
            'login_otp_code' => null,
            'login_otp_channel' => null,
            'login_otp_expires_at' => null,
            'login_otp_sent_at' => null,
            'login_otp_attempts' => 0,
        ])->save();
    }

    protected function wherePhoneMatches(Builder $query, string $phone): void
    {
        $phone = trim($phone);
        $digits = preg_replace('/\D+/', '', $phone) ?: $phone;

        $query->where(function (Builder $query) use ($phone, $digits): void {
            $query->where('phone', $phone)
                ->orWhere('phone', $digits)
                ->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), '-', ''), ' ', ''), '(', '') LIKE ?", ['%'.$digits.'%']);
        });
    }
}
