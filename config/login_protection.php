<?php

/*
|--------------------------------------------------------------------------
| Login protection (App\Services\LoginGate)
|--------------------------------------------------------------------------
|
| Decides about a sign-in request BEFORE the slow password hash (bcrypt) is
| run, so a botnet that tries a few passwords per IP and per account cannot
| burn the server's CPU. Every limit is counted in the cache, so production
| needs CACHE_STORE=redis for the counters to be shared between workers.
| See docs/login-protection.md.
|
*/

return [

    // Turn the whole gate off (the old per-IP throttles still apply).
    'enabled' => (bool) env('LOGIN_PROTECTION_ENABLED', true),

    // Wrong passwords per minute from ONE device (IP + browser) against ONE
    // account, trusted devices included, before that device has to wait.
    'device_failures_per_minute' => (int) env('LOGIN_DEVICE_FAILURES_PER_MINUTE', 5),

    // Wrong passwords against ONE account from ALL devices inside the window.
    // Past this, devices the account has not signed in from before are
    // challenged; a device it HAS signed in from (device trust) is never
    // locked out by someone else's guessing.
    'account_failures' => (int) env('LOGIN_ACCOUNT_FAILURES', 20),
    'account_window_minutes' => (int) env('LOGIN_ACCOUNT_WINDOW_MINUTES', 15),

    // A client (browser + network) with this many wrong passwords and not one
    // successful sign-in inside the window is treated as a bot: it is told
    // "login failed" without a password hash being computed.
    'fingerprint_failures' => (int) env('LOGIN_FINGERPRINT_FAILURES', 30),
    'fingerprint_window_minutes' => (int) env('LOGIN_FINGERPRINT_WINDOW_MINUTES', 10),

    // Wrong passwords per minute across the whole site (per sign-in surface).
    // Past this, every device that is not trusted is challenged.
    'global_failures_per_minute' => (int) env('LOGIN_GLOBAL_FAILURES_PER_MINUTE', 300),

    // Password hashes the server computes per minute for devices that are not
    // trusted (per sign-in surface). The budget protects the CPU: past it,
    // such devices wait; trusted devices are never counted.
    'bcrypt_per_minute' => (int) env('LOGIN_BCRYPT_PER_MINUTE', 600),

    // How long a successful sign-in keeps its device trusted.
    'trusted_device_days' => (int) env('LOGIN_TRUSTED_DEVICE_DAYS', 90),

];
