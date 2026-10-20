<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An office invite to join IMS (IMS-V2/PRD.md FR-04): a link sent by SMS or
 * e-mail, valid for 14 days, used once. Only the token's hash is kept.
 */
class InvestorInvite extends Model
{
    use BelongsToCompany;

    public const VALID_DAYS = 14;

    protected $fillable = ['company_id', 'contact', 'contact_type', 'name', 'token_hash', 'expires_at', 'invited_by', 'investor_id', 'used_at', 'revoked_at'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime', 'revoked_at' => 'datetime'];

    protected $hidden = ['token_hash'];

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->used_at !== null => __('Joined'),
            $this->revoked_at !== null => __('Cancelled'),
            $this->expires_at->isPast() => __('Expired'),
            default => __('Waiting'),
        };
    }
}
