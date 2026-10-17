<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * A sign-in that was stopped before the password was checked: too many wrong
 * passwords, from this device, for this account or across the site. It is a
 * ValidationException so every existing "catch ValidationException" around a
 * login keeps working; the API reads retryAfter for its 429 answer.
 */
final class LoginThrottled extends ValidationException
{
    public int $retryAfter = 0;

    public bool $challenge = false;

    public static function for(LoginAttempt $attempt, string $field = 'identifier'): self
    {
        $retry = max(1, $attempt->retryAfter);

        $exception = self::withMessages([
            $field => __('Too many login attempts. Please try again in :seconds seconds.', ['seconds' => $retry]),
        ]);
        $exception->retryAfter = $retry;
        $exception->challenge = $attempt->requiresChallenge();

        return $exception;
    }
}
