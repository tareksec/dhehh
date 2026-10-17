<?php

namespace App\Services;

use App\Support\LoginAttempt;
use App\Support\LoginClient;
use App\Support\ModuleGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Judges a sign-in request BEFORE the password hash (bcrypt) is computed.
 *
 * bcrypt is slow on purpose, so it is also the expensive thing an attacker
 * can make the server do: 50,000 guesses a minute from 30,000 addresses stay
 * under every per-IP limit and every per-account lockout, and each guess still
 * costs a full hash. The gate looks at the request first and answers one of:
 *
 *  - ALLOW      the normal password check runs;
 *  - THROTTLE   wait (Retry-After), nothing is computed;
 *  - CHALLENGE  an unknown device while the account or the whole site is under
 *               attack: it waits (a CAPTCHA step is planned, see
 *               docs/login-protection.md);
 *  - DENY       a client that only ever fails: told "login failed", nothing is
 *               computed.
 *
 * The signals, all counted in the cache (see config/login_protection.php):
 * the device-trust token (a device the account has signed in from before is
 * never locked out by somebody else's guessing), the failures of this device
 * against this account, the failures against this account from everywhere, the
 * failure/success record of the client's fingerprint (browser + network
 * block), and the failure rate and hash budget of the whole site.
 *
 * Callers: begin() -> (password check unless denied/blocked) -> failed() or
 * succeeded(), and rememberDevice() / issueDeviceToken() after a success.
 */
class LoginGate
{
    public const SURFACE_CUSTOMER = 'customer';

    public const SURFACE_INVESTOR = 'investor';

    public const SURFACE_ADMIN = 'admin';

    public const ALLOW = 'allow';

    public const THROTTLE = 'throttle';

    public const CHALLENGE = 'challenge';

    public const DENY = 'deny';

    private const TOKEN_VERSION = 'v1';

    private static ?string $dummyHash = null;

    public static function cookieName(string $surface): string
    {
        return 'lg_td_'.$surface;
    }

    public function enabled(): bool
    {
        return (bool) config('login_protection.enabled', true);
    }

    /**
     * @param  string  $subject  the phone or e-mail the visitor typed (any case)
     * @param  Authenticatable|null  $account  the account it names, null when there is none
     */
    public function begin(string $surface, ?int $companyId, string $subject, ?Authenticatable $account, LoginClient $client): LoginAttempt
    {
        $subject = Str::lower(trim($subject));

        // If the gate itself breaks (the cache is down, say), signing in must
        // not: the failure is reported to super admins and the attempt goes
        // through to the normal password check, as it did before the gate.
        return ModuleGuard::run(
            'login-gate',
            fn (): LoginAttempt => $this->judge($surface, $companyId, $subject, $account, $client),
            fn (): LoginAttempt => new LoginAttempt($surface, $companyId, $subject, $client, false, self::ALLOW),
        );
    }

    private function judge(string $surface, ?int $companyId, string $subject, ?Authenticatable $account, LoginClient $client): LoginAttempt
    {
        $trusted = $this->enabled() && $account !== null && $this->tokenIsValid($client->deviceToken, $surface, $companyId, $account);

        $decide = function (string $outcome, int $retryAfter = 0) use ($surface, $companyId, $subject, $client, $trusted): LoginAttempt {
            return new LoginAttempt($surface, $companyId, $subject, $client, $trusted, $outcome, $retryAfter);
        };

        if (! $this->enabled()) {
            return $decide(self::ALLOW);
        }

        // This device keeps guessing at this account: it waits, trusted or not.
        $deviceKey = $this->deviceKey($surface, $companyId, $subject, $client);

        if (RateLimiter::tooManyAttempts($deviceKey, $this->limit('device_failures_per_minute'))) {
            return $decide(self::THROTTLE, RateLimiter::availableIn($deviceKey));
        }

        // A device the account has signed in from before is not judged by what
        // strangers do to the account or the site.
        if ($trusted) {
            return $decide(self::ALLOW);
        }

        // A client that only ever fails, and has never once got in.
        $fingerprint = $this->fingerprint($client);

        if (
            RateLimiter::attempts("login-gate:fp:{$fingerprint}:fail") >= $this->limit('fingerprint_failures')
            && RateLimiter::attempts("login-gate:fp:{$fingerprint}:ok") === 0
        ) {
            return $decide(self::DENY);
        }

        // Many wrong passwords against this account from many places.
        $accountKey = $this->accountKey($surface, $companyId, $subject);

        if (RateLimiter::tooManyAttempts($accountKey, $this->limit('account_failures'))) {
            return $decide(self::CHALLENGE, RateLimiter::availableIn($accountKey));
        }

        // The whole site is being hammered.
        $globalFailKey = "login-gate:{$surface}:global:fail";

        if (RateLimiter::tooManyAttempts($globalFailKey, $this->limit('global_failures_per_minute'))) {
            return $decide(self::CHALLENGE, RateLimiter::availableIn($globalFailKey));
        }

        // The server's password-hash budget for devices nobody vouches for.
        $budgetKey = "login-gate:{$surface}:global:hash";

        if (RateLimiter::tooManyAttempts($budgetKey, $this->limit('bcrypt_per_minute'))) {
            return $decide(self::THROTTLE, RateLimiter::availableIn($budgetKey));
        }

        RateLimiter::hit($budgetKey, 60);

        return $decide(self::ALLOW);
    }

