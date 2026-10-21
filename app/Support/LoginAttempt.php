<?php

namespace App\Support;

use App\Services\LoginGate;

/**
 * LoginGate's verdict on one sign-in request, taken before any password hash
 * is computed. It also carries what the gate needs to count the result.
 */
final class LoginAttempt
{
    public function __construct(
        public readonly string $surface,
        public readonly ?int $companyId,
        /** Lower-cased phone or e-mail the visitor typed. */
        public readonly string $subject,
        public readonly LoginClient $client,
        public readonly bool $trusted,
        public readonly string $outcome,
        public readonly int $retryAfter = 0,
        /** A CAPTCHA is set up, so the visitor can clear a CHALLENGE by solving one. */
        public readonly bool $captchaAvailable = false,
    ) {}

    /** Told "login failed" without a password hash being computed. */
    public function isDenied(): bool
    {
        return $this->outcome === LoginGate::DENY;
    }

    /** Must wait, or solve the CAPTCHA when one is set up (requiresCaptcha()), before trying again. */
    public function isBlocked(): bool
    {
        return in_array($this->outcome, [LoginGate::THROTTLE, LoginGate::CHALLENGE], true);
    }

    public function requiresChallenge(): bool
    {
        return $this->outcome === LoginGate::CHALLENGE;
    }

    /** The visitor is asked to solve the CAPTCHA (rather than just wait). */
    public function requiresCaptcha(): bool
    {
        return $this->requiresChallenge() && $this->captchaAvailable;
    }
}
