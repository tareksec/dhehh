<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\InvestorIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A channel partner brings investors in and earns a share of the profit on
 * their capital. Owner (2026-09-28): partners are kept apart from investors,
 * with their own record and accounts. Each investor names the partner who
 * brought them, and a project can have several partners.
 */
class ChannelPartner extends Model
{
    use BelongsToCompany;

    public const PAYMENT_METHODS = ['bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket', 'bank' => 'Bank', 'cash' => 'Cash'];

    protected $fillable = ['company_id', 'name', 'phone', 'address', 'nid_number', 'payment_method', 'account_name', 'account_number', 'bank_name', 'bank_branch', 'notes', 'is_active', 'invite_enabled'];

    protected $casts = ['nid_number' => 'encrypted', 'is_active' => 'boolean', 'invite_enabled' => 'boolean'];

    protected static function booted(): void
    {
        // The NID / passport number is the partner ID, like investors.
        static::saving(function (self $partner): void {
            if ($partner->isDirty('nid_number') || ($partner->nid_number && ! $partner->nid_hash)) {
                $partner->nid_hash = InvestorIdentity::hash($partner->nid_number);
            }

            if ($partner->nid_hash) {
                $owner = self::query()
                    ->where('company_id', $partner->company_id)
                    ->where('nid_hash', $partner->nid_hash)
                    ->when($partner->exists, fn ($query) => $query->whereKeyNot($partner->getKey()))
                    ->first();

                if ($owner) {
                    throw ValidationException::withMessages(['nid_number' => __('This NID / passport number already belongs to channel partner :name.', ['name' => $owner->name])]);
                }
            }
        });
    }

    /** Investors this partner brought in. */
    /**
     * Gives the partner an invite code the first time it is needed. Codes are
     * random, unique per company, and never reused for someone else.
     */
    public function ensureInviteCode(): string
    {
        if (filled($this->invite_code)) {
            return (string) $this->invite_code;
        }

        do {
            $code = strtoupper(Str::random(8));
        } while (self::query()->withoutGlobalScopes()->where('company_id', $this->company_id)->where('invite_code', $code)->exists());

        $this->forceFill(['invite_code' => $code])->save();

        return $code;
    }

    /** The self sign-up link on the investor portal, or null while invites are off or the portal has no address. */
    public function inviteUrl(): ?string
    {
        if (! $this->is_active || ! $this->invite_enabled || blank($this->invite_code)) {
            return null;
        }

        return Company::query()->find($this->company_id)?->investorLinkUrl('register?code='.$this->invite_code);
    }

    /** An active partner of the current company whose invite link is on. */
    public static function forInviteCode(?string $code): ?self
    {
        $code = strtoupper(trim((string) $code));

        return $code === '' ? null : self::query()->where('invite_code', $code)->where('invite_enabled', true)->where('is_active', true)->first();
    }

    public function bankAccounts()
    {
        return $this->morphMany(InvestorBankAccount::class, 'holder');
    }

    public function investors()
    {
        return $this->hasMany(Investor::class, 'channel_partner_id');
    }

    public function payouts()
    {
        return $this->hasMany(ChannelPartnerPayout::class);
    }

    public function documents()
    {
        return $this->morphMany(InvestmentDocument::class, 'documentable');
    }

    public function scopeWhereNid(Builder $query, ?string $number): Builder
    {
        return $query->where('nid_hash', InvestorIdentity::hash($number) ?? '-');
    }

    public function partnerId(): ?string
    {
        return InvestorIdentity::normalize($this->nid_number);
    }

    /** Capital currently invested by the investors this partner brought in. */
    public function referredCapital(): float
    {
        return (float) Investment::query()
            ->whereIn('investor_id', $this->investors()->select('id'))
            ->where('status', Investment::STATUS_ACTIVE)
            ->sum('amount');
    }

    public function totalEarned(): float
    {
        return (float) $this->payouts()->sum('amount');
    }

    public function totalPaid(): float
    {
        return (float) $this->payouts()->where('payment_status', 'paid')->sum('amount');
    }

    public function totalDue(): float
    {
        return round($this->totalEarned() - $this->totalPaid(), 2);
    }
}