    /** A wrong password (or an unknown account) — also counted when nothing was computed. */
    public function failed(LoginAttempt $attempt): void
    {
        if (! $this->enabled()) {
            return;
        }

        ModuleGuard::run('login-gate', function () use ($attempt): void {
            RateLimiter::hit($this->deviceKey($attempt->surface, $attempt->companyId, $attempt->subject, $attempt->client), 60);
            RateLimiter::hit($this->accountKey($attempt->surface, $attempt->companyId, $attempt->subject), $this->minutes('account_window_minutes') * 60);
            RateLimiter::hit('login-gate:fp:'.$this->fingerprint($attempt->client).':fail', $this->minutes('fingerprint_window_minutes') * 60);
            RateLimiter::hit("login-gate:{$attempt->surface}:global:fail", 60);
        });
    }

    public function succeeded(LoginAttempt $attempt): void
    {
        if (! $this->enabled()) {
            return;
        }

        ModuleGuard::run('login-gate', function () use ($attempt): void {
            RateLimiter::clear($this->deviceKey($attempt->surface, $attempt->companyId, $attempt->subject, $attempt->client));
            RateLimiter::clear($this->accountKey($attempt->surface, $attempt->companyId, $attempt->subject));
            RateLimiter::hit('login-gate:fp:'.$this->fingerprint($attempt->client).':ok', $this->minutes('fingerprint_window_minutes') * 60);
        });
    }

    /**
     * Runs a password check that can only fail, so an unknown account costs
     * the same time as a wrong password and timing does not tell them apart.
     */
    public function spendHashTime(string $password): void
    {
        self::$dummyHash ??= Hash::make(Str::random(40));

        Hash::check($password, self::$dummyHash);
    }

    /** A token for a browser cookie (queued on the response) — web logins. */
    public function rememberDevice(string $surface, ?int $companyId, Authenticatable $account): void
    {
        if (! $this->enabled()) {
            return;
        }

        Cookie::queue(Cookie::make(
            self::cookieName($surface),
            $this->issueDeviceToken($surface, $companyId, $account),
            max(1, (int) config('login_protection.trusted_device_days')) * 1440,
            '/',
            null,
            null,
            true,
            false,
            'lax',
        ));
    }

    /**
     * The device-trust token itself, for callers that cannot set a cookie (the
     * Next.js server keeps it in its own httpOnly cookie). It names the account
     * and is signed with the app key and the account's CURRENT password hash,
     * so changing the password makes every earlier token worthless.
     */
    public function issueDeviceToken(string $surface, ?int $companyId, Authenticatable $account): string
    {
        $id = (string) $account->getAuthIdentifier();
        $issued = now()->getTimestamp();

        return implode('.', [self::TOKEN_VERSION, $id, $issued, $this->sign($surface, $companyId, $id, $issued, $account)]);
    }

    public function tokenIsValid(?string $token, string $surface, ?int $companyId, Authenticatable $account): bool
    {
        $parts = explode('.', (string) $token);

        if (count($parts) !== 4 || $parts[0] !== self::TOKEN_VERSION) {
            return false;
        }

        [, $id, $issued, $signature] = $parts;

        if ($id !== (string) $account->getAuthIdentifier() || ! ctype_digit($issued)) {
            return false;
        }

        $age = now()->getTimestamp() - (int) $issued;

        if ($age < 0 || $age > max(1, (int) config('login_protection.trusted_device_days')) * 86400) {
            return false;
        }

        return hash_equals($this->sign($surface, $companyId, $id, (int) $issued, $account), $signature);
    }

    private function sign(string $surface, ?int $companyId, string $id, int $issued, Authenticatable $account): string
    {
        return hash_hmac('sha256', implode('|', [
            $surface,
            (int) $companyId,
            $id,
            $issued,
            hash('sha256', (string) $account->getAuthPassword()),
        ]), (string) config('app.key'));
    }

    private function fingerprint(LoginClient $client): string
    {
        return sha1($client->userAgent.'|'.$client->language.'|'.$client->network());
    }

    private function deviceKey(string $surface, ?int $companyId, string $subject, LoginClient $client): string
    {
        return $this->accountKey($surface, $companyId, $subject).':device:'.sha1($client->ip.'|'.$client->userAgent);
    }

    private function accountKey(string $surface, ?int $companyId, string $subject): string
    {
        return "login-gate:{$surface}:".(int) $companyId.':'.sha1($subject);
    }

    private function limit(string $setting): int
    {
        return max(1, (int) config("login_protection.{$setting}"));
    }

    private function minutes(string $setting): int
    {
        return max(1, (int) config("login_protection.{$setting}"));
    }
}
