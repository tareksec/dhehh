<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\InvestorIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class Investor extends Model
{
    use BelongsToCompany;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_PENDING = 'pending';

    /** IMS v2 KYC (IMS-V2/PRD.md FR-10–14). */
    public const KYC_NOT_SUBMITTED = 'not_submitted';

    public const KYC_PENDING = 'pending';

    public const KYC_VERIFIED = 'verified';

    public const KYC_REJECTED = 'rejected';

    public const KYC_STATUSES = [
        self::KYC_NOT_SUBMITTED => 'Not sent yet',
        self::KYC_PENDING => 'Waiting for review',
        self::KYC_VERIFIED => 'Verified',
        self::KYC_REJECTED => 'Not accepted',
    ];

    protected $fillable = ['company_id', 'name', 'display_name', 'guardian_name', 'date_of_birth', 'phone', 'email', 'address', 'nid_number', 'stamp_number', 'cheque_number', 'channel_partner_id', 'channel_partner_change_reason', 'nominee_name', 'nominee_nid_or_passport', 'nominee_phone', 'nominee_relation', 'nominee_address'];

    protected $attributes = ['kyc_status' => self::KYC_NOT_SUBMITTED];

    // nid_number / nominee_nid_or_passport are encrypted at rest (v3 P3) --
    // same `encrypted` cast this app already uses for other sensitive
    // columns (ConversationChannel tokens, StorefrontCartRecord email).
    protected $casts = ['date_of_birth' => 'date', 'nid_number' => 'encrypted', 'nominee_nid_or_passport' => 'encrypted', 'kyc_submitted_at' => 'datetime', 'kyc_reviewed_at' => 'datetime', 'kyc_check' => 'array', 'email_verified_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (self $investor): void {
            // The NID / passport number is the investor's identity (owner's
            // rule), so it may belong to only one investor per company.
            if ($investor->isDirty('nid_number') || ($investor->nid_number && ! $investor->nid_hash)) {
                $investor->nid_hash = InvestorIdentity::hash($investor->nid_number);
            }

            if ($investor->nid_hash) {
                $owner = self::query()
                    ->where('company_id', $investor->company_id)
                    ->where('nid_hash', $investor->nid_hash)
                    ->when($investor->exists, fn ($query) => $query->whereKeyNot($investor->getKey()))
                    ->first();

                if ($owner) {
                    throw ValidationException::withMessages(['nid_number' => __('This NID / passport number already belongs to investor :name.', ['name' => $owner->name])]);
                }
            }

            if ($investor->channel_partner_id) {
                $partner = ChannelPartner::query()->withoutGlobalScopes()->find($investor->channel_partner_id);

                if (! $partner || (int) $partner->company_id !== (int) $investor->company_id) {
                    throw ValidationException::withMessages(['channel_partner_id' => 'The channel partner must belong to the same company.']);
                }
            }

            // Only a *change* to an already-assigned channel partner needs the
            // elevated permission + a logged reason (v3 P2.2 — the reassignment
            // safeguard was wrongly blocking the very first assignment on an
            // existing investor, since `isDirty` is also true going from null).
            if (! $investor->exists || ! $investor->isDirty('channel_partner_id') || $investor->getOriginal('channel_partner_id') === null) {
                return;
            }

            if (! Auth::user()?->hasPermission('investments.manage_channel_partner')) {
                throw ValidationException::withMessages(['channel_partner_id' => 'Only a Super Admin may change an assigned channel partner.']);
            }

            if (blank($investor->channel_partner_change_reason)) {
                throw ValidationException::withMessages(['channel_partner_change_reason' => 'A reason is required when changing an assigned channel partner.']);
            }
        });
    }

    /** The channel partner who brought this investor in (a separate record, not an investor). */
    public function channelPartner()
    {
        return $this->belongsTo(ChannelPartner::class, 'channel_partner_id');
    }

    public function investments()
    {
        return $this->hasMany(Investment::class);
    }

    /** The groups this investor is in (or was in), one row per group. */
    public function groupMemberships()
    {
        return $this->hasMany(InvestorGroupMember::class);
    }

    public function settlementPayouts()
    {
        return $this->hasMany(SettlementPayout::class);
    }

    public function documents()
    {
        return $this->morphMany(InvestmentDocument::class, 'documentable');
    }

    public function bankAccounts()
    {
        return $this->morphMany(InvestorBankAccount::class, 'holder');
    }

    /** One to five nominees (IMS-V2/PRD.md FR-06). */
    public function nominees()
    {
        return $this->hasMany(InvestorNominee::class)->orderBy('id');
    }

    public function kycDocuments()
    {
        return $this->hasMany(InvestorKycDocument::class);
    }

    public function capitalReturns()
    {
        return $this->hasMany(CapitalReturn::class);
    }

    public function portalAccount()
    {
        return $this->hasOne(InvestorPortalAccount::class);
    }

    public function isKycVerified(): bool
    {
        return $this->kyc_status === self::KYC_VERIFIED;
    }

    public static function kycStatusLabel(?string $status): string
    {
        return __(self::KYC_STATUSES[$status ?? self::KYC_NOT_SUBMITTED] ?? (string) $status);
    }

    public static function kycStatusColor(?string $status): string
    {
        return match ($status) {
            self::KYC_VERIFIED => 'success',
            self::KYC_PENDING => 'warning',
            self::KYC_REJECTED => 'danger',
            default => 'gray',
        };
    }

    /** 60-day written exit notices (v3 P2.4). */
    public function withdrawalNotices()
    {
        return $this->hasMany(InvestmentWithdrawalNotice::class);
    }

    /**
     * Active while any investment is active; withdrawn once every investment
     * has been withdrawn (owner, 2026-09-28); null with no investments. The
     * investor record itself is always kept.
     */
    public function investmentStatus(): ?string
    {
        $statuses = $this->relationLoaded('investments')
            ? $this->investments->pluck('status')
            : $this->investments()->pluck('status');

        if ($statuses->isEmpty()) {
            return null;
        }

        return match (true) {
            $statuses->contains(Investment::STATUS_ACTIVE) => self::STATUS_ACTIVE,
            // Only money that has not been confirmed yet (IMS v2).
            $statuses->contains(Investment::STATUS_PENDING) && ! $statuses->contains(Investment::STATUS_WITHDRAWN) => self::STATUS_PENDING,
            default => self::STATUS_WITHDRAWN,
        };
    }

    /** Investors whose NID / passport number matches, whatever its formatting. */
    public function scopeWhereNid(Builder $query, ?string $number): Builder
    {
        return $query->where('nid_hash', InvestorIdentity::hash($number) ?? '-');
    }

    /** The NID / passport number shown as the investor ID. */
    public function investorId(): ?string
    {
        return InvestorIdentity::normalize($this->nid_number);
    }

    /**
     * Which required paper copies are on file (owner's rule: NID copy,
     * nominee's NID copy when there is a nominee, and a contract for every
     * project they invested in — the project's own contract or a signed
     * copy on the investor).
     *
     * @return array<string, bool> label => on file
     */
    public function documentChecklist(): array
    {
        $categories = $this->documents()->pluck('category')->all();
        $checklist = [__('NID / passport copy') => in_array(InvestmentDocument::CATEGORY_INVESTOR_NID, $categories, true)];

        if (filled($this->nominee_name)) {
            $checklist[__('Nominee NID / passport copy')] = in_array(InvestmentDocument::CATEGORY_NOMINEE_NID, $categories, true);
        }

        if ($this->investments()->exists()) {
            $checklist[__('Contract')] = in_array('signed_contract', $categories, true)
                || $this->investments()->whereDoesntHave('project.documents', fn ($query) => $query->where('category', InvestmentDocument::CATEGORY_PROJECT_CONTRACT))->doesntExist();
        }

        return $checklist;
    }

    /** Investors missing at least one paper copy from documentChecklist(). */
    public function scopeMissingDocuments(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereDoesntHave('documents', fn ($documents) => $documents->where('category', InvestmentDocument::CATEGORY_INVESTOR_NID))
            ->orWhere(fn (Builder $query) => $query
                ->whereNotNull('nominee_name')->where('nominee_name', '!=', '')
                ->whereDoesntHave('documents', fn ($documents) => $documents->where('category', InvestmentDocument::CATEGORY_NOMINEE_NID)))
            ->orWhere(fn (Builder $query) => $query
                ->whereDoesntHave('documents', fn ($documents) => $documents->where('category', 'signed_contract'))
                ->whereHas('investments', fn ($investments) => $investments->whereDoesntHave('project.documents', fn ($documents) => $documents->where('category', InvestmentDocument::CATEGORY_PROJECT_CONTRACT)))));
    }

    /**
     * Name to print on shared documents — the pseudonym if the investor asked
     * for one (deed clause 3), otherwise the real name.
     */
    public function reportName(): string
    {
        return filled($this->display_name) ? (string) $this->display_name : (string) $this->name;
    }

    public function totalInvestedLifetime(): float
    {
        return (float) $this->investments()->where('status', '!=', Investment::STATUS_PENDING)->sum('amount');
    }

    public function totalProfitReceivedLifetime(): float
    {
        return (float) $this->settlementPayouts()->where('payment_status', 'paid')->sum('profit_share_amount');
    }
}
