<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The login of a storefront customer on the headless Next.js site. Only the
 * SHA-256 hash is stored; issue() returns the plain token exactly once.
 *
 * Looked up while an API request is being authenticated, always inside the
 * company the API key resolved to, so a token can never be used for another
 * company's customer even if it were guessed.
 */
class StorefrontCustomerToken extends Model
{
    use BelongsToCompany;

    /** Lifetime of a "remember me" login, and of a plain one. */
    public const REMEMBER_DAYS = 30;

    public const SESSION_HOURS = 48;

    protected $fillable = [
        'company_id',
        'customer_id',
        'token_hash',
        'user_agent',
        'last_used_at',
        'expires_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return array{0: self, 1: string} the stored row and the plain token (never retrievable again) */
    public static function issue(Customer $customer, bool $remember = false, ?string $userAgent = null): array
    {
        $token = Str::random(64);

        $row = static::query()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->getKey(),
            'token_hash' => hash('sha256', $token),
            'user_agent' => $userAgent !== null ? Str::limit($userAgent, 250, '') : null,
            'expires_at' => $remember ? now()->addDays(self::REMEMBER_DAYS) : now()->addHours(self::SESSION_HOURS),
        ]);

        return [$row, $token];
    }

    /** The live token row for $token within the current company, or null. */
    public static function findLive(string $token): ?self
    {
        return static::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();
    }

    /** Signs a customer out everywhere, optionally keeping one login (the one making the request). */
    public static function revokeAllFor(Customer $customer, ?int $exceptId = null): void
    {
        static::query()
            ->where('customer_id', $customer->getKey())
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->delete();
    }
}
