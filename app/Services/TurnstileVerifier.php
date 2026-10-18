<?php

namespace App\Services;

use App\Support\LoginCaptchaSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks a Cloudflare Turnstile response token with Cloudflare's siteverify
 * endpoint. A token is single-use and Cloudflare rejects a reused one.
 *
 * Any trouble (Cloudflare unreachable, an error answer, a bad key) counts as
 * "not solved": a challenged sign-in then stays a plain wait, exactly as if no
 * CAPTCHA was set up, so Cloudflare being down can't open the gate.
 */
class TurnstileVerifier
{
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** Turnstile tokens are at most 2048 characters. */
    private const MAX_TOKEN_LENGTH = 2048;

    public function configured(): bool
    {
        return LoginCaptchaSettings::isConfigured();
    }

    public function verify(?string $token, ?string $ip = null): bool
    {
        $secret = LoginCaptchaSettings::secretKey();

        if ($secret === null || $token === null || $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, array_filter([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]));

            return $response->successful() && $response->json('success') === true;
        } catch (Throwable $exception) {
            Log::warning('Turnstile verification failed: '.$exception->getMessage());

            return false;
        }
    }
}
