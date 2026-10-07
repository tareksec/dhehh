<?php

namespace App\Support\StorefrontApi;

use App\Services\StorefrontCheckoutPolicyService;

/**
 * One spelling per phone number across checkout, sign-in and tracking:
 * "+880 1712-345678", "8801712345678" and "01712345678" are the same customer.
 * Checkout already stores Bangladeshi numbers in the local 01XXXXXXXXX form, so
 * the account endpoints convert what a visitor types to that form first —
 * otherwise a customer who registered through checkout could not sign in with
 * "+880…", and a password could be guessed under many spellings of one number
 * to dodge the per-account attempt limit. Anything that isn't a Bangladeshi
 * mobile number is only trimmed.
 */
final class Phone
{
    public static function normalize(string $phone): string
    {
        $phone = trim($phone);

        return app(StorefrontCheckoutPolicyService::class)->normalizeLocalPhone($phone) ?? $phone;
    }

    /** An e-mail address is left alone; anything else is treated as a phone number. */
    public static function identifier(string $identifier): string
    {
        $identifier = trim($identifier);

        return str_contains($identifier, '@') ? $identifier : self::normalize($identifier);
    }
}
