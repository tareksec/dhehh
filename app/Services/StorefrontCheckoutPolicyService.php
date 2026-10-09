<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CustomerBlacklist;
use App\Models\Order;
use App\Models\StorefrontCheckoutAttempt;
use App\Models\StorefrontSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StorefrontCheckoutPolicyService
{
    /**
     * Normalize the submitted identity, evaluate company policy, persist a
     * privacy-minimized audit record, and block only when enforcement is on.
     *
     * @return array{0: array, 1: StorefrontCheckoutAttempt|null}
     */
    public function evaluate(
        Request $request,
        Company $company,
        StorefrontSetting $setting,
        array $data,
        float $cartTotal,
        ?string $clientIp = null,
    ): array {
        $mode = (string) ($setting->checkout_policy_mode ?: StorefrontSetting::CHECKOUT_POLICY_OFF);

        if (! array_key_exists($mode, StorefrontSetting::CHECKOUT_POLICY_MODES)) {
            $mode = StorefrontSetting::CHECKOUT_POLICY_OFF;
        }

        if ($mode === StorefrontSetting::CHECKOUT_POLICY_OFF && ! (bool) $setting->risk_payment_enabled) {
            return [$data, null];
        }

        $localPhone = $this->normalizeLocalPhone((string) ($data['phone'] ?? ''));
        $email = $this->normalizeEmail($data['email'] ?? null);
        // Behind the headless Next.js site every request reaches the ERP from
        // that server, so it forwards the visitor's own IP ($clientIp); the
        // Blade storefront sees the visitor directly.
        $ip = $this->normalizeIp($clientIp ?? $request->ip());
        $violations = [];

        if ($localPhone !== null) {
            $data['phone'] = $localPhone;
        } elseif ($mode !== StorefrontSetting::CHECKOUT_POLICY_OFF && (bool) $setting->checkout_validate_bd_phone) {
            $violations['invalid_phone'] = 'Enter a valid 11-digit Bangladesh mobile number (01XXXXXXXXX).';
        }

        $data['email'] = $email;

        if ($mode !== StorefrontSetting::CHECKOUT_POLICY_OFF && (bool) $setting->checkout_block_phone && $localPhone && $this->isBlacklisted($company, 'phone', $this->toE164($localPhone))) {
            $violations['blocked_phone'] = 'The phone number is blocked by checkout policy.';
        }

        if ($mode !== StorefrontSetting::CHECKOUT_POLICY_OFF && (bool) $setting->checkout_block_email && $email && $this->isBlacklisted($company, 'email', $email)) {
            $violations['blocked_email'] = 'The email address is blocked by checkout policy.';
        }

        if ($mode !== StorefrontSetting::CHECKOUT_POLICY_OFF && (bool) $setting->checkout_block_ip && $ip && $this->isBlacklisted($company, 'ip_address', $ip)) {
            $violations['blocked_ip'] = 'The network address is blocked by checkout policy.';
        }

        if ($mode !== StorefrontSetting::CHECKOUT_POLICY_OFF && (bool) $setting->checkout_order_limit_enabled) {
            $violations += $this->orderLimitViolations($company, $setting, $localPhone, $email, $ip);
        }

        $isEnforced = $mode === StorefrontSetting::CHECKOUT_POLICY_ENFORCE;
        $attempt = StorefrontCheckoutAttempt::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'phone_hash' => $this->fingerprint($localPhone ? $this->toE164($localPhone) : null),
            'email_hash' => $this->fingerprint($email),
            'ip_hash' => $this->fingerprint($ip),
            'phone_mask' => $this->maskPhone($localPhone),
            'email_mask' => $this->maskEmail($email),
            'ip_mask' => $this->maskIp($ip),
            'policy_mode' => $mode,
            'outcome' => $violations === []
                ? StorefrontCheckoutAttempt::OUTCOME_ALLOWED
                : ($isEnforced ? StorefrontCheckoutAttempt::OUTCOME_BLOCKED : StorefrontCheckoutAttempt::OUTCOME_OBSERVED),
            'violations' => $violations ?: null,
            'cart_total' => max(0, $cartTotal),
            'payment_method' => $data['payment_method'] ?? null,
        ]);

        if ($violations !== [] && $isEnforced) {
            if (isset($violations['invalid_phone'])) {
                throw ValidationException::withMessages(['phone' => $violations['invalid_phone']]);
            }

            $contact = trim((string) $setting->checkout_policy_contact_phone);
            $message = 'We cannot accept this order right now. Please contact the store';

            throw ValidationException::withMessages([
                'checkout' => $message.($contact !== '' ? " at {$contact}." : '.'),
            ]);
        }

        return [$data, $attempt];
    }

    public function markAccepted(?StorefrontCheckoutAttempt $attempt, Order $order): void
    {
        $attempt?->forceFill([
            'order_id' => $order->getKey(),
            'outcome' => StorefrontCheckoutAttempt::OUTCOME_ACCEPTED,
        ])->save();
    }

    public function markPendingPayment(?StorefrontCheckoutAttempt $attempt): void
    {
        $attempt?->forceFill(['outcome' => StorefrontCheckoutAttempt::OUTCOME_PENDING_PAYMENT])->save();
    }

    /** @param array{required_advance: float, reasons: array, courier_success_ratio: float|null} $decision */
    public function recordPaymentDecision(?StorefrontCheckoutAttempt $attempt, array $decision): void
    {
        $attempt?->forceFill([
            'courier_success_ratio' => $decision['courier_success_ratio'],
            'required_advance' => $decision['required_advance'],
            'advance_reasons' => $decision['reasons'] ?: null,
        ])->save();
    }

    public function normalizeLocalPhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '880')) {
            $digits = '0'.substr($digits, 3);
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '1')) {
            $digits = '0'.$digits;
        }

        return preg_match('/^01[3-9]\d{8}$/', $digits) === 1 ? $digits : null;
    }

    /** @return array<int, string> */
    public function phoneVariants(string $phone): array
    {
        $local = $this->normalizeLocalPhone($phone);

        return $local ? [$local, $this->toE164($local)] : [trim($phone)];
    }

    protected function toE164(string $localPhone): string
    {
        return '+88'.$localPhone;
    }

    protected function normalizeEmail(?string $email): ?string
    {
        $email = Str::lower(trim((string) $email));

        return $email !== '' ? $email : null;
    }

    protected function normalizeIp(?string $ip): ?string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    protected function isBlacklisted(Company $company, string $column, string $value): bool
    {
        return CustomerBlacklist::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $company->getKey()))
            ->where($column, $value)
            ->exists();
    }

    protected function orderLimitViolations(
        Company $company,
        StorefrontSetting $setting,
        ?string $localPhone,
        ?string $email,
        ?string $ip,
    ): array {
        $since = now()->subHours(max(1, (int) $setting->checkout_order_limit_hours));
        $limit = max(1, (int) $setting->checkout_order_limit_count);
        $violations = [];
        $orders = Order::withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->whereIn('source', [Order::SOURCE_STOREFRONT, Order::SOURCE_OFFER])
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', $since);

        if ($localPhone && (clone $orders)->whereHas('customer', fn ($query) => $query->whereIn('phone', [$localPhone, $this->toE164($localPhone)]))->count() >= $limit) {
            $violations['phone_order_limit'] = 'The phone number reached the recent order limit.';
        }

        if ($email && (clone $orders)->whereHas('customer', fn ($query) => $query->whereRaw('LOWER(email) = ?', [$email]))->count() >= $limit) {
            $violations['email_order_limit'] = 'The email address reached the recent order limit.';
        }

        if ($ip) {
            $accepted = StorefrontCheckoutAttempt::withoutGlobalScopes()
                ->where('company_id', $company->getKey())
                ->where('ip_hash', $this->fingerprint($ip))
                ->whereIn('outcome', [
                    StorefrontCheckoutAttempt::OUTCOME_PENDING_PAYMENT,
                    StorefrontCheckoutAttempt::OUTCOME_ACCEPTED,
                ])
                ->where('created_at', '>=', $since)
                ->count();

            if ($accepted >= $limit) {
                $violations['ip_order_limit'] = 'The network address reached the recent order limit.';
            }
        }

        return $violations;
    }

    protected function fingerprint(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    protected function maskPhone(?string $phone): ?string
    {
        return $phone ? substr($phone, 0, 3).'****'.substr($phone, -4) : null;
    }

    protected function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }

        [$name, $domain] = explode('@', $email, 2);

        return substr($name, 0, 1).'***@'.$domain;
    }

    protected function maskIp(?string $ip): ?string
    {
        if (! $ip) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '*';

            return implode('.', $parts);
        }

        return substr($ip, 0, 8).'…';
    }
}
