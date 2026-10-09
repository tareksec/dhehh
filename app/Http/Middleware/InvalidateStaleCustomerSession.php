<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Storefront counterpart of Laravel's AuthenticateSession for the `customer`
 * guard: when a customer's password changes (profile change or reset), every
 * other browser still signed in with the old password is signed out on its
 * next request. The device that made the change stays signed in, because the
 * new hash is stored after its own request.
 *
 * Laravel's AuthenticateSession can't be reused here: it reads the default
 * (staff) guard and flushes the whole session, which would also sign out a
 * staff member previewing the storefront in the same browser. This one only
 * touches the customer guard. It shares the `password_hash_customer` key with
 * the reseller panel's AuthenticateSession (both write the same format).
 */
class InvalidateStaleCustomerSession
{
    protected const SESSION_KEY = 'password_hash_customer';

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('customer');

        if (! $request->hasSession() || ! $guard instanceof SessionGuard) {
            return $next($request);
        }

        $passwordHash = (string) $guard->user()?->getAuthPassword();
        $stored = $request->session()->get(self::SESSION_KEY);

        if ($passwordHash !== '' && is_string($stored) && ! $this->matches($guard, $passwordHash, $stored)) {
            $guard->logoutCurrentDevice();
        }

        return tap($next($request), function () use ($guard, $request): void {
            $password = (string) $guard->user()?->getAuthPassword();

            // Re-stamped after every request, so a login, a sign-out or this
            // device's own password change always leaves the current value.
            if ($password === '') {
                $request->session()->forget(self::SESSION_KEY);
            } else {
                $request->session()->put(self::SESSION_KEY, $guard->hashPasswordForCookie($password));
            }
        });
    }

    protected function matches(SessionGuard $guard, string $passwordHash, string $stored): bool
    {
        // The reseller panel's Profile page stores the raw hash, Laravel's
        // middleware the HMAC form; accept both like AuthenticateSession does.
        return hash_equals($guard->hashPasswordForCookie($passwordHash), $stored)
            || hash_equals($passwordHash, $stored);
    }
}
