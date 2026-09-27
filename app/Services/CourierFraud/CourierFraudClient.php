<?php

namespace App\Services\CourierFraud;

interface CourierFraudClient
{
    /**
     * @param  array{username?: string, password?: string, api_key?: string, secret_key?: string, base_url?: string}  $credentials
     * @return array{success: int, cancel: int, total: int}|null null on failure/unconfigured
     */
    public function checkByPhone(string $phone, array $credentials): ?array;
}
