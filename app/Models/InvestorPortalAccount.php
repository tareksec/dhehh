<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\CompanyContext;
use App\Support\InvestorPortalStaffPreview;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * A sign-in to the investor portal (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md
 * §4.3). One per phone per company; it points at the investor, the channel
 * partner, or both, so one person who is both sees both roles with one
 * login. The investor and partner records themselves hold no login data.
 */
class InvestorPortalAccount extends Model implements AuthenticatableContract, FilamentUser, HasName
{
    use Authenticatable;
    use BelongsToCompany;

    /** Invited by staff; no password yet. */
    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const STATUSES = [
        self::STATUS_INVITED => 'Invited',
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_DISABLED => 'Disabled',
    ];

    protected $fillable = ['company_id', 'investor_id', 'channel_partner_id', 'name', 'phone', 'status', 'locale'];

    protected $hidden = ['password', 'remember_token', 'otp_code_hash', 'invite_token_hash'];

    protected $attributes = ['status' => self::STATUS_INVITED];

    protected $casts = [
        'password' => 'hashed',
        'otp_expires_at' => 'datetime',
        'otp_sent_at' => 'datetime',
        'otp_attempts' => 'integer',
        'invite_expires_at' => 'datetime',
        'invited_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            if (! $account->investor_id && ! $account->channel_partner_id) {
                throw ValidationException::withMessages(['investor_id' => __('A portal account must belong to an investor or a channel partner.')]);
            }

            foreach (['investor_id' => Investor::class, 'channel_partner_id' => ChannelPartner::class] as $column => $model) {
                if (! $account->{$column}) {
                    continue;
                }

                $companyId = $model::query()->withoutGlobalScopes()->whereKey($account->{$column})->value('company_id');

                if ((int) $companyId !== (int) $account->company_id) {
                    throw ValidationException::withMessages([$column => __('The portal account and its investor or channel partner must belong to the same company.')]);
                }
            }
        });
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function channelPartner(): BelongsTo
    {
        return $this->belongsTo(ChannelPartner::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** True while a partner page may be shown: the account links to a partner who is still active. */
    public function isChannelPartner(): bool
    {
        return $this->channel_partner_id !== null && (bool) $this->channelPartner?->is_active;
    }

    public function isInvestor(): bool
    {
        return $this->investor_id !== null && $this->investor !== null;
    }

    /**
     * Re-checked by Filament on every request, so disabling an account (or
     * switching the portal off) locks the person out at once. Only an active
     * account of the host's own company may enter.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() !== 'investor' || $this->status === self::STATUS_DISABLED) {
            return false;
        }

        // Only the person's own sign-in needs an active account with a password;
        // a staff preview (InvestorPortalStaffPreview) may also look at an
        // account that has not set one yet.
        if (! InvestorPortalStaffPreview::isFor($this) && ($this->status !== self::STATUS_ACTIVE || blank($this->password))) {
            return false;
        }

        $company = app(CompanyContext::class)->company();

        return $company !== null
            && (int) $company->getKey() === (int) $this->company_id
            && $company->investor_portal_enabled
            && ($this->isInvestor() || $this->isChannelPartner());
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public static function statusLabel(?string $status): string
    {
        return __(self::STATUSES[$status] ?? (string) $status);
    }
}
