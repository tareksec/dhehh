<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\StorefrontCustomerToken;
use Illuminate\Http\Request;

/**
 * Who is the logged-in customer behind an API request? The Next.js server
 * sends the customer's token in "X-Customer-Token" next to the company API
 * key. Resolution happens inside the company the API key set, so a token only
 * ever resolves to a customer of that company.
 */
class StorefrontCustomerSession
{
    public const HEADER = 'X-Customer-Token';

    /** @return array{customer: Customer, token: StorefrontCustomerToken}|null */
    public function resolve(Request $request): ?array
    {
        $plain = trim((string) $request->header(self::HEADER, ''));

        if ($plain === '' || strlen($plain) > 200) {
            return null;
        }

        $token = StorefrontCustomerToken::findLive($plain);
        $customer = $token?->customer;

        // A customer an admin has deactivated, or whose password was removed,
        // can no longer use an old login.
        if (! $token || ! $customer || ! $customer->is_active || blank($customer->password)) {
            return null;
        }

        if ($token->last_used_at === null || $token->last_used_at->diffInMinutes(now()) >= 5) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return ['customer' => $customer, 'token' => $token];
    }
}
