<?php

namespace App\Support\StorefrontApi;

use App\Models\Customer;
use App\Models\StorefrontCustomerActivity;
use App\Models\StorefrontCustomerToken;

/** The customer facts the headless account area shows (never the password, OTP or internal ids). */
final class AccountPayload
{
    /** @return array<string, mixed> */
    public static function customer(Customer $customer): array
    {
        return [
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'address' => $customer->address,
            'customer_type' => $customer->customer_type,
            'member_since' => $customer->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> the response of every successful sign-in */
    public static function session(Customer $customer, StorefrontCustomerToken $row, string $plainToken): array
    {
        return [
            'customer' => self::customer($customer),
            'token' => $plainToken,
            'expires_at' => $row->expires_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function activity(StorefrontCustomerActivity $activity): array
    {
        return [
            'type' => $activity->type,
            'title' => $activity->title,
            'description' => $activity->description,
            'created_at' => $activity->created_at?->toIso8601String(),
        ];
    }
}
