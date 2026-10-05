<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\StorefrontCustomerActivity;
use App\Models\StorefrontCustomerToken;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\CustomerAccountService;
use App\Services\StorefrontCustomerActivityService;
use App\Services\StorefrontNotificationService;
use App\Support\StorefrontApi\AccountPayload;
use App\Support\StorefrontApi\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Customer sign-in for the headless Next.js site (/api/v1/storefront/account/…).
 * Every rule lives in CustomerAccountService, the same service the Blade
 * storefront uses: a registration reuses the CRM record of the same phone, a
 * wrong password is counted per identifier, a login code is valid for 10
 * minutes and five tries, a reset code for 15 minutes.
 *
 * The API answers with a token instead of a cookie. The Next.js server keeps it
 * in an httpOnly cookie and sends it back as "X-Customer-Token"; it is never
 * visible to browser scripts. Failures carry a stable "error" code so the
 * website can word them in Bangla or English itself.
 */
class StorefrontAccountAuthController extends Controller
{
    public function __construct(
        protected CompanyContext $context,
        protected CustomerAccountService $accounts,
        protected StorefrontNotificationService $notifications,
        protected StorefrontCustomerActivityService $activities,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:40', $this->phoneRule()],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'password' => ['required', 'string', 'min:8', 'max:200', 'confirmed'],
            'remember' => ['nullable', 'boolean'],
        ]);
        $data['phone'] = Phone::normalize($data['phone']);

        try {
            $customer = $this->accounts->register($data);
        } catch (ValidationException $exception) {
            return $this->fail(array_key_exists('email', $exception->errors()) ? 'email_taken' : 'account_exists', 409);
        }

        $this->activities->record(
            $customer,
            StorefrontCustomerActivity::TYPE_ACCOUNT_CREATED,
            'Account created',
            "Your {$customer->company->name} customer account is ready.",
        );

        return $this->signedIn($request, $customer, (bool) ($data['remember'] ?? false), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:200'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $identifier = Phone::identifier($data['identifier']);

        try {
            $customer = $this->accounts->attemptLogin($identifier, $data['password']);
        } catch (ValidationException) {
            return $this->fail('too_many_attempts', 429, ['retry_after' => $this->retryAfter('storefront-login:'.strtolower($identifier))]);
        }

        if (! $customer) {
            return $this->fail('invalid_credentials', 422);
        }

        if (! $customer->is_active) {
            return $this->fail('account_disabled', 403);
        }

        $this->activities->record($customer, StorefrontCustomerActivity::TYPE_LOGIN, 'Signed in to your account', 'A successful sign-in was recorded.');

        return $this->signedIn($request, $customer, (bool) ($data['remember'] ?? false));
    }

    /** Sends a 6-digit login code by SMS (phone) or email. Same answer whether or not the account exists. */
    public function sendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
        ]);
        $identifier = Phone::identifier($data['identifier']);
        $isEmail = str_contains($identifier, '@');
        $setting = $this->setting($request);

        if ($isEmail && ! filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['identifier' => 'Enter a valid email address.']);
        }

        if (! $isEmail && $this->digits($identifier) < 10) {
            throw ValidationException::withMessages(['identifier' => 'Enter a valid phone number.']);
        }

        if (! $isEmail && ! $this->notifications->smsConfigured($setting)) {
            return $this->fail('otp_unavailable', 422);
        }

        $customer = $this->accounts->findRegisteredByIdentifier($identifier);

        if ($customer) {
            $this->accounts->sendLoginOtp($this->context->company(), $setting, $customer, $identifier);
        }

        return response()->json(['ok' => true, 'masked_identifier' => $this->mask($identifier)], 202);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'code' => ['required', 'digits:6'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $identifier = Phone::identifier($data['identifier']);
        $customer = $this->accounts->findRegisteredByIdentifier($identifier);

        if (! $customer || ! $this->accounts->verifyLoginOtp($customer, $data['code'])) {
            return $this->fail('otp_invalid', 422);
        }

        $this->activities->record(
            $customer,
            StorefrontCustomerActivity::TYPE_LOGIN,
            'Signed in with OTP',
            'A successful one-time-code sign-in was recorded.',
            ['channel' => str_contains($identifier, '@') ? 'email' : 'sms'],
        );

        return $this->signedIn($request, $customer, (bool) ($data['remember'] ?? false));
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40', $this->phoneRule()],
        ]);
        $setting = $this->setting($request);

        if (! $this->notifications->smsConfigured($setting)) {
            return $this->fail('sms_unavailable', 422);
        }

        $customer = $this->accounts->findRegisteredByPhone(Phone::normalize($data['phone']));

        if ($customer) {
            $this->accounts->sendPasswordResetCode($this->context->company(), $setting, $customer);
        }

        // Same answer whether or not a match was found, so the endpoint can't
        // be used to find out which phone numbers have accounts.
        return response()->json(['ok' => true], 202);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40', $this->phoneRule()],
            'code' => ['required', 'string', 'max:10'],
            'password' => ['required', 'string', 'min:8', 'max:200', 'confirmed'],
        ]);

        $customer = $this->accounts->findRegisteredByPhone(Phone::normalize($data['phone']));

        if ($customer && ($wait = $this->accounts->resetLockedFor($customer)) > 0) {
            return $this->fail('too_many_attempts', 429, ['retry_after' => $wait]);
        }

        if (! $customer || ! $this->accounts->resetPassword($customer, $data['code'], $data['password'])) {
            return $this->fail('reset_code_invalid', 422);
        }

        // Anyone who had the old password is signed out everywhere.
        StorefrontCustomerToken::revokeAllFor($customer);

        $this->activities->record($customer, StorefrontCustomerActivity::TYPE_PASSWORD_RESET, 'Password reset', 'Your account password was reset successfully.');

        return $this->signedIn($request, $customer, false);
    }

    /** Ends only the login this request was made with. */
    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('storefront_customer_token')?->delete();

        return response()->json(['ok' => true]);
    }

    protected function signedIn(Request $request, Customer $customer, bool $remember, int $status = 200): JsonResponse
    {
        [$row, $plain] = StorefrontCustomerToken::issue(
            $customer,
            $remember,
            is_string($request->input('client_user_agent')) ? $request->input('client_user_agent') : null,
        );

        return response()->json(['data' => AccountPayload::session($customer, $row, $plain)], $status);
    }

    protected function fail(string $error, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $error] + $extra, $status);
    }

    protected function setting(Request $request): StorefrontSetting
    {
        return $request->attributes->get('storefront_setting');
    }

    protected function phoneRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($this->digits((string) $value) < 10) {
                $fail('Enter a valid phone number.');
            }
        };
    }

    protected function digits(string $value): int
    {
        return strlen(preg_replace('/\D+/', '', $value) ?: '');
    }

    protected function retryAfter(string $key): int
    {
        return RateLimiter::availableIn($key);
    }

    protected function mask(string $identifier): string
    {
        if (str_contains($identifier, '@')) {
            [$local, $domain] = explode('@', $identifier, 2);

            return mb_substr($local, 0, 1).str_repeat('•', max(3, mb_strlen($local) - 1)).'@'.$domain;
        }

        $digits = preg_replace('/\D+/', '', $identifier) ?: $identifier;

        return str_repeat('•', max(0, mb_strlen($digits) - 4)).mb_substr($digits, -4);
    }
}
