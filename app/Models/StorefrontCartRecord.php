<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontCartRecord extends Model
{
    use BelongsToCompany;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_REMINDED = 'reminded';

    public const STATUS_CHECKOUT_STARTED = 'checkout_started';

    public const STATUSES = [
        self::STATUS_ACTIVE => 'Cart active',
        self::STATUS_CHECKOUT_STARTED => 'Checkout started',
        self::STATUS_REMINDED => 'Reminder sent',
        self::STATUS_CONVERTED => 'Converted',
    ];

    protected $fillable = [
        'company_id',
        'reseller_customer_id',
        'session_id',
        'phone',
        'customer_name',
        'email',
        'address',
        'items',
        'subtotal',
        'status',
        'checkout_started_at',
        'last_activity_at',
        'reminded_at',
        'recovery_opened_at',
        'reminder_count',
        'last_reminder_channels',
        'recovered_order_id',
        'converted_at',
        'recovered_at',
    ];

    protected $casts = [
        'items' => 'array',
        'email' => 'encrypted',
        'address' => 'encrypted',
        'subtotal' => 'decimal:2',
        'checkout_started_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'reminded_at' => 'datetime',
        'recovery_opened_at' => 'datetime',
        'reminder_count' => 'integer',
        'last_reminder_channels' => 'array',
        'converted_at' => 'datetime',
        'recovered_at' => 'datetime',
    ];

    public function recoveredOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'recovered_order_id');
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'reseller_customer_id')->withTrashed();
    }

    public function itemCount(): int
    {
        return (int) collect($this->items)->sum('quantity');
    }

    public function recoveryToken(): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [$this->getKey(), $this->company_id, $this->session_id, $this->created_at?->getTimestamp()]),
            (string) config('app.key'),
        );
    }

    public function hasValidRecoveryToken(string $token): bool
    {
        return $token !== '' && hash_equals($this->recoveryToken(), $token);
    }

    public function recoveryUrl(): ?string
    {
        $domain = $this->company?->domain;

        if (! $domain) {
            return null;
        }

        $path = route('storefront.cart.recover', [
            'cart' => $this->getKey(),
            'token' => $this->recoveryToken(),
        ], false);

        return 'https://'.$domain.$path;
    }

    public function markConverted(?Order $order = null): void
    {
        $isRecovered = $this->reminded_at !== null || $this->recovery_opened_at !== null;

        $this->forceFill([
            'status' => self::STATUS_CONVERTED,
            'recovered_order_id' => $order?->getKey() ?? $this->recovered_order_id,
            'converted_at' => $this->converted_at ?? now(),
            'recovered_at' => $isRecovered ? ($this->recovered_at ?? now()) : $this->recovered_at,
            'last_activity_at' => now(),
        ])->save();
    }
}
